<?php
declare(strict_types=1);

namespace MiniErp\sales\orders;

use MiniErp\inventory\ReservationService;
use MiniErp\sales\quotes\QuoteInventory;
use MiniErp\shared\AppError;
use MiniErp\shared\database\TransactionContext;
use MiniErp\shared\security\Actor;

/** Sales-side adapter for Inventory's line reservation service. Caller holds Quote and Order parent locks.
 * Locks Sales-owned lines by ID before Inventory locks; groups calls by Product ID then line ID so
 * repeated Products consume combined availability under the same balance lock. Any later failure must
 * propagate to OrderActions and roll back the whole Order. No Inventory tables are queried here.
 */
final readonly class InventoryOrderAdapter implements OrderInventory
{
    public function __construct(private ReservationService $reservations, private QuoteInventory $references) {}

    public function reserve(TransactionContext $tx, Actor $actor, string $orderId, string $warehouseId, array $lines): void
    {
        $id = $this->orderId($orderId);
        $saved = $this->lockedLines($tx, $actor, $id);
        $expected = array_column($saved, null, 'line_id');
        if (count($lines) !== count($saved)) { throw new AppError('INVALID_STATE', 'Order demand has changed.', 409); }
        foreach ($lines as $line) {
            if (!is_array($line) || !is_string($line['line_id'] ?? null) || !isset($expected[$line['line_id']]) ||
                count($line) !== 3 || ($line['product_id'] ?? null) !== $expected[$line['line_id']]['product_id'] ||
                ($line['quantity'] ?? null) !== $expected[$line['line_id']]['quantity']) {
                throw new AppError('INVALID_STATE', 'Order demand has changed.', 409);
            }
            unset($expected[$line['line_id']]);
        }
        if ($expected !== []) { throw new AppError('INVALID_STATE', 'Order demand has changed.', 409); }
        $this->references->validate($tx, $actor, $warehouseId, array_values(array_unique(array_column($saved, 'product_id'))));
        foreach ($this->byProduct($saved) as $line) {
            try {
                $result = $this->reservations->reserveStock($actor, $tx, $id, (int) $line['line_id'],
                    (int) $line['product_id'], (int) $line['quantity']);
            } catch (AppError $error) {
                if ($error->errorCode !== 'INSUFFICIENT_STOCK') { throw $error; }
                throw new AppError($error->errorCode, 'Product ID ' . $line['product_id'] . ': ' . $error->getMessage(), $error->status);
            }
            if (($result['status'] ?? null) !== 'ACTIVE' || ($result['sales_order_id'] ?? null) !== $id ||
                ($result['sales_line_id'] ?? null) !== (int) $line['line_id'] || ($result['product_id'] ?? null) !== (int) $line['product_id'] ||
                ($result['reserved_quantity'] ?? null) !== (int) $line['quantity'] || ($result['reservation_id'] ?? 0) < 1) {
                throw new AppError('INVENTORY_UNAVAILABLE', 'Inventory did not confirm the complete Order reservation.', 503);
            }
        }
    }

    public function release(TransactionContext $tx, Actor $actor, string $orderId): void
    {
        $id = $this->orderId($orderId);
        foreach ($this->byProduct($this->lockedLines($tx, $actor, $id)) as $line) {
            $detail = $this->reservations->getReservationDetail($actor, $tx, $id, (int) $line['line_id']);
            if ($detail['status'] !== 'ACTIVE' || $detail['product_id'] !== (int) $line['product_id'] ||
                $detail['active_quantity'] !== (int) $line['quantity']) {
                throw new AppError('INVALID_STATE', 'Inventory reservation does not match the saved Order line.', 409);
            }
            // Inventory independently verifies Logistics claims and refuses a missing claim callback.
            $result = $this->reservations->releaseReservation($actor, $tx, $detail['reservation_id'], $id,
                (int) $line['line_id'], (int) $line['product_id'], (int) $line['quantity']);
            if ($result['status'] !== 'RELEASED' || $result['released_quantity'] !== (int) $line['quantity']) {
                throw new AppError('INVENTORY_UNAVAILABLE', 'Inventory did not confirm the complete reservation release.', 503);
            }
        }
    }

    private function orderId(string $id): int
    {
        if (!preg_match('/\A[1-9][0-9]{0,14}\z/D', $id)) { throw new AppError('VALIDATION_ERROR', 'Provide the saved numeric Order record ID.'); }
        return (int) $id;
    }

    /** Lock original Sales line identities, never presentation positions, before any Inventory write. */
    private function lockedLines(TransactionContext $tx, Actor $actor, int $orderId): array
    {
        $rows = $tx->query("SELECT RECORD#, PRODUCT_ID, TO_CHAR(QUANTITY, 'FM999999999') AS QUANTITY_TEXT
            FROM ME_OE_DOCUMENT_LINES WHERE CNY# = :company_id AND DOCUMENT_RECORD# = :id ORDER BY RECORD# FOR UPDATE",
            ['company_id' => $actor->companyId, 'id' => $orderId]);
        if ($rows === []) { throw new AppError('INVALID_STATE', 'Order has no saved demand to reserve or release.', 409); }
        $lines = [];
        foreach ($rows as $row) {
            $line = ['line_id' => (string) $row['RECORD#'], 'product_id' => (string) $row['PRODUCT_ID'], 'quantity' => (string) $row['QUANTITY_TEXT']];
            if (!preg_match('/\A[1-9][0-9]{0,14}\z/D', $line['line_id']) || !preg_match('/\A[1-9][0-9]{0,9}\z/D', $line['product_id']) ||
                !preg_match('/\A[1-9][0-9]{0,8}\z/D', $line['quantity'])) {
                throw new AppError('INVALID_STATE', 'Order contains invalid saved demand.', 409);
            }
            $lines[] = $line;
        }
        return $lines;
    }

    private function byProduct(array $lines): array
    {
        usort($lines, static fn (array $a, array $b): int =>
            [(int) $a['product_id'], (int) $a['line_id']] <=> [(int) $b['product_id'], (int) $b['line_id']]);
        return $lines;
    }
}
