<?php
declare(strict_types=1);

namespace MiniErp\shared;

final readonly class WebPath
{
    public string $basePath;

    public function __construct(string $basePath = '')
    {
        if ($basePath !== '' && (!str_starts_with($basePath, '/')
            || str_contains($basePath, '//') || str_contains($basePath, '\\')
            || preg_match('/[\x00-\x20\x7F?#]/', $basePath)
            || preg_match('#(?:^|/)\.\.?(?:/|$)#', $basePath))) {
            throw new AppError('CONFIGURATION_ERROR', 'APP_BASE_PATH must be an absolute URL path without a host, query or traversal segments.', 503);
        }
        $this->basePath = rtrim($basePath, '/');
    }

    public static function fromScriptName(string $scriptName): self
    {
        return new self(preg_match('#^(.*)/index\.php$#D', $scriptName, $match) ? $match[1] : '');
    }

    public function url(string $path): string
    {
        if (!str_starts_with($path, '/') || str_starts_with($path, '//')
            || str_contains($path, '\\') || preg_match('/[\x00-\x1F\x7F]/', $path)) {
            throw new \InvalidArgumentException('Use an application-relative URL starting with a single slash.');
        }
        return $this->basePath . $path;
    }

    public function routePath(string $path): string
    {
        if ($path === $this->basePath) {
            return '/';
        }
        if ($this->basePath !== '' && !str_starts_with($path, $this->basePath . '/')) {
            throw new AppError('NOT_FOUND', 'Page or operation not found.', 404);
        }
        $route = substr($path, strlen($this->basePath));
        return $route === '/index.php' ? '/' : $route;
    }
}
