<?php
declare(strict_types=1);

namespace MiniErp\shared;

use MiniErp\shared\database\Connection;
use MiniErp\shared\security\Actor;
use MiniErp\shared\security\Authorization;
use MiniErp\shared\security\TokenService;
use MiniErp\shared\security\UserService;

final class Api
{
    /** @var list<array{method: string, pattern: string, path: string, object: string, operation: string, handler: \Closure, status: int}> */
    private array $routes = [];
    private readonly TokenService $tokens;

    public function __construct(private readonly Connection $connection, Config $config, private readonly string $clientAddress = 'unknown')
    {
        $this->tokens = new TokenService($connection, $config->apiTokenTtl());
        $this->register('GET', '/note', 'shared.note', 'read',
            fn (Actor $actor): array => (new NeutralNote($connection))->read($actor));
        $this->register('POST', '/note', 'shared.note', 'update',
            fn (Actor $actor, array $input): array => (new NeutralNote($connection))->update(
                $actor, self::string($input, 'action_reference'), self::string($input, 'text'), self::integer($input, 'expected_version'),
            ));
        $users = new UserService($connection);
        $this->register('GET', '/users', 'shared.user', 'read',
            static function (Actor $actor, array $input, array $params, array $query) use ($users): array {
                $page = self::pagination($query);
                $items = array_map(static fn (array $row): array => [
                    'id' => $row['ID'], 'username' => $row['USERNAME'], 'display_name' => $row['DISPLAY_NAME'],
                    'is_admin' => (int) $row['IS_ADMIN'] === 1, 'active' => (int) $row['ACTIVE'] === 1,
                    'current_version' => (int) $row['VERSION'],
                ], $users->listUsers($actor, $page['offset'], $page['page_size']));
                return ['items' => $items,
                    'page' => $page['page'], 'page_size' => $page['page_size']];
            });
        $this->register('POST', '/users', 'shared.user', 'create',
            static fn (Actor $actor, array $input): array => ['id' => $users->create(
                $actor, self::string($input, 'username'), self::string($input, 'display_name'),
                self::string($input, 'password'), self::boolean($input, 'is_admin'),
            )], 201);
        $this->register('PATCH', '/users/{id}', 'shared.user', 'update',
            static function (Actor $actor, array $input, array $params) use ($users): array {
                $version = self::integer($input, 'expected_version');
                $users->update($actor, $params['id'], self::string($input, 'display_name'),
                    self::boolean($input, 'is_admin'), self::boolean($input, 'active'), $version);
                return ['id' => $params['id'], 'current_version' => $version + 1];
            });
        $this->register('POST', '/users/{id}/permissions', 'shared.user', 'permissions',
            static function (Actor $actor, array $input, array $params) use ($users): array {
                $version = self::integer($input, 'expected_version');
                if (!isset($input['grants']) || !is_array($input['grants']) || !array_is_list($input['grants'])) {
                    throw new AppError('VALIDATION_ERROR', 'grants must be a JSON array of object:operation strings.');
                }
                $users->savePermissions($actor, $params['id'], $input['grants'], $version);
                return ['id' => $params['id'], 'current_version' => $version + 1];
            });
        $this->register('POST', '/users/{id}/reset-password', 'shared.user', 'reset_password',
            static function (Actor $actor, array $input, array $params) use ($users): array {
                $version = self::integer($input, 'expected_version');
                $users->resetPassword($actor, $params['id'], self::string($input, 'password'), $version);
                return ['id' => $params['id'], 'current_version' => $version + 1];
            });
        foreach (['inventory', 'purchasing', 'sales', 'logistics'] as $module) {
            $file = dirname(__DIR__) . '/' . $module . '/api.php';
            if (is_file($file)) {
                $register = require $file;
                if (!$register instanceof \Closure) {
                    throw new \LogicException('Module api.php must return a route-registration closure.');
                }
                $register($this, $connection);
            }
        }
    }

    /** Handler receives Actor, JSON input, path parameters and query parameters; returns its service result. */
    public function register(string $method, string $path, string $object, string $operation, callable $handler, int $status = 200): void
    {
        if (!in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true)
            || !preg_match('#\A/(?:[a-z][a-z0-9-]*|\{[a-z][a-z0-9_]*\})(?:/(?:[a-z][a-z0-9-]*|\{[a-z][a-z0-9_]*\}))*\z#', $path)
            || $path === '/auth' || str_starts_with($path, '/auth/')
            || $object === '' || $operation === '' || !in_array($status, [200, 201], true)) {
            throw new \InvalidArgumentException('Routes require a valid method/path, explicit permission and a 200/201 success status.');
        }
        foreach ($this->routes as $route) {
            if ($route['method'] === $method && $route['path'] === $path) {
                throw new \InvalidArgumentException('Duplicate API route.');
            }
        }
        $segments = [];
        $names = [];
        foreach (explode('/', $path) as $segment) {
            if (str_starts_with($segment, '{')) {
                $name = substr($segment, 1, -1);
                if (isset($names[$name])) {
                    throw new \InvalidArgumentException('Duplicate API path parameter.');
                }
                $names[$name] = true;
                $segments[] = '(?P<' . $name . '>[a-zA-Z0-9_-]{1,100})';
            } else {
                $segments[] = preg_quote($segment, '#');
            }
        }
        $this->routes[] = ['method' => $method, 'pattern' => '#\A' . implode('/', $segments) . '\z#',
            'path' => $path, 'object' => $object, 'operation' => $operation,
            'handler' => \Closure::fromCallable($handler), 'status' => $status];
    }

    /** @return array{status: int, headers: array<string, string>, body: string} */
    public function handle(string $method, string $path, #[\SensitiveParameter] array $input, #[\SensitiveParameter] array $headers = [], array $query = []): array
    {
        try {
            if ($method === 'POST' && $path === '/api/v1/auth/login') {
                if (isset($headers['authorization'])) {
                    throw new AppError('VALIDATION_ERROR', 'Omit Authorization when signing in.');
                }
                if (strtolower(trim(explode(';', $headers['content-type'] ?? '')[0])) !== 'application/json') {
                    throw new AppError('UNSUPPORTED_MEDIA_TYPE', 'API writes require Content-Type: application/json.', 415);
                }
                return $this->response(200, $this->tokens->login(
                    self::string($input, 'username'), self::string($input, 'password'), self::string($input, 'company'), $this->clientAddress,
                ));
            }
            $token = self::bearer($headers);
            $actor = $this->tokens->authenticate($token);
            if ($method !== 'GET' && strtolower(trim(explode(';', $headers['content-type'] ?? '')[0])) !== 'application/json') {
                throw new AppError('UNSUPPORTED_MEDIA_TYPE', 'API writes require Content-Type: application/json.', 415);
            }
            if ($method === 'GET' && $path === '/api/v1/auth/me') {
                return $this->response(200, ['id' => $actor->id, 'username' => $actor->username,
                    'company_id' => $actor->companyId, 'company_name' => $actor->companyName, 'roles' => $actor->roles,
                    'is_admin' => $actor->isAdmin, 'must_change_password' => $actor->mustChangePassword,
                    'permissions' => (new Authorization($this->connection))->permissions($actor)]);
            }
            if ($method === 'POST' && $path === '/api/v1/auth/logout') {
                $this->tokens->revoke($token);
                return $this->response(200, ['revoked' => true]);
            }
            if ($method === 'POST' && $path === '/api/v1/auth/change-password') {
                (new UserService($this->connection))->changePassword(
                    $actor, self::string($input, 'current_password'), self::string($input, 'new_password'),
                );
                return $this->response(200, ['password_changed' => true, 'login_required' => true]);
            }
            if ($actor->mustChangePassword) {
                throw new AppError('PASSWORD_CHANGE_REQUIRED', 'Change your temporary password first.', 403);
            }
            foreach ($this->routes as $route) {
                if ($route['method'] === $method && preg_match($route['pattern'], substr($path, strlen('/api/v1')), $params)) {
                    (new Authorization($this->connection))->requirePermission($actor, $route['object'], $route['operation']);
                    $params = array_filter($params, 'is_string', ARRAY_FILTER_USE_KEY);
                    $result = ($route['handler'])($actor, $input, $params, $query);
                    if (!is_array($result)) {
                        throw new \LogicException('API handlers must return an array service result.');
                    }
                    return $this->response($route['status'], $result, is_string($input['action_reference'] ?? null) ? $input['action_reference'] : null);
                }
            }
            throw new AppError('NOT_FOUND', 'API operation not found.', 404);
        } catch (AppError $error) {
            if ($error->errorCode === 'SESSION_EXPIRED') {
                $error = new AppError('UNAUTHENTICATED', 'Invalid or expired API token.', 401);
            }
            $response = $this->response($error->status, null, null, ['code' => $error->errorCode, 'message' => $error->getMessage()]);
            if ($error->status === 401) {
                $response['headers']['WWW-Authenticate'] = 'Bearer';
            }
            if ($error->status === 429) {
                $response['headers']['Retry-After'] = '60';
            }
            return $response;
        }
    }

    /** @return array{page: int, page_size: int, offset: int, filters: array<string, string>} */
    public static function pagination(array $query, array $allowedFilters = []): array
    {
        $filters = [];
        foreach ($query as $key => $value) {
            if (!in_array($key, ['page', 'page_size', ...$allowedFilters], true) || !is_string($value)) {
                throw new AppError('VALIDATION_ERROR', 'Unsupported filter or invalid query value.');
            }
            if (!in_array($key, ['page', 'page_size'], true)) {
                $filters[$key] = $value;
            }
        }
        $page = filter_var($query['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000000]]);
        $size = filter_var($query['page_size'] ?? 25, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
        if ($page === false || $size === false) {
            throw new AppError('VALIDATION_ERROR', 'page must be 1-1000000 and page_size must be 1-100.');
        }
        return ['page' => $page, 'page_size' => $size, 'offset' => ($page - 1) * $size, 'filters' => $filters];
    }

    public static function string(#[\SensitiveParameter] array $input, string $key): string
    {
        if (!isset($input[$key]) || !is_string($input[$key]) || $input[$key] === '') {
            throw new AppError('VALIDATION_ERROR', $key . ' must be a non-empty string.');
        }
        return $input[$key];
    }

    public static function integer(array $input, string $key): int
    {
        if (!isset($input[$key]) || !is_int($input[$key]) || $input[$key] < 1) {
            throw new AppError('VALIDATION_ERROR', $key . ' must be a positive JSON integer.');
        }
        return $input[$key];
    }

    public static function boolean(array $input, string $key): bool
    {
        if (!isset($input[$key]) || !is_bool($input[$key])) {
            throw new AppError('VALIDATION_ERROR', $key . ' must be a JSON boolean.');
        }
        return $input[$key];
    }

    public static function bearer(#[\SensitiveParameter] array $headers): string
    {
        if (!is_string($headers['authorization'] ?? null)
            || !preg_match('/\A(?i:Bearer) ([a-f0-9]{64})\z/', $headers['authorization'], $match)) {
            throw new AppError('UNAUTHENTICATED', 'Supply an API bearer token.', 401);
        }
        return $match[1];
    }

    private function response(int $status, ?array $result, ?string $operation = null, ?array $error = null): array
    {
        return ['status' => $status, 'headers' => ['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'no-store'],
            'body' => json_encode(['success' => $error === null, 'operation_id' => $operation,
                'current_version' => $result['current_version'] ?? $result['invoice']['current_version'] ?? null,
                'result' => $result, 'error' => $error], JSON_THROW_ON_ERROR)];
    }
}
