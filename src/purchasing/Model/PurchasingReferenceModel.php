<?php
declare(strict_types=1);

namespace MiniErp\purchasing\Model;

use MiniErp\shared\database\Connection;
use MiniErp\shared\security\Actor;

final readonly class PurchasingReferenceModel
{
    public function __construct(private Connection $connection) {}

    public function products(Actor $actor, string $search): array
    {
        return array_map(static fn (array $row): array => [
            'product_id' => (int) $row['PRODUCT_ID'], 'name' => $row['PRODUCT_NAME'],
            'sku' => $row['SKU'], 'active' => strtoupper((string) $row['IS_ACTIVE']) === 'Y',
        ], $this->connection->query(
            'SELECT PRODUCT_ID, PRODUCT_NAME, SKU, IS_ACTIVE FROM ME_INV_PRODUCTS
             WHERE COMPANY_ID = :company_id
               AND (UPPER(PRODUCT_NAME) LIKE UPPER(:search) OR UPPER(SKU) LIKE UPPER(:search))
             ORDER BY PRODUCT_NAME, PRODUCT_ID FETCH FIRST 200 ROWS ONLY',
            ['company_id' => $actor->companyId, 'search' => '%' . $search . '%'],
        ));
    }

    public function vendors(Actor $actor, string $search): array
    {
        return array_map(static fn (array $row): array => [
            'id' => (string) $row['ID'], 'name' => $row['NAME'], 'code' => $row['VENDOR_ID'],
            'status' => strtolower((string) $row['STATUS']), 'payment_terms' => $row['PAYMENT_TERMS'],
        ], $this->connection->query(
            'SELECT ID, NAME, VENDOR_ID, STATUS, PAYMENT_TERMS FROM ME_PUR_VENDOR
             WHERE CNYID = :company_id AND (UPPER(NAME) LIKE UPPER(:search) OR UPPER(VENDOR_ID) LIKE UPPER(:search))
             ORDER BY NAME, ID FETCH FIRST 200 ROWS ONLY',
            ['company_id' => $actor->companyId, 'search' => '%' . $search . '%'],
        ));
    }
}
