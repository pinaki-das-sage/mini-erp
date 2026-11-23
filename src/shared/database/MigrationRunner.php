<?php
declare(strict_types=1);

namespace MiniErp\shared\database;

use MiniErp\shared\AppError;

final readonly class MigrationRunner
{
    public function __construct(private Connection $connection) {}

    /** @return array<string, string> Component name => directory, in execution order. */
    public function directories(string $root): array
    {
        if (!is_dir($root) || !is_readable($root)) {
            throw new AppError('MIGRATION_ERROR', 'Migration root does not exist or is unreadable.', 503);
        }
        $entries = scandir($root);
        if ($entries === false) {
            throw new AppError('MIGRATION_ERROR', 'Cannot enumerate migration directories.', 503);
        }
        sort($entries, SORT_STRING);
        $directories = [];
        $sequences = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || !is_dir($root . '/' . $entry)) {
                continue;
            }
            if (is_link($root . '/' . $entry)
                || !preg_match('/\A([0-9]{3})-([a-z][a-z0-9_]{0,99})\z/', $entry, $match)
                || $match[1] === '000') {
                throw new AppError('MIGRATION_ERROR', 'Migration folders must use NNN-component (001-999), without symlinks: ' . $entry, 503);
            }
            if (isset($sequences[$match[1]]) || isset($directories[$match[2]])) {
                throw new AppError('MIGRATION_ERROR', 'Duplicate migration sequence or component: ' . $entry, 503);
            }
            $sequences[$match[1]] = true;
            $directories[$match[2]] = $root . '/' . $entry;
        }
        if ($directories === []) {
            throw new AppError('MIGRATION_ERROR', 'No migration component directories found.', 503);
        }
        return $directories;
    }

    /** @return array<string, list<string>> Applied versions per component, including unchanged components. */
    public function applyAll(string $root, ?string $component = null): array
    {
        $directories = $this->directories($root);
        if ($component !== null) {
            if (!isset($directories[$component])) {
                throw new AppError('MIGRATION_ERROR', 'Unknown migration component: ' . $component, 503);
            }
            $directories = [$component => $directories[$component]];
        }
        $completed = [];
        foreach ($directories as $name => $directory) {
            $completed[$name] = $this->apply($directory);
        }
        return $completed;
    }

    public function plan(string $directory): array
    {
        if (!is_dir($directory)) {
            throw new AppError('MIGRATION_ERROR', 'Migration directory does not exist.', 503);
        }
        $files = glob($directory . '/*.php');
        if ($files === false) {
            throw new AppError('MIGRATION_ERROR', 'Cannot enumerate migrations.', 503);
        }
        sort($files, SORT_STRING);
        $plan = [];
        foreach ($files as $path) {
            $statements = require $path;
            if (!is_array($statements) || $statements === []) {
                throw new AppError('MIGRATION_ERROR', 'Migration must return a nonempty statement list: ' . basename($path), 503);
            }
            foreach ($statements as $statement) {
                if (!is_string($statement) || !preg_match('/^\s*(CREATE|ALTER|DROP|DECLARE|INSERT|UPDATE|DELETE|MERGE)\b/i', $statement)) {
                    throw new AppError('MIGRATION_ERROR', 'Migration contains an invalid schema statement.', 503);
                }
            }
            $checksum = hash_file('sha256', $path);
            if ($checksum === false) {
                throw new AppError('MIGRATION_ERROR', 'Cannot checksum migration.', 503);
            }
            $plan[] = ['version' => basename($path), 'checksum' => $checksum, 'path' => $path, 'statements' => $statements];
        }
        return $plan;
    }

    public function apply(string $directory): array
    {
        $plan = $this->plan($directory);
        $name = basename($directory);
        $component = preg_match('/\A[0-9]{3}-(.+)\z/', $name, $match) ? $match[1] : $name;
        if ($this->connection->query("SELECT TABLE_NAME FROM USER_TABLES WHERE TABLE_NAME = 'ME_MIGRATIONS'") === []) {
            $this->connection->executeSchema(
                "CREATE TABLE ME_MIGRATIONS (
                    COMPONENT VARCHAR2(100) NOT NULL, VERSION VARCHAR2(150) NOT NULL,
                    CHECKSUM VARCHAR2(64) NOT NULL, STATUS VARCHAR2(10) NOT NULL,
                    STARTED_AT TIMESTAMP WITH TIME ZONE DEFAULT SYSTIMESTAMP NOT NULL,
                    APPLIED_AT TIMESTAMP WITH TIME ZONE,
                    PRIMARY KEY (COMPONENT, VERSION), CHECK (STATUS IN ('RUNNING', 'APPLIED'))
                )"
            );
        }
        $existing = $this->connection->query('SELECT VERSION, CHECKSUM, STATUS FROM ME_MIGRATIONS WHERE COMPONENT = :component', ['component' => $component]);
        $versions = array_column($plan, null, 'version');
        $applied = [];
        foreach ($existing as $row) {
            if (!isset($versions[$row['VERSION']]) || $row['CHECKSUM'] !== $versions[$row['VERSION']]['checksum'] || $row['STATUS'] !== 'APPLIED') {
                throw new AppError('MIGRATION_ERROR', 'Migration changed, was removed or is incomplete: ' . $row['VERSION'] . '. Stop and follow the documented DDL recovery procedure.', 503);
            }
            $applied[$row['VERSION']] = true;
        }
        $transactions = new TransactionManager($this->connection);
        $completed = [];
        foreach ($plan as $migration) {
            if (isset($applied[$migration['version']])) {
                continue;
            }
            $transactions->run(fn (TransactionContext $tx) => $tx->execute(
                "INSERT INTO ME_MIGRATIONS (COMPONENT, VERSION, CHECKSUM, STATUS) VALUES (:component, :version, :checksum, 'RUNNING')",
                ['component' => $component, 'version' => $migration['version'], 'checksum' => $migration['checksum']],
            ));
            // Oracle DDL commits implicitly. A failure leaves RUNNING, never a false APPLIED marker.
            foreach ($migration['statements'] as $statement) {
                if (preg_match('/^\s*(CREATE|ALTER|DROP|DECLARE)\b/i', $statement)) {
                    $this->connection->executeSchema($statement);
                } else {
                    $transactions->run(fn (TransactionContext $tx) => $tx->execute($statement));
                }
            }
            $transactions->run(fn (TransactionContext $tx) => $tx->execute(
                "UPDATE ME_MIGRATIONS SET STATUS = 'APPLIED', APPLIED_AT = SYSTIMESTAMP WHERE COMPONENT = :component AND VERSION = :version",
                ['component' => $component, 'version' => $migration['version']],
            ));
            $completed[] = $migration['version'];
        }
        return $completed;
    }
}
