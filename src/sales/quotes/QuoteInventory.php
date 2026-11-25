<?php
declare(strict_types=1);

namespace MiniErp\sales\quotes;

use MiniErp\shared\database\TransactionContext;
use MiniErp\shared\security\Actor;

/** $productIds contains Inventory PRODUCT_ID values as decimal strings.
 * The Inventory adapter must reject unavailable, inactive and foreign-company references.
 * Quotes validate master data only; they do not check or reserve stock.
 */
interface QuoteInventory
{
    public function validate(TransactionContext $tx, Actor $actor, string $warehouseId, array $productIds): void;
}
