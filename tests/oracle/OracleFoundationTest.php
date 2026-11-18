<?php
declare(strict_types=1);

namespace MiniErp\Tests\oracle;

use MiniErp\sales\LeadService;
use MiniErp\shared\AppError;
use MiniErp\shared\Api;
use MiniErp\shared\Config;
use MiniErp\shared\database\DatabaseError;
use MiniErp\shared\database\OciConnection;
use MiniErp\shared\database\TransactionContext;
use MiniErp\shared\database\TransactionManager;
use MiniErp\shared\NeutralNote;
use MiniErp\shared\security\AuthService;
use MiniErp\shared\security\Actor;
use MiniErp\shared\security\Authorization;
use MiniErp\shared\security\CompanyBootstrap;
use MiniErp\shared\security\UserService;
use PHPUnit\Framework\TestCase;

final class OracleFoundationTest extends TestCase
{
    private Config $config;
    private OciConnection $connection;

    protected function setUp(): void
    {
        $this->config = require dirname(__DIR__, 2) . '/bootstrap.php';
        if (($_ENV['LAB_ORACLE_TEST'] ?? getenv('LAB_ORACLE_TEST')) !== '1') {
            self::markTestSkipped('Remote Oracle not configured/enabled. This is NOT Oracle acceptance.');
        }
        $expected = $_ENV['ORACLE_TEST_SCHEMA'] ?? getenv('ORACLE_TEST_SCHEMA');
        if (!is_string($expected) || !str_ends_with(strtoupper($expected), '_TEST')) {
            self::fail('Set ORACLE_TEST_SCHEMA to a dedicated disposable schema ending in _TEST.');
        }
        $this->connection = OciConnection::connect($this->config);
        self::assertSame(strtoupper($expected), $this->connection->query('SELECT USER AS SCHEMA_NAME FROM DUAL')[0]['SCHEMA_NAME']);
    }

    public function testLeadsPersistReplayArchiveRestoreAndRejectForeignCompanyAndLostUpdates(): void
    {
        $companyIds = [];
        $userId = 'lead-test-' . bin2hex(random_bytes(8));
        $manager = new TransactionManager($this->connection);
        $service = new LeadService($this->connection);
        try {
            $manager->run(function (TransactionContext $tx) use (&$companyIds, $userId): void {
                foreach ([1, 2] as $number) {
                    $id = (int) $tx->query('SELECT ME_COMPANY_SEQ.NEXTVAL AS ID FROM DUAL')[0]['ID'];
                    $companyIds[] = $id;
                    $tx->execute("INSERT INTO ME_COMPANY (ID, CODE, NAME) VALUES (:id, :code, 'Lead test company')",
                        ['id' => $id, 'code' => strtoupper($userId) . '-' . $number]);
                    $tx->execute("INSERT INTO ME_USERS (COMPANY_ID, ID, USERNAME, DISPLAY_NAME, PASSWORD_HASH)
                        VALUES (:company_id, :id, :username, 'Lead test user', :hash)",
                        ['company_id' => $id, 'id' => $userId . '-' . $number, 'username' => $userId,
                            'hash' => password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT)]);
                }
            });
            $actor = new Actor($userId . '-1', $userId, [], $companyIds[0]);
            try {
                $service->create($actor, ['contact_name' => 'Denied', 'phone' => '123', 'action_reference' => 'denied']);
                self::fail('New users must not have Leads access.');
            } catch (AppError $error) {
                self::assertSame('FORBIDDEN', $error->errorCode);
            }
            $manager->run(function (TransactionContext $tx) use ($actor): void {
                foreach (['create', 'read', 'update', 'archive', 'restore'] as $operation) {
                    $tx->execute("INSERT INTO ME_USER_PERMISSIONS (COMPANY_ID, USER_ID, OBJECT_CODE, OPERATION)
                        VALUES (:company_id, :user_id, 'sales.leads', :operation)",
                        ['company_id' => $actor->companyId, 'user_id' => $actor->id, 'operation' => $operation]);
                }
            });
            $create = ['contact_name' => 'Alex', 'email' => 'alex@example.test', 'notes' => str_repeat("\u{1F642}", 1000), 'action_reference' => 'create'];
            $lead = $service->create($actor, $create);
            self::assertSame($lead, $service->create($actor, $create));
            self::assertSame($create['notes'], $service->detail($actor, $lead['id'])['notes']);
            $observer = new LeadService(OciConnection::connect($this->config));
            self::assertSame(1, $observer->detail($actor, $lead['id'])['current_version']);
            self::assertSame($lead['id'], $service->list($actor, ['search' => 'Alex'])['items'][0]['id']);
            $service->update($actor, $lead['id'], ['status' => 'contacted', 'expected_version' => 1, 'action_reference' => 'update']);
            try {
                $service->update($actor, $lead['id'], ['status' => 'lost', 'expected_version' => 1, 'action_reference' => 'stale']);
                self::fail('Stale edit must fail.');
            } catch (AppError $error) {
                self::assertSame('STALE_VERSION', $error->errorCode);
            }
            self::assertSame([], $this->connection->query("SELECT ACTION_REFERENCE FROM ME_ACTIONS WHERE COMPANY_ID = :company_id AND ACTION_REFERENCE = 'stale'",
                ['company_id' => $actor->companyId]));
            $service->archive($actor, $lead['id'], ['expected_version' => 2, 'action_reference' => 'archive']);
            self::assertSame([], $service->list($actor)['items']);
            self::assertTrue($service->detail($actor, $lead['id'])['archived']);
            self::assertCount(1, $service->list($actor, ['archived' => 'true'])['items']);
            $service->restore($actor, $lead['id'], ['expected_version' => 3, 'action_reference' => 'restore']);
            self::assertFalse($observer->detail($actor, $lead['id'])['archived']);
            self::assertSame(4, $observer->detail($actor, $lead['id'])['current_version']);
            $foreign = new Actor($userId . '-2', $userId, [], $companyIds[1], true);
            try {
                $service->archive($foreign, $lead['id'], ['expected_version' => 4, 'action_reference' => 'foreign']);
                self::fail('Even an administrator cannot mutate another company lead.');
            } catch (AppError $error) {
                self::assertSame('NOT_FOUND', $error->errorCode);
            }
            self::assertSame([], $service->list($foreign)['items']);
            $events = $this->connection->query('SELECT EVENT_TYPE FROM ME_AUDIT WHERE COMPANY_ID = :company_id ORDER BY EVENT_TYPE',
                ['company_id' => $actor->companyId]);
            self::assertSame(['LEAD_ARCHIVE', 'LEAD_CREATE', 'LEAD_RESTORE', 'LEAD_UPDATE'], array_column($events, 'EVENT_TYPE'));
        } finally {
            $manager->run(function (TransactionContext $tx) use ($companyIds): void {
                foreach ($companyIds as $companyId) {
                    foreach (['ME_SAL_LEADS', 'ME_AUDIT', 'ME_ACTIONS', 'ME_USER_PERMISSIONS', 'ME_USERS'] as $table) {
                        $tx->execute("DELETE FROM $table WHERE COMPANY_ID = :company_id", ['company_id' => $companyId]);
                    }
                    $tx->execute('DELETE FROM ME_COMPANY WHERE ID = :company_id', ['company_id' => $companyId]);
                }
            });
        }
    }

    public function testTwoWritesCommitTogetherAndFailureRollsBothBack(): void
    {
        $observer = OciConnection::connect($this->config);
        $manager = new TransactionManager($this->connection);
        $id1 = 'tx-' . bin2hex(random_bytes(8));
        $id2 = 'tx-' . bin2hex(random_bytes(8));
        try {
            $manager->run(function (TransactionContext $tx) use ($observer, $id1, $id2): void {
                foreach ([$id1, $id2] as $id) {
                    $tx->execute('INSERT INTO ME_NOTES (ID, BODY, COMPANY_ID) VALUES (:id, :body, 1)', ['id' => $id, 'body' => 'before']);
                }
                self::assertSame([], $observer->query('SELECT ID FROM ME_NOTES WHERE ID IN (:a, :b)', ['a' => $id1, 'b' => $id2]));
            });
            self::assertCount(2, $observer->query('SELECT ID FROM ME_NOTES WHERE ID IN (:a, :b)', ['a' => $id1, 'b' => $id2]));
            try {
                $manager->run(function (TransactionContext $tx) use ($id1, $id2): never {
                    foreach ([$id1, $id2] as $id) {
                        $tx->execute('UPDATE ME_NOTES SET BODY = :body WHERE ID = :id', ['body' => 'after', 'id' => $id]);
                    }
                    throw new AppError('INVALID_STATE', 'Injected second-write failure.', 409);
                });
                self::fail('Expected a rollback.');
            } catch (AppError $error) {
                self::assertSame('INVALID_STATE', $error->errorCode);
            }
            $rows = $observer->query('SELECT BODY FROM ME_NOTES WHERE ID IN (:a, :b)', ['a' => $id1, 'b' => $id2]);
            self::assertSame(['before', 'before'], array_column($rows, 'BODY'));
        } finally {
            $manager->run(fn (TransactionContext $tx) => $tx->execute(
                'DELETE FROM ME_NOTES WHERE ID IN (:a, :b)', ['a' => $id1, 'b' => $id2],
            ));
        }
    }

    public function testTwoIndependentSessionsEnforceRowLock(): void
    {
        $second = OciConnection::connect($this->config);
        $this->connection->begin();
        $second->begin();
        try {
            self::assertCount(1, $this->connection->query("SELECT ID FROM ME_NOTES WHERE ID = 'welcome' AND COMPANY_ID = 1 FOR UPDATE NOWAIT"));
            try {
                $second->query("SELECT ID FROM ME_NOTES WHERE ID = 'welcome' AND COMPANY_ID = 1 FOR UPDATE NOWAIT");
                self::fail('The second independent session must not acquire the held row.');
            } catch (DatabaseError $error) {
                self::assertSame(54, $error->oracleCode);
            }
        } finally {
            $second->rollback();
            $this->connection->rollback();
        }
    }

    public function testRealReplayAuditStaleVersionAndMultibyteLobRoundTrip(): void
    {
        $actor = (new AuthService($this->connection))->actor('lab-buyer', 1);
        $note = new NeutralNote($this->connection);
        $before = $note->read($actor);
        $reference = 'oracle-' . bin2hex(random_bytes(8));
        $staleReference = $reference . '-stale';
        $manager = new TransactionManager($this->connection);
        try {
            $text = str_repeat("\u{1F642}", 500);
            $result = $note->update($actor, $reference, $text, $before['current_version']);
            self::assertSame($text, $note->read($actor)['text']);
            self::assertSame($result, $note->update($actor, $reference, $text, $before['current_version']));
            self::assertSame(1, (int) $this->connection->query('SELECT COUNT(*) AS TOTAL FROM ME_AUDIT WHERE ACTION_REFERENCE = :reference', ['reference' => $reference])[0]['TOTAL']);
            try {
                $note->update($actor, $staleReference, 'stale', $before['current_version']);
                self::fail('A new action with the old version must fail.');
            } catch (AppError $error) {
                self::assertSame('STALE_VERSION', $error->errorCode);
            }
            self::assertSame([], $this->connection->query('SELECT ACTION_REFERENCE FROM ME_ACTIONS WHERE ACTION_REFERENCE = :reference', ['reference' => $staleReference]));
        } finally {
            $manager->run(function (TransactionContext $tx) use ($reference, $staleReference, $before): void {
                $parameters = ['a' => $reference, 'b' => $staleReference];
                $tx->execute('DELETE FROM ME_AUDIT WHERE ACTION_REFERENCE IN (:a, :b)', $parameters);
                $tx->execute('DELETE FROM ME_ACTIONS WHERE ACTION_REFERENCE IN (:a, :b)', $parameters);
                $tx->execute("UPDATE ME_NOTES SET BODY = :body, VERSION = :version WHERE ID = 'welcome' AND COMPANY_ID = 1",
                    ['body' => $before['text'], 'version' => $before['current_version']]);
            });
        }
    }

    public function testCompanyUserPermissionsAndSessionRevocation(): void
    {
        $suffix = bin2hex(random_bytes(8));
        $adminId = 'oracle-admin-' . $suffix;
        $username = 'oracle-' . $suffix;
        $object = 'tests.items_' . $suffix;
        $password = 'oracle-fixture-password';
        $manager = new TransactionManager($this->connection);
        $manager->run(function (TransactionContext $tx) use ($adminId, $username, $object, $password): void {
            $tx->execute('INSERT INTO ME_USERS (ID, USERNAME, DISPLAY_NAME, PASSWORD_HASH, IS_ADMIN, COMPANY_ID)
                VALUES (:id, :username, :name, :hash, 1, 1)',
                ['id' => $adminId, 'username' => $username, 'name' => 'Oracle test admin', 'hash' => password_hash($password, PASSWORD_DEFAULT)]);
            $tx->execute("INSERT INTO ME_OBJECTS (CODE, NAME, MODULE_CODE, ALLOW_READ) VALUES (:object, 'Test items', 'tests', 1)", ['object' => $object]);
            $tx->execute("INSERT INTO ME_OBJECT_ACTIONS (OBJECT_CODE, ACTION_CODE, NAME) VALUES (:object, 'post', 'Post')", ['object' => $object]);
        });
        $userId = null;
        try {
            $auth = new AuthService($this->connection);
            self::assertFalse((new CompanyBootstrap($this->connection))->run($username, 'different-fixture-password'));
            $admin = $auth->authenticate(strtoupper($username), $password, 'sia-mini-erp');
            self::assertTrue($admin->isAdmin);
            $users = new UserService($this->connection);
            $userId = $users->create($admin, 'user-' . $suffix, 'Oracle graduate', $password, false);
            $user = $auth->actor($userId, 1);
            self::assertTrue($user->mustChangePassword);
            $users->changePassword($user, $password, 'changed-fixture-password');
            $user = $auth->authenticate('user-' . $suffix, 'changed-fixture-password', 'SIA-MINI-ERP');
            self::assertFalse($user->mustChangePassword);
            $permissions = new Authorization($this->connection);
            try {
                $permissions->requirePermission($user, $object, 'read');
                self::fail('New non-admin must have no grants.');
            } catch (AppError $error) {
                self::assertSame('FORBIDDEN', $error->errorCode);
            }
            $version = (int) $users->detail($admin, $userId)['user']['VERSION'];
            $users->savePermissions($admin, $userId, [$object . ':read', $object . ':post'], $version);
            try {
                $auth->actor($userId, 1, $user->securityVersion);
                self::fail('Changing grants must invalidate the old session version.');
            } catch (AppError $error) {
                self::assertSame('SESSION_EXPIRED', $error->errorCode);
            }
            $current = $auth->actor($userId, 1);
            $permissions->requirePermission($current, $object, 'read');
            $permissions->requirePermission($current, $object, 'post');
            $audits = $this->connection->query('SELECT DETAILS_JSON FROM ME_SECURITY_AUDIT WHERE TARGET_ID = :id', ['id' => $userId]);
            self::assertCount(3, $audits);
            foreach ($audits as $audit) {
                self::assertStringNotContainsString($password, $audit['DETAILS_JSON']);
                self::assertStringNotContainsString('changed-fixture-password', $audit['DETAILS_JSON']);
            }
        } finally {
            $manager->run(function (TransactionContext $tx) use ($adminId, $userId, $object): void {
                $tx->execute('DELETE FROM ME_SECURITY_AUDIT WHERE ACTOR_ID = :id', ['id' => $adminId]);
                if ($userId !== null) {
                    $tx->execute('DELETE FROM ME_SECURITY_AUDIT WHERE TARGET_ID = :id', ['id' => $userId]);
                    $tx->execute('DELETE FROM ME_USER_PERMISSIONS WHERE USER_ID = :id', ['id' => $userId]);
                    $tx->execute('DELETE FROM ME_USER_ROLES WHERE USER_ID = :id', ['id' => $userId]);
                    $tx->execute('DELETE FROM ME_USERS WHERE ID = :id', ['id' => $userId]);
                }
                $tx->execute('DELETE FROM ME_OBJECT_ACTIONS WHERE OBJECT_CODE = :object', ['object' => $object]);
                $tx->execute('DELETE FROM ME_OBJECTS WHERE CODE = :object', ['object' => $object]);
                $tx->execute('DELETE FROM ME_USERS WHERE ID = :id', ['id' => $adminId]);
            });
        }
    }

    public function testNewCompanyHasItsOwnAdminNoteAndDatabaseEnforcedUserBoundary(): void
    {
        $code = 'TENANT_' . strtoupper(bin2hex(random_bytes(6)));
        $companyId = (new CompanyBootstrap($this->connection))->create($code, 'Isolated Oracle tenant');
        $manager = new TransactionManager($this->connection);
        $reference = 'tenant-' . bin2hex(random_bytes(12));
        $original = null;
        try {
            $auth = new AuthService($this->connection);
            $admin = $auth->authenticate('admin', 'admin', $code);
            self::assertTrue($admin->isAdmin);
            self::assertSame($companyId, $admin->companyId);
            $users = new UserService($this->connection);
            self::assertCount(11, $users->listUsers($admin));
            $buyer = $auth->authenticate('buyer', 'buyer', $code);
            self::assertSame($companyId, $buyer->companyId);
            self::assertNotSame('lab-buyer', $buyer->id);
            $note = new NeutralNote($this->connection);
            self::assertSame(1, $note->read($buyer)['current_version']);
            $defaultActor = $auth->actor('lab-buyer', 1);
            $original = $note->read($defaultActor);
            $note->update($buyer, $reference, 'Other company note', 1);
            $note->update($defaultActor, $reference, 'Default company note', $original['current_version']);
            self::assertSame('Other company note', $note->read($admin)['text']);
            self::assertSame('Default company note', $note->read($defaultActor)['text']);
            self::assertSame(2, (int) $this->connection->query(
                'SELECT COUNT(*) AS TOTAL FROM ME_ACTIONS WHERE ACTION_REFERENCE = :reference', ['reference' => $reference],
            )[0]['TOTAL']);
            try {
                $users->detail($admin, 'lab-buyer');
                self::fail('A company administrator must not read another tenant user.');
            } catch (AppError $error) {
                self::assertSame('NOT_FOUND', $error->errorCode);
            }
            try {
                $manager->run(fn (TransactionContext $tx) => $tx->execute(
                    "INSERT INTO ME_USER_ROLES (COMPANY_ID, USER_ID, ROLE_CODE) VALUES (:company_id, 'lab-buyer', 'supervisor')",
                    ['company_id' => $companyId],
                ));
                self::fail('The composite foreign key must reject a foreign-company user.');
            } catch (DatabaseError $error) {
                self::assertSame(2291, $error->oracleCode);
            }
        } finally {
            $manager->run(function (TransactionContext $tx) use ($companyId, $reference, $original): void {
                $tx->execute('DELETE FROM ME_AUDIT WHERE COMPANY_ID = 1 AND ACTION_REFERENCE = :reference', ['reference' => $reference]);
                $tx->execute('DELETE FROM ME_ACTIONS WHERE COMPANY_ID = 1 AND ACTION_REFERENCE = :reference', ['reference' => $reference]);
                if ($original !== null) {
                    $tx->execute("UPDATE ME_NOTES SET BODY = :body, VERSION = :version WHERE COMPANY_ID = 1 AND ID = 'welcome'",
                        ['body' => $original['text'], 'version' => $original['current_version']]);
                }
                foreach (['ME_AUDIT', 'ME_ACTIONS', 'ME_USER_PERMISSIONS', 'ME_USER_ROLES', 'ME_NOTES', 'ME_USERS'] as $table) {
                    $tx->execute("DELETE FROM $table WHERE COMPANY_ID = :company_id", ['company_id' => $companyId]);
                }
                $tx->execute('DELETE FROM ME_COMPANY WHERE ID = :company_id', ['company_id' => $companyId]);
            });
        }
    }

    public function testRealBearerApiWritesReplayAndAccountChangesRevokeAccess(): void
    {
        $this->connection->query('SELECT TOKEN_HASH FROM ME_API_TOKENS WHERE 1 = 0');
        $code = 'API_' . strtoupper(bin2hex(random_bytes(6)));
        $companyId = (new CompanyBootstrap($this->connection))->create($code, 'API acceptance tenant');
        $manager = new TransactionManager($this->connection);
        $username = 'api' . strtolower(bin2hex(random_bytes(6)));
        $reference = 'api-note-' . bin2hex(random_bytes(8));
        try {
            $api = new Api($this->connection, $this->config, 'oracle-' . $code);
            $login = $api->handle('POST', '/api/v1/auth/login',
                ['company' => $code, 'username' => 'admin', 'password' => 'admin'], ['content-type' => 'application/json']);
            self::assertSame(200, $login['status']);
            $token = json_decode($login['body'], true, flags: JSON_THROW_ON_ERROR)['result']['access_token'];
            $headers = ['authorization' => 'Bearer ' . $token, 'content-type' => 'application/json'];
            $noteInput = ['text' => 'API-owned tenant note', 'action_reference' => $reference, 'expected_version' => 1];
            $write = $api->handle('POST', '/api/v1/note', $noteInput, $headers);
            self::assertSame(200, $write['status']);
            self::assertSame($write['body'], $api->handle('POST', '/api/v1/note', $noteInput, $headers)['body']);
            self::assertSame(409, $api->handle('POST', '/api/v1/note', [...$noteInput, 'text' => 'Changed payload'], $headers)['status']);
            $create = $api->handle('POST', '/api/v1/users', ['username' => $username, 'display_name' => 'API fixture',
                'password' => 'Temporary-fixture-pass', 'is_admin' => false], $headers);
            self::assertSame(201, $create['status']);
            $id = json_decode($create['body'], true, flags: JSON_THROW_ON_ERROR)['result']['id'];
            $userLogin = $api->handle('POST', '/api/v1/auth/login', ['company' => $code, 'username' => $username,
                'password' => 'Temporary-fixture-pass'], ['content-type' => 'application/json']);
            self::assertSame(200, $userLogin['status']);
            $userHeaders = ['authorization' => 'Bearer ' . json_decode($userLogin['body'], true)['result']['access_token'],
                'content-type' => 'application/json'];
            self::assertSame(403, $api->handle('GET', '/api/v1/note', [], $userHeaders)['status']);
            self::assertSame(200, $api->handle('POST', '/api/v1/auth/change-password',
                ['current_password' => 'Temporary-fixture-pass', 'new_password' => 'Changed-fixture-pass'], $userHeaders)['status']);
            self::assertSame(401, $api->handle('GET', '/api/v1/auth/me', [], $userHeaders)['status']);
            $userLogin = $api->handle('POST', '/api/v1/auth/login', ['company' => $code, 'username' => $username,
                'password' => 'Changed-fixture-pass'], ['content-type' => 'application/json']);
            $userHeaders['authorization'] = 'Bearer ' . json_decode($userLogin['body'], true)['result']['access_token'];
            self::assertSame(403, $api->handle('GET', '/api/v1/note', [], $userHeaders)['status']);
            self::assertSame(200, $api->handle('POST', '/api/v1/users/' . $id . '/permissions',
                ['expected_version' => 2, 'grants' => ['shared.note:read']], $headers)['status']);
            self::assertSame(401, $api->handle('GET', '/api/v1/auth/me', [], $userHeaders)['status']);
            $userLogin = $api->handle('POST', '/api/v1/auth/login', ['company' => $code, 'username' => $username,
                'password' => 'Changed-fixture-pass'], ['content-type' => 'application/json']);
            $userHeaders['authorization'] = 'Bearer ' . json_decode($userLogin['body'], true)['result']['access_token'];
            self::assertSame(200, $api->handle('GET', '/api/v1/note', [], $userHeaders)['status']);
            $list = $api->handle('GET', '/api/v1/users', [], $headers, ['page' => '2', 'page_size' => '2']);
            self::assertCount(2, json_decode($list['body'], true)['result']['items']);
            self::assertSame(200, $api->handle('PATCH', '/api/v1/users/' . $id,
                ['expected_version' => 3, 'display_name' => 'Disabled fixture', 'is_admin' => false, 'active' => false], $headers)['status']);
            self::assertSame(401, $api->handle('GET', '/api/v1/auth/me', [], $userHeaders)['status']);
            self::assertSame(200, $api->handle('POST', '/api/v1/auth/logout', [], $headers)['status']);
            self::assertSame(401, $api->handle('GET', '/api/v1/auth/me', [], $headers)['status']);
        } finally {
            $manager->run(function (TransactionContext $tx) use ($companyId, $code, $username): void {
                foreach (['ME_API_TOKENS', 'ME_SECURITY_AUDIT', 'ME_AUDIT', 'ME_ACTIONS', 'ME_USER_PERMISSIONS',
                    'ME_USER_ROLES', 'ME_NOTES', 'ME_USERS'] as $table) {
                    $tx->execute("DELETE FROM $table WHERE COMPANY_ID = :company_id", ['company_id' => $companyId]);
                }
                foreach (['ip:oracle-' . $code, 'account:' . $code . "\0admin", 'account:' . $code . "\0" . $username] as $key) {
                    $tx->execute('DELETE FROM ME_API_LOGIN_LIMITS WHERE LOGIN_KEY = :key', ['key' => hash('sha256', $key)]);
                }
                $tx->execute('DELETE FROM ME_COMPANY WHERE ID = :company_id', ['company_id' => $companyId]);
            });
            }
}
}
