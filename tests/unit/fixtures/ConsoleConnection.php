<?php
declare(strict_types=1);

namespace MiniErp\Tests\unit\fixtures;

use MiniErp\shared\Config;
use MiniErp\shared\database\Connection;
use MiniErp\shared\database\DatabaseError;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

final class ConsoleConnection implements Connection
{
    public static string $mode;
    public static string $iniPath;
    private bool $verified = false;

    public static function connect(Config $config): self
    {
        if ($config->oracle()['username'] !== 'fixture_schema') {
            throw new \LogicException('Console did not use the fixture INI configuration.');
        }
        if (self::$mode === 'connect_failure') {
            throw new DatabaseError(12541);
        }
        return new self();
    }

    public function query(string $sql, array $parameters = []): array
    {
        if ($sql === 'SELECT USER AS SCHEMA_NAME FROM DUAL') {
            if (self::$mode === 'query_failure') {
                throw new DatabaseError(3113);
            }
            if (self::$mode === 'missing_schema') {
                return [];
            }
            $this->verified = true;
            return [['SCHEMA_NAME' => self::$mode === 'schema_mismatch' ? 'OTHER_SCHEMA' : 'FIXTURE_SCHEMA']];
        }
        if (!$this->verified) {
            throw new \LogicException('Migration started before checking connectivity.');
        }
        if (str_contains($sql, 'FROM ME_COMPANY')) {
            return [['ID' => 1]];
        }
        if (str_contains($sql, 'ME_COMPANY_SEQ.NEXTVAL')) {
            return [['ID' => 2]];
        }
        if (self::$mode === 'existing_admin' && str_contains($sql, 'FROM ME_USERS WHERE USERNAME') && $parameters['username'] === 'admin') {
            return [['ID' => 'bootstrap-admin', 'IS_ADMIN' => 1, 'ACTIVE' => 1]];
        }
        return [];
    }

    public function execute(string $sql, array $parameters = []): int
    {
        $this->executeSchema($sql);
        return 1;
    }

    public function executeSchema(string $sql): void
    {
        if (!$this->verified) {
            throw new \LogicException('Migration wrote before checking connectivity.');
        }
        file_put_contents(self::$iniPath . '.writes', $sql . "\n", FILE_APPEND);
    }

    public function begin(): void {}
    public function commit(): void {}
    public function rollback(): void {}
}

ConsoleConnection::$mode = $argv[1];
ConsoleConnection::$iniPath = $argv[2];
foreach (['ORACLE_DB_HOST', 'ORACLE_DB_PORT', 'ORACLE_DB_SERVICE', 'ORACLE_DB_USER', 'ORACLE_DB_PASSWORD',
    'ORACLE_USERNAME', 'ORACLE_PASSWORD', 'ORACLE_CONNECTION'] as $key) {
    $_ENV[$key] = $_SERVER[$key] = '';
}
$_ENV['BOOTSTRAP_ADMIN_USER'] = $_ENV['BOOTSTRAP_ADMIN_PASSWORD'] = '';
$_ENV['LAB_SEED_ALLOWED'] = '0';
$_ENV['LAB_SEED_PASSWORD'] = '';
$_ENV['ORACLE_CONFIG_FILE'] = ConsoleConnection::$iniPath;
class_alias(ConsoleConnection::class, 'MiniErp\shared\database\OciConnection');
$argv = $argv[3] === 'addcompany'
    ? [dirname(__DIR__, 3) . '/bin/addcompany.php', ...array_slice($argv, 4)]
    : [dirname(__DIR__, 3) . '/bin/console.php', ...array_slice($argv, 3)];
require $argv[0];
