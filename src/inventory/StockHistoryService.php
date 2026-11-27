<?php
declare(strict_types=1);

namespace MiniErp\inventory;

use MiniErp\shared\Api;
use MiniErp\shared\AppError;
use MiniErp\shared\database\Connection;
use MiniErp\shared\security\Actor;
use MiniErp\shared\security\Authorization;

/** Read-only stock movement history (INV-03 "ledger and history"). Movement rows are never edited. */
final readonly class StockHistoryService
{
    public function __construct(private Connection $connection) {}

    /**
     * One product's movements, oldest first (by movement time, then by id for movements in the same instant).
     *
     * @param array<string, string> $query page and page_size
     * @return array{items: list<array<string, mixed>>, page: int, page_size: int, total: int}
     */
    public function forProduct(Actor $actor, int $productId, array $query = []): array
    {
        (new Authorization($this->connection))->requirePermission($actor, 'inventory.movements', 'read');
        $page = Api::pagination($query);
        $keys = ['company_id' => $actor->companyId, 'product_id' => $productId];
        if ($this->connection->query('SELECT PRODUCT_ID FROM ME_INV_PRODUCTS WHERE COMPANY_ID = :company_id AND PRODUCT_ID = :product_id', $keys) === []) {
            throw new AppError('NOT_FOUND', 'Product not found.', 404);
        }
        $total = (int) $this->connection->query(
            'SELECT COUNT(*) AS TOTAL FROM ME_INV_STOCK_MOVEMENT WHERE COMPANY_ID = :company_id AND PRODUCT_ID = :product_id',
            $keys,
        )[0]['TOTAL'];
        $rows = $this->connection->query(
            <<<'SQL'
            SELECT MOVEMENT_ID, TO_CHAR(MOVEMENT_AT AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS MOVEMENT_AT,
                   MOVEMENT_TYPE, SOURCE_TYPE, SOURCE_ID, RESERVATION_ID,
                   OPENING_BALANCE, RECEIVED_QTY, DISPATCHED_QTY, CLOSING_BALANCE
            FROM ME_INV_STOCK_MOVEMENT
            WHERE COMPANY_ID = :company_id AND PRODUCT_ID = :product_id
            ORDER BY MOVEMENT_AT ASC, MOVEMENT_ID ASC
            OFFSET :offset ROWS FETCH NEXT :page_size ROWS ONLY
            SQL,
            $keys + ['offset' => $page['offset'], 'page_size' => $page['page_size']],
        );
        return [
            'items' => array_map(static fn (array $row): array => [
                'movement_id' => (int) $row['MOVEMENT_ID'],
                'moved_at' => (string) $row['MOVEMENT_AT'],
                'type' => (string) $row['MOVEMENT_TYPE'],
                'source_type' => (string) $row['SOURCE_TYPE'],
                'source_id' => (string) $row['SOURCE_ID'],
                'reservation_id' => $row['RESERVATION_ID'] === null ? null : (int) $row['RESERVATION_ID'],
                'opening_balance' => (int) $row['OPENING_BALANCE'],
                'received_quantity' => (int) $row['RECEIVED_QTY'],
                'dispatched_quantity' => (int) $row['DISPATCHED_QTY'],
                'closing_balance' => (int) $row['CLOSING_BALANCE'],
            ], $rows),
            'page' => $page['page'],
            'page_size' => $page['page_size'],
            'total' => $total,
        ];
    }
}