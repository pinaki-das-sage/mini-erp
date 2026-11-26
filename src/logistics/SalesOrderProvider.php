<?php

declare(strict_types=1);

namespace MiniErp\logistics;

use MiniErp\shared\AppError;
use MiniErp\shared\database\Connection;
use MiniErp\shared\database\TransactionContext;
use MiniErp\shared\security\Actor;
use MiniErp\shared\security\Authorization;
use MiniErp\sales\orders\LogisticsOrderProvider as LogisticsOrderProviderContract;

final readonly class SalesOrderProvider implements LogisticsOrderProviderContract
{
    public function __construct(private Connection $connection) {}

    /** Owner event references use logtrip_assignment_, logtrip_dispatch_, logtrip_cancellation_
     * or logtrip_delivery_. Audit and assigned-driver IDs reference numeric ME_USERS.ID,
     * resolved from the authenticated actor's company-scoped USER_ID.
     */
    public function assertEvent(
        TransactionContext $tx,
        Actor $actor,
        string $event,
        string $orderId,
        string $tripId,
        array $lines,
        string $eventReference
    ): void {
        if (
            !in_array($event, ['assignment', 'cancellation', 'delivery'], true)
            || !preg_match('/\A[A-Za-z0-9_-]{1,100}\z/D', $eventReference)
        ) {
            throw new AppError('VALIDATION_ERROR', 'Provide a valid Logistics event and reference.');
        }

        $this->id($orderId);
        $this->id($tripId);
        $rows = $tx->query(
            'SELECT T.ID, T.STATUS, T.SALES_ORDER_ID, T.ASSIGNED_AT, T.ASSIGNED_BY,
                    T.CANCELLED_AT, T.CANCELLED_BY, T.DISPATCHED_AT, T.DISPATCHED_BY,
                    T.DELIVERED_AT, T.DELIVERED_BY, D.USER_ID AS DRIVER_USER_ID
             FROM ME_LOG_TRIPS T LEFT JOIN ME_LOG_DRIVERS D
               ON D.COMPANY_ID = T.COMPANY_ID AND D.ID = T.DRIVER_ID
             WHERE T.COMPANY_ID = :company_id AND T.ID = :trip_id AND T.SALES_ORDER_ID = :order_id',
            ['company_id' => $actor->companyId, 'trip_id' => $tripId, 'order_id' => $orderId]
        );

        if (count($rows) !== 1) {
            throw new AppError('NOT_FOUND', 'Trip does not belong to this Order and company.', 404);
        }

        $users = $tx->query(
            'SELECT ID FROM ME_USERS WHERE COMPANY_ID = :company_id AND USER_ID = :user_id AND ACTIVE = 1',
            ['company_id' => $actor->companyId, 'user_id' => $actor->id]
        );
        if (count($users) !== 1) {
            throw new AppError('FORBIDDEN', 'Authenticated actor has no active company user record.', 403);
        }
        $actorUserId = (string) $users[0]['ID'];

        $trip = $rows[0];
        $expectedStatus = match (true) {
            $event === 'assignment' && str_starts_with($eventReference, 'logtrip_assignment_') => 'D',
            $event === 'assignment' && str_starts_with($eventReference, 'logtrip_dispatch_') => 'S',
            $event === 'cancellation' && str_starts_with($eventReference, 'logtrip_cancellation_') => 'X',
            $event === 'delivery' && str_starts_with($eventReference, 'logtrip_delivery_') => 'C',
            default => throw new AppError('INVALID_STATE', 'Trip event reference does not match the Sales event.', 409),
        };
        if ($trip['STATUS'] !== $expectedStatus) {
            throw new AppError('INVALID_STATE', 'Trip status does not match the Sales event.', 409);
        }

        $evidence = match ($expectedStatus) {
            'D' => 'ASSIGNED',
            'S' => 'DISPATCHED',
            'X' => 'CANCELLED',
            'C' => 'DELIVERED',
        };

        (new Authorization($this->connection))->requirePermission(
            $actor,
            'logistics.trips',
            match ($expectedStatus) {
                'D' => 'create',
                'S', 'X', 'C' => 'update',
            }
        );

        if (($trip[$evidence . '_AT'] ?? null) === null || (string) ($trip[$evidence . '_BY'] ?? '') !== $actorUserId) {
            throw new AppError('FORBIDDEN', 'Trip event must be recorded by the authenticated actor.', 403);
        }

        if ($event === 'delivery' && (string) ($trip['DRIVER_USER_ID'] ?? '') !== $actorUserId) {
            throw new AppError('FORBIDDEN', 'Only the assigned driver may record delivery.', 403);
        }

        $actual = $tx->query(
            'SELECT ID AS TRIP_LINE_ID, SALES_LINE_ID AS LINE_ID
             FROM ME_LOG_TRIP_LINES WHERE COMPANY_ID = :company_id AND TRIP_ID = :trip_id ORDER BY ID',
            ['company_id' => $actor->companyId, 'trip_id' => $tripId]
        );
        $expected = [];

        foreach ($lines as $line) {
            $lineId = $this->id($line['line_id'] ?? null);
            $tripLineId = $this->id($line['trip_line_id'] ?? null);

            if (isset($expected[$lineId]) || in_array($tripLineId, $expected, true)) {
                throw new AppError('VALIDATION_ERROR', 'Duplicate Order or Trip line mapping.');
            }

            $expected[$lineId] = $tripLineId;
        }

        if ($expected === [] || count($actual) !== count($expected)) {
            throw new AppError('INVALID_STATE', 'Provide the complete saved Trip line mapping.', 409);
        }

        foreach ($actual as $line) {
            if (($expected[(string) $line['LINE_ID']] ?? null) !== (string) $line['TRIP_LINE_ID']) {
                throw new AppError('INVALID_STATE', 'Trip line mapping does not match this Order event.', 409);
            }
        }
    }

    public function getAssignments(TransactionContext $tx, Actor $actor, string $orderId): array
    {
        $this->id($orderId);
        (new Authorization($this->connection))->requirePermission($actor, 'logistics.trips', 'read');

        $rows = $tx->query(
            "SELECT L.SALES_LINE_ID AS LINE_ID, T.ID AS TRIP_ID, L.ID AS TRIP_LINE_ID,
                    L.PRODUCT_ID, TO_CHAR(L.QUANTITY, 'TM9', 'NLS_NUMERIC_CHARACTERS = ''.,''') AS QUANTITY_TEXT, T.STATUS
             FROM ME_LOG_TRIPS T
             JOIN ME_LOG_TRIP_LINES L ON L.COMPANY_ID = T.COMPANY_ID AND L.TRIP_ID = T.ID
             WHERE T.COMPANY_ID = :company_id AND T.SALES_ORDER_ID = :order_id AND T.STATUS <> 'X'
             ORDER BY L.SALES_LINE_ID, T.ID, L.ID",
            ['company_id' => $actor->companyId, 'order_id' => $orderId]
        );
        $result = [];
        $seen = [];
        $tripStatuses = [];
        $tripLines = [];

        foreach ($rows as $row) {
            $status = match ($row['STATUS']) {
                'D' => 'Draft',
                'S' => 'Dispatched',
                'C' => 'Delivered',
                default => throw new AppError('LOGISTICS_UNAVAILABLE', 'Unknown Trip status.', 503),
            };
            $fact = [
                'line_id' => $this->storedId($row['LINE_ID']),
                'trip_id' => $this->storedId($row['TRIP_ID']),
                'trip_line_id' => $this->storedId($row['TRIP_LINE_ID']),
                'product_id' => $this->storedId($row['PRODUCT_ID']),
                'quantity' => $this->storedId($row['QUANTITY_TEXT']),
                'trip_status' => $status
            ];

            if (isset($seen[$fact['line_id']]) || isset($tripLines[$fact['trip_line_id']])) {
                throw new AppError('INVALID_STATE', 'An Order line has multiple current Trip assignments.', 409);
            }
            if (isset($tripStatuses[$fact['trip_id']]) && $tripStatuses[$fact['trip_id']] !== $status) {
                throw new AppError('INVALID_STATE', 'Lines on the same Trip must have the same Trip status.', 409);
            }

            $seen[$fact['line_id']] = true;
            $tripLines[$fact['trip_line_id']] = true;
            $tripStatuses[$fact['trip_id']] = $status;
            $result[] = $fact;
        }

        return $result;
    }

    public function assertUnassigned(TransactionContext $tx, Actor $actor, string $orderId): void
    {
        if ($this->getAssignments($tx, $actor, $orderId) !== []) {
            throw new AppError('INVALID_STATE', 'Order has a Trip assignment.', 409);
        }
    }

    public function getPlannedQuantity(Actor $actor, TransactionContext $tx, int $orderId, int $lineId): int
    {
        $this->id((string) $lineId);

        foreach ($this->getAssignments($tx, $actor, (string) $orderId) as $line) {
            if ($line['line_id'] === (string) $lineId && $line['trip_status'] === 'Draft') {
                if (strlen($line['quantity']) > 9) {
                    throw new AppError('LOGISTICS_UNAVAILABLE', 'Trip claim exceeds supported Inventory quantity.', 503);
                }

                return (int) $line['quantity'];
            }
        }

        return 0;
    }

    private function id(mixed $value): string
    {
        if (!is_string($value) || !preg_match('/\A[1-9][0-9]{0,14}\z/D', $value)) {
            throw new AppError('VALIDATION_ERROR', 'IDs must be positive decimal strings of up to 15 digits.');
        }

        return $value;
    }

    private function storedId(mixed $value): string
    {
        $value = (string) $value;

        if (!preg_match('/\A[1-9][0-9]{0,14}\z/D', $value)) {
            throw new AppError('LOGISTICS_UNAVAILABLE', 'Invalid saved Logistics ID or quantity.', 503);
        }

        return $value;
    }
}
