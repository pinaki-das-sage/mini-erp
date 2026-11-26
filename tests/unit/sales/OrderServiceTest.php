<?php
declare(strict_types=1);

namespace MiniErp\Tests\unit\sales;

use MiniErp\sales\orders\OrderInventory;
use MiniErp\sales\orders\OrderLogistics;
use MiniErp\sales\orders\OrderService;
use MiniErp\shared\Api;
use MiniErp\shared\AppError;
use MiniErp\shared\Config;
use MiniErp\shared\database\Connection;
use MiniErp\shared\database\DatabaseError;
use MiniErp\shared\database\TransactionContext;
use MiniErp\shared\security\Actor;
use PHPUnit\Framework\TestCase;

final class OrderServiceTest extends TestCase
{
    private function actor(int $company = 7, bool $admin = true): Actor { return new Actor('admin', 'admin', [], $company, $admin); }
    private function input(): array
    {
        return ['source_quote_number' => 'SQ-000000000000010'];
    }
    private function sourceLines(): array
    {
        return [
            ['source_line_id' => '100', 'product_id' => '163', 'description' => 'Product', 'quantity' => '3', 'unit_price' => '12.50'],
            ['source_line_id' => '101', 'product_id' => '163', 'description' => 'Sample', 'quantity' => '1', 'unit_price' => '0']];
    }
    private function service(OrderMemoryConnection $db, bool $providers = true): OrderService
    {
        $inventory = new class($db) implements OrderInventory {
            public function __construct(private OrderMemoryConnection $db) {}
            public function reserve(TransactionContext $tx, Actor $actor, string $orderId, string $warehouseId, array $lines): void
            {
                TestCase::assertSame('W01', $warehouseId);
                TestCase::assertNotEmpty($lines);
                foreach ($lines as $index => $line) {
                    $this->db->reservations[$orderId . ':' . $line['line_id']] = $line['quantity'];
                    if ($index === 1 && $this->db->failReserve) { throw new AppError('INSUFFICIENT_STOCK', 'Insufficient stock.', 409); }
                }
            }
            public function release(TransactionContext $tx, Actor $actor, string $orderId): void
            {
                $this->db->reservations = [];
                if ($this->db->failRelease) { throw new AppError('INVENTORY_UNAVAILABLE', 'Release failed.', 503); }
            }
        };
        $logistics = new class($db) implements OrderLogistics {
            public function __construct(private OrderMemoryConnection $db) {}
            public function assertUnassigned(TransactionContext $tx, Actor $actor, string $orderId): void
            {
                if ($this->db->assigned) { throw new AppError('INVALID_STATE', 'Order is assigned to a Trip.', 409); }
            }
        };
        return new OrderService($db, $providers ? $inventory : null, $providers ? $logistics : null, static fn (): string => '2026-11-25');
    }
    private function reject(callable $operation, string $code): void
    {
        try { $operation(); self::fail('Expected ' . $code); }
        catch (AppError $error) { self::assertSame($code, $error->errorCode, $error->getMessage()); }
    }

    public function testQuoteOnlyCreationCopiesSnapshotsClaimsSourceAndReplays(): void
    {
        $db = new OrderMemoryConnection(); $service = $this->service($db, false);
        $input = [...$this->input(), 'action_reference' => 'create-order'];
        $saved = $service->create($this->actor(), $input);
        self::assertSame('SO-000000000000001', $saved['order_number']);
        self::assertSame('Draft', $saved['status']);
        self::assertSame('2026-11-25', $saved['order_date']);
        self::assertSame('Original shipping snapshot', $saved['shipping_address']);
        self::assertSame('37.50', $saved['document_total']);
        self::assertSame(['100', '101'], array_column($saved['lines'], 'source_line_id'));
        self::assertSame([false, false], array_column($saved['lines'], 'trip_assigned'));
        self::assertSame($saved, $service->create($this->actor(), $input));
        self::assertCount(1, $db->audit);
        self::assertSame([], $db->reservations);
        self::assertCount(1, $db->links);
        $this->reject(fn () => $service->create($this->actor(), $this->input()), 'INVALID_STATE');
        $this->reject(fn () => $service->create($this->actor(), [...$input, 'notes' => 'changed']), 'DUPLICATE_ACTION_CONFLICT');
        $this->reject(fn () => $service->get($this->actor(8), $saved['order_number']), 'NOT_FOUND');
    }

    public function testSourceAndInheritedValuesCannotBeBypassed(): void
    {
        foreach ([[], ['source_quote_number' => null], ['source_quote_number' => 'missing'],
            ['customer_code' => 'OTHER'], ['warehouse_id' => 'W02'], ['shipping_address' => 'Changed'],
            ['payment_terms' => 'NET_15'], ['order_date' => '2026-11-22'], ['status' => 'Submitted'],
            ['lines' => []], ['lines' => $this->sourceLines()], ['lines' => array_reverse($this->sourceLines())],
            ['lines' => [[...$this->sourceLines()[0], 'quantity' => '4'], $this->sourceLines()[1]]]] as $change) {
            $db = new OrderMemoryConnection(); $service = $this->service($db);
            $input = $change === [] ? [] : [...$this->input(), ...$change];
            try { $service->create($this->actor(), $input); self::fail('Invalid source input was accepted.'); }
            catch (AppError) { self::assertCount(1, $db->documents); self::assertSame([], $db->links); self::assertSame([], $db->actions); }
        }
        foreach (['DRAFT', 'IN_PROGRESS'] as $status) {
            $db = new OrderMemoryConnection(); $db->documents[10]['STATUS'] = $status;
            $this->reject(fn () => $this->service($db)->create($this->actor(), $this->input()), 'INVALID_STATE');
        }
        $db = new OrderMemoryConnection(); $db->activeCustomer = false;
        $this->reject(fn () => $this->service($db)->create($this->actor(), $this->input()), 'VALIDATION_ERROR');
    }

    public function testOptionalOrderFieldsDoNotReplaceInheritedQuoteValues(): void
    {
        $db = new OrderMemoryConnection(); $service = $this->service($db);
        $saved = $service->create($this->actor(), [...$this->input(), 'order_date' => '2026-11-26',
            'requested_delivery_date' => '2026-11-27', 'customer_po_number' => 'PO-044', 'notes' => 'Deliver in the morning']);
        self::assertSame('2026-11-26', $saved['order_date']);
        self::assertSame('2026-11-27', $saved['requested_delivery_date']);
        self::assertSame('PO-044', $saved['customer_po_number']);
        self::assertSame('Deliver in the morning', $saved['notes']);
        self::assertSame('CUST-001', $saved['customer_code']);
        self::assertSame('W01', $saved['warehouse_id']);
        self::assertSame('Original shipping snapshot', $saved['shipping_address']);
        self::assertSame('NET_30', $saved['payment_terms']);
        self::assertSame(['3', '1'], array_column($saved['lines'], 'quantity'));
        self::assertSame('37.50', $saved['document_total']);
    }

    public function testExpiredQuoteDeliveryDateDoesNotBlockSourceOnlyOrderCreation(): void
    {
        foreach (['2026-11-24' => null, '2026-11-25' => '2026-11-25', '2026-11-26' => '2026-11-26'] as $sourceDate => $expected) {
            $db = new OrderMemoryConnection();
            $db->documents[10]['DELIVERY_DATE_TEXT'] = $sourceDate;
            $saved = $this->service($db)->create($this->actor(), $this->input());
            self::assertSame('2026-11-25', $saved['order_date']);
            self::assertSame($expected, $saved['requested_delivery_date']);
            self::assertSame($sourceDate, $db->documents[10]['DELIVERY_DATE_TEXT']);
        }
        $db = new OrderMemoryConnection();
        $db->documents[10]['DELIVERY_DATE_TEXT'] = '2026-11-24';
        $service = $this->service($db);
        $this->reject(fn () => $service->create($this->actor(), [...$this->input(), 'requested_delivery_date' => '2026-11-24']), 'VALIDATION_ERROR');
        self::assertCount(1, $db->documents);
        self::assertSame([], $db->links);
        self::assertSame([], $db->actions);
        $saved = $service->create($this->actor(), [...$this->input(), 'requested_delivery_date' => null]);
        self::assertNull($saved['requested_delivery_date']);
    }

    public function testEditRetainsLineIdsAndDeleteReleasesQuoteClaim(): void
    {
        $db = new OrderMemoryConnection(); $service = $this->service($db);
        $saved = $service->create($this->actor(), $this->input()); $number = $saved['order_number'];
        $lines = array_map(static fn (array $line): array => array_intersect_key($line,
            array_flip(['line_id', 'source_line_id', 'product_id', 'description', 'quantity', 'unit_price'])), $saved['lines']);
        $edit = ['expected_version' => 1, 'lines' => $lines, 'notes' => 'Order note', 'customer_po_number' => 'PO-123'];
        $changed = $service->update($this->actor(), $number, $edit);
        self::assertSame(2, $changed['current_version']); self::assertSame('PO-123', $changed['customer_po_number']);
        self::assertSame(array_column($saved['lines'], 'line_id'), array_column($changed['lines'], 'line_id'));
        $this->reject(fn () => $service->update($this->actor(), $number, $edit), 'STALE_VERSION');
        $this->reject(fn () => $service->update($this->actor(), $number, [...$edit, 'expected_version' => 2, 'source_quote_number' => null]), 'VALIDATION_ERROR');
        $this->reject(fn () => $service->update($this->actor(), $number, [...$edit, 'expected_version' => 2,
            'lines' => [[...$lines[0], 'line_id' => '999'], $lines[1]]]), 'VALIDATION_ERROR');
        $deleted = $service->action($this->actor(), $number, 'delete', ['expected_version' => 2, 'action_reference' => 'delete-order']);
        self::assertTrue($deleted['deleted']); self::assertSame([], $db->links);
        self::assertSame($deleted, $service->action($this->actor(), $number, 'delete', ['expected_version' => 2, 'action_reference' => 'delete-order']));
        self::assertNotSame($number, $service->create($this->actor(), $this->input())['order_number']);
    }

    public function testSubmitRollbackReplayReturnAndNoInvoiceOnSubmit(): void
    {
        $db = new OrderMemoryConnection(); $service = $this->service($db);
        $saved = $service->create($this->actor(), $this->input()); $number = $saved['order_number'];
        $db->failReserve = true;
        $this->reject(fn () => $service->action($this->actor(), $number, 'submit', ['expected_version' => 1]), 'INSUFFICIENT_STOCK');
        self::assertSame([], $db->reservations); self::assertSame($saved, $service->get($this->actor(), $number));
        $db->failReserve = false;
        $request = ['expected_version' => 1, 'action_reference' => 'submit-order'];
        $submitted = $service->action($this->actor(), $number, 'submit', $request);
        self::assertSame('Submitted', $submitted['status']); self::assertCount(2, $db->reservations);
        self::assertSame($submitted, $service->action($this->actor(), $number, 'submit', $request));
        self::assertCount(2, $db->documents); // Quote + Order; Invoice creation is separate.
        self::assertArrayNotHasKey('invoice_number', $submitted);
        $this->reject(fn () => $service->action($this->actor(), $number, 'submit', ['expected_version' => 2]), 'INVALID_STATE');
        $reservations = $db->reservations;
        $db->assigned = true;
        $this->reject(fn () => $service->action($this->actor(), $number, 'return_to_draft', ['expected_version' => 2]), 'INVALID_STATE');
        $db->assigned = false; $db->failRelease = true;
        $this->reject(fn () => $service->action($this->actor(), $number, 'return_to_draft', ['expected_version' => 2]), 'INVENTORY_UNAVAILABLE');
        self::assertSame($reservations, $db->reservations);
        $db->failRelease = false;
        $draft = $service->action($this->actor(), $number, 'return_to_draft', ['expected_version' => 2]);
        self::assertSame('Draft', $draft['status']); self::assertSame([], $db->reservations); self::assertCount(1, $db->links);
        self::assertSame('Submitted', $service->action($this->actor(), $number, 'submit', ['expected_version' => 3])['status']);
    }

    public function testMissingProvidersAndAuditFailurePreserveAllState(): void
    {
        $db = new OrderMemoryConnection(); $service = $this->service($db, false);
        $saved = $service->create($this->actor(), $this->input());
        $this->reject(fn () => $service->action($this->actor(), $saved['order_number'], 'submit', ['expected_version' => 1]), 'INVENTORY_UNAVAILABLE');
        self::assertSame($saved, $service->get($this->actor(), $saved['order_number']));
        $db = new OrderMemoryConnection(); $db->failAudit = true;
        try { $this->service($db)->create($this->actor(), $this->input()); self::fail('Audit failure must roll back.'); }
        catch (DatabaseError) { self::assertCount(1, $db->documents); self::assertSame([], $db->links); self::assertCount(2, $db->lines); }
    }

    public function testDownstreamLinksBlockReturnAndProgressReadsNeverCreateInvoices(): void
    {
        $db = new OrderMemoryConnection(); $service = $this->service($db);
        $saved = $service->create($this->actor(), $this->input()); $number = $saved['order_number'];
        $service->action($this->actor(), $number, 'submit', ['expected_version' => 1]);
        $db->links[] = ['CNY#' => 7, 'SOURCE_DOCUMENT_RECORD#' => 11, 'TARGET_DOCUMENT_RECORD#' => 99];
        $this->reject(fn () => $service->action($this->actor(), $number, 'return_to_draft', ['expected_version' => 2]), 'INVALID_STATE');
        self::assertCount(2, $db->reservations);
        foreach (['IN_PROGRESS' => 'In Progress', 'CLOSED' => 'Closed'] as $stored => $public) {
            $db->documents[11]['STATUS'] = $stored;
            foreach ($db->lines as &$line) {
                if ($line['DOCUMENT_RECORD#'] === 11) { $line['TRIP_ID'] = 9; $line['TRIP_STATUS'] = $stored === 'CLOSED' ? 'Delivered' : 'Draft'; }
            }
            unset($line);
            $read = $service->get($this->actor(), $number);
            self::assertSame($public, $read['status']); self::assertSame([], $read['available_actions']);
            self::assertSame('9', $read['lines'][0]['trip_reference']); self::assertTrue($read['lines'][0]['trip_assigned']);
            self::assertCount(2, $db->documents); // Reading Closed must not create an Invoice.
            $this->reject(fn () => $service->action($this->actor(), $number, 'return_to_draft', ['expected_version' => 2]), 'INVALID_STATE');
        }
    }

    public function testPermissionsAreCheckedAgainOnReplayAndSourceOnlyJsonIsAccepted(): void
    {
        $db = new OrderMemoryConnection(); $service = $this->service($db);
        $input = [...$this->input(), 'action_reference' => 'decoded-source'];
        $decoded = get_object_vars(json_decode(json_encode($input), false));
        $saved = $service->create($this->actor(), $decoded);
        self::assertSame($saved, $service->create($this->actor(), $input));
        $db->grants = ['sales.orders:create'];
        $this->reject(fn () => $service->create($this->actor(7, false), $input), 'FORBIDDEN');
        $this->reject(fn () => $service->create(new Actor('other', 'other', [], 7, true), $input), 'DUPLICATE_ACTION_CONFLICT');
        self::assertCount(1, $db->audit);
    }

    public function testApiRoutesPermissionIsolationAndEligibleSourceLookup(): void
    {
        $db = new OrderMemoryConnection(); $api = new Api($db, Config::fromArray([]));
        $headers = ['authorization' => 'Bearer ' . str_repeat('a', 64), 'content-type' => 'application/json'];
        self::assertSame(401, $api->handle('GET', '/api/v1/sales/orders', [])['status']);
        $eligible = $api->handle('GET', '/api/v1/sales/orders/eligible-quotes', [], $headers);
        self::assertSame(200, $eligible['status'], $eligible['body']);
        self::assertCount(1, json_decode($eligible['body'], true)['result']['items']);
        $input = get_object_vars(json_decode(json_encode($this->input()), false));
        $created = $api->handle('POST', '/api/v1/sales/orders', $input, $headers);
        self::assertSame(201, $created['status'], $created['body']);
        $number = json_decode($created['body'], true)['result']['order_number'];
        $url = '/api/v1/sales/orders/' . $number;
        self::assertSame(200, $api->handle('GET', $url, [], $headers)['status']);
        self::assertSame(200, $api->handle('GET', '/api/v1/sales/orders', [], $headers)['status']);
        self::assertSame(503, $api->handle('POST', $url . '/submit', ['expected_version' => 1], $headers)['status']);
        $db->apiAdmin = false; $db->grants = ['sales.orders:read'];
        self::assertSame(403, $api->handle('POST', $url . '/submit', ['expected_version' => 1], $headers)['status']);
        self::assertSame(403, $api->handle('GET', '/api/v1/sales/orders/eligible-quotes', [], $headers)['status']);
        self::assertSame(200, $api->handle('GET', $url, [], $headers)['status']);
    }

    public function testListFiltersOrdersWithoutQuoteSourcesBeforePaginationWithoutChangingRecords(): void
    {
        $db = new OrderMemoryConnection(); $service = $this->service($db);
        $saved = $service->create($this->actor(), $this->input());
        // Older standalone data predates the current Quote-only scope and sorts before the supported Order.
        $db->documents[5] = [...$db->documents[11], 'RECORD#' => 5,
            'DOCUMENT_NUMBER' => 'SO-000000000000005', 'DATE_TEXT' => '2026-11-24'];
        $documents = $db->documents; $links = $db->links;
        $page = $service->list($this->actor(), ['page' => '1', 'page_size' => '1']);
        self::assertSame([$saved['order_number']], array_column($page['items'], 'order_number'));
        self::assertArrayNotHasKey('lines', $page['items'][0]);
        self::assertSame([], $service->list($this->actor(), ['page' => '2', 'page_size' => '1'])['items']);
        self::assertSame($documents, $db->documents); self::assertSame($links, $db->links);
        $this->reject(fn () => $service->get($this->actor(), 'SO-000000000000005'), 'INVALID_STATE');
        $this->reject(fn () => $service->action($this->actor(), 'SO-000000000000005', 'submit', ['expected_version' => 1]), 'INVALID_STATE');
        $db->documents[6] = [...$db->documents[10], 'RECORD#' => 6, 'DOCUMENT_TYPE' => 'SALES_INVOICE', 'DOCUMENT_NUMBER' => 'SI-000000000000006'];
        $db->links[] = ['CNY#' => 7, 'SOURCE_DOCUMENT_RECORD#' => 6, 'TARGET_DOCUMENT_RECORD#' => 5];
        self::assertSame([$saved['order_number']], array_column($service->list($this->actor())['items'], 'order_number'));
        $db->links = []; // A page containing only unsupported Orders is an empty list, not a 409.
        self::assertSame([], $service->list($this->actor())['items']);
    }

    public function testDraftLinesCanBeEditedAddedRemovedAndReorderedWithoutChangingQuote(): void
    {
        $db = new OrderMemoryConnection(); $service = $this->service($db);
        $saved = $service->create($this->actor(), $this->input()); $number = $saved['order_number'];
        $quote = $db->documents[10]; $quoteLines = [$db->lines[100], $db->lines[101]];
        $retainedId = $saved['lines'][0]['line_id'];
        $edit = ['expected_version' => 1, 'action_reference' => 'edit-lines', 'lines' => [
            (object) ['product_id' => '268', 'description' => 'New product', 'quantity' => '2', 'unit_price' => '3.50'],
            (object) ['line_id' => $retainedId, 'product_id' => '163', 'description' => 'Edited description', 'quantity' => '8', 'unit_price' => '10']]];
        $updated = $service->update($this->actor(), $number, $edit);
        self::assertSame('87.00', $updated['document_total']); self::assertSame(2, $updated['current_version']);
        self::assertSame([1, 2], array_column($updated['lines'], 'line_number'));
        self::assertNull($updated['lines'][0]['source_line_id']);
        self::assertSame($retainedId, $updated['lines'][1]['line_id']);
        self::assertSame('100', $updated['lines'][1]['source_line_id']);
        self::assertSame('Edited description', $updated['lines'][1]['description']);
        self::assertNotContains($saved['lines'][1]['line_id'], array_column($updated['lines'], 'line_id'));
        self::assertSame($quote, $db->documents[10]); self::assertSame($quoteLines, [$db->lines[100], $db->lines[101]]);
        self::assertSame($updated, $service->update($this->actor(), $number, $edit));
        $this->reject(fn () => $service->update($this->actor(), $number, [...$edit, 'notes' => 'Different']), 'DUPLICATE_ACTION_CONFLICT');
        $submitted = $service->action($this->actor(), $number, 'submit', ['expected_version' => 2]);
        self::assertSame('Submitted', $submitted['status']);
        self::assertSame(['2', '8'], array_values($db->reservations));
        $this->reject(fn () => $service->update($this->actor(), $number, ['expected_version' => 3, 'notes' => 'Blocked']), 'INVALID_STATE');
    }

    public function testPartialHeaderUpdatesPreserveLinesAndProductReplacementClearsLineOrigin(): void
    {
        $db = new OrderMemoryConnection(); $service = $this->service($db);
        $saved = $service->create($this->actor(), $this->input()); $number = $saved['order_number'];
        $updated = $service->update($this->actor(), $number, ['expected_version' => 1, 'notes' => 'Header only']);
        self::assertSame($saved['lines'], $updated['lines']); self::assertSame('Header only', $updated['notes']);
        $line = $saved['lines'][0];
        $changed = $service->update($this->actor(), $number, ['expected_version' => 2, 'lines' => [[
            'line_id' => $line['line_id'], 'source_line_id' => $line['source_line_id'],
            'product_id' => '268', 'description' => 'Replacement', 'quantity' => '5', 'unit_price' => '0.10']]]);
        self::assertSame('0.50', $changed['document_total']); self::assertSame('Header only', $changed['notes']);
        self::assertSame($line['line_id'], $changed['lines'][0]['line_id']);
        self::assertNull($changed['lines'][0]['source_line_id']);
        self::assertCount(1, $changed['lines']); self::assertCount(1, $db->links);
        self::assertSame('0.50', $service->action($this->actor(), $number, 'submit', ['expected_version' => 3])['document_total']);
    }

    public function testInvalidDraftLinesNeverChangeSavedOrderOrQuote(): void
    {
        $db = new OrderMemoryConnection(); $service = $this->service($db);
        $saved = $service->create($this->actor(), $this->input());
        $line = ['line_id' => $saved['lines'][0]['line_id'], 'product_id' => '163', 'description' => 'Product', 'quantity' => '3', 'unit_price' => '12.50'];
        foreach ([null, [], [$line, $line], [[...$line, 'line_id' => '100']], [[...$line, 'line_id' => null]],
            [[...$line, 'source_line_id' => '101']], [['source_line_id' => '100', 'product_id' => '163', 'description' => 'New', 'quantity' => '1', 'unit_price' => '1']],
            [[...$line, 'product_id' => 'ITEM-001']], [[...$line, 'quantity' => '0']], [[...$line, 'quantity' => '1.5']],
            [[...$line, 'unit_price' => '1.001']], [[...$line, 'quantity' => '999999999', 'unit_price' => '9999999999.99']],
            [[...$line, 'line_amount' => '1.00']], [[...$line, 'trip_assigned' => true]], [(object) ['quantity' => '1']]] as $lines) {
            $this->reject(fn () => $service->update($this->actor(), $saved['order_number'], ['expected_version' => 1, 'lines' => $lines]), 'VALIDATION_ERROR');
            self::assertSame($saved, $service->get($this->actor(), $saved['order_number']));
        }
        self::assertCount(1, $db->audit);
        $db->links[] = ['CNY#' => 7, 'SOURCE_DOCUMENT_RECORD#' => 11, 'TARGET_DOCUMENT_RECORD#' => 99];
        $this->reject(fn () => $service->update($this->actor(), $saved['order_number'], ['expected_version' => 1, 'notes' => 'Referenced']), 'INVALID_STATE');
    }

    public function testLaterLineSaveFailureRollsBackHeaderLinesVersionAndRetryRecord(): void
    {
        $db = new OrderMemoryConnection(); $service = $this->service($db);
        $saved = $service->create($this->actor(), $this->input());
        $db->lineWrites = 0; $db->failOnLineWrite = 2;
        $lines = array_map(static fn (array $line): array => [
            'line_id' => $line['line_id'], 'product_id' => $line['product_id'], 'description' => 'Changed', 'quantity' => '9', 'unit_price' => '1'], $saved['lines']);
        try {
            $service->update($this->actor(), $saved['order_number'], ['expected_version' => 1, 'action_reference' => 'failed-update', 'notes' => 'Changed', 'lines' => $lines]);
            self::fail('Later-line failure should roll back.');
        } catch (DatabaseError) {
            self::assertSame($saved, $service->get($this->actor(), $saved['order_number']));
            self::assertCount(1, $db->actions); self::assertCount(1, $db->audit);
        }
    }
}

/** Transactional Sales orchestration double. Does not prove Oracle syntax, locks or real stock effects. */
final class OrderMemoryConnection implements Connection
{
    public array $documents = [], $lines = [], $links = [], $actions = [], $audit = [], $reservations = [], $grants = [], $payments = [];
    public bool $apiAdmin = true, $activeCustomer = true, $assigned = false, $failReserve = false, $failRelease = false, $failAudit = false;
    public int $lineWrites = 0, $failOnLineWrite = 0;
    private int $sequence = 0, $documentSequence = 10, $lineSequence = 101, $invoiceSequence = 0;
    private ?array $snapshot = null;
    public function __construct()
    {
        $this->documents[10] = ['RECORD#' => 10, 'CNY#' => 7, 'DOCUMENT_TYPE' => 'SALES_QUOTE', 'DOCUMENT_NUMBER' => 'SQ-000000000000010',
            'CUSTOMER_RECORD#' => 1, 'WAREHOUSE_ID' => 'W01', 'DATE_TEXT' => '2026-11-23', 'DELIVERY_DATE_TEXT' => null,
            'SHIPPING_ADDRESS' => 'Original shipping snapshot', 'PAYMENT_TERMS' => 'NET_30', 'NOTES' => '', 'STATUS' => 'SUBMITTED', 'VERSION' => 2];
        foreach ([100 => ['Product', '3', '12.50'], 101 => ['Sample', '1', '0.00']] as $id => [$description, $qty, $price]) {
            $this->lines[$id] = ['RECORD#' => $id, 'CNY#' => 7, 'DOCUMENT_RECORD#' => 10, 'LINE_NUMBER' => $id - 99,
                'PRODUCT_ID' => '163', 'DESCRIPTION' => $description, 'QUANTITY_TEXT' => $qty, 'PRICE_TEXT' => $price,
                'SOURCE_LINE_RECORD#' => null, 'TRIP_ID' => null, 'LOGISTICS_TRIP_LINE_ID' => null, 'TRIP_STATUS' => null];
        }
    }
    public function begin(): void { if ($this->snapshot !== null) { throw new \LogicException('Nested transaction'); } $this->snapshot = [$this->documents, $this->lines, $this->links, $this->actions, $this->audit, $this->reservations]; }
    public function commit(): void { $this->snapshot = null; }
    public function rollback(): void { [$this->documents, $this->lines, $this->links, $this->actions, $this->audit, $this->reservations] = $this->snapshot; $this->snapshot = null; }
    public function executeSchema(string $sql): void { throw new \LogicException('No DDL'); }
    public function query(string $sql, array $p = []): array
    {
        if (str_contains($sql, 'FROM ME_API_TOKENS')) { return [['USER_ID' => 'admin', 'COMPANY_ID' => 7, 'SECURITY_VERSION' => 1, 'EXPIRES_AT' => time() + 3600, 'REVOKED_AT' => null]]; }
        if (str_contains($sql, 'JOIN ME_COMPANY')) { return [['ID' => 'admin', 'USERNAME' => 'admin', 'IS_ADMIN' => (int) $this->apiAdmin, 'COMPANY_ID' => 7, 'SECURITY_VERSION' => 1, 'MUST_CHANGE_PASSWORD' => 0, 'COMPANY_NAME' => 'Test']]; }
        if (str_contains($sql, 'FROM ME_USER_ROLES')) { return []; }
        if (str_contains($sql, 'FROM ME_OBJECTS')) { return [['ACTIVE' => 1, 'ALLOW_CREATE' => 1, 'ALLOW_READ' => 1, 'ALLOW_UPDATE' => 1, 'ALLOW_DELETE' => 1]]; }
        if (str_contains($sql, 'FROM ME_OBJECT_ACTIONS')) { return [['ACTION_CODE' => $p['operation']]]; }
        if (str_contains($sql, 'FROM ME_USER_PERMISSIONS')) { return in_array($p['object'] . ':' . $p['operation'], $this->grants, true) ? [['USER_ID' => $p['user_id']]] : []; }
        if (str_contains($sql, 'ME_OE_INVOICE_NO_SEQ.NEXTVAL')) { return [['NEXT_NUMBER' => ++$this->invoiceSequence]]; }
        if (str_contains($sql, '.NEXTVAL')) { return [['NEXT_NUMBER' => ++$this->sequence]]; }
        if (!isset($p['company_id']) || !str_contains($sql, 'CNY# = :company_id') && !str_contains($sql, 'COMPANY_ID = :company_id')) { throw new \LogicException('Unscoped read: ' . $sql); }
        if (str_contains($sql, 'FROM ME_ACTIONS')) { $r = $this->actions[$p['company_id'] . ':' . $p['reference']] ?? null; return $r ? [$r] : []; }
        if (str_contains($sql, 'FROM ME_OE_CUSTOMERS')) { return $p['company_id'] === 7 ? [['RECORD#' => 1, 'CUSTOMER_CODE' => 'CUST-001', 'CUSTOMER_NAME' => 'Example Traders', 'IS_ACTIVE' => $this->activeCustomer ? 'T' : 'F']] : []; }
        if (str_contains($sql, 'SELECT I.DOCUMENT_NUMBER AS INVOICE_NUMBER FROM ME_OE_DOCUMENTS O')) {
            foreach ($this->documents as $order) {
                if ($order['CNY#'] !== $p['company_id'] || $order['DOCUMENT_TYPE'] !== 'SALES_ORDER' || $order['DOCUMENT_NUMBER'] !== $p['order_number']) { continue; }
                foreach ($this->links as $link) {
                    $invoice = $this->documents[$link['TARGET_DOCUMENT_RECORD#']] ?? null;
                    if ($link['CNY#'] === $p['company_id'] && $link['SOURCE_DOCUMENT_RECORD#'] === $order['RECORD#'] && $invoice !== null && $invoice['CNY#'] === $p['company_id'] && $invoice['DOCUMENT_TYPE'] === 'SALES_INVOICE') {
                        return [['INVOICE_NUMBER' => $invoice['DOCUMENT_NUMBER']]];
                    }
                }
            }
            return [];
        }
        if (str_contains($sql, 'FROM ME_OE_SOURCE_LINKS') && !str_contains($sql, 'FROM ME_OE_DOCUMENTS')) {
            return array_values(array_filter($this->links, fn ($r) => $r['CNY#'] === $p['company_id'] &&
                (!isset($p['source_id']) || $r['SOURCE_DOCUMENT_RECORD#'] == $p['source_id']) && (!isset($p['target_id']) || $r['TARGET_DOCUMENT_RECORD#'] == $p['target_id'])));
        }
        if (str_contains($sql, 'FROM ME_OE_PAYMENTS')) {
            return array_values(array_filter($this->payments, fn ($r) => $r['CNY#'] === $p['company_id'] && $r['INVOICE_RECORD#'] == $p['id']));
        }
        if (str_contains($sql, 'SELECT X.RECORD#')) {
            $origins = array_column(array_filter($this->lines, fn ($r) => $r['CNY#'] === $p['company_id'] && $r['DOCUMENT_RECORD#'] == $p['id']), 'RECORD#');
            return array_values(array_filter($this->lines, fn ($r) => $r['CNY#'] === $p['company_id'] && in_array($r['SOURCE_LINE_RECORD#'], $origins, false)));
        }
        if (str_contains($sql, 'FROM ME_OE_DOCUMENT_LINES')) {
            $r = array_values(array_filter($this->lines, fn ($r) => $r['CNY#'] === $p['company_id'] && $r['DOCUMENT_RECORD#'] == $p['id']));
            usort($r, fn ($a, $b) => $a['LINE_NUMBER'] <=> $b['LINE_NUMBER']); return $r;
        }
        if (str_contains($sql, 'FROM ME_OE_DOCUMENTS')) {
            $rows = array_values(array_filter($this->documents, fn ($r) => $r['CNY#'] === $p['company_id'] &&
                (!isset($p['document_number']) || $r['DOCUMENT_NUMBER'] === $p['document_number']) &&
                (!isset($p['id']) || $r['RECORD#'] == $p['id']) && (!isset($p['document_type']) || $r['DOCUMENT_TYPE'] === $p['document_type']) &&
                (!isset($p['status']) || $r['STATUS'] === $p['status'])));
            if (str_contains($sql, 'NOT EXISTS')) { $rows = array_values(array_filter($rows, fn ($r) => !array_filter($this->links, fn ($l) => $l['SOURCE_DOCUMENT_RECORD#'] === $r['RECORD#']))); }
            if (str_contains($sql, "QS.DOCUMENT_TYPE = 'SALES_QUOTE'")) {
                $rows = array_values(array_filter($rows, function (array $row): bool {
                    foreach ($this->links as $link) {
                        $source = $this->documents[$link['SOURCE_DOCUMENT_RECORD#']] ?? null;
                        if ($link['CNY#'] === $row['CNY#'] && $link['TARGET_DOCUMENT_RECORD#'] === $row['RECORD#'] &&
                            $source !== null && $source['CNY#'] === $row['CNY#'] && $source['DOCUMENT_TYPE'] === 'SALES_QUOTE') { return true; }
                    }
                    return false;
                }));
            }
            if (str_contains($sql, 'ORDER BY D.DOCUMENT_DATE')) {
                usort($rows, fn ($a, $b) => [$a['DATE_TEXT'], $a['DOCUMENT_NUMBER']] <=> [$b['DATE_TEXT'], $b['DOCUMENT_NUMBER']]);
            }
            if (isset($p['offset_rows'])) { $rows = array_slice($rows, $p['offset_rows'], $p['limit_rows']); }
            return $rows;
        }
        throw new \LogicException('Unexpected query: ' . $sql);
    }
    public function execute(string $sql, array $p = []): int
    {
        if ($this->snapshot === null || !isset($p['company_id'])) { throw new \LogicException('Unscoped/nontransactional write'); }
        $key = $p['company_id'] . ':' . ($p['reference'] ?? '');
        if (str_starts_with($sql, 'INSERT INTO ME_ACTIONS')) {
            if (isset($this->actions[$key])) { throw new DatabaseError(1); }
            $this->actions[$key] = ['ACTOR_ID' => $p['actor'], 'PAYLOAD_HASH' => $p['hash'], 'RESULT_JSON' => null];
        } elseif (str_starts_with($sql, 'UPDATE ME_ACTIONS')) { $this->actions[$key]['RESULT_JSON'] = $p['result'];
        } elseif (str_starts_with($sql, 'INSERT INTO ME_AUDIT')) {
            if ($this->failAudit) { throw new DatabaseError(3113); } $this->audit[] = $p;
        } elseif (str_starts_with($sql, 'INSERT INTO ME_OE_DOCUMENTS')) {
            $id = ++$this->documentSequence;
            $this->documents[$id] = ['RECORD#' => $id, 'CNY#' => $p['company_id'],
                'DOCUMENT_TYPE' => str_contains($sql, "'SALES_INVOICE'") ? 'SALES_INVOICE' : 'SALES_ORDER', 'DOCUMENT_NUMBER' => $p['document_number'],
                'CUSTOMER_RECORD#' => $p['customer_record'], 'WAREHOUSE_ID' => $p['warehouse_id'], 'DATE_TEXT' => $p['order_date'], 'DELIVERY_DATE_TEXT' => $p['requested_delivery_date'] ?? null,
                'DUE_DATE_TEXT' => $p['due_date'] ?? null, 'SHIPPING_ADDRESS' => $p['shipping_address'], 'PAYMENT_TERMS' => $p['payment_terms'], 'CUSTOMER_PO_NUMBER' => $p['customer_po_number'] ?? '', 'NOTES' => $p['notes'], 'STATUS' => 'DRAFT', 'VERSION' => 1];
        } elseif (str_starts_with($sql, 'UPDATE ME_OE_DOCUMENTS')) {
            $r = &$this->documents[$p['id']]; $r['VERSION']++;
            if (isset($p['status'])) { $r['STATUS'] = $p['status']; }
            else { $r['DATE_TEXT'] = $p['order_date']; $r['DELIVERY_DATE_TEXT'] = $p['requested_delivery_date']; $r['NOTES'] = $p['notes']; $r['CUSTOMER_PO_NUMBER'] = $p['customer_po_number']; }
        } elseif (str_starts_with($sql, 'UPDATE ME_OE_DOCUMENT_LINES') && isset($p['shift'])) {
            foreach ($this->lines as &$line) { if ($line['CNY#'] === $p['company_id'] && $line['DOCUMENT_RECORD#'] == $p['id']) { $line['LINE_NUMBER'] += $p['shift']; } }
            unset($line);
        } elseif (str_starts_with($sql, 'INSERT INTO ME_OE_DOCUMENT_LINES') || str_starts_with($sql, 'UPDATE ME_OE_DOCUMENT_LINES')) {
            if (++$this->lineWrites === $this->failOnLineWrite) { throw new DatabaseError(3113); }
            $id = $p['line_id'] ?? ++$this->lineSequence;
            foreach ($this->lines as $other) {
                if ((string) $other['RECORD#'] !== (string) $id && $other['CNY#'] === $p['company_id'] &&
                    $other['DOCUMENT_RECORD#'] == $p['id'] && $other['LINE_NUMBER'] === $p['line_number']) { throw new DatabaseError(1); }
            }
            $this->lines[$id] = ['RECORD#' => $id, 'CNY#' => $p['company_id'], 'DOCUMENT_RECORD#' => $p['id'], 'LINE_NUMBER' => $p['line_number'], 'PRODUCT_ID' => $p['product_id'],
                'DESCRIPTION' => $p['description'], 'QUANTITY_TEXT' => $p['quantity'], 'PRICE_TEXT' => $p['unit_price'], 'SOURCE_LINE_RECORD#' => $p['source_line_id'], 'TRIP_ID' => null, 'TRIP_STATUS' => null];
        } elseif (str_starts_with($sql, 'INSERT INTO ME_OE_SOURCE_LINKS')) {
            foreach ($this->links as $r) { if ($r['CNY#'] === $p['company_id'] && ($r['SOURCE_DOCUMENT_RECORD#'] == $p['source_id'] || $r['TARGET_DOCUMENT_RECORD#'] == $p['target_id'])) { throw new DatabaseError(1); } }
            $this->links[] = ['CNY#' => $p['company_id'], 'SOURCE_DOCUMENT_RECORD#' => $p['source_id'], 'TARGET_DOCUMENT_RECORD#' => $p['target_id']];
        } elseif (str_starts_with($sql, 'DELETE FROM ME_OE_SOURCE_LINKS')) { $this->links = array_values(array_filter($this->links, fn ($r) => $r['CNY#'] !== $p['company_id'] || $r['TARGET_DOCUMENT_RECORD#'] != $p['target_id']));
        } elseif (str_starts_with($sql, 'DELETE FROM ME_OE_DOCUMENT_LINES')) { $this->lines = array_filter($this->lines, fn ($r) => $r['CNY#'] !== $p['company_id'] || $r['DOCUMENT_RECORD#'] != $p['id'] || isset($p['line_id']) && (string) $r['RECORD#'] !== (string) $p['line_id']);
        } elseif (str_starts_with($sql, 'DELETE FROM ME_OE_DOCUMENTS')) { unset($this->documents[$p['id']]);
        } else { throw new \LogicException('Unexpected write: ' . $sql); }
        return 1;
    }
}
