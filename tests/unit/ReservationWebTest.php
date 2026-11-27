<?php
declare(strict_types=1);

namespace MiniErp\Tests\unit;

use MiniErp\inventory\ReservationWeb;
use MiniErp\shared\AppError;
use MiniErp\shared\Config;
use MiniErp\shared\Kernel;
use MiniErp\shared\WebPath;
use MiniErp\shared\database\Connection;
use MiniErp\shared\security\Actor;
use MiniErp\shared\view\Renderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReservationWebTest extends TestCase
{
    private function actor(int $company = 1, bool $admin = true): Actor
    {
        return new Actor('tester', 'tester', [], $company, isAdmin: $admin);
    }

    private function row(int $id = 22, string $status = 'ACTIVE'): array
    {
        return ['RESERVATION_ID' => $id, 'SALES_ORDER_ID' => 61, 'SALES_LINE_ID' => 61, 'PRODUCT_ID' => 163,
            'RESERVED_QUANTITY' => 5, 'RESERVATION_STATUS' => $status, 'TRIP_ID' => null, 'TRIP_LINE_ID' => null];
    }

    private function connection(array $rows = [], int $total = 0, bool $granted = true, ?string $unitLabel = 'box'): Connection
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::never())->method('execute');
        $db->expects(self::never())->method('executeSchema');
        $db->method('query')->willReturnCallback(static function (string $sql, array $params = []) use ($rows, $total, $granted, $unitLabel): array {
            if (str_contains($sql, 'FROM ME_OBJECTS')) { return [['ACTIVE' => 1, 'ALLOW_READ' => 1]]; }
            if (str_contains($sql, 'FROM ME_USER_PERMISSIONS')) { return $granted ? [['USER_ID' => 'tester']] : []; }
            if (str_contains($sql, 'FROM ME_LOG_TRIPS')) {
                self::assertSame(1, $params['company_id']);
                return [['CLAIMED_QUANTITY' => 2, 'INVALID_LINES' => 0]];
            }
            if (str_contains($sql, 'FROM ME_OE_DOCUMENTS')) {
                self::assertStringContainsString('P.UNIT_LABEL', $sql);
                self::assertSame(1, $params['company_id']);
                self::assertSame(61, $params['sales_order_id']);
                self::assertSame(61, $params['sales_line_id']);
                self::assertSame(163, $params['product_id']);
                return [['DOCUMENT_NUMBER' => 'SO-000061', 'LINE_NUMBER' => 3, 'SKU' => 'RF-001', 'UNIT_LABEL' => $unitLabel]];
            }
            self::assertStringContainsString('FROM ME_INV_RESERVATION', $sql);
            self::assertStringContainsString('COMPANY_ID = :company_id', $sql);
            self::assertStringNotContainsString('FOR UPDATE', $sql);
            if ($params['company_id'] !== 1) { return str_contains($sql, 'COUNT(*)') ? [['TOTAL' => 0]] : []; }
            if (str_contains($sql, 'COUNT(*)')) { return [['TOTAL' => $total]]; }
            if (isset($params['reservation_id'])) {
                return array_values(array_filter($rows, static fn (array $row): bool => $row['RESERVATION_ID'] === $params['reservation_id']));
            }
            self::assertStringContainsString('P.UNIT_LABEL', $sql);
            return array_map(static fn (array $row): array => $row + ['DOCUMENT_NUMBER' => 'SO-000061', 'LINE_NUMBER' => 3, 'SKU' => 'RF-001', 'UNIT_LABEL' => $unitLabel], $rows);
        });
        return $db;
    }

    public static function productUnits(): iterable
    {
        yield 'box' => ['box', 'box'];
        yield 'pack' => ['pack', 'pack'];
        yield 'roll' => ['roll', 'roll'];
        yield 'missing unit' => [null, 'Unavailable'];
        yield 'HTML is escaped' => ['<b>box</b>', '&lt;b&gt;box&lt;/b&gt;'];
    }

    #[DataProvider('productUnits')]
    public function testProductUnitIsReadAndRenderedOnListAndDetail(?string $unitLabel, string $display): void
    {
        foreach (['/modules/inventory/reservations', '/modules/inventory/reservations/22'] as $path) {
            $db = $this->connection([$this->row()], 1, unitLabel: $unitLabel);
            $page = (new ReservationWeb($db, new WebPath('/lab')))->handle('GET', $path, [], $this->actor());
            $isList = $path === '/modules/inventory/reservations';
            $data = $isList ? $page['data']['items'][0] : $page['data'];
            self::assertSame($unitLabel ?? 'Unavailable', $data['unit_label']);
            $html = (new Renderer(new WebPath('/lab')))->render($page['view'], $page['title'], 'csrf', $page['data']);
            self::assertStringContainsString($isList ? '>UOM</th>' : '>UOM</dt>', $html);
            self::assertStringContainsString('<span class="reservation-uom">' . $display . '</span>', $html);
            self::assertStringNotContainsString('<b>box</b>', $html);
        }
    }

    public function testOnlyOwnsReservationsPrefixes(): void
    {
        self::assertTrue(ReservationWeb::matches('/modules/inventory/reservations'));
        self::assertTrue(ReservationWeb::matches('/api/inventory/reservations/22'));
        foreach (['/modules/inventory/products', '/api/inventory/products/163', '/modules/inventory/reservationsevil', '/api/v1/inventory/testing/reservations'] as $path) {
            self::assertFalse(ReservationWeb::matches($path));
        }
    }

    public function testListPreservesMountedLinksFiltersAndPagination(): void
    {
        $db = $this->connection([$this->row()], 45);
        $web = new ReservationWeb($db, new WebPath('/users/ding/mini-erp/public'));
        $result = $web->handle('GET', '/modules/inventory/reservations', [], $this->actor(),
            ['status' => 'ACTIVE', 'product_id' => '163', 'page' => '2', 'page_size' => '20']);
        self::assertSame('inventory/reservations/list.tpl', $result['view']);
        self::assertSame(21, $result['data']['first']);
        self::assertSame(40, $result['data']['last']);
        self::assertSame('/users/ding/mini-erp/public/modules/inventory/reservations/22', $result['data']['items'][0]['detail_url']);
        self::assertSame('/users/ding/mini-erp/public/modules/inventory/products/163', $result['data']['items'][0]['product_url']);
        self::assertStringContainsString('status=ACTIVE', $result['data']['next_url']);
        self::assertStringContainsString('product_id=163', $result['data']['next_url']);
        self::assertStringContainsString('page=3', $result['data']['next_url']);
        self::assertSame(2, $result['data']['items'][0]['claimed_quantity']);
        self::assertSame(3, $result['data']['items'][0]['releasable_quantity']);
        self::assertSame('SO-000061', $result['data']['items'][0]['document_number']);
        self::assertSame(3, $result['data']['items'][0]['line_number']);
        self::assertSame('RF-001', $result['data']['items'][0]['sku']);
        self::assertSame('box', $result['data']['items'][0]['unit_label']);
        $columns = array_column($result['data']['columns'], null, 'key');
        self::assertTrue($columns['status']['filtered']);
        self::assertSame('status', $columns['status']['type']);
        self::assertSame('text', $columns['document_number']['type']);
        self::assertSame('numeric', $columns['active_quantity']['type']);
    }

    public function testSearchUsesBindingsAndDeterministicOraclePaging(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::never())->method('execute');
        $db->method('query')->willReturnCallback(static function (string $sql, array $params = []): array {
            if (str_contains($sql, 'FROM ME_OBJECTS')) { return [['ACTIVE' => 1, 'ALLOW_READ' => 1]]; }
            self::assertSame(1, $params['company_id']);
            self::assertSame(61, $params['search_order']);
            self::assertStringContainsString('SALES_LINE_ID = :search_line', $sql);
            self::assertStringNotContainsString('= 61', $sql);
            if (str_contains($sql, 'COUNT(*)')) { return [['TOTAL' => 100]]; }
            self::assertStringContainsString('ORDER BY R.RESERVATION_ID DESC OFFSET :row_offset ROWS FETCH NEXT :page_size ROWS ONLY', $sql);
            self::assertSame(20, $params['row_offset']);
            self::assertSame(10, $params['page_size']);
            return [];
        });
        (new ReservationWeb($db, new WebPath()))->handle('GET', '/api/inventory/reservations', [], $this->actor(),
            ['q' => '61', 'page' => '3', 'page_size' => '10']);
    }

    public function testHistoricalDetailOpensSelectedReleasedCycleInsteadOfLatestActiveCycle(): void
    {
        $db = $this->connection([$this->row(22, 'RELEASED'), $this->row(44)], 2);
        $web = new ReservationWeb($db, new WebPath('/lab'));
        $result = $web->handle('GET', '/modules/inventory/reservations/22', [], $this->actor());
        self::assertSame(22, $result['data']['reservation_id']);
        self::assertSame('RELEASED', $result['data']['status']);
        self::assertSame(5, $result['data']['released_quantity']);
        self::assertSame(0, $result['data']['releasable_quantity']);
    }

    public function testDetailUsesSuppliedRealAdapterWhenAvailable(): void
    {
        $db = $this->connection([$this->row()], 1);
        $web = new ReservationWeb($db, new WebPath(), static function (Actor $actor, $tx, int $order, int $line): int {
            self::assertSame(1, $actor->companyId);
            self::assertSame(61, $order);
            self::assertSame(61, $line);
            return 2;
        });
        $result = $web->handle('GET', '/api/inventory/reservations/22', [], $this->actor());
        self::assertSame(2, $result['result']['claimed_quantity']);
        self::assertSame(3, $result['result']['releasable_quantity']);
    }

    public function testOtherCompanyCannotOpenReservation(): void
    {
        $db = $this->connection([$this->row()], 1);
        $result = (new ReservationWeb($db, new WebPath()))->handle('GET', '/modules/inventory/reservations/22', [], $this->actor(2));
        self::assertSame(404, $result['status']);
        self::assertSame('inventory/reservations/error.tpl', $result['view']);
    }

    public function testPermissionDenialIsShownAsForbidden(): void
    {
        $db = $this->connection(granted: false);
        $result = (new ReservationWeb($db, new WebPath()))->handle('GET', '/modules/inventory/reservations', [], $this->actor(admin: false));
        self::assertSame(403, $result['status']);
    }

    public static function badFilters(): iterable
    {
        yield 'SQL in ID' => [['product_id' => '1 OR 1=1']];
        yield 'array' => [['status' => ['ACTIVE']]];
        yield 'invalid status' => [['status' => 'ANY']];
        yield 'company override' => [['company_id' => '2']];
        yield 'page size' => [['page_size' => '10000']];
        yield 'page zero' => [['page' => '0']];
        yield 'negative active quantity' => [['active_quantity' => '-1']];
        yield 'fractional dispatched quantity' => [['dispatched_quantity' => '1.5']];
        yield 'zero reservation ID' => [['reservation_id' => '0']];
        yield 'invalid line number' => [['line_number' => 'abc']];
    }

    #[DataProvider('badFilters')]
    public function testInvalidFiltersRenderAnErrorWithoutWrites(array $query): void
    {
        $db = $this->connection();
        $result = (new ReservationWeb($db, new WebPath()))->handle('GET', '/modules/inventory/reservations', [], $this->actor(), $query);
        self::assertSame(400, $result['status']);
    }

    public function testWritesAndDummyQueryOverridesAreNotAccepted(): void
    {
        $db = $this->connection();
        $db->expects(self::never())->method('begin');
        $web = new ReservationWeb($db, new WebPath());
        self::assertSame(405, $web->handle('POST', '/modules/inventory/reservations/22', [], $this->actor())['status']);
        self::assertSame(400, $web->handle('GET', '/modules/inventory/reservations/22', [], $this->actor(), ['simulated_claimed_quantity' => '0'])['status']);
    }

    public function testSmartyRendersActualClaimsAndEscapesErrorText(): void
    {
        $db = $this->connection([$this->row()], 1);
        $web = new ReservationWeb($db, new WebPath('/lab'));
        $renderer = new Renderer(new WebPath('/lab'));
        $page = $web->handle('GET', '/modules/inventory/reservations/22', [], $this->actor());
        $html = $renderer->render($page['view'], $page['title'], 'csrf', $page['data'] + ['actor' => $this->actor()]);
        self::assertStringContainsString('Stock is claimed by Logistics', $html);
        self::assertStringNotContainsString('Unknown', $html);
        self::assertStringContainsString('/lab/modules/inventory/products/163', $html);
        self::assertStringContainsString('SO-000061', $html);
        self::assertStringContainsString('RF-001', $html);
        self::assertStringNotContainsString('Sales order ID', $html);
        self::assertStringNotContainsString('Sales line ID', $html);
        self::assertStringContainsString('<dt>Reservation ID</dt>', $html);
        self::assertStringContainsString('<dt>Source Document ID</dt>', $html);
        self::assertStringContainsString('<dt>Source Document Line Number</dt>', $html);
        self::assertStringNotContainsString('Stock Order ID', $html);
        self::assertStringNotContainsString('Stock Line', $html);
        self::assertStringNotContainsString('Product ID', $html);
        self::assertStringNotContainsString('/testing/', $html);
        $error = $renderer->render('inventory/reservations/error.tpl', 'Reservations', 'csrf',
            ['message' => '<script>alert(1)</script>', 'code' => 'INVALID_STATE', 'list_url' => '/lab/modules/inventory/reservations']);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $error);
    }

    public function testSmartyRendersEmptyListAndFilteredPagination(): void
    {
        $db = $this->connection();
        $page = (new ReservationWeb($db, new WebPath('/lab')))->handle('GET', '/modules/inventory/reservations', [], $this->actor());
        $html = (new Renderer(new WebPath('/lab')))->render($page['view'], $page['title'], 'csrf', $page['data']);
        self::assertStringContainsString('No reservations found.', $html);
        self::assertStringContainsString('Page 1 of 1', $html);
        self::assertStringContainsString('action="/lab/modules/inventory/reservations"', $html);
    }

    public function testKernelDelegationUsesSessionAuthenticationAndMountedSmartyPage(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::never())->method('execute');
        $db->method('query')->willReturnCallback(static function (string $sql, array $params = []): array {
            if (str_contains($sql, 'FROM ME_USERS')) {
                return [['ID' => 'tester', 'USERNAME' => 'tester', 'IS_ADMIN' => 1, 'SECURITY_VERSION' => 1,
                    'MUST_CHANGE_PASSWORD' => 0, 'COMPANY_ID' => 1, 'COMPANY_NAME' => 'SIA-MINI-ERP']];
            }
            if (str_contains($sql, 'FROM ME_USER_ROLES')) { return []; }
            if (str_contains($sql, 'FROM ME_OBJECTS')) { return [['ACTIVE' => 1, 'ALLOW_READ' => 1]]; }
            self::assertStringContainsString('FROM ME_INV_RESERVATION', $sql);
            self::assertSame(1, $params['company_id']);
            return str_contains($sql, 'COUNT(*)') ? [['TOTAL' => 0]] : [];
        });
        $session = ['actor_id' => 'tester', 'security_version' => 1, 'company_id' => 1];
        $kernel = new Kernel(Config::fromArray(['APP_BASE_PATH' => '/lab']), $session, $db);
        $page = $kernel->handle('GET', '/lab/modules/inventory/reservations', []);
        self::assertSame(200, $page['status']);
        self::assertStringContainsString('No reservations found.', $page['body']);
        self::assertStringContainsString('/lab/assets/inventory-reservations.css', $page['body']);
        $api = $kernel->handle('GET', '/lab/api/inventory/reservations', []);
        self::assertSame(200, $api['status']);
        self::assertSame(0, json_decode($api['body'], true, flags: JSON_THROW_ON_ERROR)['result']['total']);
        self::assertSame(405, $kernel->handle('POST', '/lab/api/inventory/reservations', [])['status']);
    }

    public function testAnonymousBrowserCannotReadReservationPages(): void
    {
        $session = [];
        $kernel = new Kernel(Config::fromArray([]), $session);
        self::assertSame(403, $kernel->handle('GET', '/modules/inventory/reservations', [])['status']);
        self::assertSame(403, $kernel->handle('GET', '/api/inventory/reservations', [])['status']);
    }

    public function testHumanReferenceFiltersUseBoundLiteralSearchAndCompanyScopedJoins(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::never())->method('execute');
        $db->method('query')->willReturnCallback(static function (string $sql, array $params = []): array {
            if (str_contains($sql, 'FROM ME_OBJECTS')) { return [['ACTIVE' => 1, 'ALLOW_READ' => 1]]; }
            self::assertSame(1, $params['company_id']);
            self::assertSame('%RF-001%', $params['search_sku']);
            self::assertSame('%RF!_001%', $params['sku']);
            self::assertSame('%SO-00!%1%', $params['document_number']);
            self::assertArrayNotHasKey('search_order', $params);
            self::assertStringContainsString('D.CNY# = R.COMPANY_ID AND D.RECORD# = R.SALES_ORDER_ID', $sql);
            self::assertStringContainsString('S.CNY# = R.COMPANY_ID', $sql);
            self::assertStringContainsString('P.COMPANY_ID = R.COMPANY_ID', $sql);
            self::assertStringContainsString('UPPER(P.SKU) LIKE :sku', $sql);
            self::assertStringNotContainsString('rf_001', $sql);
            return str_contains($sql, 'COUNT(*)') ? [['TOTAL' => 0]] : [];
        });
        $result = (new ReservationWeb($db, new WebPath()))->handle('GET', '/api/inventory/reservations', [], $this->actor(),
            ['q' => 'RF-001', 'sku' => 'rf_001', 'document_number' => 'SO-00%1']);
        self::assertSame(200, $result['status']);
    }

    public function testListRendersBusinessReferencesAndOnlyRequestedQuantities(): void
    {
        $db = $this->connection([$this->row(22, 'DISPATCHED')], 1);
        $page = (new ReservationWeb($db, new WebPath('/lab')))->handle('GET', '/modules/inventory/reservations', [], $this->actor());
        $html = (new Renderer(new WebPath('/lab')))->render($page['view'], $page['title'], 'csrf', $page['data']);
        self::assertStringContainsString('SO-000061', $html);
        self::assertStringContainsString('RF-001', $html);
        self::assertStringContainsString('<td class="reservation-number">3</td>', $html);
        self::assertStringContainsString('reservation-header-text">Reservation ID</span>', $html);
        self::assertStringContainsString('reservation-header-text">Source Document ID</span>', $html);
        self::assertStringContainsString('reservation-header-text">Source Document Line<br>Number</span>', $html);
        self::assertStringNotContainsString('Stock Order ID', $html);
        self::assertStringContainsString('Dispatched', $html);
        self::assertStringContainsString('data-filter-column="document_number"', $html);
        self::assertStringContainsString('data-filter-type="status"', $html);
        self::assertStringContainsString('/lab/assets/inventory-reservations.js?v=2', $html);
        self::assertStringNotContainsString('Original qty', $html);
        self::assertStringNotContainsString('Releasable', $html);
        self::assertStringNotContainsString('<td>61</td>', $html);
    }

    public function testColumnFiltersBindExactNumbersIncludingZeroBeforePagination(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::never())->method('execute');
        $db->method('query')->willReturnCallback(static function (string $sql, array $params = []): array {
            if (str_contains($sql, 'FROM ME_OBJECTS')) { return [['ACTIVE' => 1, 'ALLOW_READ' => 1]]; }
            self::assertSame(1, $params['company_id']);
            self::assertSame(22, $params['reservation_id']);
            self::assertSame(3, $params['line_number']);
            self::assertSame(0, $params['active_quantity']);
            self::assertSame(2, $params['dispatched_quantity']);
            self::assertStringContainsString('(S.LINE_NUMBER) = :line_number', $sql);
            self::assertStringContainsString("CASE WHEN R.RESERVATION_STATUS = 'ACTIVE' THEN R.RESERVED_QUANTITY ELSE 0 END", $sql);
            self::assertStringContainsString("CASE WHEN R.RESERVATION_STATUS = 'DISPATCHED' THEN R.RESERVED_QUANTITY ELSE 0 END", $sql);
            return str_contains($sql, 'COUNT(*)') ? [['TOTAL' => 0]] : [];
        });
        $result = (new ReservationWeb($db, new WebPath()))->handle('GET', '/api/inventory/reservations', [], $this->actor(),
            ['reservation_id' => '22', 'line_number' => '3', 'active_quantity' => '0', 'dispatched_quantity' => '2']);
        self::assertSame(200, $result['status']);
        self::assertSame('0', $result['result']['filters']['active_quantity']);
    }
}
