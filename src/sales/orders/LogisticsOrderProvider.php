<?php
declare(strict_types=1);

namespace MiniErp\sales\orders;

use MiniErp\shared\database\TransactionContext;
use MiniErp\shared\security\Actor;

/** Sales integration boundary, implemented by LogisticsOrderAdapter using Logistics' SalesOrderProvider.
 * All methods use the caller's transaction/company. Claim writers must share Sales' parent/line locks.
 * Lookup methods must not acquire Trip locks after Sales locks; the event caller already holds its Trip.
 * Confirm numeric IDs, status meanings, permissions and complete lock order before wiring production.
 */
interface LogisticsOrderProvider extends OrderLogistics
{
    /** Recheck the real authorized Logistics event/history, including assigned-driver authorization on delivery.
     * Cancellation rows/history must still exist until Sales clears its current FK references.
     * @param 'assignment'|'cancellation'|'delivery' $event
     * @param list<array{line_id: string, trip_line_id: string}> $lines Complete whole-Order line mapping.
     */
    public function assertEvent(TransactionContext $tx, Actor $actor, string $event, string $orderId,
        string $tripId, array $lines, string $eventReference): void;

    /** Current valid assignments only; exclude Cancelled Trips. Returns [] only after an authoritative check.
     * IDs/quantities are decimal strings; one whole Order is assigned to one Trip in this increment.
     * No HTTP calls, commits, or writes to Sales tables.
     * @return list<array{line_id: string, trip_id: string, trip_line_id: string, product_id: string,
     *     quantity: string, trip_status: 'Draft'|'Dispatched'|'Delivered'}>
     */
    public function getAssignments(TransactionContext $tx, Actor $actor, string $orderId): array;

    /** Signature matches ReservationService's plannedQuantity callback exactly.
     * Return the real Draft-trip claimed quantity; failure/unavailable must throw, never return a guessed zero.
     */
    public function getPlannedQuantity(Actor $actor, TransactionContext $tx, int $orderId, int $lineId): int;
}
