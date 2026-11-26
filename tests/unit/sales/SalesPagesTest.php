<?php
declare(strict_types=1);

namespace MiniErp\Tests\unit\sales;

use MiniErp\sales\SalesPages;
use MiniErp\sales\invoices\InvoicePages;
use MiniErp\sales\invoices\InvoiceService;
use MiniErp\shared\AppError;
use MiniErp\shared\Config;
use MiniErp\shared\Kernel;
use MiniErp\shared\WebPath;
use MiniErp\shared\database\Connection;
use MiniErp\shared\security\Actor;
use MiniErp\Tests\unit\sales\fixtures\InvoiceMemoryConnection;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/CustomerServiceTest.php';

final class SalesPagesTest extends TestCase
{
    private function actor(bool $admin = true): Actor
    {
        return new Actor('admin', 'admin', [], 7, $admin);
    }

    private function invoiceConnection(InvoiceMemoryConnection $memory): Connection
    {
        $db = $this->createMock(Connection::class);

        $db->method('query')->willReturnCallback(
            static function (string $sql, array $binds = []) use ($memory): array {
                if (str_contains($sql, 'FROM ME_INV_PRODUCTS') && !isset($binds['product_id'])) {
                    $ids = $binds;
                    unset($ids['company_id']);

                    return array_values(array_filter(
                        $memory->products,
                        static fn (array $p): bool =>
                            $p['COMPANY_ID'] === $binds['company_id']
                            && in_array((string) $p['PRODUCT_ID'], $ids, true),
                    ));
                }

                return $memory->query($sql, $binds);
            },
        );

        foreach (['begin', 'commit', 'rollback'] as $method) {
            $db->method($method)->willReturnCallback($memory->$method(...));
        }

        $db->method('execute')->willReturnCallback($memory->execute(...));

        return $db;
    }

    private function source(InvoiceMemoryConnection $db): void
    {
        $db->documents[100] = $db->document(100, 'SO-1', 'SALES_ORDER', 'SUBMITTED');
        $db->lines[101] = $db->line(101, 100, 1, 163, '<Product>', '2', '10.25');
    }

    public function testKernelDelegatesSalesAndPreservesMountedUrlsAndOtherModules(): void
    {
        $session = [
            'actor_id' => 'admin',
            'company_id' => 7,
            'security_version' => 1,
            'csrf_token' => 'csrf',
        ];

        $kernel = new Kernel(
            Config::fromArray(['APP_BASE_PATH' => '/training']),
            $session,
            new InvoiceMemoryConnection(),
        );

        $response = $kernel->handle('GET', '/training/modules/sales', []);

        self::assertSame(200, $response['status']);
        self::assertStringContainsString('href="/training/modules/sales/customers"', $response['body']);
        self::assertStringContainsString('href="/training/modules/sales/invoices"', $response['body']);
        self::assertStringContainsString('href="/training/modules/sales/quotes"', $response['body']);
        self::assertStringContainsString('href="/training/modules/sales/orders"', $response['body']);
        self::assertStringContainsString('href="/training/modules/sales/payments"', $response['body']);

        self::assertSame(
            200,
            $kernel->handle('GET', '/training/modules/inventory', [])['status'],
        );

        self::assertSame(
            404,
            $kernel->handle('GET', '/training/modules/sales/customers-extra', [])['status'],
        );

        $anonymous = [];
        $kernel = new Kernel(Config::fromArray([]), $anonymous);

        self::assertSame(403, $kernel->handle('GET', '/modules/sales/customers', [])['status']);
        self::assertSame(403, $kernel->handle('GET', '/modules/sales/invoices', [])['status']);
    }

    public function testLandingShowsOnlyPermittedSections(): void
    {
        $db = new InvoiceMemoryConnection();
        $db->grants = ['7:admin:sales.customers:read'];

        $pages = new SalesPages($db, new WebPath(), 'csrf');
        $response = $pages->handle('GET', '/modules/sales', $this->actor(false), [], []);

        self::assertStringContainsString('href="/modules/sales/customers"', $response['body']);
        self::assertStringNotContainsString('href="/modules/sales/invoices"', $response['body']);

        $this->expectException(AppError::class);
        $pages->handle('GET', '/modules/sales/invoices/new', $this->actor(false), [], []);
    }

    public function testCustomerCreateEditListAndEscapingThroughSalesRouter(): void
    {
        $db = new CustomerMemoryConnection();
        $pages = new SalesPages($db, new WebPath('/training'), 'csrf');
        $base = '/modules/sales/customers';

        self::assertSame(
            200,
            $pages->handle('GET', $base . '/new', $this->actor(), [], [])['status'],
        );

        $response = $pages->handle('POST', $base . '/new', $this->actor(), [
            'csrf_token' => 'csrf',
            'action_reference' => 'customer-create',
            'customer_name' => '<Customer>',
            'billing_address_line_1' => '12 Market Road',
            'city' => 'Bengaluru',
            'country' => 'India',
        ], []);

        self::assertSame(303, $response['status']);
        self::assertSame('/training/modules/sales/customers/1', $response['headers']['Location']);

        $response = $pages->handle('POST', $base . '/1/edit', $this->actor(), [
            'csrf_token' => 'csrf',
            'action_reference' => 'customer-edit',
            'contact_name' => 'Updated contact',
        ], []);

        self::assertSame(303, $response['status']);

        $html = $pages->handle('GET', $base . '/1', $this->actor(), [], [])['body'];

        self::assertStringContainsString('&lt;Customer&gt;', $html);
        self::assertStringContainsString('Updated contact', $html);
        self::assertStringContainsString(
            '&lt;Customer&gt;',
            $pages->handle('GET', $base, $this->actor(), [], [])['body'],
        );
    }

    public function testInvalidCsrfCannotWriteCustomersOrInvoices(): void
    {
        foreach (['customers', 'invoices'] as $section) {
            $db = $this->createMock(Connection::class);
            $db->expects(self::never())->method('query');
            $db->expects(self::never())->method('begin');

            try {
                (new SalesPages($db, new WebPath(), 'csrf'))->handle(
                    'POST',
                    '/modules/sales/' . $section . '/new',
                    $this->actor(),
                    ['csrf_token' => 'wrong'],
                    [],
                );

                self::fail('Expected CSRF rejection');
            } catch (AppError $error) {
                self::assertSame(403, $error->status);
            }
        }
    }

    public function testInvoiceCreatesFromOrderAndEditsOnlyHeader(): void
    {
        $memory = new InvoiceMemoryConnection();
        $this->source($memory);
        $db = $this->invoiceConnection($memory);

        $pages = new InvoicePages(
            $db,
            new WebPath('/training'),
            'csrf',
            static fn (): string => '2026-11-25',
        );

        $base = '/modules/sales/invoices';
        $html = $pages->handle('GET', $base . '/new', $this->actor(), [], [])['body'];

        self::assertStringContainsString('name="source_order_number"', $html);
        self::assertStringNotContainsString('name="customer_code"', $html);

        $response = $pages->handle('POST', $base . '/new', $this->actor(), [
            'csrf_token' => 'csrf',
            'action_reference' => 'invoice-create',
            'source_order_number' => 'SO-1',
        ], []);

        self::assertSame(303, $response['status']);

        $number = basename($response['headers']['Location']);
        $html = $pages->handle('GET', $base . '/' . $number . '/edit', $this->actor(), [], [])['body'];

        self::assertStringContainsString('&lt;Product&gt;', $html);
        self::assertStringContainsString('name="expected_version" value="1"', $html);
        self::assertStringNotContainsString('name="lines[', $html);
        self::assertStringNotContainsString('name="source_order_number"', $html);

        $lines = $memory->lines;

        $response = $pages->handle('POST', $base . '/' . $number . '/edit', $this->actor(), [
            'csrf_token' => 'csrf',
            'action_reference' => 'invoice-edit',
            'expected_version' => '1',
            'invoice_date' => '2026-11-25',
            'due_date' => '',
            'notes' => 'Updated notes',
        ], []);

        self::assertSame(303, $response['status']);

        $saved = (new InvoiceService($db))->get($this->actor(), $number);

        self::assertSame(2, $saved['current_version']);
        self::assertSame('Updated notes', $saved['notes']);
        self::assertSame('2026-12-25', $saved['due_date']);
        self::assertSame($lines, $memory->lines);

        $response = $pages->handle('POST', $base . '/' . $number . '/edit', $this->actor(), [
            'csrf_token' => 'csrf',
            'action_reference' => 'invoice-stale',
            'expected_version' => '1',
            'notes' => '<Unsaved>',
        ], []);

        self::assertSame(409, $response['status']);
        self::assertStringContainsString('name="expected_version" value="1"', $response['body']);
        self::assertStringContainsString('&lt;Unsaved&gt;', $response['body']);
        self::assertStringContainsString('value="invoice-stale"', $response['body']);
        self::assertSame(
            'Updated notes',
            (new InvoiceService($db))->get($this->actor(), $number)['notes'],
        );

        $detail = $pages->handle('GET', $base . '/' . $number, $this->actor(), [], []);

        self::assertStringContainsString(
            '/modules/sales/payments/history?invoice_number=',
            $detail['body'],
        );

        self::assertStringNotContainsString(
            '/modules/sales/payments/new?invoice_number=',
            $detail['body'],
        );
    }

    public function testInvoiceConfirmationActionsUseVersionsAndExistingWorkflow(): void
    {
        $memory = new InvoiceMemoryConnection();
        $this->source($memory);
        $db = $this->invoiceConnection($memory);

        $service = new InvoiceService($db, static fn (): string => '2026-11-25');
        $invoice = $service->create($this->actor(), ['source_order_number' => 'SO-1']);
        $number = $invoice['invoice_number'];

        $pages = new InvoicePages(
            $db,
            new WebPath('/training'),
            'csrf',
            static fn (): string => '2026-11-25',
        );

        $base = '/modules/sales/invoices/' . $number;

        foreach (['submit', 'move-to-draft', 'delete'] as $index => $action) {
            $response = $pages->handle('GET', $base . '/' . $action, $this->actor(), [], []);

            self::assertSame(200, $response['status']);
            self::assertStringContainsString('name="expected_version"', $response['body']);

            $response = $pages->handle('POST', $base . '/' . $action, $this->actor(), [
                'csrf_token' => 'csrf',
                'expected_version' => (string) ($index + 1),
                'action_reference' => 'action-' . $action,
            ], []);

            self::assertSame(303, $response['status']);
            self::assertSame(
                $action === 'submit' ? '20.50' : '0.00',
                $memory->customers[1]['BALANCE'],
            );
        }

        self::assertSame('/training/modules/sales/invoices', $response['headers']['Location']);
        self::assertCount(1, $memory->documents);
        self::assertSame([], $memory->links);
    }

    public function testInvoiceListRendersAndReportsInvalidFilters(): void
    {
        $db = $this->createMock(Connection::class);
        $memory = new InvoiceMemoryConnection();

        $db->method('query')->willReturnCallback(
            static function (string $sql, array $binds = []) use ($memory): array {
                if (str_contains($sql, 'WITH LINE_TOTALS')) {
                    return [];
                }

                return $memory->query($sql, $binds);
            },
        );

        $pages = new SalesPages($db, new WebPath('/training'), 'csrf');
        $response = $pages->handle('GET', '/modules/sales/invoices', $this->actor(), [], []);

        self::assertSame(200, $response['status']);
        self::assertStringContainsString('No invoices match your search.', $response['body']);
        self::assertStringContainsString(
            'href="/training/modules/sales/invoices/new"',
            $response['body'],
        );

        $response = $pages->handle(
            'GET',
            '/modules/sales/invoices',
            $this->actor(),
            [],
            ['status' => '<Invalid>'],
        );

        self::assertSame(400, $response['status']);
        self::assertStringContainsString('role="alert"', $response['body']);
    }
}