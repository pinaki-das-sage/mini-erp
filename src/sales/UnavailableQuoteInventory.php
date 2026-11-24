<?php
declare(strict_types=1);

namespace MiniErp\sales;

use MiniErp\shared\AppError;
use MiniErp\shared\database\TransactionContext;
use MiniErp\shared\security\Actor;

final class UnavailableQuoteInventory implements QuoteInventory
{
    public function validate(TransactionContext $tx, Actor $actor, string $warehouseId, array $productIds): void
    {
        throw new AppError('INVENTORY_UNAVAILABLE', 'Quote item and warehouse validation is not connected to Inventory yet.', 503);
    }
}
