<?php
declare(strict_types=1);

namespace MiniErp\Tests\unit\sales;

use MiniErp\inventory\QuoteInventoryValidator;
use MiniErp\inventory\ReservationService;
use MiniErp\sales\orders\InventoryOrderAdapter;
use MiniErp\sales\orders\OrderService;
use MiniErp\sales\orders\SalesTripAssignmentGuard;
use MiniErp\shared\AppError;
use MiniErp\shared\database\TransactionContext;
use MiniErp\shared\database\TransactionManager;
use MiniErp\shared\security\Actor;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/InventoryOrderAdapterTest.php';

final class SalesTripAssignmentGuardTest extends TestCase
{
    private function actor(int $company = 7): Actor { return new Actor('admin', 'admin', [], $company, true); }
    private function setupOrder(): array
    {
        $db = new ReservationMemoryConnection(); $guard = new SalesTripAssignmentGuard();
        $service = new OrderService($db, new InventoryOrderAdapter(
            new ReservationService($db, $guard->getPlannedQuantity(...)), new QuoteInventoryValidator()), $guard,
            static fn (): string => '2026-11-25');
        $order = $service->create($this->actor(), ['source_quote_number' => 'SQ-000000000000010']);
        $order = $service->action($this->actor(), $order['order_number'], 'submit', ['expected_version' => 1]);
        return [$db, $guard, $service, $order];
    }
    private function reject(callable $operation, string $code): void
    {
        try { $operation(); self::fail('Expected ' . $code); }
        catch (AppError $error) { self::assertSame($code, $error->errorCode, $error->getMessage()); }
    }

    public function testAnyTripReferenceOrActiveStatusBlocksReturnAndIndependentGuard(): void
    {
        foreach ([['TRIP_ID' => 7], ['LOGISTICS_TRIP_LINE_ID' => 501], ['TRIP_STATUS' => 'Draft'],
            ['TRIP_STATUS' => 'Dispatched'], ['TRIP_STATUS' => 'Delivered'], ['TRIP_STATUS' => 'unexpected'],
            ['TRIP_STATUS' => 'Cancelled', 'LOGISTICS_TRIP_LINE_ID' => 501]] as $trip) {
            [$db, $guard, $service, $order] = $this->setupOrder();
            $lineId = (int) $order['lines'][0]['line_id'];
            $db->sales->lines[$lineId] = [...$db->sales->lines[$lineId], ...$trip];
            $this->reject(fn () => $service->action($this->actor(), $order['order_number'], 'return_to_draft', ['expected_version' => 2]), 'INVALID_STATE');
            $this->reject(fn () => (new TransactionManager($db))->run(fn (TransactionContext $tx) =>
                $guard->assertUnassigned($tx, $this->actor(), '11')), 'INVALID_STATE');
            self::assertSame(4, $db->balances[163]['RESERVED']);
            self::assertSame('SUBMITTED', $db->sales->documents[11]['STATUS']);
            self::assertSame(2, $db->sales->documents[11]['VERSION']);
        }
    }

    public function testClearedCancelledReferencesAllowWholeOrderRelease(): void
    {
        [$db, , $service, $order] = $this->setupOrder();
        foreach ($order['lines'] as $line) {
            $db->sales->lines[(int) $line['line_id']]['TRIP_STATUS'] = 'Cancelled';
        }
        self::assertSame(['move_to_draft'], $service->get($this->actor(), $order['order_number'])['available_actions']);
        $request = ['expected_version' => 2, 'action_reference' => 'return-cleared'];
        $draft = $service->action($this->actor(), $order['order_number'], 'return_to_draft', $request);
        self::assertSame('Draft', $draft['status']); self::assertSame(3, $draft['current_version']);
        self::assertSame(['ON_HAND' => 20, 'RESERVED' => 0], $db->balances[163]);
        self::assertSame(['RELEASED', 'RELEASED'], array_column($db->reservations, 'RESERVATION_STATUS'));
        self::assertSame($draft, $service->action($this->actor(), $order['order_number'], 'return_to_draft', $request));
    }

    public function testMixedTripsKeepDraftClaimsPerLineAndAnyRemainingAssignmentBlocksReturn(): void
    {
        [$db, $guard, , $order] = $this->setupOrder();
        [$first, $second] = array_column($order['lines'], 'line_id');
        $db->sales->lines[(int) $first] = [...$db->sales->lines[(int) $first], 'TRIP_ID' => 7, 'LOGISTICS_TRIP_LINE_ID' => 701, 'TRIP_STATUS' => 'Dispatched'];
        $db->sales->lines[(int) $second] = [...$db->sales->lines[(int) $second], 'TRIP_ID' => 8, 'LOGISTICS_TRIP_LINE_ID' => 802, 'TRIP_STATUS' => 'Draft'];
        (new TransactionManager($db))->run(function (TransactionContext $tx) use ($db, $guard, $first, $second): void {
            self::assertSame(0, $guard->getPlannedQuantity($this->actor(), $tx, 11, (int) $first));
            self::assertSame(1, $guard->getPlannedQuantity($this->actor(), $tx, 11, (int) $second));
            $db->sales->lines[(int) $second] = [...$db->sales->lines[(int) $second], 'TRIP_ID' => null, 'LOGISTICS_TRIP_LINE_ID' => null, 'TRIP_STATUS' => 'Cancelled'];
            self::assertSame(0, $guard->getPlannedQuantity($this->actor(), $tx, 11, (int) $second));
            $this->reject(fn () => $guard->assertUnassigned($tx, $this->actor(), '11'), 'INVALID_STATE');
        });
    }

    public function testCallbackUsesSavedDraftClaimQuantityAndValidatesOwnership(): void
    {
        [$db, $guard, , $order] = $this->setupOrder();
        $lineId = (int) $order['lines'][0]['line_id'];
        $run = fn (): int => (new TransactionManager($db))->run(fn (TransactionContext $tx): int =>
            $guard->getPlannedQuantity($this->actor(), $tx, 11, $lineId));
        self::assertSame(0, $run());
        $db->sales->lines[$lineId]['TRIP_STATUS'] = 'Draft'; self::assertSame(3, $run());
        $db->sales->lines[$lineId]['TRIP_STATUS'] = 'Cancelled';
        $db->sales->lines[$lineId]['LOGISTICS_TRIP_LINE_ID'] = 501; self::assertSame(3, $run());
        $db->sales->lines[$lineId]['LOGISTICS_TRIP_LINE_ID'] = null; self::assertSame(0, $run());
        $this->reject(fn () => (new TransactionManager($db))->run(fn (TransactionContext $tx): int =>
            $guard->getPlannedQuantity($this->actor(8), $tx, 11, $lineId)), 'NOT_FOUND');
        $this->reject(fn () => (new TransactionManager($db))->run(fn (TransactionContext $tx): int =>
            $guard->getPlannedQuantity($this->actor(), $tx, 11, 999)), 'NOT_FOUND');
    }

    public function testFailedLookupAndLaterLineReleaseFailurePreserveSubmittedStateAndStock(): void
    {
        foreach ([1 => 'LOGISTICS_UNAVAILABLE', 5 => 'INVENTORY_UNAVAILABLE'] as $fault => $code) {
            [$db, , $service, $order] = $this->setupOrder();
            $db->tripLookupCount = 0; $db->failTripLookupAt = $fault;
            $this->reject(fn () => $service->action($this->actor(), $order['order_number'], 'return_to_draft',
                ['expected_version' => 2, 'action_reference' => 'failed-release']), $code);
            self::assertSame(['ON_HAND' => 20, 'RESERVED' => 4], $db->balances[163]);
            self::assertSame(['ACTIVE', 'ACTIVE'], array_column($db->reservations, 'RESERVATION_STATUS'));
            self::assertSame(2, $db->sales->documents[11]['VERSION']);
            self::assertSame('SUBMITTED', $db->sales->documents[11]['STATUS']);
            self::assertCount(2, $db->sales->actions);
        }
    }
}
