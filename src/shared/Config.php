<?php
declare(strict_types=1);

namespace MiniErp\shared;

final readonly class Config
{
    private function __construct(
        public string $environment,
        public string $mode,
        private array $values,
    ) {}

    public static function fromArray(array $values): self
    {
        $environment = $values['APP_ENV'] ?? 'local';
        $mode = $values['APP_MODE'] ?? 'standalone';
        if (!in_array($environment, ['local', 'production'], true)
            || !in_array($mode, ['standalone', 'integrated'], true)) {
            throw new AppError('CONFIGURATION_ERROR', 'Invalid APP_ENV or APP_MODE.', 503);
        }
        return new self($environment, $mode, $values);
    }

    public static function fromIni(string $path, array $environment = []): self
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new AppError('CONFIGURATION_ERROR', 'Oracle configuration file is missing or unreadable.', 503);
        }
        $ini = @parse_ini_file($path, true, INI_SCANNER_RAW);
        if ($ini === false || !isset($ini['oracle']) || !is_array($ini['oracle'])) {
            throw new AppError('CONFIGURATION_ERROR', 'Configuration must contain an [oracle] section.', 503);
        }
        $values = [];
        foreach (['host' => 'HOST', 'port' => 'PORT', 'service_name' => 'SERVICE', 'username' => 'USER', 'password' => 'PASSWORD'] as $key => $suffix) {
            if (isset($ini['oracle'][$key]) && !is_string($ini['oracle'][$key])) {
                throw new AppError('CONFIGURATION_ERROR', 'Oracle configuration values must be strings.', 503);
            }
            $values['ORACLE_DB_' . $suffix] = $ini['oracle'][$key] ?? '';
        }
        return self::fromArray(array_replace($values, $environment));
    }

    public function webPath(string $scriptName): WebPath
    {
        if (array_key_exists('APP_BASE_PATH', $this->values) && !is_string($this->values['APP_BASE_PATH'])) {
            throw new AppError('CONFIGURATION_ERROR', 'APP_BASE_PATH must be a string.', 503);
        }
        $base = $this->values['APP_BASE_PATH'] ?? '';
        return $base === '' ? WebPath::fromScriptName($scriptName) : new WebPath($base);
    }

    public function apiTokenTtl(): int
    {
        $ttl = filter_var($this->values['API_TOKEN_TTL_SECONDS'] ?? 3600, FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 60, 'max_range' => 86400]]);
        if ($ttl === false) {
            throw new AppError('CONFIGURATION_ERROR', 'API_TOKEN_TTL_SECONDS must be an integer from 60 to 86400.', 503);
        }
        return $ttl;
    }

    /** @return array{username: string, password: string, connection: string} */
    public function oracle(): array
    {
        $username = $this->values['ORACLE_DB_USER'] ?? $this->values['ORACLE_USERNAME'] ?? '';
        $password = $this->values['ORACLE_DB_PASSWORD'] ?? $this->values['ORACLE_PASSWORD'] ?? '';
        $connection = $this->values['ORACLE_CONNECTION'] ?? '';
        if ($connection === '') {
            $host = $this->values['ORACLE_DB_HOST'] ?? '';
            $service = $this->values['ORACLE_DB_SERVICE'] ?? '';
            $port = $this->values['ORACLE_DB_PORT'] ?? '';
            if ($port === '') {
                $port = '1521';
            }
            if (!is_string($port) || !ctype_digit($port) || (int) $port < 1 || (int) $port > 65535) {
                throw new AppError('CONFIGURATION_ERROR', 'Oracle port must be between 1 and 65535.', 503);
            }
            if (is_string($host) && $host !== '' && is_string($service) && $service !== '') {
                $connection = '//' . $host . ':' . $port . '/' . $service;
            }
        }
        foreach ([$username, $password, $connection] as $value) {
            if (!is_string($value) || $value === '') {
                throw new AppError('CONFIGURATION_ERROR', 'Configure Oracle in the server environment before using database features.', 503);
            }
        }
        return ['username' => $username, 'password' => $password, 'connection' => $connection];
    }
}
