<?php
declare(strict_types=1);

namespace MiniErp\Tests\unit;

use MiniErp\shared\Config;
use MiniErp\shared\Kernel;
use MiniErp\shared\database\Connection;
use PHPUnit\Framework\TestCase;

final class KernelTest extends TestCase
{
    public function testApacheSubdirectoryLandingAndUrls(): void
    {
        $base = '/users/pinaki.das/mini-erp/public';
        $session = [];
        $kernel = new Kernel(Config::fromArray(['APP_BASE_PATH' => $base]), $session);
        $response = $kernel->handle('GET', $base . '/', []);
        self::assertSame(200, $response['status']);
        self::assertStringContainsString('action="' . $base . '/login"', $response['body']);
        self::assertStringContainsString('href="' . $base . '/assets/app.css"', $response['body']);
        self::assertStringContainsString('src="' . $base . '/assets/app.js"', $response['body']);
        self::assertSame(200, $kernel->handle('GET', $base . '/health', [])['status']);
        self::assertSame(403, $kernel->handle('POST', $base . '/api/note', [])['status']);
        self::assertSame(404, $kernel->handle('GET', '/health', [])['status']);
    }

    public function testSubdirectoryLoginRedirectStaysInApplication(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnOnConsecutiveCalls(
            [['ID' => 'u', 'USERNAME' => 'user', 'PASSWORD_HASH' => password_hash('fixture-password', PASSWORD_DEFAULT),
                'IS_ADMIN' => 0, 'SECURITY_VERSION' => 1, 'MUST_CHANGE_PASSWORD' => 1, 'COMPANY_NAME' => 'SIA-MINI-ERP', 'COMPANY_ID' => 1]],
            [['ROLE_CODE' => 'trainee']],
        );
        $session = [];
        $kernel = new Kernel(Config::fromArray([]), $session, $db, '/nested/index.php');
        $kernel->handle('GET', '/nested/', []);
        $response = $kernel->handle('POST', '/nested/login', [
            'csrf_token' => $session['csrf_token'], 'company' => 'SIA-MINI-ERP', 'username' => 'user', 'password' => 'fixture-password',
        ]);
        self::assertSame(303, $response['status']);
        self::assertSame('/nested/account/password', $response['headers']['Location']);
        $response = $kernel->handle('POST', '/nested/logout', ['csrf_token' => $session['csrf_token']]);
        self::assertSame('/nested/', $response['headers']['Location']);
    }

    public function testLandingAndModulePagesWorkWithoutOracle(): void
    {
        $session = [];
        $kernel = new Kernel(Config::fromArray([]), $session);
        $response = $kernel->handle('GET', '/', []);
        self::assertSame(200, $response['status']);
        self::assertStringContainsString('Sign in to Mini ERP', $response['body']);
        self::assertSame(403, $kernel->handle('GET', '/modules/sales', [])['status']);
        self::assertSame(404, $kernel->handle('GET', '/missing', [])['status']);
    }

    public function testLivenessDoesNotClaimOracleReadiness(): void
    {
        $session = [];
        $kernel = new Kernel(Config::fromArray([]), $session);
        self::assertSame(200, $kernel->handle('GET', '/health', [])['status']);
        $ready = $kernel->handle('GET', '/ready', []);
        self::assertSame(503, $ready['status']);
        self::assertStringContainsString('CONFIGURATION_ERROR', $ready['body']);
    }

    public function testLoginAndProtectedWriteRejectInvalidCsrfWithoutOracle(): void
    {
        $session = [];
        $kernel = new Kernel(Config::fromArray([]), $session);
        self::assertSame(200, $kernel->handle('GET', '/login', [])['status']);
        self::assertSame(403, $kernel->handle('POST', '/login', ['csrf_token' => 'bad'])['status']);
        self::assertSame(403, $kernel->handle('POST', '/api/note', [], ['x-csrf-token' => 'bad'])['status']);
    }

    public function testReadinessRejectsModifiedAppliedMigration(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(function (string $sql): array {
            if (str_contains($sql, 'USER_TABLES')) {
                return array_map(fn (string $table) => ['TABLE_NAME' => $table],
                    ['ME_MIGRATIONS', 'ME_USERS', 'ME_ROLES', 'ME_USER_ROLES', 'ME_NOTES', 'ME_ACTIONS', 'ME_AUDIT',
                        'ME_COMPANY', 'ME_OBJECTS', 'ME_OBJECT_ACTIONS', 'ME_USER_PERMISSIONS', 'ME_SECURITY_AUDIT',
                        'ME_API_TOKENS', 'ME_API_LOGIN_LIMITS']);
            }
            if (str_contains($sql, "STATUS <> 'APPLIED'")) {
                return [];
            }
            if (str_contains($sql, 'FROM ME_MIGRATIONS')) {
                return [['VERSION' => '001_foundation.php', 'CHECKSUM' => 'changed', 'STATUS' => 'APPLIED']];
            }
            return [['ID' => 'welcome']];
        });
        $session = [];
        self::assertSame(503, (new Kernel(Config::fromArray([]), $session, $db))->handle('GET', '/ready', [])['status']);
    }

    public function testLoginUsesServerActorAndRotatesCsrf(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnOnConsecutiveCalls(
            [['ID' => 'lab-buyer', 'USERNAME' => 'buyer', 'PASSWORD_HASH' => password_hash('fixture-only-password', PASSWORD_DEFAULT),
                'IS_ADMIN' => 0, 'SECURITY_VERSION' => 1, 'MUST_CHANGE_PASSWORD' => 0, 'COMPANY_NAME' => 'SIA-MINI-ERP', 'COMPANY_ID' => 1]],
            [['ROLE_CODE' => 'trainee']],
        );
        $session = [];
        $kernel = new Kernel(Config::fromArray([]), $session, $db);
        $kernel->handle('GET', '/login', []);
        $oldToken = $session['csrf_token'];
        $result = $kernel->handle('POST', '/login', [
            'csrf_token' => $oldToken, 'company' => 'SIA-MINI-ERP', 'username' => 'buyer', 'password' => 'fixture-only-password', 'actor_id' => 'forged', 'company_id' => 999,
        ]);
        self::assertSame(303, $result['status']);
        self::assertSame('lab-buyer', $session['actor_id']);
        self::assertSame(1, $session['company_id']);
        self::assertNotSame($oldToken, $session['csrf_token']);
    }

    public function testLoginShowsManualCompanyAndSettingsDeniesAnonymous(): void
    {
        $session = [];
        $kernel = new Kernel(Config::fromArray([]), $session);
        self::assertStringContainsString('name="company"', $kernel->handle('GET', '/login', [])['body']);
        self::assertSame(403, $kernel->handle('GET', '/settings/users', [])['status']);
        self::assertSame(403, $kernel->handle('GET', '/api/settings/users', [])['status']);
    }

    public function testBootstrapUserMustChangePasswordBeforeSettings(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnOnConsecutiveCalls(
            [['ID' => 'a', 'USERNAME' => 'admin', 'IS_ADMIN' => 1, 'SECURITY_VERSION' => 1, 'MUST_CHANGE_PASSWORD' => 1, 'COMPANY_NAME' => 'SIA-MINI-ERP', 'COMPANY_ID' => 1]],
            [['ROLE_CODE' => 'trainee']],
        );
        $session = ['actor_id' => 'a', 'security_version' => 1, 'company_id' => 1];
        $response = (new Kernel(Config::fromArray([]), $session, $db))->handle('GET', '/settings/users', []);
        self::assertSame(403, $response['status']);
        self::assertStringContainsString('PASSWORD_CHANGE_REQUIRED', $response['body']);
    }

    public function testSettingsUiAndApiShareAdminGuard(): void
    {
        foreach (['/settings/users', '/api/settings/users'] as $path) {
            $db = $this->createMock(Connection::class);
            $db->method('query')->willReturnOnConsecutiveCalls(
                [['ID' => 'u', 'USERNAME' => 'user', 'IS_ADMIN' => 0, 'SECURITY_VERSION' => 1, 'MUST_CHANGE_PASSWORD' => 0, 'COMPANY_NAME' => 'SIA-MINI-ERP', 'COMPANY_ID' => 1]],
                [['ROLE_CODE' => 'trainee']],
            );
            $session = ['actor_id' => 'u', 'security_version' => 1, 'company_id' => 1];
            self::assertSame(403, (new Kernel(Config::fromArray([]), $session, $db))->handle('GET', $path, [])['status']);
        }
    }

    public function testAdminUserListAndCreateScreenRender(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(function (string $sql): array {
            if (str_contains($sql, 'JOIN ME_COMPANY')) {
                return [['ID' => 'a', 'USERNAME' => 'admin', 'IS_ADMIN' => 1, 'SECURITY_VERSION' => 1, 'MUST_CHANGE_PASSWORD' => 0, 'COMPANY_NAME' => 'SIA-MINI-ERP', 'COMPANY_ID' => 1]];
            }
            if (str_contains($sql, 'ME_USER_ROLES')) {
                return [['ROLE_CODE' => 'trainee']];
            }
            return [['ID' => 'u', 'USERNAME' => 'newuser', 'DISPLAY_NAME' => '<script>', 'IS_ADMIN' => 0, 'ACTIVE' => 1, 'VERSION' => 1]];
        });
        $session = ['actor_id' => 'a', 'security_version' => 1, 'company_id' => 1];
        $kernel = new Kernel(Config::fromArray([]), $session, $db);
        $list = $kernel->handle('GET', '/settings/users', []);
        self::assertSame(200, $list['status']);
        self::assertStringContainsString('newuser', $list['body']);
        self::assertStringContainsString('&lt;script&gt;', $list['body']);
        self::assertStringContainsString('name="is_admin"', $kernel->handle('GET', '/settings/users/new', [])['body']);
    }

    public function testRegistryAutomaticallyPopulatesPermissionForm(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(function (string $sql): array {
            if (str_contains($sql, 'JOIN ME_COMPANY')) {
                return [['ID' => 'a', 'USERNAME' => 'admin', 'IS_ADMIN' => 1, 'SECURITY_VERSION' => 1, 'MUST_CHANGE_PASSWORD' => 0, 'COMPANY_NAME' => 'SIA-MINI-ERP', 'COMPANY_ID' => 1]];
            }
            if (str_contains($sql, 'ME_USER_ROLES')) {
                return [['ROLE_CODE' => 'trainee']];
            }
            if (str_contains($sql, 'FROM ME_OBJECTS')) {
                return [['CODE' => 'newmodule.items', 'NAME' => 'New items', 'MODULE_CODE' => 'newmodule',
                    'ALLOW_CREATE' => 1, 'ALLOW_READ' => 1, 'ALLOW_UPDATE' => 0, 'ALLOW_DELETE' => 0]];
            }
            if (str_contains($sql, 'ME_OBJECT_ACTIONS')) {
                return [['OBJECT_CODE' => 'newmodule.items', 'ACTION_CODE' => 'post', 'NAME' => 'Post']];
            }
            if (str_contains($sql, 'ME_USER_PERMISSIONS')) {
                return [['OBJECT_CODE' => 'newmodule.items', 'OPERATION' => 'read']];
            }
            return [['ID' => 'u', 'USERNAME' => 'user', 'DISPLAY_NAME' => 'User', 'IS_ADMIN' => 0, 'ACTIVE' => 1, 'VERSION' => 1]];
        });
        $session = ['actor_id' => 'a', 'security_version' => 1, 'company_id' => 1];
        $response = (new Kernel(Config::fromArray([]), $session, $db))->handle('GET', '/settings/users/u', []);
        self::assertSame(200, $response['status']);
        self::assertStringContainsString('value="newmodule.items:post"', $response['body']);
        self::assertStringNotContainsString('value="newmodule.items:delete"', $response['body']);
    }

    public function testRevokedSessionIsClearedBeforeSettings(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturn([[
            'ID' => 'u', 'USERNAME' => 'admin', 'IS_ADMIN' => 1, 'SECURITY_VERSION' => 2,
            'MUST_CHANGE_PASSWORD' => 0, 'COMPANY_NAME' => 'SIA-MINI-ERP',
        ]]);
        $session = ['actor_id' => 'u', 'security_version' => 1, 'company_id' => 1];
        self::assertSame(403, (new Kernel(Config::fromArray([]), $session, $db))->handle('GET', '/settings/users', [])['status']);
        self::assertSame([], $session);
    }

    public function testAdminApiRejectsClientStringAsBoolean(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnOnConsecutiveCalls(
            [['ID' => 'a', 'USERNAME' => 'admin', 'IS_ADMIN' => 1, 'SECURITY_VERSION' => 1, 'MUST_CHANGE_PASSWORD' => 0, 'COMPANY_NAME' => 'SIA-MINI-ERP', 'COMPANY_ID' => 1]],
            [['ROLE_CODE' => 'trainee']],
        );
        $db->expects(self::never())->method('execute');
        $session = ['actor_id' => 'a', 'security_version' => 1, 'company_id' => 1, 'csrf_token' => str_repeat('a', 64)];
        $response = (new Kernel(Config::fromArray([]), $session, $db))->handle('POST', '/api/settings/users', [
            'operation' => 'create', 'username' => 'graduate', 'display_name' => 'Graduate',
            'password' => 'fixture-password', 'is_admin' => 'false',
        ], ['x-csrf-token' => $session['csrf_token']]);
        self::assertSame(400, $response['status']);
        self::assertStringContainsString('is_admin must be a boolean', $response['body']);
    }
}
