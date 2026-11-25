<?php
declare(strict_types=1);

namespace MiniErp\logistics;

use MiniErp\shared\AppError;
use MiniErp\shared\database\Connection;
use MiniErp\shared\database\TransactionContext;
use MiniErp\shared\security\Actor;
use MiniErp\shared\security\Authorization;

/** Logistics-owned reads based on log_002 through log_009 at fd03d60.
 * Caller owns the transaction and Trip locks; do not commit or write Sales tables here.
 * Proposed status mapping: D=Draft, S=Dispatched, C=Closed/delivered, X=Cancelled.
 */
final readonly class SalesOrderProvider
{
    public function __construct(private Connection $connection) {}

    /** Verify persisted event evidence, not a client assertion. Sales owns event-reference replay.
     * Cancellation must retain the Trip and its lines until the Sales callback succeeds.
     * @param list<array{line_id: string, trip_line_id: string}> $lines All Order lines on this same Trip.
     */
    public function assertEvent(TransactionContext $tx, Actor $actor, string $event, string $orderId,
        string $tripId, array $lines, string $eventReference): void
    {
        if (!in_array($event, ['assignment', 'cancellation', 'delivery'], true) ||
            !preg_match('/\A[A-Za-z0-9_-]{1,100}\z/D', $eventReference)) {
            throw new AppError('VALIDATION_ERROR', 'Provide a valid Logistics event and reference.');
        }
        $this->id($orderId); $this->id($tripId);
        // No FOR UPDATE here: the owner must acquire Trip locks BEFORE Sales preflight locks.
        $rows = $tx->query('SELECT T.ID, T.STATUS, T.SALES_ORDER_ID, T.ASSIGNED_AT, T.ASSIGNED_BY,
            T.CANCELLED_AT, T.CANCELLED_BY, T.DISPATCHED_AT, T.DISPATCHED_BY,
            T.DELIVERED_AT, T.DELIVERED_BY, D.USER_ID AS DRIVER_USER_ID
            FROM ME_LOG_TRIPS T LEFT JOIN ME_LOG_DRIVERS D
              ON D.COMPANY_ID = T.COMPANY_ID AND D.ID = T.DRIVER_ID
            WHERE T.COMPANY_ID = :company_id AND T.ID = :trip_id AND T.SALES_ORDER_ID = :order_id',
            ['company_id' => $actor->companyId, 'trip_id' => $tripId, 'order_id' => $orderId]);
        if (count($rows) !== 1) { throw new AppError('NOT_FOUND', 'Trip does not belong to this Order and company.', 404); }
        $trip = $rows[0];
        $status = $trip['STATUS'];
        $evidence = match (true) {
            $event === 'assignment' && $status === 'D' => 'ASSIGNED',
            $event === 'assignment' && $status === 'S' => 'DISPATCHED',
            $event === 'cancellation' && $status === 'X' => 'CANCELLED',
            $event === 'delivery' && $status === 'C' => 'DELIVERED',
            default => throw new AppError('INVALID_STATE', 'Trip status does not match the Sales event.', 409),
        };
        (new Authorization($this->connection))->requirePermission($actor, 'logistics.trips',
            $evidence === 'ASSIGNED' ? 'create' : 'update');
        if (($trip[$evidence . '_AT'] ?? null) === null || (string) ($trip[$evidence . '_BY'] ?? '') !== $actor->id) {
            throw new AppError('FORBIDDEN', 'Trip event must be recorded by the authenticated actor.', 403);
        }
        if ($event === 'delivery' && (string) ($trip['DRIVER_USER_ID'] ?? '') !== $actor->id) {
            throw new AppError('FORBIDDEN', 'Only the assigned driver may record delivery.', 403);
        }
        $actual = $tx->query('SELECT ID AS TRIP_LINE_ID, SALES_LINE_ID AS LINE_ID
            FROM ME_LOG_TRIP_LINES WHERE COMPANY_ID = :company_id AND TRIP_ID = :trip_id ORDER BY ID',
            ['company_id' => $actor->companyId, 'trip_id' => $tripId]);
        $expected = [];
        foreach ($lines as $line) {
            $lineId = $this->id($line['line_id'] ?? null);
            $tripLine = $this->id($line['trip_line_id'] ?? null);
            if (isset($expected[$lineId]) || in_array($tripLine, $expected, true)) {
                throw new AppError('VALIDATION_ERROR', 'Duplicate Order or Trip line mapping.');
            }
            $expected[$lineId] = $tripLine;
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

    /** @return list<array{line_id:string, trip_id:string, trip_line_id:string, product_id:string, quantity:string, trip_status:string}> */
    public function getAssignments(TransactionContext $tx, Actor $actor, string $orderId): array
    {
        $this->id($orderId);
        (new Authorization($this->connection))->requirePermission($actor, 'logistics.trips', 'read');
        $rows = $tx->query("SELECT L.SALES_LINE_ID AS LINE_ID, T.ID AS TRIP_ID, L.ID AS TRIP_LINE_ID,
            L.PRODUCT_ID, TO_CHAR(L.QUANTITY, 'TM9', 'NLS_NUMERIC_CHARACTERS = ''.,''') AS QUANTITY_TEXT, T.STATUS
            FROM ME_LOG_TRIPS T JOIN ME_LOG_TRIP_LINES L ON L.COMPANY_ID = T.COMPANY_ID AND L.TRIP_ID = T.ID
            WHERE T.COMPANY_ID = :company_id AND T.SALES_ORDER_ID = :order_id AND T.STATUS <> 'X'
            ORDER BY L.SALES_LINE_ID, T.ID, L.ID", ['company_id' => $actor->companyId, 'order_id' => $orderId]);
        $result = []; $seen = [];
        foreach ($rows as $row) {
            $status = match ($row['STATUS']) {
                'D' => 'Draft', 'S' => 'Dispatched', 'C' => 'Delivered',
                default => throw new AppError('LOGISTICS_UNAVAILABLE', 'Unknown Trip status.', 503),
            };
            $fact = ['line_id' => $this->storedId($row['LINE_ID']), 'trip_id' => $this->storedId($row['TRIP_ID']),
                'trip_line_id' => $this->storedId($row['TRIP_LINE_ID']), 'product_id' => $this->storedId($row['PRODUCT_ID']),
                'quantity' => $this->storedId($row['QUANTITY_TEXT']), 'trip_status' => $status];
            if (isset($seen[$fact['line_id']])) {
                throw new AppError('INVALID_STATE', 'An Order line has multiple current Trip assignments.', 409);
            }
            $seen[$fact['line_id']] = true; $result[] = $fact;
        }
        if (count(array_unique(array_column($result, 'trip_id'))) > 1 ||
            count(array_unique(array_column($result, 'trip_status'))) > 1) {
            throw new AppError('INVALID_STATE', 'The whole Order must belong to one Trip with the same status on every line.', 409);
        }
        return $result;
    }

    public function assertUnassigned(TransactionContext $tx, Actor $actor, string $orderId): void
    {
        if ($this->getAssignments($tx, $actor, $orderId) !== []) {
            throw new AppError('INVALID_STATE', 'Order has a Trip assignment.', 409);
        }
    }

    /** Inventory callback: only Draft Trips claim still-reserved stock. */
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
