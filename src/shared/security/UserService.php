<?php
declare(strict_types=1);

namespace MiniErp\shared\security;

use MiniErp\shared\AppError;
use MiniErp\shared\database\Connection;
use MiniErp\shared\database\DatabaseError;
use MiniErp\shared\database\TransactionContext;
use MiniErp\shared\database\TransactionManager;

final readonly class UserService
{
    public function __construct(private Connection $connection) {}

    public function listUsers(Actor $actor, ?int $offset = null, int $limit = 25): array
    {
        $actor->requireAdmin();
        if ($offset !== null && ($offset < 0 || $limit < 1 || $limit > 100)) {
            throw new AppError('VALIDATION_ERROR', 'Invalid user-list pagination.');
        }
        $sql = 'SELECT ID, USERNAME, DISPLAY_NAME, IS_ADMIN, ACTIVE, VERSION FROM ME_USERS WHERE COMPANY_ID = :company_id ORDER BY USERNAME';
        $parameters = ['company_id' => $actor->companyId];
        if ($offset !== null) {
            $sql .= ' OFFSET :offset ROWS FETCH NEXT :limit ROWS ONLY';
            $parameters += ['offset' => $offset, 'limit' => $limit];
        }
        return $this->connection->query($sql, $parameters);
    }

    public function detail(Actor $actor, string $id): array
    {
        $actor->requireAdmin();
        $rows = $this->connection->query('SELECT ID, USERNAME, DISPLAY_NAME, IS_ADMIN, ACTIVE, VERSION FROM ME_USERS WHERE ID = :id AND COMPANY_ID = :company_id',
            ['id' => $id, 'company_id' => $actor->companyId]);
        if ($rows === []) {
            throw new AppError('NOT_FOUND', 'User not found.', 404);
        }
        return [
            'user' => $rows[0],
            'objects' => $this->connection->query('SELECT CODE, NAME, MODULE_CODE, ALLOW_CREATE, ALLOW_READ, ALLOW_UPDATE, ALLOW_DELETE FROM ME_OBJECTS WHERE ACTIVE = 1 ORDER BY MODULE_CODE, NAME'),
            'actions' => $this->connection->query('SELECT OBJECT_CODE, ACTION_CODE, NAME FROM ME_OBJECT_ACTIONS ORDER BY OBJECT_CODE, ACTION_CODE'),
            'grants' => array_map(fn (array $r) => $r['OBJECT_CODE'] . ':' . $r['OPERATION'],
                $this->connection->query('SELECT OBJECT_CODE, OPERATION FROM ME_USER_PERMISSIONS WHERE USER_ID = :id AND COMPANY_ID = :company_id',
                    ['id' => $id, 'company_id' => $actor->companyId])),
        ];
    }

    public function create(Actor $actor, string $username, string $name, #[\SensitiveParameter] string $password, bool $admin): string
    {
        $actor->requireAdmin();
        $this->validateName($name);
        $this->validatePassword($password);
        if (!preg_match('/^[a-z0-9][a-z0-9._-]{2,99}$/', $username)) {
            throw new AppError('VALIDATION_ERROR', 'Username must contain 3-100 lowercase letters, digits, dots, underscores or hyphens.');
        }
        try {
            return $this->transaction($actor, function (TransactionContext $tx) use ($actor, $username, $name, $password, $admin): string {
                $id = bin2hex(random_bytes(16));
                $tx->execute(
                    'INSERT INTO ME_USERS (ID, USERNAME, DISPLAY_NAME, PASSWORD_HASH, IS_ADMIN, MUST_CHANGE_PASSWORD, COMPANY_ID)
                     VALUES (:id, :username, :name, :hash, :admin, 1, :company_id)',
                    ['id' => $id, 'company_id' => $actor->companyId, 'username' => $username, 'name' => $name, 'hash' => password_hash($password, PASSWORD_DEFAULT), 'admin' => $admin ? 1 : 0],
                );
                $tx->execute("INSERT INTO ME_USER_ROLES (USER_ID, ROLE_CODE, COMPANY_ID) VALUES (:id, 'trainee', :company_id)", ['id' => $id, 'company_id' => $actor->companyId]);
                $this->audit($tx, $actor, $id, 'USER_CREATED', ['username' => $username, 'name' => $name, 'admin' => $admin]);
                return $id;
            });
        } catch (DatabaseError $error) {
            if ($error->oracleCode === 1) {
                throw new AppError('VALIDATION_ERROR', 'Username already exists.', 409);
            }
            throw $error;
        }
    }

    public function update(Actor $actor, string $id, string $name, bool $admin, bool $active, int $version): void
    {
        $actor->requireAdmin();
        $this->validateName($name);
        $this->transaction($actor, function (TransactionContext $tx) use ($actor, $id, $name, $admin, $active, $version): void {
            $user = $this->lockUser($tx, $id, $version, $actor->companyId);
            if ((int) $user['IS_ADMIN'] === 1 && (int) $user['ACTIVE'] === 1 && (!$admin || !$active)) {
                $count = (int) $tx->query('SELECT COUNT(*) AS TOTAL FROM ME_USERS WHERE IS_ADMIN = 1 AND ACTIVE = 1 AND COMPANY_ID = :company_id',
                    ['company_id' => $actor->companyId])[0]['TOTAL'];
                if ($count <= 1) {
                    throw new AppError('INVALID_STATE', 'The last active administrator cannot be disabled or demoted.', 409);
                }
            }
            $tx->execute(
                'UPDATE ME_USERS SET DISPLAY_NAME = :name, IS_ADMIN = :admin, ACTIVE = :active,
                 VERSION = VERSION + 1, SECURITY_VERSION = SECURITY_VERSION + 1 WHERE ID = :id AND COMPANY_ID = :company_id',
                ['id' => $id, 'company_id' => $actor->companyId, 'name' => $name, 'admin' => $admin ? 1 : 0, 'active' => $active ? 1 : 0],
            );
            $this->audit($tx, $actor, $id, 'USER_UPDATED', ['before' => $user, 'name' => $name, 'admin' => $admin, 'active' => $active]);
        });
    }

    public function savePermissions(Actor $actor, string $id, array $grants, int $version): void
    {
        $actor->requireAdmin();
        $this->transaction($actor, function (TransactionContext $tx) use ($actor, $id, $grants, $version): void {
            $user = $this->lockUser($tx, $id, $version, $actor->companyId);
            if ((int) $user['IS_ADMIN'] === 1) {
                throw new AppError('INVALID_STATE', 'Administrators already have all registered permissions.', 409);
            }
            $validated = [];
            foreach ($grants as $grant) {
                if (!is_string($grant) || substr_count($grant, ':') !== 1) {
                    throw new AppError('VALIDATION_ERROR', 'Invalid permission selection.');
                }
                [$object, $operation] = explode(':', $grant);
                (new Authorization($this->connection))->requirePermission($actor, $object, $operation);
                $validated[$grant] = [$object, $operation];
            }
            $before = $tx->query('SELECT OBJECT_CODE, OPERATION FROM ME_USER_PERMISSIONS WHERE USER_ID = :id AND COMPANY_ID = :company_id',
                ['id' => $id, 'company_id' => $actor->companyId]);
            $tx->execute('DELETE FROM ME_USER_PERMISSIONS WHERE USER_ID = :id AND COMPANY_ID = :company_id', ['id' => $id, 'company_id' => $actor->companyId]);
            foreach ($validated as [$object, $operation]) {
                $tx->execute('INSERT INTO ME_USER_PERMISSIONS (USER_ID, OBJECT_CODE, OPERATION, COMPANY_ID) VALUES (:id, :object, :operation, :company_id)',
                    ['id' => $id, 'company_id' => $actor->companyId, 'object' => $object, 'operation' => $operation]);
            }
            $tx->execute('UPDATE ME_USERS SET VERSION = VERSION + 1, SECURITY_VERSION = SECURITY_VERSION + 1 WHERE ID = :id AND COMPANY_ID = :company_id',
                ['id' => $id, 'company_id' => $actor->companyId]);
            $this->audit($tx, $actor, $id, 'PERMISSIONS_CHANGED', ['before' => $before, 'grants' => array_keys($validated)]);
        });
    }

    public function resetPassword(Actor $actor, string $id, #[\SensitiveParameter] string $password, int $version): void
    {
        $actor->requireAdmin();
        $this->validatePassword($password);
        $this->transaction($actor, function (TransactionContext $tx) use ($actor, $id, $password, $version): void {
            $this->lockUser($tx, $id, $version, $actor->companyId);
            $tx->execute('UPDATE ME_USERS SET PASSWORD_HASH = :hash, MUST_CHANGE_PASSWORD = 1,
                VERSION = VERSION + 1, SECURITY_VERSION = SECURITY_VERSION + 1 WHERE ID = :id AND COMPANY_ID = :company_id',
                ['id' => $id, 'company_id' => $actor->companyId, 'hash' => password_hash($password, PASSWORD_DEFAULT)]);
            $this->audit($tx, $actor, $id, 'PASSWORD_RESET', []);
        });
    }

    public function changePassword(Actor $actor, #[\SensitiveParameter] string $oldPassword, #[\SensitiveParameter] string $newPassword): void
    {
        $this->validatePassword($newPassword);
        if ($oldPassword === $newPassword) {
            throw new AppError('VALIDATION_ERROR', 'Choose a different password.');
        }
        $this->transaction($actor, function (TransactionContext $tx) use ($actor, $oldPassword, $newPassword): void {
            $rows = $tx->query('SELECT ID, PASSWORD_HASH FROM ME_USERS WHERE ID = :id AND COMPANY_ID = :company_id AND ACTIVE = 1 FOR UPDATE',
                ['id' => $actor->id, 'company_id' => $actor->companyId]);
            if ($rows === [] || !password_verify($oldPassword, $rows[0]['PASSWORD_HASH'])) {
                throw new AppError('FORBIDDEN', 'Current password is incorrect.', 403);
            }
            $tx->execute('UPDATE ME_USERS SET PASSWORD_HASH = :hash, MUST_CHANGE_PASSWORD = 0,
                VERSION = VERSION + 1, SECURITY_VERSION = SECURITY_VERSION + 1 WHERE ID = :id AND COMPANY_ID = :company_id',
                ['id' => $actor->id, 'company_id' => $actor->companyId, 'hash' => password_hash($newPassword, PASSWORD_DEFAULT)]);
            $this->audit($tx, $actor, $actor->id, 'PASSWORD_CHANGED', []);
        }, false);
    }

    private function transaction(Actor $actor, callable $operation, bool $requireAdmin = true): mixed
    {
        return (new TransactionManager($this->connection))->run(function (TransactionContext $tx) use ($actor, $operation, $requireAdmin): mixed {
            // Serialize security changes within this company, not across tenants.
            if ($tx->query('SELECT ID FROM ME_COMPANY WHERE ID = :company_id AND ACTIVE = 1 FOR UPDATE', ['company_id' => $actor->companyId]) === []) {
                throw new AppError('INVALID_STATE', 'Company is not configured.', 409);
            }
            $rows = $tx->query('SELECT IS_ADMIN, ACTIVE, SECURITY_VERSION, MUST_CHANGE_PASSWORD FROM ME_USERS WHERE ID = :id AND COMPANY_ID = :company_id',
                ['id' => $actor->id, 'company_id' => $actor->companyId]);
            if ($rows === [] || (int) $rows[0]['ACTIVE'] !== 1 || (int) $rows[0]['SECURITY_VERSION'] !== $actor->securityVersion) {
                throw new AppError('SESSION_EXPIRED', 'Sign in again.', 403);
            }
            if ($requireAdmin && ((int) $rows[0]['IS_ADMIN'] !== 1 || (int) $rows[0]['MUST_CHANGE_PASSWORD'] === 1)) {
                throw new AppError('FORBIDDEN', 'Administrator access with a permanent password is required.', 403);
            }
            return $operation($tx);
        });
    }

    private function lockUser(TransactionContext $tx, string $id, int $version, int $companyId): array
    {
        $rows = $tx->query('SELECT ID, DISPLAY_NAME, IS_ADMIN, ACTIVE, VERSION FROM ME_USERS WHERE ID = :id AND COMPANY_ID = :company_id FOR UPDATE',
            ['id' => $id, 'company_id' => $companyId]);
        if ($rows === []) {
            throw new AppError('NOT_FOUND', 'User not found.', 404);
        }
        if ((int) $rows[0]['VERSION'] !== $version) {
            throw new AppError('STALE_VERSION', 'User changed. Refresh before saving.', 409);
        }
        return $rows[0];
    }

    private function validateName(string $name): void
    {
        if (trim($name) === '' || mb_strlen($name) > 150 || strlen($name) > 150) {
            throw new AppError('VALIDATION_ERROR', 'Display name must contain 1-150 bytes.');
        }
    }

    private function validatePassword(#[\SensitiveParameter] string $password): void
    {
        if (strlen($password) < 12 || strlen($password) > 72) {
            throw new AppError('VALIDATION_ERROR', 'Password must contain 12-72 bytes.');
        }
    }

    private function audit(TransactionContext $tx, Actor $actor, string $target, string $event, array $details): void
    {
        $tx->execute('INSERT INTO ME_SECURITY_AUDIT (ID, ACTOR_ID, TARGET_ID, EVENT_TYPE, DETAILS_JSON, COMPANY_ID) VALUES (:id, :actor, :target, :event, :details, :company_id)',
            ['id' => bin2hex(random_bytes(16)), 'company_id' => $actor->companyId, 'actor' => $actor->id, 'target' => $target, 'event' => $event,
                'details' => json_encode($details, JSON_THROW_ON_ERROR)]);
    }
}
