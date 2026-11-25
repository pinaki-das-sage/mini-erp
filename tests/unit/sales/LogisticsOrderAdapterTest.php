<?php
declare(strict_types=1);

namespace MiniErp\Tests\unit\sales;

use MiniErp\logistics\SalesOrderProvider;
use MiniErp\sales\orders\LogisticsOrderAdapter;
use MiniErp\shared\AppError;
use MiniErp\shared\database\Connection;
use MiniErp\shared\database\DatabaseError;
use MiniErp\shared\database\TransactionContext;
use MiniErp\shared\security\Actor;
use PHPUnit\Framework\TestCase;

final class LogisticsOrderAdapterTest extends TestCase
{
    private function setupProvider(): array
    {
        $db = new LogisticsProviderConnection();
        return [$db, new LogisticsOrderAdapter(new SalesOrderProvider($db)), new TransactionContext($db),
            new Actor('driver-user', 'driver', [], 7, true)];
    }

    public function testAssignmentsClaimsAndStatusTranslationUseLogisticsTablesAndCompany(): void
    {
        [$db, $service, $tx, $actor] = $this->setupProvider();
        self::assertSame('Draft', $service->getAssignments($tx, $actor, '11')[0]['trip_status']);
        self::assertSame(2, $service->getPlannedQuantity($actor, $tx, 11, 123));
        foreach (['S' => 'Dispatched', 'C' => 'Delivered'] as $stored => $status) {
            $db->status = $stored;
            self::assertSame($status, $service->getAssignments($tx, $actor, '11')[0]['trip_status']);
            self::assertSame(0, $service->getPlannedQuantity($actor, $tx, 11, 123));
        }
        $db->status = 'X';
        self::assertSame([], $service->getAssignments($tx, $actor, '11'));
        $service->assertUnassigned($tx, $actor, '11');
        self::assertSame([], $service->getAssignments($tx, new Actor('other', 'other', [], 8, true), '11'));
        self::assertSame([], $service->getAssignments($tx, $actor, '12'));
        $db->status = 'D';
        $this->reject(fn () => $service->assertUnassigned($tx, $actor, '11'), 'INVALID_STATE');
    }

    public function testEventsRequirePersistedActorEvidenceAndCompleteMapping(): void
    {
        [$db, $service, $tx, $actor] = $this->setupProvider();
        $mapping = [['line_id' => '123', 'trip_line_id' => '501']];
        foreach (['D' => 'assignment', 'S' => 'assignment', 'X' => 'cancellation', 'C' => 'delivery'] as $status => $event) {
            $db->status = $status;
            $referenceEvent = $status === 'S' ? 'dispatch' : $event;
            $service->assertEvent($tx, $actor, $event, '11', '7', $mapping, 'logtrip_' . $referenceEvent . '_event_1');
        }
        $db->eventActor = 'someone-else';
        $this->reject(fn () => $service->assertEvent($tx, $actor, 'delivery', '11', '7', $mapping, 'logtrip_delivery_event_2'), 'FORBIDDEN');
        $db->eventActor = $db->actorUserId; $db->driverUser = 'another-driver';
        $this->reject(fn () => $service->assertEvent($tx, $actor, 'delivery', '11', '7', $mapping, 'logtrip_delivery_event_2'), 'FORBIDDEN');
        $db->driverUser = $db->actorUserId; $db->timestamp = null;
        $this->reject(fn () => $service->assertEvent($tx, $actor, 'delivery', '11', '7', $mapping, 'logtrip_delivery_event_2'), 'FORBIDDEN');
        $db->timestamp = '2026-11-25';
        $this->reject(fn () => $service->assertEvent($tx, $actor, 'delivery', '11', '7', [], 'logtrip_delivery_event_2'), 'INVALID_STATE');
        $this->reject(fn () => $service->assertEvent($tx, $actor, 'delivery', '11', '7', [['line_id' => '123', 'trip_line_id' => '502']], 'logtrip_delivery_event_2'), 'INVALID_STATE');
        $this->reject(fn () => $service->assertEvent($tx, $actor, 'assignment', '11', '7', $mapping, 'logtrip_assignment_event_2'), 'INVALID_STATE');
        $this->reject(fn () => $service->assertEvent($tx, $actor, 'delivery', '12', '7', $mapping, 'logtrip_delivery_event_2'), 'NOT_FOUND');
        $this->reject(fn () => $service->assertEvent($tx, $actor, 'delivery', '11', '7', $mapping, 'logtrip_assignment_wrong_event'), 'INVALID_STATE');
        $db->activeUser = false;
        $this->reject(fn () => $service->assertEvent($tx, $actor, 'delivery', '11', '7', $mapping, 'logtrip_delivery_event_2'), 'FORBIDDEN');
    }

    public function testProviderRejectsMultipleTripsOrMixedProgressForOneOrder(): void
    {
        [$db, $service, $tx, $actor] = $this->setupProvider();
        $db->assignmentRows = [
            ['LINE_ID' => 123, 'TRIP_ID' => 7, 'TRIP_LINE_ID' => 501, 'PRODUCT_ID' => 163, 'QUANTITY_TEXT' => '2', 'STATUS' => 'D'],
            ['LINE_ID' => 124, 'TRIP_ID' => 8, 'TRIP_LINE_ID' => 502, 'PRODUCT_ID' => 268, 'QUANTITY_TEXT' => '3', 'STATUS' => 'S'],
            ['LINE_ID' => 125, 'TRIP_ID' => 9, 'TRIP_LINE_ID' => 503, 'PRODUCT_ID' => 37, 'QUANTITY_TEXT' => '1', 'STATUS' => 'C'],
        ];
        $this->reject(fn () => $service->getAssignments($tx, $actor, '11'), 'INVALID_STATE');
        $this->reject(fn () => $service->getPlannedQuantity($actor, $tx, 11, 123), 'INVALID_STATE');
        foreach ($db->assignmentRows as &$row) { $row['TRIP_ID'] = 7; }
        unset($row);
        $this->reject(fn () => $service->getAssignments($tx, $actor, '11'), 'INVALID_STATE');
        foreach ($db->assignmentRows as &$row) { $row['STATUS'] = 'D'; }
        unset($row);
        self::assertSame(['7', '7', '7'], array_column($service->getAssignments($tx, $actor, '11'), 'trip_id'));
        self::assertSame(2, $service->getPlannedQuantity($actor, $tx, 11, 123));
    }

    public function testInvalidFactsAndDuplicateClaimsDoNotBecomeZero(): void
    {
        [$db, $service, $tx, $actor] = $this->setupProvider();
        $db->duplicate = true;
        $this->reject(fn () => $service->getPlannedQuantity($actor, $tx, 11, 123), 'INVALID_STATE');
        $db->duplicate = false;
        foreach (['0', '-1', '1.5', '#########', '1000000000'] as $quantity) {
            $db->quantity = $quantity;
            $this->reject(fn () => $service->getPlannedQuantity($actor, $tx, 11, 123), 'LOGISTICS_UNAVAILABLE');
        }
        $db->quantity = '2'; $db->status = '?';
        $this->reject(fn () => $service->getAssignments($tx, $actor, '11'), 'LOGISTICS_UNAVAILABLE');
        $db->status = 'D'; $db->fail = true;
        $this->expectException(DatabaseError::class);
        $service->getPlannedQuantity($actor, $tx, 11, 123);
    }

    public function testPermissionFailureIsNotAnEmptyResult(): void
    {
        [$db, $service, $tx, $actor] = $this->setupProvider();
        $db->registered = false;
        $this->reject(fn () => $service->getAssignments($tx, $actor, '11'), 'FORBIDDEN');
        $db->registered = true;
        $this->reject(fn () => $service->getAssignments($tx, new Actor('denied', 'denied', [], 7), '11'), 'FORBIDDEN');
    }

    private function reject(callable $operation, string $code): void
    {
        try { $operation(); self::fail('Expected ' . $code); }
        catch (AppError $error) { self::assertSame($code, $error->errorCode); }
    }
}

final class LogisticsProviderConnection implements Connection
{
    public ?array $assignmentRows = null;
    public string $status = 'D';
    public string $actorUserId = '42';
    public string $eventActor = '42';
    public string $driverUser = '42';
    public bool $activeUser = true;
    public ?string $timestamp = '2026-11-25';
    public string $quantity = '2';
    public bool $duplicate = false;
    public bool $fail = false;
    public bool $registered = true;

    public function query(string $sql, array $parameters = []): array
    {
        if (str_contains($sql, 'FROM ME_OBJECTS')) {
            return $this->registered ? [['ACTIVE' => 1, 'ALLOW_CREATE' => 1, 'ALLOW_READ' => 1, 'ALLOW_UPDATE' => 1, 'ALLOW_DELETE' => 1]] : [];
        }
        if (str_contains($sql, 'FROM ME_USER_PERMISSIONS')) { return []; }
        TestCase::assertArrayHasKey('company_id', $parameters);
        if ($this->fail) { throw new DatabaseError(942); }
        if ($parameters['company_id'] !== 7 || isset($parameters['order_id']) && $parameters['order_id'] !== '11') { return []; }
        if (str_contains($sql, 'FROM ME_USERS')) {
            return $this->activeUser && $parameters['user_id'] === 'driver-user' ? [['ID' => $this->actorUserId]] : [];
        }
        if (str_contains($sql, 'DRIVER_USER_ID')) {
            if ($parameters['trip_id'] !== '7') { return []; }
            return [['ID' => 7, 'STATUS' => $this->status, 'SALES_ORDER_ID' => 11,
                'ASSIGNED_AT' => $this->timestamp, 'ASSIGNED_BY' => $this->eventActor,
                'DISPATCHED_AT' => $this->timestamp, 'DISPATCHED_BY' => $this->eventActor,
                'CANCELLED_AT' => $this->timestamp, 'CANCELLED_BY' => $this->eventActor,
                'DELIVERED_AT' => $this->timestamp, 'DELIVERED_BY' => $this->eventActor, 'DRIVER_USER_ID' => $this->driverUser]];
        }
        if (str_contains($sql, 'FROM ME_LOG_TRIP_LINES WHERE')) { return [['LINE_ID' => 123, 'TRIP_LINE_ID' => 501]]; }
        if (preg_match('/FROM ME_LOG_TRIPS T\s+JOIN ME_LOG_TRIP_LINES/', $sql)) {
            if ($this->assignmentRows !== null) { return $this->assignmentRows; }
            if ($this->status === 'X') { return []; }
            $row = ['LINE_ID' => 123, 'TRIP_ID' => 7, 'TRIP_LINE_ID' => 501, 'PRODUCT_ID' => 163,
                'QUANTITY_TEXT' => $this->quantity, 'STATUS' => $this->status];
            return $this->duplicate ? [$row, [...$row, 'TRIP_ID' => 8, 'TRIP_LINE_ID' => 502]] : [$row];
        }
        throw new \LogicException('Unhandled SQL: ' . $sql);
    }

    public function execute(string $sql, array $parameters = []): int { throw new \LogicException('Read provider must not write.'); }
    public function executeSchema(string $sql): void { throw new \LogicException('No DDL.'); }
    public function begin(): void { throw new \LogicException('Caller owns transaction.'); }
    public function commit(): void { throw new \LogicException('Caller owns transaction.'); }
    public function rollback(): void { throw new \LogicException('Caller owns transaction.'); }
}
