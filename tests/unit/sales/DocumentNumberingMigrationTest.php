<?php
declare(strict_types=1);

namespace MiniErp\Tests\unit\sales;

use MiniErp\shared\database\Connection;
use MiniErp\shared\database\MigrationRunner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DocumentNumberingMigrationTest extends TestCase
{
    #[DataProvider('numberingStates')]
    public function testExistingTableMigrationsAreSkippedAndOnlyPendingSequencesAreCreated(bool $numberingApplied): void
    {
        $db = $this->createMock(Connection::class);
        $runner = new MigrationRunner($db);
        $directory = dirname(__DIR__, 3) . '/database/migrations/sales';
        $plan = $runner->plan($directory);
        $versions = array_column($plan, 'version');
        self::assertContains('007_oe_source_links.php', $versions);
        self::assertContains('008_oe_payments.php', $versions);
        self::assertContains('009_oe_document_numbering.php', $versions);
        self::assertNotContains('010_oe_quote_numbering.php', $versions);
        self::assertNotContains('007_oe_quote_numbering.php', $versions);
        $pending = $numberingApplied ? [] : ['009_oe_document_numbering.php'];
        $applied = [];
        foreach ($plan as $migration) {
            if (!in_array($migration['version'], $pending, true)) {
                $applied[] = ['VERSION' => $migration['version'], 'CHECKSUM' => $migration['checksum'], 'STATUS' => 'APPLIED'];
            }
        }
        $db->method('query')->willReturnOnConsecutiveCalls([['TABLE_NAME' => 'ME_MIGRATIONS']], $applied);
        $created = [];
        $db->expects(self::exactly($numberingApplied ? 0 : 4))->method('executeSchema')->willReturnCallback(
            static function (string $sql) use (&$created): void {
                self::assertMatchesRegularExpression('/\ACREATE SEQUENCE ME_OE_(QUOTE|ORDER|INVOICE|PAYMENT)_NO_SEQ .* NOCYCLE CACHE 20\z/', $sql);
                preg_match('/CREATE SEQUENCE (\w+)/', $sql, $match);
                $created[] = $match[1];
            },
        );
        $db->method('execute')->willReturn(1);
        self::assertSame($pending, $runner->apply($directory));
        if ($numberingApplied) {
            self::assertSame([], $created);
            return;
        }
        self::assertContains('ME_OE_QUOTE_NO_SEQ', $created);
        self::assertContains('ME_OE_ORDER_NO_SEQ', $created);
        self::assertContains('ME_OE_INVOICE_NO_SEQ', $created);
        self::assertContains('ME_OE_PAYMENT_NO_SEQ', $created);
        self::assertSame(count($created), count(array_unique($created)));
    }

    public static function numberingStates(): array
    {
        return ['Numbering migration already applied' => [true], 'Numbering migration still pending' => [false]];
    }
}
