<?php
declare(strict_types=1);

namespace MiniErp\Tests\unit\sales;

use MiniErp\inventory\QuoteInventoryValidator;
use MiniErp\inventory\ReservationService;
use MiniErp\sales\orders\InventoryOrderAdapter;
use MiniErp\sales\orders\LogisticsOrderProvider;
use MiniErp\sales\orders\OrderService;
use MiniErp\sales\orders\SalesFulfillment;
use MiniErp\shared\AppError;
use MiniErp\shared\database\Connection;
use MiniErp\shared\database\TransactionContext;
use MiniErp\shared\database\TransactionManager;
use MiniErp\shared\security\Actor;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/InventoryOrderAdapterTest.php';

final class SalesFulfillmentTest extends TestCase
{
    private function actor(): Actor { return new Actor('admin', 'admin', [], 7, true); }
    private function setupOrder(): array
    {
        $db = new FulfillmentMemoryConnection(); $provider = $db->logistics;
        $reservations = new ReservationService($db, $provider->getPlannedQuantity(...));
        $orders = new OrderService($db, new InventoryOrderAdapter($reservations, new QuoteInventoryValidator()), $provider,
            static fn (): string => '2026-11-25');
        $order = $orders->create($this->actor(), ['source_quote_number' => 'SQ-000000000000010']);
        $order = $orders->action($this->actor(), $order['order_number'], 'submit', ['expected_version' => 1]);
        return [$db, $provider, $reservations, $orders, new SalesFulfillment($db, $provider, $reservations), $order];
    }
    private function input(array $order, int $version, string $reference): array
    {
        return ['expected_version' => $version, 'event_reference' => $reference, 'trip_id' => '7',
            'lines' => array_map(static fn (array $line): array => ['line_id' => $line['line_id'],
                'trip_line_id' => (string) (500 + $line['line_number'])], $order['lines'])];
    }
    private function facts(array $order, string $status): array
    {
        return array_map(static fn (array $line): array => ['line_id' => $line['line_id'], 'trip_id' => '7',
            'trip_line_id' => (string) (500 + $line['line_number']), 'product_id' => $line['product_id'],
            'quantity' => $line['quantity'], 'trip_status' => $status], $order['lines']);
    }
    private function reject(callable $operation, string $code): void
    {
        try { $operation(); self::fail('Expected ' . $code); }
        catch (AppError $error) { self::assertSame($code, $error->errorCode, $error->getMessage()); }
    }
    private function assign(array $setup): array
    {
        [$db, $provider, , , $service, $order] = $setup;
        return (new TransactionManager($db))->run(function (TransactionContext $tx) use ($provider, $service, $order): array {
            $locked = $service->lockOrderForEvent($tx, $this->actor(), $order['order_number'], 'assignment');
            self::assertSame('11', $locked['order_id']); self::assertSame(2, $locked['current_version']);
            $provider->assignments = $this->facts($order, 'Draft');
            return $service->recordTripAssignment($tx, $this->actor(), $order['order_number'], $this->input($order, 2, 'trip-7-assign-1'));
        });
    }
    private function dispatch(array $setup): array
    {
        [$db, $provider, $reservations, , $service, $order] = $setup;
        return (new TransactionManager($db))->run(function (TransactionContext $tx) use ($provider, $reservations, $service, $order): array {
            $service->lockOrderForEvent($tx, $this->actor(), $order['order_number'], 'assignment');
            foreach ($order['lines'] as $line) {
                $r = $reservations->getReservationDetail($this->actor(), $tx, 11, (int) $line['line_id']);
                $reservations->consumeReservation($this->actor(), $tx, $r['reservation_id'], 11, (int) $line['line_id'],
                    (int) $line['product_id'], (int) $line['quantity'], 7, 500 + $line['line_number']);
            }
            $provider->assignments = $this->facts($order, 'Dispatched');
            return $service->recordTripAssignment($tx, $this->actor(), $order['order_number'], $this->input($order, 3, 'trip-7-dispatch-1'));
        });
    }

    public function testAssignmentCancellationAndReturnReleaseStockInSeparateActions(): void
    {
        $setup = $this->setupOrder(); [$db, $provider, , $orders, $service, $order] = $setup;
        $assigned = $this->assign($setup);
        self::assertSame('In Progress', $assigned['status']); self::assertSame(3, $assigned['current_version']);
        self::assertSame(4, $db->inventory->balances[163]['RESERVED']);
        $this->reject(fn () => $orders->action($this->actor(), $order['order_number'], 'return_to_draft', ['expected_version' => 3]), 'INVALID_STATE');
        $cancelled = (new TransactionManager($db))->run(function (TransactionContext $tx) use ($provider, $service, $order): array {
            $service->lockOrderForEvent($tx, $this->actor(), $order['order_number'], 'cancellation');
            $provider->assignments = [];
            return $service->recordTripCancellation($tx, $this->actor(), $order['order_number'], $this->input($order, 3, 'trip-7-cancel-1'));
        });
        self::assertSame('Submitted', $cancelled['status']); self::assertSame(4, $cancelled['current_version']);
        self::assertSame(4, $db->inventory->balances[163]['RESERVED']);
        $read = $orders->get($this->actor(), $order['order_number']);
        self::assertNull($read['lines'][0]['trip_reference']); self::assertSame('Cancelled', $read['lines'][0]['trip_status']);
        $draft = $orders->action($this->actor(), $order['order_number'], 'return_to_draft', ['expected_version' => 4]);
        self::assertSame('Draft', $draft['status']); self::assertSame(0, $db->inventory->balances[163]['RESERVED']);
        self::assertSame(20, $db->inventory->balances[163]['ON_HAND']);
    }

    public function testDispatchDeliveryClosesOrderWithoutCreatingAnotherInvoiceAndReplays(): void
    {
        $setup = $this->setupOrder(); [$db, $provider, , $orders, $service, $order] = $setup;
        $first = $this->assign($setup);
        $replay = (new TransactionManager($db))->run(fn (TransactionContext $tx): array =>
            $service->recordTripAssignment($tx, $this->actor(), $order['order_number'], $this->input($order, 2, 'trip-7-assign-1')));
        self::assertSame($first, $replay);
        $this->dispatch($setup);
        self::assertSame(['ON_HAND' => 16, 'RESERVED' => 0], $db->inventory->balances[163]);
        $input = $this->input($order, 4, 'trip-7-deliver-1');
        $closed = (new TransactionManager($db))->run(function (TransactionContext $tx) use ($provider, $service, $order, $input): array {
            $service->lockOrderForEvent($tx, $this->actor(), $order['order_number'], 'delivery');
            $provider->assignments = $this->facts($order, 'Delivered');
            return $service->recordDelivery($tx, $this->actor(), $order['order_number'], $input);
        });
        self::assertSame('Closed', $closed['status']); self::assertSame(5, $closed['current_version']);
        self::assertArrayNotHasKey('invoice_number', $closed);
        self::assertSame('DRAFT', $db->inventory->sales->documents[12]['STATUS']);
        self::assertSame(['ON_HAND' => 16, 'RESERVED' => 0], $db->inventory->balances[163]); // No second deduction on delivery.
        self::assertSame('Closed', $orders->get($this->actor(), $order['order_number'])['status']);
        $replayed = (new TransactionManager($db))->run(fn (TransactionContext $tx): array =>
            $service->recordDelivery($tx, $this->actor(), $order['order_number'], $input));
        self::assertSame($closed, $replayed); self::assertCount(3, $db->inventory->sales->documents);
        $this->reject(fn () => (new TransactionManager($db))->run(fn (TransactionContext $tx): array =>
            $service->recordDelivery($tx, $this->actor(), $order['order_number'], [...$input, 'event_reference' => 'different-delivery', 'expected_version' => 5])), 'INVALID_STATE');
        self::assertCount(3, $db->inventory->sales->documents);
    }

    public function testDeliveryPreservesTheInvoiceCreatedAtOrderSubmission(): void
    {
        $setup = $this->setupOrder(); [$db, $provider, , , $service, $order] = $setup;
        self::assertSame('DRAFT', $db->inventory->sales->documents[12]['STATUS']);
        $this->assign($setup); $this->dispatch($setup);
        $invoice = $db->inventory->sales->documents[12];
        (new TransactionManager($db))->run(function (TransactionContext $tx) use ($provider, $service, $order): array {
            $service->lockOrderForEvent($tx, $this->actor(), $order['order_number'], 'delivery');
            $provider->assignments = $this->facts($order, 'Delivered');
            return $service->recordDelivery($tx, $this->actor(), $order['order_number'], $this->input($order, 4, 'deliver-existing-invoice'));
        });
        self::assertSame($invoice, $db->inventory->sales->documents[12]);
        self::assertCount(3, $db->inventory->sales->documents);
    }

    public function testWrongDemandStaleVersionAndDeniedOwnerEventRollBack(): void
    {
        foreach (['quantity', 'partial', 'stale', 'owner-denied'] as $failure) {
            $setup = $this->setupOrder(); [$db, $provider, , $orders, $service, $order] = $setup;
            $this->reject(fn () => (new TransactionManager($db))->run(function (TransactionContext $tx) use ($provider, $service, $order, $failure): array {
                $service->lockOrderForEvent($tx, $this->actor(), $order['order_number'], 'assignment');
                $provider->assignments = $this->facts($order, 'Draft');
                $input = $this->input($order, $failure === 'stale' ? 1 : 2, 'rejected-event');
                if ($failure === 'quantity') { $provider->assignments[1]['quantity'] = '999'; }
                if ($failure === 'partial') { array_pop($input['lines']); }
                if ($failure === 'owner-denied') { $provider->denyEvent = true; }
                return $service->recordTripAssignment($tx, $this->actor(), $order['order_number'], $input);
            }), match ($failure) { 'quantity' => 'INVALID_STATE', 'partial' => 'VALIDATION_ERROR', 'stale' => 'STALE_VERSION', default => 'FORBIDDEN' });
            self::assertSame([], $provider->assignments);
            self::assertSame('Submitted', $orders->get($this->actor(), $order['order_number'])['status']);
            self::assertSame(4, $db->inventory->balances[163]['RESERVED']); self::assertCount(2, $db->inventory->sales->actions);
        }
    }

    public function testCancellationCannotUndoDispatchAndDeliveryNeedsConsumption(): void
    {
        $setup = $this->setupOrder(); [$db, $provider, , $orders, $service, $order] = $setup;
        $this->assign($setup);
        $this->reject(fn () => (new TransactionManager($db))->run(function (TransactionContext $tx) use ($provider, $service, $order): array {
            $provider->assignments = $this->facts($order, 'Delivered');
            return $service->recordDelivery($tx, $this->actor(), $order['order_number'], $this->input($order, 3, 'undispatched-delivery'));
        }), 'INVALID_STATE');
        $this->dispatch($setup);
        $this->reject(fn () => (new TransactionManager($db))->run(function (TransactionContext $tx) use ($provider, $service, $order): array {
            $provider->assignments = [];
            return $service->recordTripCancellation($tx, $this->actor(), $order['order_number'], $this->input($order, 4, 'late-cancel'));
        }), 'INVALID_STATE');
        self::assertSame(['ON_HAND' => 16, 'RESERVED' => 0], $db->inventory->balances[163]);
    }

    public function testPermissionsTenantAndChangedEventPayloadAreRejected(): void
    {
        $setup = $this->setupOrder(); [$db, , , , $service, $order] = $setup;
        $input = $this->input($order, 2, 'trip-7-assign-1');
        $this->reject(fn () => (new TransactionManager($db))->run(fn (TransactionContext $tx): array =>
            $service->recordTripAssignment($tx, new Actor('other', 'other', [], 7, false), $order['order_number'], $input)), 'FORBIDDEN');
        $this->reject(fn () => (new TransactionManager($db))->run(fn (TransactionContext $tx): array =>
            $service->recordTripAssignment($tx, new Actor('admin', 'admin', [], 8, true), $order['order_number'], $input)), 'NOT_FOUND');
        $this->assign($setup);
        $this->reject(fn () => (new TransactionManager($db))->run(fn (TransactionContext $tx): array =>
            $service->recordTripAssignment($tx, $this->actor(), $order['order_number'], [...$input, 'expected_version' => 3])), 'DUPLICATE_ACTION_CONFLICT');
        $this->reject(fn () => (new TransactionManager($db))->run(fn (TransactionContext $tx): array =>
            $service->recordTripAssignment($tx, new Actor('admin', 'admin', [], 7, false), $order['order_number'], $input)), 'FORBIDDEN');
    }

    public function testLateAuditFailureRollsBackSalesAndLogisticsCancellation(): void
    {
        $setup = $this->setupOrder(); [$db, $provider, , $orders, $service, $order] = $setup;
        $this->assign($setup); $db->inventory->sales->failAudit = true;
        try {
            (new TransactionManager($db))->run(function (TransactionContext $tx) use ($provider, $service, $order): array {
                $service->lockOrderForEvent($tx, $this->actor(), $order['order_number'], 'cancellation');
                $provider->assignments = [];
                return $service->recordTripCancellation($tx, $this->actor(), $order['order_number'], $this->input($order, 3, 'audit-failure'));
            });
            self::fail('Audit failure must roll back.');
        } catch (\MiniErp\shared\database\DatabaseError) {
            self::assertCount(2, $provider->assignments);
            self::assertSame('In Progress', $orders->get($this->actor(), $order['order_number'])['status']);
            self::assertSame(3, $orders->get($this->actor(), $order['order_number'])['current_version']);
            self::assertSame(4, $db->inventory->balances[163]['RESERVED']);
            self::assertCount(3, $db->inventory->sales->audit);
        }
    }
}

/** Test-only authoritative Logistics double. Production must provide the real owner implementation. */
final class FulfillmentLogisticsDouble implements LogisticsOrderProvider
{
    public array $assignments = [];
    public bool $denyEvent = false;
    public function assertEvent(TransactionContext $tx, Actor $actor, string $event, string $orderId, string $tripId, array $lines, string $eventReference): void
    {
        if ($this->denyEvent || $actor->companyId !== 7) { throw new AppError('FORBIDDEN', 'Logistics denied this event/driver.', 403); }
    }
    public function getAssignments(TransactionContext $tx, Actor $actor, string $orderId): array { return $this->assignments; }
    public function assertUnassigned(TransactionContext $tx, Actor $actor, string $orderId): void
    {
        if ($this->assignments !== []) { throw new AppError('INVALID_STATE', 'Order has a Logistics assignment.', 409); }
    }
    public function getPlannedQuantity(Actor $actor, TransactionContext $tx, int $orderId, int $lineId): int
    {
        foreach ($this->assignments as $line) { if ((int) $line['line_id'] === $lineId && $line['trip_status'] === 'Draft') { return (int) $line['quantity']; } }
        return 0;
    }
}

/** Transactional simulation across real Sales/Inventory code and test Logistics/Invoice adapters; not Oracle proof. */
final class FulfillmentMemoryConnection implements Connection
{
    public ReservationMemoryConnection $inventory;
    public FulfillmentLogisticsDouble $logistics;
    public array $movements = [];
    private ?array $snapshot = null;
    private int $movementSequence = 0;
    public function __construct() { $this->inventory = new ReservationMemoryConnection(); $this->logistics = new FulfillmentLogisticsDouble(); }
    public function begin(): void { $this->inventory->begin(); $this->snapshot = [$this->logistics->assignments, $this->movements]; }
    public function commit(): void { $this->inventory->commit(); $this->snapshot = null; }
    public function rollback(): void { $this->inventory->rollback(); [$this->logistics->assignments, $this->movements] = $this->snapshot; $this->snapshot = null; }
    public function executeSchema(string $sql): void { throw new \LogicException('No DDL'); }
    public function query(string $sql, array $p = []): array
    {
        if (str_contains($sql, 'ME_INV_MOVEMENT_SEQ.NEXTVAL')) { return [['MOVEMENT_ID' => ++$this->movementSequence]]; }
        if (str_contains($sql, 'FROM ME_INV_STOCK_MOVEMENT')) {
            return array_values(array_filter($this->movements, fn ($r) => $r['COMPANY_ID'] === $p['company_id'] &&
                ($r['RESERVATION_ID'] === $p['reservation_id'] || $r['SOURCE_ID'] === $p['source_id'])));
        }
        return $this->inventory->query($sql, $p);
    }
    public function execute(string $sql, array $p = []): int
    {
        if ($this->snapshot === null) { throw new \LogicException('No shared transaction'); }
        if (str_starts_with($sql, 'UPDATE ME_OE_DOCUMENT_LINES') && isset($p['trip_status'])) {
            foreach ($this->inventory->sales->lines as &$line) {
                if ($line['CNY#'] === $p['company_id'] && $line['DOCUMENT_RECORD#'] == $p['id'] && $line['RECORD#'] == $p['line_id']) {
                    $line['TRIP_ID'] = $p['trip_id']; $line['LOGISTICS_TRIP_LINE_ID'] = $p['trip_line_id']; $line['TRIP_STATUS'] = $p['trip_status']; return 1;
                }
            }
            return 0;
        }
        if (str_starts_with($sql, 'INSERT INTO ME_INV_STOCK_MOVEMENT')) {
            $this->movements[] = ['COMPANY_ID' => $p['company_id'], 'MOVEMENT_ID' => $p['movement_id'], 'PRODUCT_ID' => $p['product_id'],
                'RESERVATION_ID' => $p['reservation_id'], 'DISPATCHED_QTY' => $p['quantity'], 'OPENING_BALANCE' => $p['opening'],
                'CLOSING_BALANCE' => $p['closing'], 'MOVEMENT_TYPE' => 'DISPATCH', 'SOURCE_TYPE' => 'TRIP', 'SOURCE_ID' => $p['source_id']]; return 1;
        }
        if (str_starts_with($sql, 'UPDATE ME_INV_STOCK_BALANCE') && str_contains($sql, 'ON_HAND = ON_HAND -')) {
            $balance = &$this->inventory->balances[$p['product_id']];
            if ($balance['ON_HAND'] < $p['quantity'] || $balance['RESERVED'] < $p['quantity']) { return 0; }
            $balance['ON_HAND'] -= $p['quantity']; $balance['RESERVED'] -= $p['quantity']; return 1;
        }
        if (str_starts_with($sql, 'UPDATE ME_INV_RESERVATION') && str_contains($sql, "RESERVATION_STATUS = 'DISPATCHED'")) {
            foreach ($this->inventory->reservations as &$row) {
                if ($row['RESERVATION_ID'] === $p['reservation_id'] && $row['COMPANY_ID'] === $p['company_id'] && $row['RESERVATION_STATUS'] === 'ACTIVE') {
                    $row['RESERVATION_STATUS'] = 'DISPATCHED'; $row['TRIP_ID'] = $p['trip_id']; $row['TRIP_LINE_ID'] = $p['trip_line_id']; return 1;
                }
            }
            return 0;
        }
        return $this->inventory->execute($sql, $p);
    }
}
