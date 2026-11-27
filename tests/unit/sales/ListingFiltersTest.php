<?php
declare(strict_types=1);

namespace MiniErp\Tests\unit\sales;

use MiniErp\sales\ListingFilters;
use MiniErp\sales\DocumentPages;
use MiniErp\sales\orders\OrderService;
use MiniErp\sales\quotes\QuoteService;
use MiniErp\sales\invoices\InvoiceService;
use MiniErp\shared\AppError;
use MiniErp\shared\WebPath;
use MiniErp\shared\database\Connection;
use MiniErp\shared\security\Actor;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/QuoteServiceTest.php';

final class ListingFiltersTest extends TestCase
{
    public function testRangesAndFixedPageSize(): void
    {
        foreach (['2026-11-24 to 2026-11-25', '2026-11-24 - 2026-11-25', '2026-11-24–2026-11-25'] as $range) {
            self::assertSame(['page_size' => '25', 'from_date' => '2026-11-24', 'to_date' => '2026-11-25'],
                ListingFilters::query(['page_size' => '100', 'date_range' => $range]));
        }
        self::assertSame(['page_size' => '25', 'from_date' => '2026-11-24', 'to_date' => '2026-11-24'], ListingFilters::query(['date_range' => '2026-11-24']));
        self::assertSame(['page_size' => '25'], ListingFilters::query(['date_range' => '', 'from_date' => '2026-11-24', 'to_date' => '2026-11-25']));
        foreach (['2026-02-30 to 2026-03-01', '2026-11-25 to 2026-11-24', 'invalid', ['2026-11-24']] as $range) {
            try { ListingFilters::query(['date_range' => $range]); self::fail('Invalid range accepted'); }
            catch (AppError $error) { self::assertSame(400, $error->status); }
        }
    }

    public function testDocumentNumbersAreBoundWithTenantAndDateFiltersBeforePagination(): void
    {
        foreach (['quote', 'order', 'invoice'] as $kind) {
            $db = $this->createMock(Connection::class);
            $db->expects(self::never())->method('execute');
            $db->method('query')->willReturnCallback(static function (string $sql, array $binds = []) use ($kind): array {
                if (str_contains($sql, 'FROM ME_OBJECTS')) { return [['ACTIVE' => 1, 'ALLOW_READ' => 1]]; }
                self::assertSame(7, $binds['company_id']);
                self::assertSame('DOC-22', $binds[$kind . '_number']);
                self::assertStringContainsString('D.DOCUMENT_NUMBER = :' . $kind . '_number', $sql);
                self::assertStringNotContainsString('DOC-22', $sql);
                self::assertStringContainsString('D.CNY# = :company_id', $sql);
                self::assertSame('2026-11-24', $binds['from_date']);
                self::assertSame('2026-11-25', $binds['to_date']);
                self::assertSame(25, $binds['limit_rows']);
                self::assertSame(25, $binds['offset_rows']);
                return [];
            });
            $service = match ($kind) { 'quote' => new QuoteService($db), 'order' => new OrderService($db), 'invoice' => new InvoiceService($db) };
            $service->list(new Actor('admin', 'admin', [], 7, true), ListingFilters::query([
                $kind . '_number' => 'DOC-22', 'page' => '2', 'page_size' => '100', 'date_range' => '2026-11-24 to 2026-11-25',
            ]));
        }
    }

    public function testTwentySixQuotesAreSplitAcrossTwoPagesAndFiltersSurviveNavigation(): void
    {
        $db = new QuoteMemoryConnection();
        $actor = new Actor('admin', 'admin', [], 7, true);
        $service = new QuoteService($db);
        for ($i = 1; $i <= 26; $i++) {
            $service->create($actor, ['customer_code' => 'CUST-001', 'warehouse_id' => 'W01', 'quote_date' => '2026-11-24',
                'lines' => [['product_id' => '163', 'description' => 'Item', 'quantity' => '1', 'unit_price' => '1.00']]]);
        }
        // This fixture does not implement list pagination; apply the actual bound window to its rows.
        $paged = $this->createMock(Connection::class);
        $paged->method('query')->willReturnCallback(static function (string $sql, array $binds = []) use ($db): array {
            $rows = $db->query($sql, $binds);
            return isset($binds['offset_rows']) ? array_slice($rows, $binds['offset_rows'], $binds['limit_rows']) : $rows;
        });
        foreach (['begin', 'commit', 'rollback'] as $method) { $paged->method($method)->willReturnCallback($db->$method(...)); }
        $pages = new DocumentPages($paged, new WebPath('/training'), 'csrf', 'quote');
        $query = ['customer_code' => 'CUST-001', 'date_range' => '2026-11-24 to 2026-11-25'];
        $first = $pages->handle('GET', '/modules/sales/quotes', $actor, [], $query)['body'];
        self::assertSame(25, substr_count($first, 'href="/training/modules/sales/quotes/SQ-'));
        self::assertStringContainsString('Next &gt;</a>', $first);
        self::assertStringContainsString('rel="next"', $first);
        self::assertStringContainsString('data-date-open="from"', $first);
        self::assertStringContainsString('data-date-open="to"', $first);
        self::assertStringContainsString('date_range=2026-11-24+to+2026-11-25&amp;page=2', $first);
        $second = $pages->handle('GET', '/modules/sales/quotes', $actor, [], [...$query, 'page' => '2'])['body'];
        self::assertSame(1, substr_count($second, 'href="/training/modules/sales/quotes/SQ-'));
        self::assertStringContainsString('Previous</a>', $second);
        self::assertStringContainsString('rel="prev"', $second);
        self::assertStringContainsString('aria-current="page">Page 2</span>', $second);
        self::assertStringNotContainsString('Next &gt;</a>', $second);
    }
}
