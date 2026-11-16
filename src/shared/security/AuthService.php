<?php
declare(strict_types=1);

namespace MiniErp\shared\security;

use MiniErp\shared\AppError;
use MiniErp\shared\database\Connection;

final readonly class AuthService
{
    public function __construct(private Connection $connection) {}

    public function authenticate(string $username, #[\SensitiveParameter] string $password, string $company): Actor
    {
        $rows = $this->connection->query(
            'SELECT U.ID, U.USERNAME, U.PASSWORD_HASH, U.IS_ADMIN, U.SECURITY_VERSION, U.MUST_CHANGE_PASSWORD, U.COMPANY_ID, C.NAME AS COMPANY_NAME
             FROM ME_USERS U JOIN ME_COMPANY C ON U.COMPANY_ID = C.ID
             WHERE U.USERNAME = :username AND U.ACTIVE = 1 AND C.CODE = :company AND C.ACTIVE = 1',
            ['username' => strtolower(trim($username)), 'company' => strtoupper(trim($company))],
        );
        if ($rows === [] || !password_verify($password, $rows[0]['PASSWORD_HASH'])) {
            throw new AppError('FORBIDDEN', 'Invalid company, username or password.', 403);
        }
        return $this->loadActor($rows[0]);
    }

    public function actor(string $id, int $companyId, ?int $securityVersion = null): Actor
    {
        $rows = $this->connection->query(
            'SELECT U.ID, U.USERNAME, U.IS_ADMIN, U.SECURITY_VERSION, U.MUST_CHANGE_PASSWORD, U.COMPANY_ID, C.NAME AS COMPANY_NAME
             FROM ME_USERS U JOIN ME_COMPANY C ON U.COMPANY_ID = C.ID
             WHERE U.ID = :id AND U.COMPANY_ID = :company_id AND U.ACTIVE = 1 AND C.ACTIVE = 1',
            ['id' => $id, 'company_id' => $companyId],
        );
        if ($rows === []) {
            throw new AppError('FORBIDDEN', 'Sign in with an active account.', 403);
        }
        if ($securityVersion !== null && (int) $rows[0]['SECURITY_VERSION'] !== $securityVersion) {
            throw new AppError('SESSION_EXPIRED', 'Your account changed. Sign in again.', 403);
        }
        return $this->loadActor($rows[0]);
    }

    private function loadActor(array $user): Actor
    {
        $companyId = filter_var($user['COMPANY_ID'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($companyId === false) {
            throw new AppError('DATABASE_ERROR', 'Account has no valid company identity.', 503);
        }
        $roles = $this->connection->query('SELECT ROLE_CODE FROM ME_USER_ROLES WHERE USER_ID = :id AND COMPANY_ID = :company_id',
            ['id' => $user['ID'], 'company_id' => $companyId]);
        return new Actor(
            $user['ID'], $user['USERNAME'], array_column($roles, 'ROLE_CODE'),
            $companyId,
            (int) $user['IS_ADMIN'] === 1, (int) $user['SECURITY_VERSION'],
            (int) $user['MUST_CHANGE_PASSWORD'] === 1, $user['COMPANY_NAME'],
        );
    }
}
