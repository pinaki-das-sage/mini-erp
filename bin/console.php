<?php
declare(strict_types=1);

use MiniErp\shared\AppError;
use MiniErp\shared\database\DatabaseError;
use MiniErp\shared\database\MigrationRunner;
use MiniErp\shared\database\OciConnection;
use MiniErp\shared\database\TransactionManager;
use MiniErp\shared\Seeder;
use MiniErp\shared\security\CompanyBootstrap;

try {
    $config = require dirname(__DIR__) . '/bootstrap.php';
    if (PHP_MAJOR_VERSION !== 8 || PHP_MINOR_VERSION !== 4) {
        throw new AppError('CONFIGURATION_ERROR', 'Use PHP 8.4.', 503);
    }
    $command = $argv[1] ?? 'help';
    if ($command === 'help') {
        echo "Commands: doctor [--offline], migrate [--module=shared|sales], bootstrap, seed, company:create CODE [NAME]\n";
        echo "Migrations default to Shared only; --module=sales applies Shared before Sales.\n";
        echo "Write commands use the configured Oracle connection; --confirm-schema=NAME is optional.\n";
        exit(0);
    }
    if (!in_array($command, ['doctor', 'migrate', 'bootstrap', 'seed', 'company:create'], true)) {
        throw new InvalidArgumentException('Unknown command.');
    }
    $migrationModule = 'shared';
    if ($command === 'migrate') {
        $moduleProvided = false;
        foreach (array_slice($argv, 2) as $argument) {
            if (str_starts_with($argument, '--confirm-schema=')) {
                continue;
            }
            if (!str_starts_with($argument, '--module=') || $moduleProvided) {
                throw new InvalidArgumentException('Usage: migrate [--module=shared|sales] [--confirm-schema=NAME]');
            }
            $migrationModule = substr($argument, strlen('--module='));
            if (!in_array($migrationModule, ['shared', 'sales'], true)) {
                throw new InvalidArgumentException('Migration module must be shared or sales.');
            }
            $moduleProvided = true;
        }
    }
    if ($command === 'doctor' && in_array('--offline', $argv, true)) {
        echo "PHP 8.4 and Composer bootstrap OK. Offline only: Oracle, migrations and login NOT VERIFIED.\n";
        exit(0);
    }
    $connection = OciConnection::connect($config);
    if ($command === 'doctor') {
        $connection->query('SELECT 1 FROM DUAL');
        echo "Oracle connection OK. Check /ready for migration/seed readiness. ERP modules are not implemented.\n";
        exit(0);
    }
    $confirmed = $config->oracle()['username'];
    foreach ($argv as $argument) {
        if (str_starts_with($argument, '--confirm-schema=')) {
            $confirmed = substr($argument, strlen('--confirm-schema='));
        }
    }
    $rows = $connection->query('SELECT USER AS SCHEMA_NAME FROM DUAL');
    if (count($rows) !== 1 || !isset($rows[0]['SCHEMA_NAME']) || !is_string($rows[0]['SCHEMA_NAME']) || $rows[0]['SCHEMA_NAME'] === '') {
        throw new AppError('DATABASE_ERROR', 'Could not verify the connected Oracle schema.', 503);
    }
    $schema = $rows[0]['SCHEMA_NAME'];
    if (strtoupper($confirmed) !== $schema) {
        throw new AppError('FORBIDDEN', 'Connected Oracle schema does not match the configured username or explicit schema confirmation.', 403);
    }
    echo 'Oracle connection OK. Schema: ' . $schema . "\n";
    if ($command === 'migrate') {
        $runner = new MigrationRunner($connection);
        $components = $migrationModule === 'sales' ? ['shared', 'sales'] : ['shared'];
        foreach ($components as $component) {
            $completed = $runner->apply(dirname(__DIR__) . '/database/migrations/' . $component);
            $label = $component === 'shared' ? 'Applied: ' : 'Applied sales migrations: ';
            echo $completed === [] ? "No pending $component migrations.\n" : $label . implode(', ', $completed) . "\n";
        }
    } elseif ($command === 'bootstrap' || $command === 'company:create') {
        $username = $_ENV['BOOTSTRAP_ADMIN_USER'] ?? getenv('BOOTSTRAP_ADMIN_USER');
        $password = $_ENV['BOOTSTRAP_ADMIN_PASSWORD'] ?? getenv('BOOTSTRAP_ADMIN_PASSWORD');
        $username = is_string($username) && $username !== '' ? $username : 'admin';
        $password = is_string($password) && $password !== '' ? $password : 'admin';
        $bootstrap = new CompanyBootstrap($connection);
        if ($command === 'company:create') {
            $code = $argv[2] ?? '';
            $name = $argv[3] ?? $code;
            $id = $bootstrap->create($code, $name, $username, $password);
            echo 'Company ' . strtoupper(trim($code)) . ' created (ID ' . $id . ") with its administrator and training accounts.\n";
            exit(0);
        }
        $created = $bootstrap->run($username, $password);
        echo $created ? "Administrator created. Password changes are available in the UI.\n" : "Administrator exists; password unchanged.\n";
        $accounts = (new Seeder($connection, new TransactionManager($connection)))->bootstrap();
        echo $accounts === [] ? "Lab accounts exist; passwords unchanged.\n" : 'Created lab accounts: ' . implode(', ', $accounts) . "\n";
    } else {
        $allowed = $_ENV['LAB_SEED_ALLOWED'] ?? getenv('LAB_SEED_ALLOWED');
        if ($allowed !== '1') {
            throw new AppError('FORBIDDEN', 'Set LAB_SEED_ALLOWED=1 only for an isolated training schema.', 403);
        }
        $password = $_ENV['LAB_SEED_PASSWORD'] ?? getenv('LAB_SEED_PASSWORD');
        $created = (new Seeder($connection, new TransactionManager($connection)))->seed(is_string($password) ? $password : '');
        echo $created === [] ? "No new accounts. Existing users/passwords were not changed.\n" : 'Created lab accounts: ' . implode(', ', $created) . "\n";
    }
} catch (AppError $error) {
    fwrite(STDERR, $error->errorCode . ': ' . $error->getMessage() . "\n");
    exit(1);
} catch (DatabaseError $error) {
    fwrite(STDERR, 'Oracle operation failed (code ' . $error->oracleCode . "). No readiness claim.\n");
    exit(1);
} catch (InvalidArgumentException $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
} catch (Throwable $error) {
    error_log('mini-erp console_error type=' . get_class($error));
    fwrite(STDERR, "Command failed. Check the configuration and migration recovery instructions.\n");
    exit(1);
}
