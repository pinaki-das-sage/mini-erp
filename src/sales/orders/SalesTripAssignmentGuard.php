<?php
declare(strict_types=1);

namespace MiniErp\sales\orders;

use MiniErp\shared\AppError;
use MiniErp\shared\database\TransactionContext;
use MiniErp\shared\security\Actor;

/** Simplified assignment authority: Sales' saved current Trip references/status.
 * Logistics must update these fields through Sales callbacks in the SAME claim/Trip transaction and
 * synchronize on the Quote -> Order parent -> Sales-line locks. An independent Logistics-only claim
 * is not supported by this contract. Cancellation must clear both FK references.
 */
final class SalesTripAssignmentGuard implements OrderLogistics
{
    public static function hasTrip(array $line): bool
    {
        return ($line['TRIP_ID'] ?? null) !== null || ($line['LOGISTICS_TRIP_LINE_ID'] ?? null) !== null ||
            !in_array($line['TRIP_STATUS'] ?? null, [null, '', 'Cancelled'], true);
    }

    public function assertUnassigned(TransactionContext $tx, Actor $actor, string $orderId): void
    {
        foreach ($this->lockedLines($tx, $actor, $orderId) as $line) {
            if (self::hasTrip($line)) {
                throw new AppError('INVALID_STATE', 'Cannot move to Draft: this Order has a Trip assignment. Cancel its Draft Trip assignment first.', 409);
            }
        }
    }

    /** Inventory callback: actual Draft claim quantity from the saved Sales line, not a constant zero.
     * A non-Draft active Trip has no Draft claim; Inventory independently rejects release of DISPATCHED
     * reservations. Sales' return guard blocks ALL current Trip references/statuses before release.
     */
    public function getPlannedQuantity(Actor $actor, TransactionContext $tx, int $orderId, int $lineId): int
    {
        foreach ($this->lockedLines($tx, $actor, (string) $orderId) as $line) {
            if ((string) $line['RECORD#'] !== (string) $lineId) { continue; }
            if (!self::hasTrip($line) || in_array($line['TRIP_STATUS'] ?? null, ['Dispatched', 'Delivered'], true)) { return 0; }
            $quantity = (string) $line['QUANTITY_TEXT'];
            if (!preg_match('/\A[1-9][0-9]{0,8}\z/D', $quantity)) {
                throw new AppError('INVALID_STATE', 'Saved Trip claim quantity is invalid.', 409);
            }
            // Draft, missing or inconsistent status with a remaining reference is conservatively claimed.
            return (int) $quantity;
        }
        throw new AppError('NOT_FOUND', 'Order line not found for this company and Order.', 404);
    }

    private function lockedLines(TransactionContext $tx, Actor $actor, string $orderId): array
    {
        if (!preg_match('/\A[1-9][0-9]{0,14}\z/D', $orderId)) { throw new AppError('VALIDATION_ERROR', 'Provide a saved numeric Order record ID.'); }
        $orders = $tx->query('SELECT RECORD# FROM ME_OE_DOCUMENTS WHERE CNY# = :company_id
            AND RECORD# = :id AND DOCUMENT_TYPE = :document_type FOR UPDATE',
            ['company_id' => $actor->companyId, 'id' => $orderId, 'document_type' => 'SALES_ORDER']);
        if ($orders === []) { throw new AppError('NOT_FOUND', 'Order not found.', 404); }
        $lines = $tx->query("SELECT RECORD#, TRIP_ID, LOGISTICS_TRIP_LINE_ID, TRIP_STATUS,
            TO_CHAR(QUANTITY, 'FM999999999') AS QUANTITY_TEXT FROM ME_OE_DOCUMENT_LINES
            WHERE CNY# = :company_id AND DOCUMENT_RECORD# = :id ORDER BY RECORD# FOR UPDATE",
            ['company_id' => $actor->companyId, 'id' => $orderId]);
        if ($lines === []) { throw new AppError('INVALID_STATE', 'Order has no saved lines to verify.', 409); }
        return $lines;
    }
}
