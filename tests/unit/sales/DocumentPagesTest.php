<?php
declare(strict_types=1);

namespace MiniErp\Tests\unit\sales;

use MiniErp\sales\DocumentPages;
use MiniErp\sales\SalesPages;
use MiniErp\sales\orders\OrderService;
use MiniErp\sales\orders\OrderInventory;
use MiniErp\sales\orders\OrderLogistics;
use MiniErp\sales\quotes\QuoteService;
use MiniErp\shared\AppError;
use MiniErp\shared\WebPath;
use MiniErp\shared\database\Connection;
use MiniErp\shared\database\TransactionContext;
use MiniErp\shared\security\Actor;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/QuoteServiceTest.php';
require_once __DIR__ . '/OrderServiceTest.php';

final class DocumentPagesTest extends TestCase
{
    private function actor(bool $admin = true, int $company = 7): Actor { return new Actor('admin', 'admin', [], $company, $admin); }
    private function request(string $reference, array $data = []): array { return ['csrf_token' => 'csrf', 'action_reference' => $reference, ...$data]; }

    private function connection(QuoteMemoryConnection|OrderMemoryConnection $memory): Connection
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(static function (string $sql, array $binds = []) use ($memory): array {
            if (str_contains($sql, 'SELECT PRODUCT_ID, SKU FROM ME_INV_PRODUCTS')) {
                TestCase::assertSame(['company_id' => 7], $binds);
                return [['PRODUCT_ID' => '163', 'SKU' => 'Test SKU']];
            }
            return $memory->query($sql, $binds);
        });
        foreach (['begin', 'commit', 'rollback'] as $method) { $db->method($method)->willReturnCallback($memory->$method(...)); }
        $db->method('execute')->willReturnCallback($memory->execute(...));
        return $db;
    }

    private function quoteInput(): array
    {
        return $this->request('quote-create', ['customer_code' => 'CUST-001', 'warehouse_id' => 'W01', 'quote_date' => '2026-11-23',
            'valid_until' => '', 'requested_delivery_date' => '', 'shipping_address' => '', 'payment_terms' => '', 'lines_present' => '1',
            'lines' => [['line_id' => '', 'product_id' => '163', 'description' => '<Item>', 'quantity' => '3', 'unit_price' => '12.50']]]);
    }

    public function testQuoteFormCreateEditConflictAndCompleteLifecycle(): void
    {
        $memory = new QuoteMemoryConnection();
        $db = $this->connection($memory);
        $pages = new DocumentPages($db, new WebPath('/training'), 'csrf', 'quote', new QuoteService($db));
        $base = '/modules/sales/quotes';
        $form = $pages->handle('GET', $base . '/new', $this->actor(), [], []);
        self::assertSame(200, $form['status']);
        self::assertStringContainsString('action="/training/modules/sales/quotes/new"', $form['body']);
        self::assertStringContainsString('data-add-line', $form['body']);
        $response = $pages->handle('POST', $base . '/new', $this->actor(), $this->quoteInput(), []);
        self::assertSame(303, $response['status']);
        $number = basename($response['headers']['Location']);
        $path = $base . '/' . $number;
        $html = $pages->handle('GET', $path, $this->actor(), [], [])['body'];
        self::assertStringContainsString('&lt;Item&gt;', $html);
        self::assertStringContainsString('data-document-back', $html);
        self::assertStringContainsString('src="/training/assets/sales/document-navigation.js"', $html);
        self::assertStringContainsString('<dt>Customer code</dt><dd>CUST-001</dd>', $html);
        self::assertStringContainsString('<dt>Customer name</dt><dd>Example Traders</dd>', $html);
        $form = $pages->handle('GET', $path . '/edit', $this->actor(), [], []);
        self::assertStringContainsString('name="expected_version" value="1"', $form['body']);
        $response = $pages->handle('POST', $path . '/edit', $this->actor(), $this->request('quote-edit', [
            'expected_version' => '1', 'quote_date' => '2026-11-24', 'notes' => 'Header-only update',
        ]), []);
        self::assertSame(303, $response['status']);
        $response = $pages->handle('POST', $path . '/edit', $this->actor(), $this->request('stale-edit', [
            'expected_version' => '1', 'notes' => '<Unsaved>',
        ]), []);
        self::assertSame(409, $response['status']);
        self::assertStringContainsString('&lt;Unsaved&gt;', $response['body']);
        self::assertStringContainsString('name="expected_version" value="1"', $response['body']);
        self::assertStringContainsString('value="stale-edit"', $response['body']);
        foreach (['submit', 'move-to-draft', 'delete'] as $index => $action) {
            self::assertSame(200, $pages->handle('GET', $path . '/' . $action, $this->actor(), [], [])['status']);
            $input = $this->request('quote-' . $action, ['expected_version' => (string) ($index + 2)]);
            self::assertSame(303, $pages->handle('POST', $path . '/' . $action, $this->actor(), $input, [])['status']);
            self::assertSame(303, $pages->handle('POST', $path . '/' . $action, $this->actor(), $input, [])['status']);
            if ($action === 'submit') {
                $html = $pages->handle('GET', $path, $this->actor(), [], [])['body'];
                self::assertStringNotContainsString('Edit Draft</a>', $html);
                self::assertStringContainsString('Create Order', $html);
            }
        }
        self::assertSame([], $memory->documents);
    }

    public function testRemovingEveryLineIsAnErrorAndCannotSilentlyPreserveSavedLines(): void
    {
        $memory = new QuoteMemoryConnection();
        $db = $this->connection($memory);
        $pages = new DocumentPages($db, new WebPath(), 'csrf', 'quote');
        $response = $pages->handle('POST', '/modules/sales/quotes/new', $this->actor(), $this->quoteInput(), []);
        $number = basename($response['headers']['Location']);
        $lines = $memory->lines;
        $response = $pages->handle('POST', '/modules/sales/quotes/' . $number . '/edit', $this->actor(),
            $this->request('empty-lines', ['expected_version' => '1', 'lines_present' => '1']), []);
        self::assertSame(400, $response['status']);
        self::assertStringContainsString('Add at least one item line.', $response['body']);
        self::assertSame($lines, $memory->lines);
    }

    public function testOrderCopiesQuoteEditsLinesAndHandlesReservationFailureAndReturn(): void
    {
        $memory = new OrderMemoryConnection();
        $db = $this->connection($memory);
        $inventory = new class($memory) implements OrderInventory {
            public function __construct(private OrderMemoryConnection $memory) {}
            public function reserve(TransactionContext $tx, Actor $actor, string $orderId, string $warehouseId, array $lines): void
            {
                if ($this->memory->failReserve) { throw new AppError('INSUFFICIENT_STOCK', 'Not enough available stock.', 409); }
                $this->memory->reservations[$orderId] = $lines;
            }
            public function release(TransactionContext $tx, Actor $actor, string $orderId): void { unset($this->memory->reservations[$orderId]); }
        };
        $logistics = new class($memory) implements OrderLogistics {
            public function __construct(private OrderMemoryConnection $memory) {}
            public function assertUnassigned(TransactionContext $tx, Actor $actor, string $orderId): void
            {
                if ($this->memory->assigned) { throw new AppError('INVALID_STATE', 'Order has an assigned Trip.', 409); }
            }
        };
        $service = new OrderService($db, $inventory, $logistics, static fn (): string => '2026-11-25');
        $pages = new DocumentPages($db, new WebPath('/training'), 'csrf', 'order', orders: $service);
        $base = '/modules/sales/orders';
        $form = $pages->handle('GET', $base . '/new', $this->actor(), [], ['source_quote_number' => 'SQ-000000000000010']);
        self::assertSame(200, $form['status']);
        self::assertStringContainsString('value="SQ-000000000000010"', $form['body']);
        self::assertStringNotContainsString('name="lines[', $form['body']);
        self::assertStringNotContainsString('customer_po_number', $form['body']);
        $response = $pages->handle('POST', $base . '/new', $this->actor(), $this->request('order-create', [
            'source_quote_number' => 'SQ-000000000000010', 'order_date' => '', 'requested_delivery_date' => '', 'notes' => '', 'customer_po_number' => '',
        ]), []);
        self::assertSame(303, $response['status']);
        $number = basename($response['headers']['Location']);
        $path = $base . '/' . $number;
        $saved = $service->get($this->actor(), $number);
        $line = $saved['lines'][0];
        $form = $pages->handle('GET', $path . '/edit', $this->actor(), [], []);
        self::assertStringContainsString('name="lines[0][line_id]" value="' . $line['line_id'] . '"', $form['body']);
        self::assertStringNotContainsString('name="customer_code"', $form['body']);
        self::assertStringNotContainsString('customer_po_number', $form['body']);
        $response = $pages->handle('POST', $path . '/edit', $this->actor(), $this->request('order-edit', [
            'expected_version' => '1', 'notes' => '<Saved notes>', 'lines_present' => '1', 'lines' => [
                2 => ['line_id' => $line['line_id'], 'product_id' => $line['product_id'], 'description' => 'Updated line', 'quantity' => '2', 'unit_price' => '12.50'],
                5 => ['line_id' => '', 'product_id' => '163', 'description' => 'New line', 'quantity' => '1', 'unit_price' => '1.00'],
            ],
        ]), []);
        self::assertSame(303, $response['status']);
        $saved = $service->get($this->actor(), $number);
        self::assertSame('26.00', $saved['document_total']);
        self::assertSame($line['source_line_id'], $saved['lines'][0]['source_line_id']);
        self::assertNull($saved['lines'][1]['source_line_id']);
        $memory->failReserve = true;
        $submit = $this->request('order-submit', ['expected_version' => '2']);
        $response = $pages->handle('POST', $path . '/submit', $this->actor(), $submit, []);
        self::assertSame(409, $response['status']);
        self::assertStringContainsString('Not enough available stock.', $response['body']);
        self::assertSame('Draft', $service->get($this->actor(), $number)['status']);
        self::assertSame([], $memory->reservations);
        $memory->failReserve = false;
        self::assertSame(303, $pages->handle('POST', $path . '/submit', $this->actor(), $submit, [])['status']);
        self::assertNotEmpty($memory->reservations);
        self::assertSame('Submitted', $service->get($this->actor(), $number)['status']);
        $memory->assigned = true;
        $return = $this->request('order-return', ['expected_version' => '3']);
        self::assertSame(409, $pages->handle('POST', $path . '/move-to-draft', $this->actor(), $return, [])['status']);
        self::assertNotEmpty($memory->reservations);
        $memory->assigned = false;
        self::assertSame(303, $pages->handle('POST', $path . '/move-to-draft', $this->actor(), $return, [])['status']);
        self::assertSame([], $memory->reservations);
        self::assertSame('Draft', $service->get($this->actor(), $number)['status']);
        self::assertSame(303, $pages->handle('POST', $path . '/delete', $this->actor(),
            $this->request('order-delete', ['expected_version' => '4']), [])['status']);
        self::assertCount(1, $memory->documents);
    }

    public function testListsFilterPaginationEmptyStatesAndEscaping(): void
    {
        foreach (['quote', 'order'] as $kind) {
            $memory = $kind === 'quote' ? new QuoteMemoryConnection() : new OrderMemoryConnection();
            $db = $this->connection($memory);
            $pages = new DocumentPages($db, new WebPath('/training'), 'csrf', $kind);
            if ($kind === 'quote') {
                $pages->handle('POST', '/modules/sales/quotes/new', $this->actor(), $this->quoteInput(), []);
            } else {
                (new OrderService($db, businessDate: static fn (): string => '2026-11-25'))->create($this->actor(), ['source_quote_number' => 'SQ-000000000000010']);
            }
            $path = '/modules/sales/' . $kind . 's';
            $response = $pages->handle('GET', $path, $this->actor(), [], ['page_size' => '1', 'customer_code' => 'CUST-001', 'status' => 'Draft']);
            self::assertSame(200, $response['status']);
            self::assertStringNotContainsString('Next &gt;</a>', $response['body']);
            self::assertStringNotContainsString('name="page_size"', $response['body']);
            self::assertStringNotContainsString('name="warehouse_id"', $response['body']);
            self::assertStringContainsString('name="' . $kind . '_number"', $response['body']);
            self::assertStringContainsString('name="date_range"', $response['body']);
            self::assertStringContainsString('New ' . ucfirst($kind), $response['body']);
            $response = $pages->handle('GET', $path, $this->actor(), [], ['status' => '<Invalid>']);
            self::assertSame(400, $response['status']);
            self::assertStringContainsString('No ' . $kind . 's match your search.', $response['body']);
            self::assertStringNotContainsString('<Invalid>', $response['body']);
        }
    }

    public function testPermissionsCsrfAndRouteBoundariesCannotBeBypassed(): void
    {
        foreach (['quote', 'order'] as $kind) {
            $db = $this->createMock(Connection::class);
            $db->expects(self::never())->method('query');
            $db->expects(self::never())->method('begin');
            $pages = new DocumentPages($db, new WebPath(), 'csrf', $kind);
            try {
                $pages->handle('POST', '/modules/sales/' . $kind . 's/new', $this->actor(), ['csrf_token' => 'bad'], []);
                self::fail('CSRF required');
            } catch (AppError $error) { self::assertSame(403, $error->status); }
            try {
                $pages->handle('GET', '/modules/sales/' . $kind . 's-extra', $this->actor(), [], []);
                self::fail('Strict route boundary required');
            } catch (AppError $error) { self::assertSame(404, $error->status); }
            $memory = new OrderMemoryConnection();
            $pages = new SalesPages($memory, new WebPath(), 'csrf');
            try {
                $pages->handle('GET', '/modules/sales/' . $kind . 's', $this->actor(false), [], []);
                self::fail('Read permission required');
            } catch (AppError $error) { self::assertSame(403, $error->status); }
        }
    }

    public function testSubmittedOrderOffersInvoiceCreationThenLinksToExistingInvoice(): void
    {
        $memory = new OrderMemoryConnection();
        $db = $this->connection($memory);
        $service = new OrderService($db, businessDate: static fn (): string => '2026-11-25');
        $order = $service->create($this->actor(), ['source_quote_number' => 'SQ-000000000000010']);
        $pages = new DocumentPages($db, new WebPath('/training'), 'csrf', 'order', orders: $service);
        $path = '/modules/sales/orders/' . $order['order_number'];
        $html = $pages->handle('GET', $path, $this->actor(), [], [])['body'];
        self::assertStringNotContainsString('Create Invoice</button>', $html);
        self::assertStringNotContainsString('Customer PO', $html);
        $memory->documents[11]['STATUS'] = 'SUBMITTED';
        $html = $pages->handle('GET', $path, $this->actor(), [], [])['body'];
        self::assertStringContainsString('Create Invoice</button>', $html);
        self::assertStringContainsString('method="post" action="/training/modules/sales/invoices/new"', $html);
        self::assertStringContainsString('name="source_order_number" value="' . $order['order_number'] . '"', $html);
        self::assertStringContainsString('name="csrf_token" value="csrf"', $html);
        self::assertCount(2, $memory->documents); // Viewing the page never creates an Invoice.
        $memory->documents[12] = [...$memory->documents[11], 'RECORD#' => 12, 'DOCUMENT_TYPE' => 'SALES_INVOICE', 'DOCUMENT_NUMBER' => 'SI-1', 'STATUS' => 'DRAFT'];
        $memory->links[] = ['CNY#' => 7, 'SOURCE_DOCUMENT_RECORD#' => 11, 'TARGET_DOCUMENT_RECORD#' => 12];
        foreach (['SUBMITTED', 'IN_PROGRESS', 'CLOSED'] as $status) {
            $memory->documents[11]['STATUS'] = $status;
            $html = $pages->handle('GET', $path, $this->actor(), [], [])['body'];
            self::assertStringNotContainsString('Create Invoice</button>', $html);
            self::assertStringContainsString('href="/training/modules/sales/invoices/SI-1">View Invoice</a>', $html);
        }
        $memory->grants = ['sales.orders:read'];
        $html = $pages->handle('GET', $path, $this->actor(false), [], [])['body'];
        self::assertStringNotContainsString('View Invoice</a>', $html);
    }
}
