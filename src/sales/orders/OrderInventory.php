<?php
declare(strict_types=1);

namespace MiniErp\sales\orders;

use MiniErp\shared\database\TransactionContext;
use MiniErp\shared\security\Actor;

/** Inventory boundary implemented by InventoryOrderAdapter. Providers join the supplied transaction, never commit.
 * Reserve validates active same-company Products/W01, checks combined repeated Product demand under balance locks,
 * locks balances in deterministic order and reserves every line or throws. Availability alone is not success.
 * Reservations are identified by company + orderId + line_id; action references are not business identities.
 * A released Order may be resubmitted, but an existing active reservation must never be duplicated.
 */
interface OrderInventory
{
    /** @param list<array{line_id: string, product_id: string, quantity: string}> $lines */
    public function reserve(TransactionContext $tx, Actor $actor, string $orderId, string $warehouseId, array $lines): void;

    /** Release all reservations or throw, preserving the entire action on failure. */
    public function release(TransactionContext $tx, Actor $actor, string $orderId): void;
}
