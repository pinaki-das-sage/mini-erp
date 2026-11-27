<?php
declare(strict_types=1);

namespace MiniErp\inventory;

use MiniErp\shared\AppError;
use MiniErp\shared\database\TransactionContext;
use MiniErp\shared\security\Actor;

/**
 * Internal read adapter for the deployed Logistics schema (Trip -> TripLine).
 * Callers authorize Inventory reads. This is an advisory snapshot, not a release lock.
 * Release acquires the original Sales-line lock before reading these claims. Logistics
 * planning must acquire the same lock before changing claims; this adapter alone cannot
 * prevent a foreign writer that ignores that protocol from inserting a new claim.
 */
final class LogisticsClaimLookup
{
    public function getPlannedQuantity(Actor $actor, TransactionContext $tx, int $salesOrderId, int $salesLineId): int
    {
        if ($actor->companyId < 1 || $salesOrderId < 1 || $salesOrderId > 999999999999999
            || $salesLineId < 1 || $salesLineId > 999999999999999) {
            throw new AppError('VALIDATION_ERROR', 'Provide valid company context and positive Sales IDs of at most 15 digits.');
        }
        $rows = $tx->query(
            "SELECT NVL(SUM(L.QUANTITY), 0) AS CLAIMED_QUANTITY,
                    NVL(SUM(CASE WHEN L.QUANTITY IS NULL OR L.QUANTITY <= 0
                                      OR L.QUANTITY <> TRUNC(L.QUANTITY) THEN 1 ELSE 0 END), 0) AS INVALID_LINES
             FROM ME_LOG_TRIPS T
             JOIN ME_LOG_TRIP_LINES L ON L.COMPANY_ID = T.COMPANY_ID AND L.TRIP_ID = T.ID
             WHERE T.COMPANY_ID = :company_id AND T.SALES_ORDER_ID = :sales_order_id
               AND L.SALES_LINE_ID = :sales_line_id AND T.STATUS = 'D'",
            ['company_id' => $actor->companyId, 'sales_order_id' => $salesOrderId, 'sales_line_id' => $salesLineId],
        );
        if (count($rows) !== 1 || !array_key_exists('INVALID_LINES', $rows[0])
            || !array_key_exists('CLAIMED_QUANTITY', $rows[0])) {
            throw new AppError('DEPENDENCY_UNAVAILABLE', 'Logistics claim lookup did not return a valid result.', 503);
        }
        $quantity = filter_var($rows[0]['CLAIMED_QUANTITY'], FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 0, 'max_range' => 9999999999]]);
        $invalid = filter_var($rows[0]['INVALID_LINES'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($quantity === false || $invalid === false || $invalid !== 0) {
            throw new AppError('QUANTITY_CONFLICT', 'Logistics Draft-trip claims must contain valid positive whole quantities within the supported limit.', 409);
        }
        return $quantity;
    }
}
