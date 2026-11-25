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