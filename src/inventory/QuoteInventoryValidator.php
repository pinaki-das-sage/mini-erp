<?php
declare(strict_types=1);

namespace MiniErp\inventory;

use MiniErp\sales\quotes\QuoteInventory;
use MiniErp\shared\AppError;
use MiniErp\shared\database\TransactionContext;
use MiniErp\shared\security\Actor;

/**
 * Inventory side of Sales Quote submission (Draft to Submitted). Read-only: it checks that the warehouse
 * and the products on a quote are real, active and in the right company and warehouse. It does not look at
 * stock, reserve anything or write anything, and it uses the caller's transaction, so it never opens a
 * connection, commits or rolls back.
 *
 * Errors:
 *   VALIDATION_ERROR      400  bad warehouse, malformed or unknown product id, product not in that warehouse
 *   INVALID_STATE         409  a product exists but is inactive
 *   INVENTORY_UNAVAILABLE 503  Inventory could not be queried (database problem), not a problem with the quote
 */
final readonly class QuoteInventoryValidator implements QuoteInventory
{
    /** There is no warehouse table yet: ME_INV_PRODUCTS limits WAREHOUSE_ID to W01 (check constraint). */
    private const WAREHOUSES = ['W01'];
    private const CHUNK = 500;

    /**
     * @param list<string> $productIds Inventory PRODUCT_ID values as strings, for example "163". Repeats are fine.
     */
    public function validate(TransactionContext $tx, Actor $actor, string $warehouseId, array $productIds): void
    {
        $warehouseId = trim($warehouseId);
        if (!in_array($warehouseId, self::WAREHOUSES, true)) {
            throw new AppError('VALIDATION_ERROR', 'Warehouse "' . self::show($warehouseId) . '" does not exist for this company.');
        }

        $ids = [];
        foreach ($productIds as $value) {
            if (!is_string($value)) {
                throw new AppError('VALIDATION_ERROR', 'Product ids must be strings such as "163", not ' . get_debug_type($value) . '.');
            }
            if (preg_match('/\A[1-9][0-9]{0,9}\z/', $value) !== 1) {
                throw new AppError('VALIDATION_ERROR', 'Product id "' . self::show($value) . '" is not a valid Inventory product id.');
            }
            $ids[$value] = (int) $value;
        }
        if ($ids === []) {
            throw new AppError('VALIDATION_ERROR', 'A quote needs at least one product.');
        }

        $found = [];
        foreach (array_chunk($ids, self::CHUNK, true) as $chunk) {
            $binds = ['company_id' => $actor->companyId];
            $marks = [];
            foreach (array_values($chunk) as $i => $id) {
                $marks[] = ':p' . $i;
                $binds['p' . $i] = $id;
            }
            try {
                $rows = $tx->query(
                    'SELECT PRODUCT_ID, WAREHOUSE_ID, IS_ACTIVE FROM ME_INV_PRODUCTS
                     WHERE COMPANY_ID = :company_id AND PRODUCT_ID IN (' . implode(', ', $marks) . ')',
                    $binds,
                );
            } catch (\Throwable) {
                throw new AppError('INVENTORY_UNAVAILABLE', 'Inventory could not be checked right now. Try again shortly.', 503);
            }
            foreach ($rows as $row) {
                $found[(string) (int) $row['PRODUCT_ID']] = $row;
            }
        }

        $missing = $wrongWarehouse = $inactive = [];
        foreach (array_keys($ids) as $id) {
            $id = (string) $id;
            if (!isset($found[$id])) {
                $missing[] = $id;
            } elseif ((string) $found[$id]['WAREHOUSE_ID'] !== $warehouseId) {
                $wrongWarehouse[] = $id;
            } elseif ($found[$id]['IS_ACTIVE'] !== 'Y') {
                $inactive[] = $id;
            }
        }
        if ($missing !== []) {
            throw new AppError('VALIDATION_ERROR', 'Products not found for this company: ' . implode(', ', $missing) . '.');
        }
        if ($wrongWarehouse !== []) {
            throw new AppError('VALIDATION_ERROR', 'Products not stocked in warehouse ' . $warehouseId . ': ' . implode(', ', $wrongWarehouse) . '.');
        }
        if ($inactive !== []) {
            throw new AppError('INVALID_STATE', 'Inactive products cannot be quoted: ' . implode(', ', $inactive) . '.', 409);
        }
    }

    private static function show(mixed $value): string
    {
        $text = is_scalar($value) ? (string) $value : get_debug_type($value);
        return mb_substr($text, 0, 40);
    }
}