<?php
declare(strict_types=1);

namespace MiniErp\Tests\unit\sales;

use MiniErp\sales\invoices\InvoiceService;
use MiniErp\shared\AppError;
use MiniErp\shared\database\Connection;
use MiniErp\shared\security\Actor;
use PHPUnit\Framework\TestCase;

final class InvoiceServiceTest extends TestCase
{
    public function testEligibleOrdersIsReadOnlyScopedAndPreservesExactSourceTotals(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::never())->method('execute');
        $db->expects(self::never())->method('executeSchema');
        $db->expects(self::never())->method('begin');
        $grants = [];
        $db->expects(self::exactly(3))->method('query')->willReturnCallback(
            static function (string $sql, array $p) use (&$grants): array {
                if (str_contains($sql, 'FROM ME_OBJECTS')) {
                    $grants[] = $p['object'];
                    return [['ACTIVE' => 1, 'ALLOW_CREATE' => 1, 'ALLOW_READ' => 1]];
                }
                self::assertStringContainsString("D.DOCUMENT_TYPE = 'SALES_ORDER'", $sql);
                self::assertStringContainsString("D.STATUS = 'SUBMITTED'", $sql);
                self::assertStringContainsString('S.CNY# = D.CNY#', $sql);
                self::assertStringContainsString('S.SOURCE_DOCUMENT_RECORD# = D.RECORD#', $sql);
                self::assertStringContainsString('NOT EXISTS', $sql);
                self::assertStringContainsString('D.CNY# = :company_id', $sql);
                self::assertStringContainsString('L.CNY# = D.CNY#', $sql);
                self::assertStringNotContainsString('FOR UPDATE', $sql);
                self::assertSame(['company_id' => 7, 'offset_rows' => 10, 'limit_rows' => 10,
                    'customer_code' => 'CUST-0023', 'warehouse_id' => 'W01',
                    'from_date' => '2026-11-01', 'to_date' => '2026-11-25'], $p);
                return [['ORDER_NUMBER' => 'SO-22', 'CUSTOMER_CODE' => 'CUST-0023', 'CUSTOMER_NAME' => 'Customer',
                    'WAREHOUSE_ID' => 'W01', 'ORDER_DATE' => '2026-11-25', 'SHIPPING_ADDRESS' => null,
                    'PAYMENT_TERMS' => 'NET_30', 'NOTES' => null, 'VERSION' => '2',
                    'DOCUMENT_TOTAL' => '9999999999999999.99']];
            });
        $result = (new InvoiceService($db))->eligibleOrders(new Actor('admin', 'admin', [], 7, true),
            ['page' => '2', 'page_size' => '10', 'customer_code' => 'CUST-0023', 'warehouse_id' => 'W01',
                'from_date' => '2026-11-01', 'to_date' => '2026-11-25']);
        self::assertSame(['sales.invoices', 'sales.orders'], $grants);
        self::assertSame(2, $result['page']);
        self::assertSame(10, $result['page_size']);
        self::assertSame('Submitted', $result['items'][0]['status']);
        self::assertSame('SO-22', $result['items'][0]['order_number']);
        self::assertSame('9999999999999999.99', $result['items'][0]['document_total']);
        self::assertSame('', $result['items'][0]['notes']);
        self::assertSame(2, $result['items'][0]['current_version']);
    }

    public function testEligibleOrdersRequiresBothCreateInvoiceAndReadOrderGrants(): void
    {
        foreach (['sales.invoices', 'sales.orders'] as $denied) {
            $db = $this->createMock(Connection::class);
            $db->expects(self::never())->method('execute');
            $db->method('query')->willReturnCallback(static function (string $sql, array $p) use ($denied): array {
                self::assertStringContainsString('FROM ME_OBJECTS', $sql, 'Denied requests must not read Order data.');
                return [['ACTIVE' => 1, 'ALLOW_CREATE' => $p['object'] === $denied ? 0 : 1,
                    'ALLOW_READ' => $p['object'] === $denied ? 0 : 1]];
            });
            try {
                (new InvoiceService($db))->eligibleOrders(new Actor('admin', 'admin', [], 7, true));
                self::fail('Both permissions are required.');
            } catch (AppError $error) { self::assertSame('FORBIDDEN', $error->errorCode); }
        }
    }

    public function testEligibleOrdersRejectsUnsupportedStatusAndInvalidFilters(): void
    {
        foreach ([['status' => 'Draft'], ['unknown' => 'x'], ['page' => '0'], ['page_size' => '101'],
            ['from_date' => '2026-02-30'], ['from_date' => '2026-11-25', 'to_date' => '2026-11-01']] as $query) {
            $db = $this->createMock(Connection::class);
            $db->expects(self::never())->method('execute');
            $db->method('query')->willReturnCallback(static function (string $sql): array {
                self::assertStringContainsString('FROM ME_OBJECTS', $sql, 'Invalid filters must be rejected before reading Order data.');
                return [['ACTIVE' => 1, 'ALLOW_CREATE' => 1, 'ALLOW_READ' => 1]];
            });
            try {
                (new InvoiceService($db))->eligibleOrders(new Actor('admin', 'admin', [], 7, true), $query);
                self::fail('Invalid lookup filters must be rejected.');
            } catch (AppError $error) { self::assertSame('VALIDATION_ERROR', $error->errorCode); }
        }
    }

    public function testListScopesCompanyAndPreservesExactAmounts(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::never())->method('execute');
        $db->expects(self::never())->method('executeSchema');

        $db->expects(self::exactly(2))
            ->method('query')
            ->willReturnCallback(
                static function (string $sql, array $binds): array {
                    if (str_contains($sql, 'FROM ME_OBJECTS')) {
                        self::assertSame(
                            'sales.invoices',
                            $binds['object'],
                        );

                        return [['ACTIVE' => 1, 'ALLOW_READ' => 1]];
                    }

                    self::assertStringContainsString(
                        "D.DOCUMENT_TYPE = 'SALES_INVOICE'",
                        $sql,
                    );
                    self::assertStringContainsString(
                        'D.CNY# = :company_id',
                        $sql,
                    );
                    self::assertStringContainsString(
                        'C.CNY# = D.CNY#',
                        $sql,
                    );

                    foreach ([
                        'company_id',
                        'line_company_id',
                        'payment_company_id',
                    ] as $key) {
                        self::assertSame(7, $binds[$key]);
                    }

                    self::assertSame(10, $binds['offset_rows']);
                    self::assertSame(10, $binds['limit_rows']);
                    self::assertSame('SUBMITTED', $binds['status']);

                    return [[
                        'INVOICE_NUMBER' => 'INV-0001',
                        'STATUS' => 'SUBMITTED',
                        'VERSION' => '2',
                        'CUSTOMER_CODE' => 'CUST-0001',
                        'WAREHOUSE_ID' => 'WH-01',
                        'INVOICE_DATE' => '2026-11-24',
                        'DUE_DATE' => '2026-12-24',
                        'PAYMENT_TERMS' => 'NET_30',
                        'NOTES' => null,
                        'SHIPPING_ADDRESS' => null,
                        'SOURCE_ORDER_NUMBER' => null,
                        'DOCUMENT_TOTAL' => '9999999999999999.99',
                        'TOTAL_PAID' => '0.00',
                        'AMOUNT_DUE' => '9999999999999999.99',
                        'PAYMENT_STATUS' => 'Unpaid',
                    ]];
                },
            );

        $actor = new Actor('admin', 'admin', [], 7, true);

        $result = (new InvoiceService($db))->list($actor, [
            'page' => '2',
            'page_size' => '10',
            'status' => 'Submitted',
        ]);

        self::assertSame(2, $result['page']);
        self::assertSame(10, $result['page_size']);
        self::assertCount(1, $result['items']);

        $invoice = $result['items'][0];

        self::assertSame('Submitted', $invoice['status']);
        self::assertSame(2, $invoice['current_version']);
        self::assertSame(
            '9999999999999999.99',
            $invoice['document_total'],
        );
        self::assertSame(
            '9999999999999999.99',
            $invoice['amount_due'],
        );
        self::assertSame('', $invoice['notes']);
        self::assertNull($invoice['source_order_number']);
    }

    public function testUserWithoutReadPermissionCannotListInvoices(): void
    {
        $db = $this->createMock(Connection::class);

        $db->expects(self::exactly(2))
            ->method('query')
            ->willReturnCallback(
                static function (string $sql): array {
                    if (str_contains($sql, 'FROM ME_OBJECTS')) {
                        return [['ACTIVE' => 1, 'ALLOW_READ' => 1]];
                    }

                    self::assertStringContainsString(
                        'FROM ME_USER_PERMISSIONS',
                        $sql,
                    );

                    return [];
                },
            );

        $actor = new Actor('user', 'user', [], 7);

        try {
            (new InvoiceService($db))->list($actor);
            self::fail('Listing must require Invoice read permission.');
        } catch (AppError $error) {
            self::assertSame('FORBIDDEN', $error->errorCode);
            self::assertSame(403, $error->status);
        }
    }
}
