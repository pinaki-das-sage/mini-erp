<?php
declare(strict_types=1);

namespace MiniErp\Tests\unit\sales;

use MiniErp\inventory\QuoteInventoryValidator;
use MiniErp\inventory\ReservationService;
use MiniErp\sales\orders\InventoryOrderAdapter;
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

require_once __DIR__ . '/OrderServiceTest.php';

final class InventoryOrderAdapterTest extends TestCase
{
    private function actor(bool $admin = true): Actor { return new Actor('admin', 'admin', [], 7, $admin); }
    private function service(ReservationMemoryConnection $db, bool $claims = false, int $planned = 0, bool $logistics = false): OrderService
    {
        $provider = new ReservationService($db, $claims ? static fn (): int => $planned : null);
        $guard = new class implements OrderLogistics {
            public function assertUnassigned(TransactionContext $tx, Actor $actor, string $orderId): void {}
        };
        return new OrderService($db, new InventoryOrderAdapter($provider, new QuoteInventoryValidator()),
            $logistics ? $guard : null, static fn (): string => '2026-11-25');
    }
    private function create(OrderService $service): array { return $service->create($this->actor(), ['source_quote_number' => 'SQ-000000000000010']); }
    private function reject(callable $operation, string $code): void
    {
        try { $operation(); self::fail('Expected ' . $code); }
        catch (AppError $error) { self::assertSame($code, $error->errorCode, $error->getMessage()); }
    }

    public function testApiUsesRealReservationProviderWithExactStockAndSafeReplay(): void
    {
        $db = new ReservationMemoryConnection(); $db->balances[163]['ON_HAND'] = 4;
        $api = new Api($db, Config::fromArray([]));
        $headers = ['authorization' => 'Bearer ' . str_repeat('a', 64), 'content-type' => 'application/json'];
        $created = $api->handle('POST', '/api/v1/sales/orders', ['source_quote_number' => 'SQ-000000000000010'], $headers);
        self::assertSame(201, $created['status'], $created['body']);
        $order = json_decode($created['body'], true)['result'];
        $url = '/api/v1/sales/orders/' . $order['order_number'];
        $request = ['expected_version' => 1, 'action_reference' => 'reserve-order'];
        $submitted = $api->handle('POST', $url . '/submit', $request, $headers);
        self::assertSame(200, $submitted['status'], $submitted['body']);
        $result = json_decode($submitted['body'], true)['result'];
        self::assertSame('Submitted', $result['status']); self::assertSame(2, $result['current_version']);
        self::assertSame(['ON_HAND' => 4, 'RESERVED' => 4], $db->balances[163]);
        self::assertCount(2, $db->reservations);
        self::assertSame($submitted['body'], $api->handle('POST', $url . '/submit', $request, $headers)['body']);
        self::assertCount(2, $db->reservations); self::assertCount(2, $db->sales->audit);
        self::assertCount(2, $db->sales->documents); // Submission reserves stock without creating an Invoice.
        self::assertSame(['move_to_draft'], $result['available_actions']);
        $returned = $api->handle('POST', $url . '/move-to-draft', ['expected_version' => 2], $headers);
        self::assertSame(200, $returned['status'], $returned['body']);
        self::assertSame('Draft', json_decode($returned['body'], true)['result']['status']);
        self::assertSame(3, json_decode($returned['body'], true)['result']['current_version']);
        self::assertSame(0, $db->balances[163]['RESERVED']);
        self::assertSame(4, $db->balances[163]['ON_HAND']);
        self::assertTrue($db->salesLinesLockedBeforeInventory);
    }

    public function testLaterRepeatedProductShortageRollsBackEarlierLineAndAction(): void
    {
        $db = new ReservationMemoryConnection(); $db->balances[163]['ON_HAND'] = 3;
        $service = $this->service($db); $order = $this->create($service);
        try {
            $service->action($this->actor(), $order['order_number'], 'submit', ['expected_version' => 1, 'action_reference' => 'shortage']);
            self::fail('Expected stock shortage');
        } catch (AppError $error) {
            self::assertSame('INSUFFICIENT_STOCK', $error->errorCode);
            self::assertSame(409, $error->status);
            self::assertStringContainsString('Product ID 163:', $error->getMessage());
        }
        self::assertSame(['ON_HAND' => 3, 'RESERVED' => 0], $db->balances[163]);
        self::assertSame([], $db->reservations); self::assertCount(1, $db->sales->actions);
        self::assertSame($order, $service->get($this->actor(), $order['order_number']));
    }

    public function testFinalEditedDemandUsesDeterministicProductOrder(): void
    {
        $db = new ReservationMemoryConnection(); $service = $this->service($db);
        $order = $this->create($service);
        $changed = $service->update($this->actor(), $order['order_number'], ['expected_version' => 1, 'lines' => [
            ['product_id' => '268', 'description' => 'Second Product first on screen', 'quantity' => '2', 'unit_price' => '1'],
            ['product_id' => '163', 'description' => 'First Product second on screen', 'quantity' => '5', 'unit_price' => '2']]]);
        $submitted = $service->action($this->actor(), $order['order_number'], 'submit', ['expected_version' => 2]);
        self::assertSame('Submitted', $submitted['status']);
        self::assertSame([163, 268], $db->balanceLockOrder);
        self::assertSame(5, $db->balances[163]['RESERVED']); self::assertSame(2, $db->balances[268]['RESERVED']);
        self::assertSame(array_column($changed['lines'], 'line_id'), array_column($submitted['lines'], 'line_id'));
    }

    public function testApiShortageIdentifiesTheActualFailingProduct(): void
    {
        $db = new ReservationMemoryConnection(); $service = $this->service($db); $order = $this->create($service);
        $service->update($this->actor(), $order['order_number'], ['expected_version' => 1, 'lines' => [
            ['product_id' => '163', 'description' => 'Available product', 'quantity' => '1', 'unit_price' => '1'],
            ['product_id' => '268', 'description' => 'Short product', 'quantity' => '999', 'unit_price' => '1'],
        ]]);
        $headers = ['authorization' => 'Bearer ' . str_repeat('a', 64), 'content-type' => 'application/json'];
        $response = (new Api($db, Config::fromArray([])))->handle('POST', '/api/v1/sales/orders/' . $order['order_number'] . '/submit', ['expected_version' => 2], $headers);
        self::assertSame(409, $response['status']);
        $error = json_decode($response['body'], true)['error'];
        self::assertSame('INSUFFICIENT_STOCK', $error['code']);
        self::assertStringContainsString('Product ID 268:', $error['message']);
        self::assertSame(0, $db->balances[163]['RESERVED']);
        self::assertSame([], $db->reservations);
    }

    public function testProductReferencesAndInventoryPermissionAreEnforced(): void
    {
        foreach (['inactive', 'warehouse', 'missing'] as $failure) {
            $db = new ReservationMemoryConnection(); $service = $this->service($db); $order = $this->create($service);
            if ($failure === 'inactive') { $db->products[163]['IS_ACTIVE'] = 'N'; }
            elseif ($failure === 'warehouse') { $db->products[163]['WAREHOUSE_ID'] = 'W02'; }
            else { unset($db->products[163]); }
            $this->reject(fn () => $service->action($this->actor(), $order['order_number'], 'submit', ['expected_version' => 1]),
                $failure === 'inactive' ? 'INVALID_STATE' : 'VALIDATION_ERROR');
            self::assertSame([], $db->reservations); self::assertSame(0, $db->balances[163]['RESERVED']);
        }
        $db = new ReservationMemoryConnection(); $service = $this->service($db); $order = $this->create($service);
        $db->sales->grants = ['sales.orders:submit', 'sales.quotes:read'];
        $this->reject(fn () => $service->action($this->actor(false), $order['order_number'], 'submit', ['expected_version' => 1]), 'FORBIDDEN');
        $db->sales->grants[] = 'inventory.reservations:reserve';
        self::assertSame('Submitted', $service->action($this->actor(false), $order['order_number'], 'submit', ['expected_version' => 1])['status']);
    }

    public function testReleaseRejectsClaimsAndResubmissionRetainsHistory(): void
    {
        $db = new ReservationMemoryConnection(); $service = $this->service($db, true, 1, true);
        $order = $this->create($service);
        $service->action($this->actor(), $order['order_number'], 'submit', ['expected_version' => 1]);
        $this->reject(fn () => $service->action($this->actor(), $order['order_number'], 'return_to_draft', ['expected_version' => 2]), 'QUANTITY_CONFLICT');
        self::assertSame(4, $db->balances[163]['RESERVED']);
        $ready = $this->service($db, true, 0, true);
        $draft = $ready->action($this->actor(), $order['order_number'], 'return_to_draft', ['expected_version' => 2]);
        self::assertSame('Draft', $draft['status']); self::assertSame(0, $db->balances[163]['RESERVED']);
        self::assertSame(['RELEASED', 'RELEASED'], array_column($db->reservations, 'RESERVATION_STATUS'));
        $submitted = $ready->action($this->actor(), $order['order_number'], 'submit', ['expected_version' => 3]);
        self::assertSame('Submitted', $submitted['status']); self::assertCount(4, $db->reservations);
        self::assertSame(4, $db->balances[163]['RESERVED']); self::assertCount(1, $db->sales->links);
    }

    public function testReservationByIdKeepsReleasedHistorySeparateFromCurrentDraftClaims(): void
    {
        $db = new ReservationMemoryConnection(); $service = $this->service($db, true, 0, true);
        $order = $this->create($service);
        $service->action($this->actor(), $order['order_number'], 'submit', ['expected_version' => 1]);
        $oldId = $db->reservations[0]['RESERVATION_ID'];
        $service->action($this->actor(), $order['order_number'], 'return_to_draft', ['expected_version' => 2]);
        $service->action($this->actor(), $order['order_number'], 'submit', ['expected_version' => 3]);
        $currentId = $db->reservations[2]['RESERVATION_ID'];
        $calls = 0;
        $provider = new ReservationService($db, static function () use (&$calls): int { $calls++; return 1; });
        $tx = new TransactionContext($db);
        $history = $provider->getReservationById($this->actor(), $tx, $oldId);
        self::assertSame('RELEASED', $history['status']); self::assertSame($oldId, $history['reservation_id']);
        self::assertSame(0, $history['claimed_quantity']); self::assertSame(0, $history['releasable_quantity']);
        self::assertSame(0, $calls); // Current-cycle claims must not be applied to released history.
        $current = $provider->getReservationById($this->actor(), $tx, $currentId);
        self::assertSame('ACTIVE', $current['status']); self::assertSame(1, $current['claimed_quantity']);
        self::assertSame(2, $current['releasable_quantity']); self::assertSame(1, $calls);
        $latest = $provider->getReservationDetail($this->actor(), $tx, 11, (int) $order['lines'][0]['line_id']);
        self::assertSame($currentId, $latest['reservation_id']); self::assertSame(2, $calls);
        $this->reject(fn () => $provider->getReservationById(new Actor('admin', 'admin', [], 8, true), $tx, $oldId), 'NOT_FOUND');
        $this->reject(fn () => $provider->getReservationById($this->actor(false), $tx, $oldId), 'FORBIDDEN');
        foreach ([0, 10000000000] as $invalid) {
            $this->reject(fn () => $provider->getReservationById($this->actor(), $tx, $invalid), 'VALIDATION_ERROR');
        }
        $this->reject(fn () => $provider->getReservationById($this->actor(), $tx, 999), 'NOT_FOUND');
        self::assertSame(4, $db->balances[163]['RESERVED']);
    }

    public function testDispatchedDetailsDoNotRunDraftClaimLookup(): void
    {
        $db = new ReservationMemoryConnection(); $service = $this->service($db, true, 0, true);
        $order = $this->create($service);
        $service->action($this->actor(), $order['order_number'], 'submit', ['expected_version' => 1]);
        $db->reservations[0]['RESERVATION_STATUS'] = 'DISPATCHED';
        $db->reservations[0]['TRIP_ID'] = 7; $db->reservations[0]['TRIP_LINE_ID'] = 501;
        $provider = new ReservationService($db, static function (): int { throw new \LogicException('No Draft claim lookup for consumed stock.'); });
        $tx = new TransactionContext($db);
        $detail = $provider->getReservationDetail($this->actor(), $tx, 11, (int) $order['lines'][0]['line_id']);
        self::assertSame('DISPATCHED', $detail['status']); self::assertSame(3, $detail['consumed_quantity']);
        self::assertSame(0, $detail['claimed_quantity']); self::assertSame(0, $detail['active_quantity']);
        self::assertSame($detail, $provider->getReservationById($this->actor(), $tx, $detail['reservation_id']));
    }

    public function testSalesAuditFailureRollsBackInventoryProviderWrites(): void
    {
        $db = new ReservationMemoryConnection(); $service = $this->service($db); $order = $this->create($service);
        $db->sales->failAudit = true;
        try { $service->action($this->actor(), $order['order_number'], 'submit', ['expected_version' => 1]); self::fail('Audit must fail.'); }
        catch (DatabaseError) {
            self::assertSame([], $db->reservations); self::assertSame(0, $db->balances[163]['RESERVED']);
            self::assertSame('Draft', $service->get($this->actor(), $order['order_number'])['status']);
        }
    }
}

/** Executes real provider SQL against a transactional double; not Oracle/concurrency evidence. */
final class ReservationMemoryConnection implements Connection
{
    public OrderMemoryConnection $sales;
    public array $products = [], $balances = [], $reservations = [], $balanceLockOrder = [];
    public bool $salesLinesLockedBeforeInventory = true;
    public int $tripLookupCount = 0, $failTripLookupAt = 0;
    private bool $salesLocked = false;
    private ?array $snapshot = null;
    private int $sequence = 0;
    public function __construct()
    {
        $this->sales = new OrderMemoryConnection();
        foreach ([163, 268] as $id) {
            $this->products[$id] = ['COMPANY_ID' => 7, 'PRODUCT_ID' => (string) $id, 'WAREHOUSE_ID' => 'W01', 'IS_ACTIVE' => 'Y'];
            $this->balances[$id] = ['ON_HAND' => 20, 'RESERVED' => 0];
        }
    }
    public function begin(): void { $this->sales->begin(); $this->snapshot = [$this->balances, $this->reservations]; $this->salesLocked = false; }
    public function commit(): void { $this->sales->commit(); $this->snapshot = null; }
    public function rollback(): void { $this->sales->rollback(); [$this->balances, $this->reservations] = $this->snapshot; $this->snapshot = null; }
    public function executeSchema(string $sql): void { throw new \LogicException('No DDL'); }
    public function query(string $sql, array $p = []): array
    {
        if (str_contains($sql, 'SELECT RECORD#, TRIP_ID, LOGISTICS_TRIP_LINE_ID, TRIP_STATUS') &&
            ++$this->tripLookupCount === $this->failTripLookupAt) { throw new DatabaseError(942); }
        if (str_contains($sql, 'ME_INV_RESERVATION_SEQ.NEXTVAL')) { return [['RESERVATION_ID' => ++$this->sequence]]; }
        if (str_contains($sql, 'FROM ME_INV_')) {
            if (!isset($p['company_id']) || !str_contains($sql, 'COMPANY_ID = :company_id')) { throw new \LogicException('Unscoped Inventory query'); }
            if (str_contains($sql, 'FROM ME_INV_PRODUCTS')) {
                $ids = isset($p['product_id']) ? [$p['product_id']] : array_values(array_diff_key($p, ['company_id' => true]));
                return array_values(array_filter($this->products, fn ($r) => $r['COMPANY_ID'] === $p['company_id'] && in_array((int) $r['PRODUCT_ID'], $ids, true)));
            }
            if ($p['company_id'] !== 7) { return []; }
            if (str_contains($sql, 'FROM ME_INV_STOCK_BALANCE')) {
                if (str_contains($sql, 'FOR UPDATE')) { $this->balanceLockOrder[] = $p['product_id']; $this->salesLinesLockedBeforeInventory = $this->salesLinesLockedBeforeInventory && $this->salesLocked; }
                return isset($this->balances[$p['product_id']]) ? [$this->balances[$p['product_id']]] : [];
            }
            if (str_contains($sql, 'FROM ME_INV_RESERVATION')) {
                $rows = array_values(array_filter($this->reservations, fn ($r) => $r['COMPANY_ID'] === $p['company_id'] &&
                    (!isset($p['reservation_id']) || $r['RESERVATION_ID'] === $p['reservation_id']) &&
                    (!isset($p['sales_order_id']) || $r['SALES_ORDER_ID'] === $p['sales_order_id']) &&
                    (!isset($p['sales_line_id']) || $r['SALES_LINE_ID'] === $p['sales_line_id'])));
                if (str_contains($sql, 'ORDER BY RESERVATION_ID DESC')) { usort($rows, fn ($a, $b) => $b['RESERVATION_ID'] <=> $a['RESERVATION_ID']); }
                return $rows;
            }
        }
        $rows = $this->sales->query($sql, $p);
        if (str_contains($sql, 'FROM ME_OE_DOCUMENT_LINES') && str_contains($sql, 'ORDER BY RECORD# FOR UPDATE')) {
            $this->salesLocked = true; usort($rows, fn ($a, $b) => (int) $a['RECORD#'] <=> (int) $b['RECORD#']);
        }
        return $rows;
    }
    public function execute(string $sql, array $p = []): int
    {
        if (!str_contains($sql, 'ME_INV_')) { return $this->sales->execute($sql, $p); }
        if ($this->snapshot === null || ($p['company_id'] ?? null) !== 7 || !$this->salesLocked) { throw new \LogicException('Inventory writes require shared transaction/company/Sales locks'); }
        if (str_starts_with($sql, 'INSERT INTO ME_INV_RESERVATION')) {
            $this->reservations[] = ['COMPANY_ID' => 7, 'RESERVATION_ID' => $p['reservation_id'], 'SALES_ORDER_ID' => $p['sales_order_id'],
                'SALES_LINE_ID' => $p['sales_line_id'], 'PRODUCT_ID' => $p['product_id'], 'RESERVED_QUANTITY' => $p['quantity'],
                'RESERVATION_STATUS' => 'ACTIVE', 'TRIP_ID' => null, 'TRIP_LINE_ID' => null];
        } elseif (str_starts_with($sql, 'UPDATE ME_INV_STOCK_BALANCE')) {
            $balance = &$this->balances[$p['product_id']];
            if (str_contains($sql, 'RESERVED = RESERVED +')) { if ($balance['ON_HAND'] - $balance['RESERVED'] < $p['quantity']) { return 0; } $balance['RESERVED'] += $p['quantity']; }
            else { if ($balance['RESERVED'] < $p['quantity']) { return 0; } $balance['RESERVED'] -= $p['quantity']; }
        } elseif (str_starts_with($sql, 'UPDATE ME_INV_RESERVATION')) {
            foreach ($this->reservations as &$row) { if ($row['RESERVATION_ID'] === $p['reservation_id'] && $row['RESERVATION_STATUS'] === 'ACTIVE') { $row['RESERVATION_STATUS'] = 'RELEASED'; return 1; } }
            return 0;
        } else { throw new \LogicException('Unexpected Inventory write'); }
        return 1;
    }
}
