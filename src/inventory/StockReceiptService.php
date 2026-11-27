<?php
declare(strict_types=1);

namespace MiniErp\inventory;

use MiniErp\shared\AppError;
use MiniErp\shared\database\Connection;
use MiniErp\shared\database\TransactionContext;
use MiniErp\shared\security\Actor;
use MiniErp\shared\security\Authorization;

/**
 * Inventory side of a purchase receipt. The caller (Purchasing) owns the transaction:
 * this service joins it, locks the balance row, and never commits or rolls back.
 */
final readonly class StockReceiptService
{
    private const SOURCE_TYPE = 'PURCHASE_RECEIVER';
    private const MAX_QUANTITY = 9999999999;

    public function __construct(private Connection $connection) {}

    /**
     * Adds stock for one receipt line and records one RECEIVED movement.
     * Repeating the same source line with the same quantity returns the original movement;
     * the same source line with another quantity is a conflict.
     *
     * @return array{movement_id: int, product_id: int, received_quantity: int, opening_balance: int, closing_balance: int}
     */
    public function receivePurchase(TransactionContext $tx, Actor $actor, string $sourceId, int $productId, int $quantity): array
    {
        (new Authorization($this->connection))->requirePermission($actor, 'inventory.stock', 'receive');
        if (!preg_match('/\A[a-zA-Z0-9_-]{1,40}\z/', $sourceId)) {
            throw new AppError('VALIDATION_ERROR', 'source_id must be 1-40 letters, digits, underscores or hyphens.');
        }
        if ($productId < 1) {
            throw new AppError('VALIDATION_ERROR', 'product_id must be a positive whole number.');
        }
        if ($quantity < 1 || $quantity > self::MAX_QUANTITY) {
            throw new AppError('VALIDATION_ERROR', 'quantity must be a whole number of at least 1.');
        }
        $keys = ['company_id' => $actor->companyId, 'product_id' => $productId];
        $balance = $tx->query('SELECT ON_HAND FROM ME_INV_STOCK_BALANCE WHERE COMPANY_ID = :company_id AND PRODUCT_ID = :product_id FOR UPDATE', $keys);
        if ($balance === []) {
            throw new AppError('NOT_FOUND', 'Product not found.', 404);
        }
        $existing = $tx->query(
            'SELECT MOVEMENT_ID, OPENING_BALANCE, RECEIVED_QTY, CLOSING_BALANCE FROM ME_INV_STOCK_MOVEMENT
             WHERE COMPANY_ID = :company_id AND SOURCE_TYPE = :source_type AND SOURCE_ID = :source_id AND PRODUCT_ID = :product_id',
            $keys + ['source_type' => self::SOURCE_TYPE, 'source_id' => $sourceId],
        );
        if ($existing !== []) {
            if ((int) $existing[0]['RECEIVED_QTY'] !== $quantity) {
                throw new AppError('DUPLICATE_SOURCE_CONFLICT', 'This receipt line was already received with a different quantity.', 409);
            }
            return self::present($productId, $existing[0]['MOVEMENT_ID'], $existing[0]['RECEIVED_QTY'], $existing[0]['OPENING_BALANCE'], $existing[0]['CLOSING_BALANCE']);
        }
        $opening = (int) $balance[0]['ON_HAND'];
        $closing = $opening + $quantity;
        if ($closing > self::MAX_QUANTITY) {
            throw new AppError('VALIDATION_ERROR', 'The stock on hand would exceed the maximum the system can hold.');
        }
        $movementId = (int) $tx->query('SELECT ME_INV_MOVEMENT_SEQ.NEXTVAL AS ID FROM DUAL')[0]['ID'];
        $tx->execute(
            "INSERT INTO ME_INV_STOCK_MOVEMENT (COMPANY_ID, MOVEMENT_ID, PRODUCT_ID, OPENING_BALANCE, RECEIVED_QTY, DISPATCHED_QTY,
                CLOSING_BALANCE, MOVEMENT_TYPE, SOURCE_TYPE, SOURCE_ID)
             VALUES (:company_id, :movement_id, :product_id, :opening, :quantity, 0, :closing, 'RECEIVED', :source_type, :source_id)",
            $keys + ['movement_id' => $movementId, 'opening' => $opening, 'quantity' => $quantity, 'closing' => $closing,
                'source_type' => self::SOURCE_TYPE, 'source_id' => $sourceId],
        );
        $tx->execute(
            'UPDATE ME_INV_STOCK_BALANCE SET ON_HAND = ON_HAND + :quantity WHERE COMPANY_ID = :company_id AND PRODUCT_ID = :product_id',
            $keys + ['quantity' => $quantity],
        );
        return self::present($productId, $movementId, $quantity, $opening, $closing);
    }

    /** @return array{movement_id: int, product_id: int, received_quantity: int, opening_balance: int, closing_balance: int} */
    private static function present(int $productId, mixed $movementId, mixed $received, mixed $opening, mixed $closing): array
    {
        return ['movement_id' => (int) $movementId, 'product_id' => $productId, 'received_quantity' => (int) $received,
            'opening_balance' => (int) $opening, 'closing_balance' => (int) $closing];
    }
}