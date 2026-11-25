<?php
declare(strict_types=1);

namespace MiniErp\Tests\unit;

use MiniErp\shared\Api;
use MiniErp\shared\AppError;
use MiniErp\shared\Config;
use MiniErp\shared\Kernel;
use MiniErp\shared\database\Connection;
use MiniErp\shared\security\Actor;
use MiniErp\shared\security\Authorization;
use MiniErp\shared\security\TokenService;
use PHPUnit\Framework\TestCase;

final class ApiFrameworkTest extends TestCase
{
    private array $tokens = [];
    private array $limits = [];
    private int $securityVersion = 1;
    private bool $active = true;
    private bool $temporary = false;
    private bool $admin = false;
    private array $grants = [['OBJECT_CODE' => 'shared.note', 'OPERATION' => 'read']];
    private int $now = 1000;

    private function connection(): Connection
    {
        $db = $this->createMock(Connection::class);
        $passwordHash = password_hash('fixture-password', PASSWORD_DEFAULT);
        $db->method('query')->willReturnCallback(function (string $sql, array $parameters = []) use ($passwordHash): array {
            if (str_contains($sql, 'ME_API_LOGIN_LIMITS')) {
                return isset($this->limits[$parameters['key']]) ? [$this->limits[$parameters['key']]] : [];
            }
            if (str_contains($sql, 'ME_API_TOKENS')) {
                return isset($this->tokens[$parameters['hash']]) ? [$this->tokens[$parameters['hash']]] : [];
            }
            if (str_contains($sql, 'JOIN ME_COMPANY')) {
                if (!$this->active || (isset($parameters['company']) && $parameters['company'] !== 'LAB')) {
                    return [];
                }
                return [['ID' => 'u', 'USERNAME' => 'user', 'COMPANY_ID' => 7, 'COMPANY_NAME' => 'Lab',
                    'PASSWORD_HASH' => $passwordHash, 'IS_ADMIN' => $this->admin ? 1 : 0, 'SECURITY_VERSION' => $this->securityVersion,
                    'MUST_CHANGE_PASSWORD' => $this->temporary ? 1 : 0]];
            }
            if (str_contains($sql, 'ME_USER_ROLES')) {
                return [['ROLE_CODE' => 'trainee']];
            }
            if (str_contains($sql, 'ME_OBJECT_ACTIONS')) {
                return [['OBJECT_CODE' => 'shared.note', 'ACTION_CODE' => 'publish']];
            }
            if (str_contains($sql, 'ME_OBJECTS')) {
                if (($parameters['object'] ?? '') === 'shared.user') {
                    return [['CODE' => 'shared.user', 'ACTIVE' => 1, 'ALLOW_CREATE' => 1, 'ALLOW_READ' => 1,
                        'ALLOW_UPDATE' => 1, 'ALLOW_DELETE' => 0]];
                }
                return [['CODE' => 'shared.note', 'NAME' => 'Note', 'ACTIVE' => 1,
                    'ALLOW_CREATE' => 0, 'ALLOW_READ' => 1, 'ALLOW_UPDATE' => 1, 'ALLOW_DELETE' => 0]];
            }
            if (str_contains($sql, 'ME_USER_PERMISSIONS')) {
                return array_values(array_filter($this->grants, static fn (array $grant): bool =>
                    !isset($parameters['operation']) || $grant['OPERATION'] === $parameters['operation']));
            }
            if (str_contains($sql, 'ME_NOTES')) {
                self::assertSame(7, $parameters['company_id']);
                return [['ID' => 'welcome', 'BODY' => 'API note', 'VERSION' => 2]];
            }
            if (str_contains($sql, 'FROM ME_USERS')) {
                self::assertSame(7, $parameters['company_id']);
                self::assertSame(10, $parameters['offset']);
                self::assertSame(10, $parameters['limit']);
                return [['ID' => 'u', 'USERNAME' => 'user', 'DISPLAY_NAME' => 'User', 'IS_ADMIN' => 0, 'ACTIVE' => 1, 'VERSION' => 2]];
            }
            throw new \LogicException('Unexpected test query: ' . $sql);
        });
        $db->method('execute')->willReturnCallback(function (string $sql, array $parameters): int {
            if (str_starts_with($sql, 'MERGE INTO ME_API_LOGIN_LIMITS')) {
                $previous = $this->limits[$parameters['key']] ?? ['WINDOW_ID' => 0, 'ATTEMPTS' => 0];
                $this->limits[$parameters['key']] = [
                    'WINDOW_ID' => $parameters['window'],
                    'ATTEMPTS' => $previous['WINDOW_ID'] === $parameters['window'] ? $previous['ATTEMPTS'] + 1 : 1,
                ];
                return 1;
            }
            if (str_starts_with($sql, 'INSERT INTO ME_API_TOKENS')) {
                $this->tokens[$parameters['hash']] = [
                    'USER_ID' => $parameters['user_id'], 'COMPANY_ID' => $parameters['company_id'],
                    'SECURITY_VERSION' => $parameters['version'], 'EXPIRES_AT' => strtotime($parameters['expires']),
                    'REVOKED_AT' => null,
                ];
                return 1;
            }
            if (str_starts_with($sql, 'UPDATE ME_API_TOKENS')) {
                if (!isset($this->tokens[$parameters['hash']])) {
                    return 0;
                }
                $this->tokens[$parameters['hash']]['REVOKED_AT'] = $parameters['now'];
                return 1;
            }
            throw new \LogicException('Unexpected test write: ' . $sql);
        });
        return $db;
    }

    private function service(Connection $db): TokenService
    {
        return new TokenService($db, 3600, fn (): int => $this->now);
    }

    public function testLoginStoresOnlyHashAndUsesServerIdentityAndExactExpiry(): void
    {
        $service = $this->service($this->connection());
        $result = $service->login('user', 'fixture-password', 'LAB', '127.0.0.1');
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $result['access_token']);
        self::assertSame(3600, $result['expires_in']);
        self::assertSame('1970-01-01T01:16:40Z', $result['expires_at']);
        self::assertSame('Bearer', $result['token_type']);
        self::assertArrayNotHasKey($result['access_token'], $this->tokens);
        self::assertSame(7, $this->tokens[hash('sha256', $result['access_token'])]['COMPANY_ID']);
        self::assertSame('u', $service->authenticate($result['access_token'])->id);
        $this->now = 4600;
        $this->expectException(AppError::class);
        $this->expectExceptionMessage('Invalid or expired API token.');
        $service->authenticate($result['access_token']);
    }

    public function testRevocationRejectsPreviouslyValidToken(): void
    {
        $service = $this->service($this->connection());
        $token = $service->login('user', 'fixture-password', 'LAB', '127.0.0.1')['access_token'];
        $service->revoke($token);
        $this->expectException(AppError::class);
        $service->authenticate($token);
    }

    public function testSecurityChangesInvalidateTokenAndDisabledUserCannotAuthenticate(): void
    {
        $service = $this->service($this->connection());
        $token = $service->login('user', 'fixture-password', 'LAB', '127.0.0.1')['access_token'];
        $this->securityVersion++;
        try {
            $service->authenticate($token);
            self::fail('A changed security version must invalidate tokens.');
        } catch (AppError $error) {
            self::assertSame(401, $error->status);
        }
        $this->active = false;
        $this->expectException(AppError::class);
        $service->authenticate($token);
    }

    public function testFailedLoginsAreThrottledAndWindowResets(): void
    {
        $service = $this->service($this->connection());
        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                $service->login('user', 'wrong-password', 'LAB', '127.0.0.1');
                self::fail('Invalid credentials must fail.');
            } catch (AppError $error) {
                self::assertSame(401, $error->status);
            }
        }
        try {
            $service->login('user', 'fixture-password', 'LAB', '127.0.0.1');
            self::fail('Attempt six must be throttled, even with a valid password.');
        } catch (AppError $error) {
            self::assertSame(429, $error->status);
        }
        $this->now = 1060;
        self::assertSame('Bearer', $service->login('user', 'fixture-password', 'LAB', '127.0.0.1')['token_type']);
    }

    public function testApiIgnoresBrowserCookieAndRequiresBearerForProtectedRoutes(): void
    {
        $session = ['actor_id' => 'u', 'company_id' => 7, 'security_version' => 1];
        $kernel = new Kernel(Config::fromArray([]), $session, $this->connection());
        self::assertSame(401, $kernel->handle('GET', '/api/v1/note', [])['status']);
        self::assertSame(401, $kernel->handle('GET', '/api/v1/note', [], ['authorization' => 'Bearer bad'])['status']);
    }

    public function testApiLoginAndReadUseCommonEnvelopeAndCurrentPermissions(): void
    {
        $db = $this->connection();
        $session = [];
        $kernel = new Kernel(Config::fromArray([]), $session, $db);
        $login = $kernel->handle('POST', '/api/v1/auth/login', [
            'company' => 'LAB', 'username' => 'user', 'password' => 'fixture-password',
        ], ['content-type' => 'application/json']);
        self::assertSame(200, $login['status']);
        $body = json_decode($login['body'], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['success', 'operation_id', 'current_version', 'result', 'error'], array_keys($body));
        self::assertSame([], $session);
        $headers = ['authorization' => 'Bearer ' . $body['result']['access_token']];
        $read = $kernel->handle('GET', '/api/v1/note', [], $headers);
        self::assertSame(200, $read['status']);
        self::assertSame('API note', json_decode($read['body'], true)['result']['text']);
        $this->grants = [];
        self::assertSame(403, $kernel->handle('GET', '/api/v1/note', [], $headers)['status']);
    }

    public function testTemporaryPasswordAllowsIdentityButNotModuleAccess(): void
    {
        $this->temporary = true;
        $db = $this->connection();
        $api = new Api($db, Config::fromArray([]));
        $token = (new TokenService($db))->login('user', 'fixture-password', 'LAB', '127.0.0.1')['access_token'];
        $headers = ['authorization' => 'Bearer ' . $token];
        $me = $api->handle('GET', '/api/v1/auth/me', [], $headers);
        self::assertTrue(json_decode($me['body'], true)['result']['must_change_password']);
        self::assertSame(403, $api->handle('GET', '/api/v1/note', [], $headers)['status']);
    }

    public function testRoutesRequirePermissionMetadataAndSupportPathIdentifiers(): void
    {
        $db = $this->connection();
        $api = new Api($db, Config::fromArray([]));
        $api->register('GET', '/notes/{id}', 'shared.note', 'read',
            static fn (Actor $actor, array $input, array $params, array $query): array =>
                ['id' => $params['id'], 'company_id' => $actor->companyId]);
        $token = (new TokenService($db))->login('user', 'fixture-password', 'LAB', '127.0.0.1')['access_token'];
        $response = $api->handle('GET', '/api/v1/notes/abc-123', [], ['authorization' => 'Bearer ' . $token]);
        self::assertSame(['id' => 'abc-123', 'company_id' => 7], json_decode($response['body'], true)['result']);
        $this->expectException(\InvalidArgumentException::class);
        $api->register('GET', '/unsafe', '', '', static fn (): array => []);
    }

    public function testPaginationRejectsUnknownFieldsAndInvalidNumbers(): void
    {
        self::assertSame(['page' => 2, 'page_size' => 10, 'offset' => 10, 'filters' => ['sku' => 'A']],
            Api::pagination(['page' => '2', 'page_size' => '10', 'sku' => 'A'], ['sku']));
        foreach ([['page_size' => '101'], ['page' => '0'], ['page' => []], ['sql' => 'anything']] as $query) {
            try {
                Api::pagination($query, ['sku']);
                self::fail('Invalid pagination/filter must fail.');
            } catch (AppError $error) {
                self::assertSame('VALIDATION_ERROR', $error->errorCode);
            }
        }
    }

    public function testPermissionsListIncludesOnlySupportedCurrentGrants(): void
    {
        $this->grants[] = ['OBJECT_CODE' => 'shared.note', 'OPERATION' => 'delete'];
        self::assertSame(['shared.note:read'], (new Authorization($this->connection()))->permissions(
            new Actor('u', 'user', ['trainee'], 7),
        ));
    }

    public function testTokenTtlConfigurationRejectsUnsafeValues(): void
    {
        self::assertSame(3600, Config::fromArray([])->apiTokenTtl());
        self::assertSame(7200, Config::fromArray(['API_TOKEN_TTL_SECONDS' => '7200'])->apiTokenTtl());
        $this->expectException(AppError::class);
        Config::fromArray(['API_TOKEN_TTL_SECONDS' => 'forever'])->apiTokenTtl();
    }

    public function testApiRequiresJsonAndReturnsAuthenticationChallenges(): void
    {
        $api = new Api($this->connection(), Config::fromArray([]));
        self::assertSame(415, $api->handle('POST', '/api/v1/auth/login', [])['status']);
        $missing = $api->handle('GET', '/api/v1/auth/me', []);
        self::assertSame(401, $missing['status']);
        self::assertSame('Bearer', $missing['headers']['WWW-Authenticate']);
        $invalid = $api->handle('POST', '/api/v1/auth/login', ['company' => []], ['content-type' => 'application/json']);
        self::assertSame(400, $invalid['status']);
    }

    public function testApiLogoutRevokesTokenWithoutTouchingBrowserSession(): void
    {
        $db = $this->connection();
        $session = ['actor_id' => 'browser-user'];
        $kernel = new Kernel(Config::fromArray([]), $session, $db);
        $token = (new TokenService($db))->login('user', 'fixture-password', 'LAB', '127.0.0.1')['access_token'];
        $headers = ['authorization' => 'Bearer ' . $token, 'content-type' => 'application/json'];
        self::assertSame(200, $kernel->handle('POST', '/api/v1/auth/logout', [], $headers)['status']);
        self::assertSame(401, $kernel->handle('GET', '/api/v1/auth/me', [], $headers)['status']);
        self::assertSame(['actor_id' => 'browser-user'], $session);
    }

    public function testInputHelpersRejectWrongTypesAndNonPositiveVersions(): void
    {
        self::assertSame('hello', Api::string(['text' => 'hello'], 'text'));
        self::assertSame(2, Api::integer(['expected_version' => 2], 'expected_version'));
        foreach ([['text' => []], ['text' => '']] as $input) {
            try {
                Api::string($input, 'text');
                self::fail('Invalid string input must fail.');
            } catch (AppError $error) {
                self::assertSame(400, $error->status);
            }
        }
        $this->expectException(AppError::class);
        Api::integer(['expected_version' => '2'], 'expected_version');
    }

    public function testVersionedUserListIsPagedAndStillAdminOnly(): void
    {
        $db = $this->connection();
        $api = new Api($db, Config::fromArray([]));
        $this->admin = true;
        $token = (new TokenService($db))->login('user', 'fixture-password', 'LAB', '127.0.0.1')['access_token'];
        $headers = ['authorization' => 'Bearer ' . $token];
        $response = $api->handle('GET', '/api/v1/users', [], $headers, ['page' => '2', 'page_size' => '10']);
        self::assertSame(200, $response['status']);
        self::assertSame(['items' => [['id' => 'u', 'username' => 'user', 'display_name' => 'User',
            'is_admin' => false, 'active' => true, 'current_version' => 2]], 'page' => 2, 'page_size' => 10],
            json_decode($response['body'], true)['result']);
        $this->admin = false;
        $this->grants = [['OBJECT_CODE' => 'shared.user', 'OPERATION' => 'read']];
        self::assertSame(403, $api->handle('GET', '/api/v1/users', [], $headers)['status']);
    }

    public function testBooleanInputRequiresActualJsonBoolean(): void
    {
        self::assertFalse(Api::boolean(['active' => false], 'active'));
        $this->expectException(AppError::class);
        Api::boolean(['active' => 'false'], 'active');
    }

    public function testUserWriteRoutesRejectMalformedInput(): void
    {
        $this->admin = true;
        $db = $this->connection();
        $api = new Api($db, Config::fromArray([]));
        $token = (new TokenService($db))->login('user', 'fixture-password', 'LAB', '127.0.0.1')['access_token'];
        $headers = ['authorization' => 'Bearer ' . $token, 'content-type' => 'application/json'];
        foreach ([
            ['POST', '/api/v1/users', []],
            ['POST', '/api/v1/users/u/update', []],
            ['POST', '/api/v1/users/u/permissions', []],
            ['POST', '/api/v1/users/u/reset-password', []],
        ] as [$method, $path, $input]) {
            self::assertSame(400, $api->handle($method, $path, $input, $headers)['status'], $path);
        }
    }

    public function testBearerParsingRejectsMalformedHeadersBeforeOracleIsNeeded(): void
    {
        $token = str_repeat('a', 64);
        self::assertSame($token, Api::bearer(['authorization' => 'bearer ' . $token]));
        $session = [];
        self::assertSame(401, (new Kernel(Config::fromArray([]), $session))->handle(
            'GET', '/api/v1/auth/me', [], ['authorization' => 'Bearer bad'],
        )['status']);
    }

    public function testIpLimitAppliesAcrossAccountNames(): void
    {
        $this->limits[hash('sha256', 'ip:127.0.0.1')] = ['WINDOW_ID' => 16, 'ATTEMPTS' => 100];
        $service = $this->service($this->connection());
        try {
            $service->login('another-user', 'fixture-password', 'LAB', '127.0.0.1');
            self::fail('A different username must not bypass the IP throttle.');
        } catch (AppError $error) {
            self::assertSame(429, $error->status);
        }
        self::assertSame([], $this->tokens);
    }

    public function testVersionedHttpRequestsDoNotStartBrowserSessions(): void
    {
        $entry = dirname(__DIR__, 2) . '/public/index.php';
        $code = '$_ENV["APP_BASE_PATH"] = "/"; $_SERVER["SCRIPT_NAME"] = "/index.php"; $_SERVER["REQUEST_URI"] = "/api/v1/auth/me";'
            . '$_SERVER["REQUEST_METHOD"] = "GET"; function getallheaders() { return []; }'
            . 'register_shutdown_function(function () { echo "|session=" . session_status(); }); require ' . var_export($entry, true) . ';';
        $output = [];
        $exit = 0;
        exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code), $output, $exit);
        self::assertSame(0, $exit);
        self::assertStringContainsString('UNAUTHENTICATED', implode("\n", $output));
        self::assertStringEndsWith('|session=' . PHP_SESSION_NONE, implode("\n", $output));
    }

    public function testSecurityRevocationDuringServiceExecutionReturns401(): void
    {
        $db = $this->connection();
        $api = new Api($db, Config::fromArray([]));
        $api->register('GET', '/security-race', 'shared.note', 'read', static function (): never {
            throw new AppError('SESSION_EXPIRED', 'Sign in again.', 403);
        });
        $token = (new TokenService($db))->login('user', 'fixture-password', 'LAB', '127.0.0.1')['access_token'];
        $response = $api->handle('GET', '/api/v1/security-race', [], ['authorization' => 'Bearer ' . $token]);
        self::assertSame(401, $response['status']);
        self::assertSame('Bearer', $response['headers']['WWW-Authenticate']);
    }
}
