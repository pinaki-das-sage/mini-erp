<?php

declare(strict_types=1);

namespace MiniErp\shared;

use MiniErp\logistics\LogisticsUiApi;
use MiniErp\shared\database\Connection;
use MiniErp\shared\database\DatabaseError;
use MiniErp\shared\database\MigrationRunner;
use MiniErp\shared\database\OciConnection;
use MiniErp\shared\security\Actor;
use MiniErp\shared\security\AuthService;
use MiniErp\shared\security\Security;
use MiniErp\shared\security\UserService;
use MiniErp\shared\view\Renderer;

final class Kernel
{
    private array $session;
    private readonly WebPath $paths;

    public function __construct(
        private readonly Config $config,
        array &$session,
        private ?Connection $connection = null,
        string $scriptName = '/index.php',
        private readonly string $clientAddress = 'unknown',
    ) {
        $this->session = &$session;
        $this->paths = $config->webPath($scriptName);
    }

    /** @return array{status: int, headers: array<string, string>, body: string} */
    public function handle(string $method, string $path, array $input, array $headers = [], array $query = []): array
    {
        try {
            $path = $this->paths->routePath($path);
            if ($path === '/api/v1' || str_starts_with($path, '/api/v1/')) {
                if ($path !== '/api/v1/auth/login') {
                    Api::bearer($headers);
                }
                $registerModules = $path !== '/api/v1/auth/login';
                return (new Api($this->db(), $this->config, $this->clientAddress, $registerModules))
                    ->handle($method, $path, $input, $headers, $query);
            }
            // Keep the browser session bridge separate from bearer-only /api/v1.
            if (str_starts_with($path, '/ui-api/logistics')) {
                $actor = $this->actor();
                if ($method !== 'GET') {
                    Security::verifyCsrf($this->session, $headers['x-csrf-token'] ?? null);
                    if (strtolower(trim(explode(';', $headers['content-type'] ?? '')[0])) !== 'application/json') {
                        throw new AppError('UNSUPPORTED_MEDIA_TYPE', 'Logistics UI writes require Content-Type: application/json.', 415);
                    }
                }
                $operation = substr($path, strlen('/ui-api/logistics'));
                $response = (new LogisticsUiApi($this->db()))->dispatch($actor, $method, $operation, $input, $query);
                return $this->json($response['status'], [
                    'success' => true, 'operation_id' => null, 'current_version' => null,
                    'result' => $response['result'], 'error' => null,
                ]);
            }
            if (str_starts_with($path, '/api/') && isset($headers['authorization'])) {
                throw new AppError('UNAUTHENTICATED', 'Bearer clients must use /api/v1 endpoints.', 401);
            }
            if ($method === 'GET' && $path === '/health') {
                return $this->json(200, ['success' => true, 'result' => ['status' => 'alive', 'database' => 'not_checked']]);
            }
            if ($method === 'GET' && $path === '/ready') {
                $this->db()->query('SELECT 1 FROM DUAL');
                $rows = $this->db()->query("SELECT TABLE_NAME FROM USER_TABLES WHERE TABLE_NAME IN ('ME_MIGRATIONS', 'ME_USERS', 'ME_ROLES', 'ME_USER_ROLES', 'ME_NOTES', 'ME_ACTIONS', 'ME_AUDIT', 'ME_COMPANY', 'ME_OBJECTS', 'ME_OBJECT_ACTIONS', 'ME_USER_PERMISSIONS', 'ME_SECURITY_AUDIT', 'ME_API_TOKENS', 'ME_API_LOGIN_LIMITS')");
                if (count($rows) !== 14 || $this->db()->query("SELECT VERSION FROM ME_MIGRATIONS WHERE STATUS <> 'APPLIED'") !== []) {
                    throw new AppError('NOT_READY', 'Apply and verify the foundation migrations first.', 503);
                }
                $applied = array_column($this->db()->query(
                    "SELECT VERSION, CHECKSUM, STATUS FROM ME_MIGRATIONS WHERE COMPONENT = 'shared'",
                ), null, 'VERSION');
                $runner = new MigrationRunner($this->db());
                $directories = $runner->directories(dirname(__DIR__, 2) . '/database/migrations');
                if (!isset($directories['shared'])) {
                    throw new AppError('NOT_READY', 'Shared migration directory is missing.', 503);
                }
                $plan = $runner->plan($directories['shared']);
                foreach ($plan as $migration) {
                    $current = $applied[$migration['version']] ?? null;
                    if ($current === null || $current['CHECKSUM'] !== $migration['checksum'] || $current['STATUS'] !== 'APPLIED') {
                        throw new AppError('NOT_READY', 'Foundation migration checksums do not match. Follow the recovery instructions.', 503);
                    }
                }
                if ($this->db()->query("SELECT ID FROM ME_NOTES WHERE ID = 'welcome' AND COMPANY_ID = 1") === []) {
                    throw new AppError('NOT_READY', 'Seed the foundation first.', 503);
                }
                if ($this->db()->query(
                    'SELECT USER_ID FROM ME_USERS WHERE IS_ADMIN = 1 AND ACTIVE = 1 AND COMPANY_ID = 1',
                ) === []) {
                    throw new AppError('NOT_READY', 'Bootstrap the company administrator first.', 503);
                }
                return $this->json(200, ['success' => true, 'result' => ['foundation' => 'ready', 'erp_modules' => 'not_implemented']]);
            }
            if ($method === 'GET' && $path === '/') {
                if (isset($this->session['actor_id'])) {
                    $actor = $this->actor();
                    return $this->page('shared/home.tpl', $actor->companyName, ['actor' => $actor]);
                }
                return $this->page('shared/security/login.tpl', 'Sign in to Mini ERP');
            }
            if ($method === 'GET' && preg_match('#^/modules/(inventory|purchasing|sales|logistics)$#', $path, $match)) {
                return $this->page($match[1] . '/index.tpl', ucfirst($match[1]), ['module' => $match[1], 'actor' => $this->actor()]);
            }
            if ($method === 'GET' && $path === '/login') {
                return $this->page('shared/security/login.tpl', 'Sign in to Mini ERP');
            }
            if ($method === 'POST' && $path === '/login') {
                Security::verifyCsrf($this->session, $input['csrf_token'] ?? null);
                $actor = (new AuthService($this->db()))->authenticate(
                    $this->stringInput($input, 'username'),
                    $this->stringInput($input, 'password'),
                    $this->stringInput($input, 'company'),
                );
                $this->session = ['actor_id' => $actor->id, 'security_version' => $actor->securityVersion, 'company_id' => $actor->companyId];
                Security::csrfToken($this->session);
                return ['status' => 303, 'headers' => ['Location' => $this->paths->url($actor->mustChangePassword ? '/account/password' : '/')], 'body' => ''];
            }
            if ($method === 'POST' && $path === '/logout') {
                Security::verifyCsrf($this->session, $input['csrf_token'] ?? null);
                $this->session = [];
                return ['status' => 303, 'headers' => ['Location' => $this->paths->url('/')], 'body' => ''];
            }
            if ($method === 'GET' && $path === '/example') {
                $actor = $this->actor();
                return $this->page('shared/example/note.tpl', 'Neutral versioned note', [
                    'actor' => $actor,
                    'note' => (new NeutralNote($this->db()))->read($actor),
                ]);
            }
            if ($path === '/account/password') {
                $actor = $this->actor(true);
                if ($method === 'GET') {
                    return $this->page('shared/security/password.tpl', 'Change password', ['actor' => $actor]);
                }
                if ($method === 'POST') {
                    Security::verifyCsrf($this->session, $input['csrf_token'] ?? null);
                    (new UserService($this->db()))->changePassword($actor, $this->stringInput($input, 'current_password'), $this->stringInput($input, 'new_password'));
                    $this->session = [];
                    return ['status' => 303, 'headers' => ['Location' => $this->paths->url('/login?password_changed=1')], 'body' => ''];
                }
            }
            if (str_starts_with($path, '/settings/') || str_starts_with($path, '/api/settings/')) {
                return $this->settings($method, $path, $input, $headers);
            }
            if ($method === 'POST' && $path === '/api/note') {
                Security::verifyCsrf($this->session, $headers['x-csrf-token'] ?? null);
                $actor = $this->actor();
                if (!isset($input['expected_version']) || !is_int($input['expected_version'])) {
                    throw new AppError('VALIDATION_ERROR', 'expected_version must be an integer.');
                }
                $reference = $this->stringInput($input, 'action_reference');
                $result = (new NeutralNote($this->db()))->update(
                    $actor,
                    $reference,
                    $this->stringInput($input, 'text'),
                    $input['expected_version'],
                );
                return $this->json(200, [
                    'success' => true,
                    'operation_id' => $reference,
                    'current_version' => $result['current_version'],
                    'result' => $result,
                    'error' => null,
                ]);
            }
            throw new AppError('NOT_FOUND', 'Page or operation not found.', 404);
        } catch (AppError $error) {
            if ($path === '/login' && $method === 'POST') {
                return $this->page('shared/security/login.tpl', 'Sign in to SIA-MINI-ERP', ['error' => $error->getMessage()], $error->status);
            }
            if ($path === '/account/password' && $method === 'POST' && isset($actor)) {
                return $this->page('shared/security/password.tpl', 'Change password', ['actor' => $actor, 'error' => $error->getMessage()], $error->status);
            }
            $response = $this->json($error->status, [
                'success' => false,
                'operation_id' => null,
                'current_version' => null,
                'result' => null,
                'error' => ['code' => $error->errorCode, 'message' => $error->getMessage()],
            ]);
            if ($error->status === 401) {
                $response['headers']['WWW-Authenticate'] = 'Bearer';
            }
            return $response;
        } catch (DatabaseError $error) {
            $reference = bin2hex(random_bytes(8));
            error_log('mini-erp database_error request=' . $reference . ' oracle_code=' . $error->oracleCode);
            return $this->json(503, [
                'success' => false,
                'operation_id' => null,
                'current_version' => null,
                'result' => null,
                'error' => ['code' => 'DATABASE_ERROR', 'message' => 'Oracle error ORA-' . str_pad((string) $error->oracleCode, 5, '0', STR_PAD_LEFT), 'request_id' => $reference],
            ]);
        }
    }

    private function db(): Connection
    {
        return $this->connection ??= OciConnection::connect($this->config);
    }

    private function actor(bool $allowPasswordChange = false): Actor
    {
        if (!isset($this->session['actor_id']) || !is_string($this->session['actor_id'])) {
            throw new AppError('FORBIDDEN', 'Sign in before performing this operation.', 403);
        }
        if (
            !isset($this->session['security_version'], $this->session['company_id'])
            || !is_int($this->session['security_version']) || !is_int($this->session['company_id']) || $this->session['company_id'] < 1
        ) {
            $this->session = [];
            throw new AppError('SESSION_EXPIRED', 'Sign in again.', 403);
        }
        try {
            $actor = (new AuthService($this->db()))->actor($this->session['actor_id'], $this->session['company_id'], $this->session['security_version']);
        } catch (AppError $error) {
            $this->session = [];
            throw $error;
        }
        if (!$allowPasswordChange && $actor->mustChangePassword) {
            throw new AppError('PASSWORD_CHANGE_REQUIRED', 'Change your temporary password at ' . $this->paths->url('/account/password') . ' first.', 403);
        }
        return $actor;
    }

    private function settings(string $method, string $path, array $input, array $headers): array
    {
        $actor = $this->actor();
        $actor->requireAdmin();
        $users = new UserService($this->db());
        if ($method === 'GET' && $path === '/settings/users') {
            return $this->page('shared/settings/users/list.tpl', 'User management', ['actor' => $actor, 'users' => $users->listUsers($actor)]);
        }
        if ($method === 'GET' && $path === '/api/settings/users') {
            return $this->json(200, ['success' => true, 'result' => $users->listUsers($actor), 'error' => null]);
        }
        if ($method === 'GET' && $path === '/settings/users/new') {
            return $this->page('shared/settings/users/create.tpl', 'Create user', ['actor' => $actor]);
        }
        if ($method === 'GET' && preg_match('#^/settings/users/([a-zA-Z0-9_-]+)$#', $path, $match)) {
            return $this->page('shared/settings/users/edit.tpl', 'Edit user and permissions', ['actor' => $actor, ...$users->detail($actor, $match[1])]);
        }
        if ($method === 'GET' && $path === '/settings/company') {
            $companies = $this->db()->query('SELECT CODE, NAME FROM ME_COMPANY WHERE ID = :company_id', ['company_id' => $actor->companyId]);
            if ($companies === []) {
                throw new AppError('NOT_FOUND', 'Company not found.', 404);
            }
            return $this->page('shared/settings/company.tpl', 'Company', ['actor' => $actor, 'company' => $companies[0]]);
        }
        if ($method === 'POST' && $path === '/api/settings/users') {
            Security::verifyCsrf($this->session, $headers['x-csrf-token'] ?? null);
            $operation = $this->stringInput($input, 'operation');
            if ($operation === 'create') {
                $id = $users->create(
                    $actor,
                    $this->stringInput($input, 'username'),
                    $this->stringInput($input, 'display_name'),
                    $this->stringInput($input, 'password'),
                    $this->boolInput($input, 'is_admin')
                );
                return $this->json(201, ['success' => true, 'result' => ['id' => $id], 'error' => null]);
            }
            $id = $this->stringInput($input, 'id');
            if (!isset($input['expected_version']) || !is_int($input['expected_version'])) {
                throw new AppError('VALIDATION_ERROR', 'expected_version must be an integer.');
            }
            if ($operation === 'update') {
                $users->update(
                    $actor,
                    $id,
                    $this->stringInput($input, 'display_name'),
                    $this->boolInput($input, 'is_admin'),
                    $this->boolInput($input, 'active'),
                    $input['expected_version']
                );
            } elseif ($operation === 'permissions') {
                if (!isset($input['grants']) || !is_array($input['grants']) || !array_is_list($input['grants'])) {
                    throw new AppError('VALIDATION_ERROR', 'grants must be an array of object:operation strings.');
                }
                $users->savePermissions($actor, $id, $input['grants'], $input['expected_version']);
            } elseif ($operation === 'reset_password') {
                $users->resetPassword($actor, $id, $this->stringInput($input, 'password'), $input['expected_version']);
            } else {
                throw new AppError('VALIDATION_ERROR', 'Unknown user operation.');
            }
            return $this->json(200, ['success' => true, 'result' => ['id' => $id], 'error' => null]);
        }
        throw new AppError('NOT_FOUND', 'Settings operation not found.', 404);
    }

    private function boolInput(array $input, string $key): bool
    {
        if (!isset($input[$key]) || !is_bool($input[$key])) {
            throw new AppError('VALIDATION_ERROR', $key . ' must be a boolean.');
        }
        return $input[$key];
    }
    private function stringInput(array $input, string $key): string
    {
        if (!isset($input[$key]) || !is_string($input[$key]) || $input[$key] === '') {
            throw new AppError('VALIDATION_ERROR', $key . ' must be a nonempty string.');
        }
        return $input[$key];
    }

    private function page(string $view, string $title, array $data = [], int $status = 200): array
    {
        $body = (new Renderer($this->paths))->render($view, $title, Security::csrfToken($this->session), $data);
        return ['status' => $status, 'headers' => ['Content-Type' => 'text/html; charset=UTF-8'], 'body' => $body];
    }

    private function json(int $status, array $payload): array
    {
        return [
            'status' => $status,
            'headers' => ['Content-Type' => 'application/json; charset=UTF-8'],
            'body' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)
        ];
    }
}
