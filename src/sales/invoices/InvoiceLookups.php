<?php
declare(strict_types=1);

namespace MiniErp\sales\invoices;

use MiniErp\sales\SalesPermissions;
use MiniErp\shared\database\Connection;
use MiniErp\shared\security\Actor;
use MiniErp\shared\security\Authorization;

final readonly class InvoiceLookups
{
    public function __construct(private Connection $connection) {}

    public function forForm(Actor $actor, array $invoice): array
    {
        $permissions = new SalesPermissions(
            new Authorization($this->connection),
        );

        $permissions->requireDocument(
            $actor,
            'invoice',
            empty($invoice['current_version']) ? 'create' : 'edit',
        );

        // Include the saved Customer even if it has since become inactive.
        $customerRows = $this->connection->query(
            "SELECT CUSTOMER_CODE, CUSTOMER_NAME, PAYMENT_TERMS, IS_ACTIVE
             FROM ME_OE_CUSTOMERS
             WHERE CNY# = :company_id
               AND (IS_ACTIVE = 'T' OR CUSTOMER_CODE = :customer_code)
             ORDER BY CUSTOMER_NAME, CUSTOMER_CODE",
            [
                'company_id' => $actor->companyId,
                'customer_code' => $invoice['customer_code'] ?? '',
            ],
        );

        $customers = array_map(static fn (array $row): array => [
            'customer_code' => $row['CUSTOMER_CODE'],
            'customer_name' => $row['CUSTOMER_NAME']
                . ($row['IS_ACTIVE'] === 'T' ? '' : ' (inactive)'),
            'payment_terms' => $row['PAYMENT_TERMS'],
        ], $customerRows);

        $productRows = $this->productRows(
            $actor,
            $invoice['lines'] ?? [],
            true,
        );

        $products = array_map(static fn (array $row): array => [
            'product_id' => (string) $row['PRODUCT_ID'],
            'sku' => $row['SKU']
                . ($row['IS_ACTIVE'] === 'Y' ? '' : ' (inactive)'),
            'warehouse_id' => $row['WAREHOUSE_ID'],
        ], $productRows);

        $warehouses = array_column($products, 'warehouse_id');

        if (($invoice['warehouse_id'] ?? '') !== '') {
            $warehouses[] = $invoice['warehouse_id'];
        }

        $warehouses = array_values(array_unique($warehouses));
        sort($warehouses, SORT_STRING);

        return [
            'customerOptions' => $customers,
            'products' => $products,
            'warehouses' => $warehouses,
        ];
    }

    public function withProductLabels(Actor $actor, array $invoice): array
    {
        (new SalesPermissions(new Authorization($this->connection)))
            ->requireDocument($actor, 'invoice', 'read');

        $labels = [];

        foreach ($this->productRows($actor, $invoice['lines'], false) as $row) {
            $labels[(string) $row['PRODUCT_ID']] =
                $row['SKU'] . ' (' . $row['WAREHOUSE_ID'] . ')';
        }

        foreach ($invoice['lines'] as &$line) {
            $line['product_label'] =
                $labels[$line['product_id']] ?? 'Unavailable item';
        }
        unset($line);

        return $invoice;
    }

    private function productRows(
        Actor $actor,
        array $lines,
        bool $includeActive,
    ): array {
        $ids = [];

        foreach ($lines as $line) {
            $id = $line['product_id'] ?? '';

            if (is_string($id) && preg_match('/\A[1-9][0-9]{0,9}\z/D', $id)) {
                $ids[$id] = $id;
            }
        }

        $conditions = $includeActive ? ["IS_ACTIVE = 'Y'"] : [];
        $binds = ['company_id' => $actor->companyId];
        $position = 0;

        // Chunk references to stay within Oracle's IN-list limit.
        foreach (array_chunk(array_values($ids), 500) as $chunk) {
            $markers = [];

            foreach ($chunk as $id) {
                $name = 'product_' . $position++;
                $markers[] = ':' . $name;
                $binds[$name] = $id;
            }

            $conditions[] = 'PRODUCT_ID IN (' . implode(', ', $markers) . ')';
        }

        if ($conditions === []) {
            return [];
        }

        return $this->connection->query(
            'SELECT PRODUCT_ID, SKU, WAREHOUSE_ID, IS_ACTIVE
             FROM ME_INV_PRODUCTS
             WHERE COMPANY_ID = :company_id
               AND (' . implode(' OR ', $conditions) . ')
             ORDER BY SKU, PRODUCT_ID',
            $binds,
        );
    }
}