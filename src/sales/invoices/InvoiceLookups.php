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

    public function withProductLabels(Actor $actor, array $invoice): array
    {
        (new SalesPermissions(new Authorization($this->connection)))
            ->requireDocument($actor, 'invoice', 'read');

        $labels = [];

        foreach ($this->productRows($actor, $invoice['lines']) as $row) {
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
    ): array {
        $ids = [];

        foreach ($lines as $line) {
            $id = $line['product_id'] ?? '';

            if (is_string($id) && preg_match('/\A[1-9][0-9]{0,9}\z/D', $id)) {
                $ids[$id] = $id;
            }
        }

        $conditions = [];
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