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
    public function testMigrationPlanTracksContentAndSortedVersion(): void
    {
        $runner = new MigrationRunner($this->createMock(Connection::class));
        $plan = $runner->plan(dirname(__DIR__, 2) . '/database/migrations/shared');
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
        (new MigrationRunner($db))->apply(dirname(__DIR__, 2) . '/database/migrations/shared');
    }

    public function testFailedDdlCannotBeReportedAsApplied(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnOnConsecutiveCalls([['TABLE_NAME' => 'ME_MIGRATIONS']], []);
        $db->expects(self::once())->method('executeSchema')->willThrowException(new \RuntimeException('DDL failed'));
        $db->expects(self::once())->method('execute')->willReturn(1);
        $this->expectException(\RuntimeException::class);
        (new MigrationRunner($db))->apply(dirname(__DIR__, 2) . '/database/migrations/shared');
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
        $plan = $runner->plan(dirname(__DIR__, 2) . '/database/migrations/shared');
        self::assertCount(6, $plan);
        self::assertTrue((bool) array_filter($plan[1]['statements'], fn (string $sql) => str_starts_with($sql, 'INSERT INTO ME_OBJECTS')));
    }

    public function testRegistrationDmlUsesTransactionsAndFailureNeverMarksApplied(): void
    {
        $db = $this->createMock(Connection::class);
        $runner = new MigrationRunner($db);
        $plan = $runner->plan(dirname(__DIR__, 2) . '/database/migrations/shared');
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
        $runner->apply(dirname(__DIR__, 2) . '/database/migrations/shared');
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
