<?php
declare(strict_types=1);

use Dotenv\Dotenv;
use MiniErp\shared\Config;

require __DIR__ . '/vendor/autoload.php';

Dotenv::createImmutable(__DIR__)->safeLoad();
$environment = [];
foreach (['APP_ENV', 'APP_MODE', 'APP_BASE_PATH', 'API_TOKEN_TTL_SECONDS', 'ORACLE_CONFIG_FILE', 'ORACLE_DB_HOST', 'ORACLE_DB_PORT', 'ORACLE_DB_SERVICE', 'ORACLE_DB_USER', 'ORACLE_DB_PASSWORD', 'ORACLE_USERNAME', 'ORACLE_PASSWORD', 'ORACLE_CONNECTION'] as $key) {
    $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
    if (is_string($value) && $value !== '') {
        $environment[$key] = $value;
    }
}
$path = $environment['ORACLE_CONFIG_FILE'] ?? __DIR__ . '/config.ini';
if (isset($environment['ORACLE_CONFIG_FILE']) || is_file($path)) {
    return Config::fromIni($path, $environment);
}
return Config::fromArray($environment);
