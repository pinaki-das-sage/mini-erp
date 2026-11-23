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
     * Deactivating is refused while any stock is reserved. There is no delete.
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
                if ($tx->query('SELECT PRODUCT_ID FROM ME_INV_PRODUCTS WHERE COMPANY_ID = :company_id AND PRODUCT_ID = :product_id FOR UPDATE', $keys) === []) {
                    throw new AppError('NOT_FOUND', 'Product not found.', 404);
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
     * @param array<string, string> $query page and page_size only
     * @return array{items: list<array<string, mixed>>, page: int, page_size: int}
     */
    public function list(Actor $actor, array $query = []): array
    {
        (new Authorization($this->connection))->requirePermission($actor, 'inventory.products', 'read');
        $page = Api::pagination($query);
        $rows = $this->connection->query(
            'SELECT PRODUCT_ID, PRODUCT_NAME, SKU, WAREHOUSE_ID, UNIT_LABEL, UNIT_WEIGHT_GRAMS, IS_ACTIVE
             FROM ME_INV_PRODUCTS WHERE COMPANY_ID = :company_id ORDER BY SKU
             OFFSET :offset ROWS FETCH NEXT :page_size ROWS ONLY',
            ['company_id' => $actor->companyId, 'offset' => $page['offset'], 'page_size' => $page['page_size']],
        );
        return ['items' => array_map(self::present(...), $rows), 'page' => $page['page'], 'page_size' => $page['page_size']];
    }

    /** @return array<string, mixed> */
    public function get(Actor $actor, int $productId): array
    {
        (new Authorization($this->connection))->requirePermission($actor, 'inventory.products', 'read');
        $rows = $this->connection->query(
            'SELECT PRODUCT_ID, PRODUCT_NAME, SKU, WAREHOUSE_ID, UNIT_LABEL, UNIT_WEIGHT_GRAMS, IS_ACTIVE
             FROM ME_INV_PRODUCTS WHERE COMPANY_ID = :company_id AND PRODUCT_ID = :product_id',
            ['company_id' => $actor->companyId, 'product_id' => $productId],
        );
        if ($rows === []) {
            throw new AppError('NOT_FOUND', 'Product not found.', 404);
        }
        return self::present($rows[0]);
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
        ];
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
