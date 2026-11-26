<?php
declare(strict_types=1);

namespace MiniErp\Tests\unit;

use MiniErp\shared\database\Connection;
use MiniErp\shared\database\MigrationRunner;
use MiniErp\shared\database\TransactionManager;
use MiniErp\shared\AppError;
use MiniErp\shared\Seeder;
use PHPUnit\Framework\TestCase;

final class MigrationTest extends TestCase
{
    private array $temporaryFiles = [];
    private array $temporaryDirectories = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $file) {
            unlink($file);
        }
        foreach (array_reverse($this->temporaryDirectories) as $directory) {
            rmdir($directory);
        }
    }

    private function migrationTree(array $components): string
    {
        $root = sys_get_temp_dir() . '/mini-erp-discovery-' . bin2hex(random_bytes(8));
        mkdir($root);
        $this->temporaryDirectories[] = $root;
        foreach ($components as $component => $migrations) {
            $directory = $root . '/' . $component;
            mkdir($directory);
            $this->temporaryDirectories[] = $directory;
            foreach ($migrations as $filename => $statement) {
                $file = $directory . '/' . $filename;
                file_put_contents($file, '<?php return ' . var_export([$statement], true) . ';');
                $this->temporaryFiles[] = $file;
            }
        }
        return $root;
    }

    public function testAutoDiscoveryOrdersFoldersAndFilesAndFindsNewComponents(): void
    {
        $root = $this->migrationTree([
            '003-inventory' => ['001_product.php' => 'CREATE TABLE INV_MARKER (ID NUMBER)'],
            '002-sales' => ['002_second.php' => 'CREATE TABLE SECOND_MARKER (ID NUMBER)',
                '001_first.php' => 'CREATE TABLE FIRST_MARKER (ID NUMBER)'],
            '001-shared' => ['001_base.php' => 'CREATE TABLE BASE_MARKER (ID NUMBER)'],
        ]);
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(static fn (string $sql): array =>
            str_contains($sql, 'USER_TABLES') ? [['TABLE_NAME' => 'ME_MIGRATIONS']] : []);
        $executed = [];
        $db->method('executeSchema')->willReturnCallback(static function (string $sql) use (&$executed): void {
            $executed[] = $sql;
        });
        $db->method('execute')->willReturn(1);
        $runner = new MigrationRunner($db);
        self::assertSame(['shared', 'sales', 'inventory'], array_keys($runner->directories($root)));
        self::assertSame([
            'shared' => ['001_base.php'], 'sales' => ['001_first.php', '002_second.php'], 'inventory' => ['001_product.php'],
        ], $runner->applyAll($root));
        self::assertSame(['CREATE TABLE BASE_MARKER (ID NUMBER)', 'CREATE TABLE FIRST_MARKER (ID NUMBER)',
            'CREATE TABLE SECOND_MARKER (ID NUMBER)', 'CREATE TABLE INV_MARKER (ID NUMBER)'], $executed);
    }

    public function testSequencedDirectoryRetainsExistingComponentIdentityAndDoesNotReapply(): void
    {
        $root = $this->migrationTree(['002-sales' => ['001_leads.php' => 'CREATE TABLE LEAD_MARKER (ID NUMBER)']]);
        $db = $this->createMock(Connection::class);
        $checksum = hash_file('sha256', $root . '/002-sales/001_leads.php');
        $db->method('query')->willReturnCallback(static function (string $sql, array $params = []) use ($checksum): array {
            if (str_contains($sql, 'USER_TABLES')) {
                return [['TABLE_NAME' => 'ME_MIGRATIONS']];
            }
            self::assertSame('sales', $params['component']);
            return [['VERSION' => '001_leads.php', 'CHECKSUM' => $checksum, 'STATUS' => 'APPLIED']];
        });
        $db->expects(self::never())->method('executeSchema');
        $db->expects(self::never())->method('execute');
        self::assertSame(['sales' => []], (new MigrationRunner($db))->applyAll($root));
    }

    public function testTargetedComponentIsResolvedFromDiscoveredFolder(): void
    {
        $root = $this->migrationTree([
            '001-shared' => ['001_base.php' => 'CREATE TABLE BASE_MARKER (ID NUMBER)'],
            '009-new_module' => ['001_new.php' => 'CREATE TABLE NEW_MARKER (ID NUMBER)'],
        ]);
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(static fn (string $sql): array =>
            str_contains($sql, 'USER_TABLES') ? [['TABLE_NAME' => 'ME_MIGRATIONS']] : []);
        $db->expects(self::once())->method('executeSchema')->with('CREATE TABLE NEW_MARKER (ID NUMBER)');
        $db->method('execute')->willReturn(1);
        self::assertSame(['new_module' => ['001_new.php']], (new MigrationRunner($db))->applyAll($root, 'new_module'));
    }

    public function testUnknownComponentFailsBeforeDatabaseWrites(): void
    {
        $root = $this->migrationTree(['001-shared' => ['001_base.php' => 'CREATE TABLE BASE_MARKER (ID NUMBER)']]);
        $db = $this->createMock(Connection::class);
        $db->expects(self::never())->method('executeSchema');
        $db->expects(self::never())->method('execute');
        $this->expectException(AppError::class);
        $this->expectExceptionMessage('Unknown migration component');
        (new MigrationRunner($db))->applyAll($root, '../shared');
    }

    public function testAmbiguousOrUnsequencedDirectoriesFailBeforeWriting(): void
    {
        foreach ([
            ['001-shared', '001-sales'], ['001-sales', '002-sales'], ['shared'], ['000-shared'],
        ] as $names) {
            $root = $this->migrationTree(array_fill_keys($names, ['001_test.php' => 'CREATE TABLE TEST_MARKER (ID NUMBER)']));
            $db = $this->createMock(Connection::class);
            $db->expects(self::never())->method('executeSchema');
            try {
                (new MigrationRunner($db))->applyAll($root);
                self::fail('Invalid migration directories must fail before execution.');
            } catch (AppError $error) {
                self::assertSame('MIGRATION_ERROR', $error->errorCode);
            }
        }
    }

    public function testEarlierFailureStopsLaterComponents(): void
    {
        $root = $this->migrationTree([
            '001-shared' => ['001_base.php' => 'CREATE TABLE BASE_MARKER (ID NUMBER)'],
            '002-sales' => ['001_lead.php' => 'CREATE TABLE LEAD_MARKER (ID NUMBER)'],
        ]);
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(static fn (string $sql): array =>
            str_contains($sql, 'USER_TABLES') ? [['TABLE_NAME' => 'ME_MIGRATIONS']] : []);
        $db->expects(self::once())->method('executeSchema')->with('CREATE TABLE BASE_MARKER (ID NUMBER)')
            ->willThrowException(new \RuntimeException('Injected DDL failure'));
        $db->expects(self::once())->method('execute')->willReturn(1);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Injected DDL failure');
        (new MigrationRunner($db))->applyAll($root);
    }

    public function testRepeatedDiscoveryRunSkipsAllPreviouslyAppliedVersions(): void
    {
        $root = $this->migrationTree([
            '001-shared' => ['001_init.php' => 'CREATE TABLE BASE_MARKER (ID NUMBER)'],
            '002-sales' => ['001_init.php' => 'CREATE TABLE LEAD_MARKER (ID NUMBER)'],
        ]);
        $ledger = [];
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(static function (string $sql, array $params = []) use (&$ledger): array {
            return str_contains($sql, 'USER_TABLES') ? [['TABLE_NAME' => 'ME_MIGRATIONS']]
                : array_values($ledger[$params['component']] ?? []);
        });
        $db->expects(self::exactly(2))->method('executeSchema');
        $db->method('execute')->willReturnCallback(static function (string $sql, array $params) use (&$ledger): int {
            self::assertContains($params['component'], ['shared', 'sales']);
            if (str_starts_with($sql, 'INSERT')) {
                $ledger[$params['component']][$params['version']] = ['VERSION' => $params['version'],
                    'CHECKSUM' => $params['checksum'], 'STATUS' => 'RUNNING'];
            } else {
                $ledger[$params['component']][$params['version']]['STATUS'] = 'APPLIED';
            }
            return 1;
        });
        $runner = new MigrationRunner($db);
        self::assertSame(['shared' => ['001_init.php'], 'sales' => ['001_init.php']], $runner->applyAll($root));
        self::assertSame(['shared' => [], 'sales' => []], $runner->applyAll($root));
    }

    public function testEmptyAndMissingMigrationRootsFailExplicitly(): void
    {
        $root = $this->migrationTree([]);
        foreach ([$root, $root . '/missing'] as $directory) {
            try {
                (new MigrationRunner($this->createMock(Connection::class)))->directories($directory);
                self::fail('An empty or missing migration root must not report success.');
            } catch (AppError $error) {
                self::assertSame('MIGRATION_ERROR', $error->errorCode);
            }
        }
    }

    public function testMigrationPlanTracksContentAndSortedVersion(): void
    {
        $runner = new MigrationRunner($this->createMock(Connection::class));
        $plan = $runner->plan(dirname(__DIR__, 2) . '/database/migrations/001-shared');
        self::assertNotEmpty($plan);
        self::assertSame('001_foundation.php', $plan[0]['version']);
        self::assertSame(hash_file('sha256', $plan[0]['path']), $plan[0]['checksum']);
        self::assertNotEmpty($plan[0]['statements']);
    }

    public function testAppliedMigrationChangeRequiresExplicitRecovery(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnOnConsecutiveCalls(
            [['TABLE_NAME' => 'ME_MIGRATIONS']],
            [['VERSION' => '001_foundation.php', 'CHECKSUM' => 'modified', 'STATUS' => 'APPLIED']]
        );
        $db->expects(self::never())->method('executeSchema');
        $this->expectException(\RuntimeException::class);
        (new MigrationRunner($db))->apply(dirname(__DIR__, 2) . '/database/migrations/001-shared');
    }

    public function testFailedDdlCannotBeReportedAsApplied(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnOnConsecutiveCalls([['TABLE_NAME' => 'ME_MIGRATIONS']], []);
        $db->expects(self::once())->method('executeSchema')->willThrowException(new \RuntimeException('DDL failed'));
        $db->expects(self::once())->method('execute')->willReturn(1);
        $this->expectException(\RuntimeException::class);
        (new MigrationRunner($db))->apply(dirname(__DIR__, 2) . '/database/migrations/001-shared');
    }

    public function testSeederRequiresExplicitPassword(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::never())->method('begin');
        $this->expectException(\InvalidArgumentException::class);
        (new Seeder($db, new TransactionManager($db)))->seed('');
    }

    public function testRegistrationRowsCanBeAddedByMigration(): void
    {
        $runner = new MigrationRunner($this->createMock(Connection::class));
<<<<<<< HEAD
        $plan = $runner->plan(dirname(__DIR__, 2) . '/database/migrations/001-shared');
        self::assertCount(4, $plan);
=======
        $plan = $runner->plan(dirname(__DIR__, 2) . '/database/migrations/shared');
        self::assertCount(6, $plan);
>>>>>>> origin/sagarkhadka
        self::assertTrue((bool) array_filter($plan[1]['statements'], fn (string $sql) => str_starts_with($sql, 'INSERT INTO ME_OBJECTS')));
    }

    public function testRegistrationDmlUsesTransactionsAndFailureNeverMarksApplied(): void
    {
        $db = $this->createMock(Connection::class);
        $runner = new MigrationRunner($db);
        $plan = $runner->plan(dirname(__DIR__, 2) . '/database/migrations/001-shared');
        $db->method('query')->willReturnOnConsecutiveCalls(
            [['TABLE_NAME' => 'ME_MIGRATIONS']],
            [['VERSION' => $plan[0]['version'], 'CHECKSUM' => $plan[0]['checksum'], 'STATUS' => 'APPLIED']],
        );
        $inTransaction = false;
        $db->method('begin')->willReturnCallback(function () use (&$inTransaction): void { $inTransaction = true; });
        $db->method('commit')->willReturnCallback(function () use (&$inTransaction): void { $inTransaction = false; });
        $db->method('rollback')->willReturnCallback(function () use (&$inTransaction): void { $inTransaction = false; });
        $db->method('executeSchema')->willReturnCallback(function () use (&$inTransaction): void {
            self::assertFalse($inTransaction, 'DDL must not run inside a migration DML transaction.');
        });
        $db->method('execute')->willReturnCallback(function (string $sql) use (&$inTransaction): int {
            self::assertTrue($inTransaction, 'Every registration/ledger DML write must be transactional.');
            self::assertStringNotContainsString("SET STATUS = 'APPLIED'", $sql);
            if (str_starts_with($sql, 'INSERT INTO ME_OBJECTS')) {
                throw new AppError('VALIDATION_ERROR', 'Injected registry failure.');
            }
            return 1;
        });
        $this->expectException(AppError::class);
        $runner->apply(dirname(__DIR__, 2) . '/database/migrations/001-shared');
    }

    public function testAnonymousSchemaGuardRunsOutsideLedgerTransactions(): void
    {
        $directory = sys_get_temp_dir() . '/mini-erp-migration-' . bin2hex(random_bytes(8));
        mkdir($directory);
        $statement = 'DECLARE guard_count NUMBER; BEGIN NULL; END;';
        file_put_contents($directory . '/001_guard.php', '<?php return [' . var_export($statement, true) . '];');
        try {
            $db = $this->createMock(Connection::class);
            $db->method('query')->willReturnOnConsecutiveCalls([['TABLE_NAME' => 'ME_MIGRATIONS']], []);
            $inTransaction = false;
            $db->method('begin')->willReturnCallback(static function () use (&$inTransaction): void { $inTransaction = true; });
            $db->method('commit')->willReturnCallback(static function () use (&$inTransaction): void { $inTransaction = false; });
            $db->expects(self::once())->method('executeSchema')->with($statement)
                ->willReturnCallback(static function () use (&$inTransaction): void { self::assertFalse($inTransaction); });
            $db->expects(self::exactly(2))->method('execute')->willReturnCallback(
                static function () use (&$inTransaction): int { self::assertTrue($inTransaction); return 1; },
            );
            self::assertSame(['001_guard.php'], (new MigrationRunner($db))->apply($directory));
        } finally {
            unlink($directory . '/001_guard.php');
            rmdir($directory);
        }
    }
}
