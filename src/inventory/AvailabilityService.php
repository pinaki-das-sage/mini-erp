<?php
declare(strict_types=1);

namespace MiniErp\inventory;

use MiniErp\shared\AppError;
use MiniErp\shared\database\Connection;
use MiniErp\shared\database\TransactionContext;
use MiniErp\shared\security\Actor;
use MiniErp\shared\security\Authorization;

final readonly class AvailabilityService
{
    public function __construct(private Connection $connection) {}

    /**
     * @param list<int> $productIds
     * @return list<array{product_id: int, on_hand: int, reserved: int, available: int}>
     */
    public function getAvailability(Actor $actor, TransactionContext $tx, array $productIds): array
    {
        (new Authorization($this->connection))->requirePermission($actor, 'inventory.stock_balance', 'read');

        if (!array_is_list($productIds) || $productIds === [] || count($productIds) > 1000) {
            throw new AppError('VALIDATION_ERROR', 'Provide between 1 and 1000 product IDs.');
        }
        foreach ($productIds as $id) {
            if (!is_int($id) || $id < 1) {
                throw new AppError('VALIDATION_ERROR', 'Each product ID must be a positive integer.');
            }
        }
        $productIds = array_values(array_unique($productIds));
        $parameters = ['company_id' => $actor->companyId];
        $placeholders = [];
        foreach ($productIds as $index => $id) {
            $key = 'product_' . $index;
            $placeholders[] = ':' . $key;
            $parameters[$key] = $id;
        }

        // One query gives a consistent snapshot and keeps both sides of the join company-scoped.
        $rows = $tx->query(
            'SELECT P.PRODUCT_ID, NVL(B.ON_HAND, 0) AS ON_HAND, NVL(B.RESERVED, 0) AS RESERVED
             FROM ME_INV_PRODUCTS P
             LEFT JOIN ME_INV_STOCK_BALANCE B
               ON B.COMPANY_ID = P.COMPANY_ID AND B.PRODUCT_ID = P.PRODUCT_ID
             WHERE P.COMPANY_ID = :company_id AND P.PRODUCT_ID IN (' . implode(', ', $placeholders) . ')',
            $parameters,
        );
        $byId = [];
        foreach ($rows as $row) {
            $id = (int) $row['PRODUCT_ID'];
            $onHand = (int) $row['ON_HAND'];
            $reserved = (int) $row['RESERVED'];
            $byId[$id] = ['product_id' => $id, 'on_hand' => $onHand,
                'reserved' => $reserved, 'available' => $onHand - $reserved];
        }
        $result = [];
        foreach ($productIds as $id) {
            if (!isset($byId[$id])) {
                throw new AppError('INVALID_STATE', 'One or more products do not exist for this company.', 409);
            }
            $result[] = $byId[$id];
        }
        return $result;
    }
}
