<?php
declare(strict_types=1);

namespace MiniErp\inventory;

use MiniErp\shared\ActionExecutor;
use MiniErp\shared\Api;
use MiniErp\shared\AppError;
use MiniErp\shared\database\Connection;
use MiniErp\shared\database\DatabaseError;
use MiniErp\shared\database\TransactionContext;
use MiniErp\shared\database\TransactionManager;
use MiniErp\shared\security\Actor;
use MiniErp\shared\security\Authorization;

final readonly class ProductService
{
    public function __construct(private Connection $connection) {}

    public static function normaliseSku(string $sku): string
    {
        return mb_strtoupper(trim($sku));
    }

    /**
     * Creates a product and its zero-stock balance row in one transaction.
     *
     * @param array<string, mixed> $input name, sku, unit_label, unit_weight_grams
     * @return array{product_id: int, sku: string}
     */
    public function create(Actor $actor, string $reference, array $input): array
    {
        (new Authorization($this->connection))->requirePermission($actor, 'inventory.products', 'create');
        $product = self::validate($input);
        $executor = new ActionExecutor(new TransactionManager($this->connection));
        return $executor->run($actor, $reference, $product,
            function (TransactionContext $tx) use ($actor, $product): array {
                $productId = (int) $tx->query('SELECT ME_INV_PRODUCT_SEQ.NEXTVAL AS ID FROM DUAL')[0]['ID'];
                try {
                    $tx->execute(
                        'INSERT INTO ME_INV_PRODUCTS (COMPANY_ID, PRODUCT_ID, PRODUCT_NAME, SKU, UNIT_LABEL, UNIT_WEIGHT_GRAMS)
                         VALUES (:company_id, :product_id, :name, :sku, :unit_label, :weight)',
                        ['company_id' => $actor->companyId, 'product_id' => $productId, 'name' => $product['name'],
                         'sku' => $product['sku'], 'unit_label' => $product['unit_label'], 'weight' => $product['unit_weight_grams']],
                    );
                } catch (DatabaseError $error) {
                    if ($error->oracleCode === 1) {
                        throw new AppError('DUPLICATE_SKU', 'A product with this SKU already exists.', 409);
                    }
                    throw $error;
                }
                $tx->execute(
                    'INSERT INTO ME_INV_STOCK_BALANCE (COMPANY_ID, PRODUCT_ID) VALUES (:company_id, :product_id)',
                    ['company_id' => $actor->companyId, 'product_id' => $productId],
                );
                return ['product_id' => $productId, 'sku' => $product['sku']];
            },
            'INVENTORY_PRODUCT_CREATE',
        );
    }

    /**
     * Changes name, unit, weight and active flag. SKU is the business key and cannot change.
     * Deactivating is refused while any stock is reserved. Unit weight is frozen once the product
     * has movements or reservations. Removing a product is `delete()`.
     *
     * @param array<string, mixed> $input name, unit_label, unit_weight_grams, active
     * @return array<string, mixed>
     */
    public function update(Actor $actor, string $reference, int $productId, array $input): array
    {
        (new Authorization($this->connection))->requirePermission($actor, 'inventory.products', 'update');
        if (array_key_exists('sku', $input)) {
            throw new AppError('VALIDATION_ERROR', 'sku cannot be changed.');
        }
        $product = self::validate($input, false);
        if (!is_bool($input['active'] ?? null)) {
            throw new AppError('VALIDATION_ERROR', 'active must be a JSON boolean.');
        }
        $product['active'] = $input['active'];
        $executor = new ActionExecutor(new TransactionManager($this->connection));
        return $executor->run($actor, $reference, ['product_id' => $productId] + $product,
            function (TransactionContext $tx) use ($actor, $productId, $product): array {
                $keys = ['company_id' => $actor->companyId, 'product_id' => $productId];
                $current = $tx->query('SELECT PRODUCT_ID, UNIT_LABEL, UNIT_WEIGHT_GRAMS FROM ME_INV_PRODUCTS WHERE COMPANY_ID = :company_id AND PRODUCT_ID = :product_id FOR UPDATE', $keys);
                if ($current === []) {
                    throw new AppError('NOT_FOUND', 'Product not found.', 404);
                }
                if (((int) $current[0]['UNIT_WEIGHT_GRAMS'] !== $product['unit_weight_grams'] || (string) $current[0]['UNIT_LABEL'] !== $product['unit_label'])
                    && $tx->query(
                        'SELECT 1 AS USED FROM DUAL WHERE EXISTS (SELECT 1 FROM ME_INV_STOCK_MOVEMENT WHERE COMPANY_ID = :company_id AND PRODUCT_ID = :product_id)
                            OR EXISTS (SELECT 1 FROM ME_INV_RESERVATION WHERE COMPANY_ID = :company_id AND PRODUCT_ID = :product_id)',
                        $keys,
                    ) !== []) {
                    throw new AppError('PRODUCT_IN_USE', 'Unit and weight cannot change after the product has stock movements or reservations.', 409);
                }
                if (!$product['active']) {
                    $balance = $tx->query('SELECT RESERVED FROM ME_INV_STOCK_BALANCE WHERE COMPANY_ID = :company_id AND PRODUCT_ID = :product_id FOR UPDATE', $keys);
                    if ($balance !== [] && (int) $balance[0]['RESERVED'] > 0) {
                        throw new AppError('INVALID_STATE', 'This product has reserved stock and cannot be deactivated.', 409);
                    }
                }
                $tx->execute(
                    'UPDATE ME_INV_PRODUCTS SET PRODUCT_NAME = :name, UNIT_LABEL = :unit_label, UNIT_WEIGHT_GRAMS = :weight, IS_ACTIVE = :is_active
                     WHERE COMPANY_ID = :company_id AND PRODUCT_ID = :product_id',
                    $keys + ['name' => $product['name'], 'unit_label' => $product['unit_label'],
                        'weight' => $product['unit_weight_grams'], 'is_active' => $product['active'] ? 'Y' : 'N'],
                );
                return ['product_id' => $productId, 'name' => $product['name'], 'unit_label' => $product['unit_label'],
                    'unit_weight_grams' => $product['unit_weight_grams'], 'active' => $product['active']];
            },
            'INVENTORY_PRODUCT_UPDATE',
        );
    }

    /**
     * Shared product lookup (contract P1, INV-02) for Purchasing, Sales and Logistics: product IDs in,
     * one entry per distinct ID out, in the order first requested. Any unknown ID fails the whole call.
     *
     * @param list<mixed> $productIds 1 to 100 whole numbers, each at least 1
     * @return list<array<string, mixed>>
     */
    public function lookupProducts(Actor $actor, array $productIds): array
    {
        (new Authorization($this->connection))->requirePermission($actor, 'inventory.products', 'read');
        $productIds = array_values($productIds);
        if ($productIds === [] || count($productIds) > 100) {
            throw new AppError('VALIDATION_ERROR', 'Send between 1 and 100 product IDs.');
        }
        foreach ($productIds as $id) {
            if (!is_int($id) || $id < 1) {
                throw new AppError('VALIDATION_ERROR', 'Every product ID must be a whole number of at least 1.');
            }
        }
        $ids = array_values(array_unique($productIds));
        $params = ['company_id' => $actor->companyId];
        $slots = [];
        foreach ($ids as $index => $id) {
            $params['id' . $index] = $id;
            $slots[] = ':id' . $index;
        }
        $rows = $this->connection->query(
            'SELECT PRODUCT_ID, PRODUCT_NAME, SKU, WAREHOUSE_ID, UNIT_LABEL, UNIT_WEIGHT_GRAMS, IS_ACTIVE
             FROM ME_INV_PRODUCTS WHERE COMPANY_ID = :company_id AND PRODUCT_ID IN (' . implode(', ', $slots) . ')',
            $params,
        );
        $found = [];
        foreach ($rows as $row) {
            $found[(int) $row['PRODUCT_ID']] = $row;
        }
        $missing = array_values(array_filter($ids, static fn (int $id): bool => !isset($found[$id])));
        if ($missing !== []) {
            throw new AppError('NOT_FOUND', 'Unknown product IDs: ' . implode(', ', $missing) . '.', 404);
        }
        return array_map(static fn (int $id): array => self::present($found[$id]), $ids);
    }

    /**
     * Removes a product that was never used. Refused with PRODUCT_IN_USE while any stock is on hand
     * or reserved, or while any movement or reservation exists, because that history must keep
     * pointing at the product. Removes the (zero) balance row first, then the product.
     *
     * @return array{product_id: int, deleted: bool}
     */
    public function delete(Actor $actor, string $reference, int $productId): array
    {
        (new Authorization($this->connection))->requirePermission($actor, 'inventory.products', 'delete');
        $executor = new ActionExecutor(new TransactionManager($this->connection));
        return $executor->run($actor, $reference, ['product_id' => $productId],
            function (TransactionContext $tx) use ($actor, $productId): array {
                $keys = ['company_id' => $actor->companyId, 'product_id' => $productId];
                $current = $tx->query('SELECT PRODUCT_ID FROM ME_INV_PRODUCTS WHERE COMPANY_ID = :company_id AND PRODUCT_ID = :product_id FOR UPDATE', $keys);
                if ($current === []) {
                    throw new AppError('NOT_FOUND', 'Product not found.', 404);
                }
                $balance = $tx->query('SELECT ON_HAND, RESERVED FROM ME_INV_STOCK_BALANCE WHERE COMPANY_ID = :company_id AND PRODUCT_ID = :product_id FOR UPDATE', $keys);
                if ($balance !== [] && ((int) $balance[0]['ON_HAND'] > 0 || (int) $balance[0]['RESERVED'] > 0)) {
                    throw new AppError('PRODUCT_IN_USE', 'Products with stock or transaction history cannot be deleted.', 409);
                }
                if ($tx->query(
                    'SELECT 1 AS USED FROM DUAL WHERE EXISTS (SELECT 1 FROM ME_INV_STOCK_MOVEMENT WHERE COMPANY_ID = :company_id AND PRODUCT_ID = :product_id)
                        OR EXISTS (SELECT 1 FROM ME_INV_RESERVATION WHERE COMPANY_ID = :company_id AND PRODUCT_ID = :product_id)',
                    $keys,
                ) !== []) {
                    throw new AppError('PRODUCT_IN_USE', 'Products with stock or transaction history cannot be deleted.', 409);
                }
                $tx->execute('DELETE FROM ME_INV_STOCK_BALANCE WHERE COMPANY_ID = :company_id AND PRODUCT_ID = :product_id', $keys);
                $tx->execute('DELETE FROM ME_INV_PRODUCTS WHERE COMPANY_ID = :company_id AND PRODUCT_ID = :product_id', $keys);
                return ['product_id' => $productId, 'deleted' => true];
            },
            'INVENTORY_PRODUCT_DELETE',
        );
    }

    /**
     * Pages through the company's products ordered by SKU. `q` searches SKU and name (contains, any case). `sku` and `name` filter on that column alone (contains, any case) and
     * `status` is active or inactive; blank values are ignored and all filters combine with AND.
     * Each item says whether it can be deleted (no stock, reservations or movement history).
     *
     * @param array<string, string> $query page, page_size, q, sku, name and status
     * @return array{items: list<array<string, mixed>>, page: int, page_size: int, total: int}
     */
    public function list(Actor $actor, array $query = []): array
    {
        (new Authorization($this->connection))->requirePermission($actor, 'inventory.products', 'read');
        $filters = ['company_id' => $actor->companyId];
        $where = 'p.COMPANY_ID = :company_id';
        if (array_key_exists('q', $query)) {
            $text = $query['q'];
            unset($query['q']);
            if (!is_string($text) || mb_strlen(trim($text)) > 100) {
                throw new AppError('VALIDATION_ERROR', 'q must be text of at most 100 characters.');
            }
            if (trim($text) !== '') {
                $filters['q'] = '%' . strtr(mb_strtoupper(trim($text)), ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']) . '%';
                $where .= " AND (UPPER(p.SKU) LIKE :q ESCAPE '\\' OR UPPER(p.PRODUCT_NAME) LIKE :q ESCAPE '\\')";
            }
        }
        foreach (['sku' => 'p.SKU', 'name' => 'p.PRODUCT_NAME'] as $key => $column) {
            if (!array_key_exists($key, $query)) {
                continue;
            }
            $text = $query[$key];
            unset($query[$key]);
            if (!is_string($text) || mb_strlen(trim($text)) > 100) {
                throw new AppError('VALIDATION_ERROR', $key . ' must be text of at most 100 characters.');
            }
            if (trim($text) !== '') {
                $filters[$key] = '%' . strtr(mb_strtoupper(trim($text)), ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']) . '%';
                $where .= ' AND UPPER(' . $column . ") LIKE :" . $key . " ESCAPE '\\'";
            }
        }
        if (array_key_exists('status', $query)) {
            $status = $query['status'];
            unset($query['status']);
            $flags = ['active' => 'Y', 'inactive' => 'N'];
            if (!is_string($status) || (trim($status) !== '' && !isset($flags[strtolower(trim($status))]))) {
                throw new AppError('VALIDATION_ERROR', 'status must be active or inactive.');
            }
            if (trim($status) !== '') {
                $filters['status'] = $flags[strtolower(trim($status))];
                $where .= ' AND p.IS_ACTIVE = :status';
            }
        }
        $page = Api::pagination($query);
        $total = (int) $this->connection->query('SELECT COUNT(*) AS TOTAL FROM ME_INV_PRODUCTS p WHERE ' . $where, $filters)[0]['TOTAL'];
        $rows = $this->connection->query(
            'SELECT p.PRODUCT_ID, p.PRODUCT_NAME, p.SKU, p.WAREHOUSE_ID, p.UNIT_LABEL, p.UNIT_WEIGHT_GRAMS, p.IS_ACTIVE,
                    CASE WHEN COALESCE(b.ON_HAND, 0) = 0 AND COALESCE(b.RESERVED, 0) = 0
                          AND NOT EXISTS (SELECT 1 FROM ME_INV_STOCK_MOVEMENT m WHERE m.COMPANY_ID = p.COMPANY_ID AND m.PRODUCT_ID = p.PRODUCT_ID)
                          AND NOT EXISTS (SELECT 1 FROM ME_INV_RESERVATION r WHERE r.COMPANY_ID = p.COMPANY_ID AND r.PRODUCT_ID = p.PRODUCT_ID)
                         THEN 1 ELSE 0 END AS CAN_DELETE
             FROM ME_INV_PRODUCTS p
             LEFT JOIN ME_INV_STOCK_BALANCE b ON b.COMPANY_ID = p.COMPANY_ID AND b.PRODUCT_ID = p.PRODUCT_ID
             WHERE ' . $where . ' ORDER BY p.SKU
             OFFSET :offset ROWS FETCH NEXT :page_size ROWS ONLY',
            $filters + ['offset' => $page['offset'], 'page_size' => $page['page_size']],
        );
        return ['items' => array_map(self::present(...), $rows), 'page' => $page['page'], 'page_size' => $page['page_size'], 'total' => $total];
    }

    /**
     * One product with its stock: on hand, reserved, and available (on hand minus reserved).
     *
     * @return array<string, mixed>
     */
    public function get(Actor $actor, int $productId): array
    {
        (new Authorization($this->connection))->requirePermission($actor, 'inventory.products', 'read');
        $rows = $this->connection->query(
            'SELECT p.PRODUCT_ID, p.PRODUCT_NAME, p.SKU, p.WAREHOUSE_ID, p.UNIT_LABEL, p.UNIT_WEIGHT_GRAMS, p.IS_ACTIVE,
                    COALESCE(b.ON_HAND, 0) AS ON_HAND, COALESCE(b.RESERVED, 0) AS RESERVED
             FROM ME_INV_PRODUCTS p
             LEFT JOIN ME_INV_STOCK_BALANCE b ON b.COMPANY_ID = p.COMPANY_ID AND b.PRODUCT_ID = p.PRODUCT_ID
             WHERE p.COMPANY_ID = :company_id AND p.PRODUCT_ID = :product_id',
            ['company_id' => $actor->companyId, 'product_id' => $productId],
        );
        if ($rows === []) {
            throw new AppError('NOT_FOUND', 'Product not found.', 404);
        }
        $onHand = (int) $rows[0]['ON_HAND'];
        $reserved = (int) $rows[0]['RESERVED'];
        return self::present($rows[0]) + ['on_hand' => $onHand, 'reserved' => $reserved, 'available' => $onHand - $reserved];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function present(array $row): array
    {
        return [
            'product_id' => (int) $row['PRODUCT_ID'], 'name' => $row['PRODUCT_NAME'], 'sku' => $row['SKU'],
            'warehouse_id' => $row['WAREHOUSE_ID'], 'unit_label' => $row['UNIT_LABEL'],
            'unit_weight_grams' => (int) $row['UNIT_WEIGHT_GRAMS'], 'active' => $row['IS_ACTIVE'] === 'Y',
        ] + (array_key_exists('CAN_DELETE', $row) ? ['can_delete' => (int) $row['CAN_DELETE'] === 1] : []);
    }

    /**
     * @param array<string, mixed> $input
     * @return array{name: string, sku?: string, unit_label: string, unit_weight_grams: int}
     */
    private static function validate(array $input, bool $withSku = true): array
    {
        $name = self::text($input, 'name', 255);
        $product = ['name' => $name];
        if ($withSku) {
            $sku = self::normaliseSku(self::text($input, 'sku', 50));
            if (mb_strlen($sku) > 50 || !preg_match('/\A[A-Z0-9][A-Z0-9._-]*\z/', $sku)) {
                throw new AppError('VALIDATION_ERROR', 'sku may contain only letters, digits, dot, underscore and hyphen, and must start with a letter or digit.');
            }
            $product['sku'] = $sku;
        }
        $product['unit_label'] = self::text($input, 'unit_label', 50);
        $weight = $input['unit_weight_grams'] ?? null;
        if (!is_int($weight) || $weight < 1 || $weight > 9999999999) {
            throw new AppError('VALIDATION_ERROR', 'unit_weight_grams must be a whole number of grams, at least 1.');
        }
        $product['unit_weight_grams'] = $weight;
        return $product;
    }

    /** @param array<string, mixed> $input */
    private static function text(array $input, string $key, int $max): string
    {
        $value = $input[$key] ?? null;
        $value = is_string($value) ? trim($value) : '';
        if ($value === '' || mb_strlen($value) > $max) {
            throw new AppError('VALIDATION_ERROR', $key . ' must be 1-' . $max . ' characters.');
        }
        return $value;
    }
}