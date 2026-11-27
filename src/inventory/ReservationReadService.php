<?php
declare(strict_types=1);

namespace MiniErp\inventory;

use MiniErp\shared\AppError;
use MiniErp\shared\database\Connection;
use MiniErp\shared\database\TransactionContext;
use MiniErp\shared\security\Actor;
use MiniErp\shared\security\Authorization;

/** Read-only reservation lister with company-scoped display references. */
final readonly class ReservationReadService
{
    public function __construct(private Connection $connection, private ?\Closure $plannedQuantity = null) {}

    public function list(Actor $actor, TransactionContext $tx, array $query): array
    {
        (new Authorization($this->connection))->requirePermission($actor, 'inventory.reservations', 'read');
        $filters = ['q' => '', 'status' => '', 'product_id' => '', 'sales_order_id' => '', 'sku' => '', 'document_number' => '',
            'reservation_id' => '', 'line_number' => '', 'active_quantity' => '', 'dispatched_quantity' => ''];
        foreach ($query as $key => $value) {
            if (!in_array($key, [...array_keys($filters), 'page', 'page_size'], true) || !is_string($value)) {
                throw new AppError('VALIDATION_ERROR', 'Unsupported reservation filter.');
            }
        }
        foreach ($filters as $key => $_) { $filters[$key] = trim($query[$key] ?? ''); }
        if (!in_array($filters['status'], ['', 'ACTIVE', 'RELEASED', 'DISPATCHED'], true)) {
            throw new AppError('VALIDATION_ERROR', 'Choose Active, Released, Dispatched, or All statuses.');
        }
        $where = ['R.COMPANY_ID = :company_id'];
        $params = ['company_id' => $actor->companyId];
        foreach (['product_id' => 10, 'sales_order_id' => 15] as $key => $digits) {
            if ($filters[$key] === '') { continue; }
            if (!ctype_digit($filters[$key]) || strlen($filters[$key]) > $digits || (int) $filters[$key] < 1) {
                throw new AppError('VALIDATION_ERROR', 'Search and ID filters must be positive whole numbers within the supported ID length.');
            }
            $where[] = 'R.' . strtoupper($key) . ' = :' . $key;
            $params[$key] = (int) $filters[$key];
        }
        foreach (['reservation_id' => ['R.RESERVATION_ID', 1, 9999999999], 'line_number' => ['S.LINE_NUMBER', 1, 999999999999999],
            'active_quantity' => ["CASE WHEN R.RESERVATION_STATUS = 'ACTIVE' THEN R.RESERVED_QUANTITY ELSE 0 END", 0, 9999999999],
            'dispatched_quantity' => ["CASE WHEN R.RESERVATION_STATUS = 'DISPATCHED' THEN R.RESERVED_QUANTITY ELSE 0 END", 0, 9999999999]] as $key => [$expression, $minimum, $maximum]) {
            if ($filters[$key] === '') { continue; }
            $value = ctype_digit($filters[$key])
                ? filter_var($filters[$key], FILTER_VALIDATE_INT, ['options' => ['min_range' => $minimum, 'max_range' => $maximum]]) : false;
            if ($value === false) {
                throw new AppError('VALIDATION_ERROR', 'Enter valid whole numbers in reservation, line and quantity filters. Quantities may be zero.');
            }
            $where[] = '(' . $expression . ') = :' . $key;
            $params[$key] = $value;
        }
        foreach (['q', 'sku', 'document_number'] as $key) {
            if (strlen($filters[$key]) > 100 || preg_match('/[\x00-\x1F\x7F]/', $filters[$key])) {
                throw new AppError('VALIDATION_ERROR', 'Search text must be at most 100 characters without control characters.');
            }
        }
        $contains = static fn (string $value): string => '%' . strtr(strtoupper($value), ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
        if ($filters['q'] !== '') {
            $search = ["UPPER(D.DOCUMENT_NUMBER) LIKE :search_document ESCAPE '!'", "UPPER(P.SKU) LIKE :search_sku ESCAPE '!'"];
            $params['search_document'] = $contains($filters['q']);
            $params['search_sku'] = $contains($filters['q']);
            if (ctype_digit($filters['q']) && strlen($filters['q']) <= 15) {
                foreach (['search_reservation' => 'R.RESERVATION_ID', 'search_order' => 'R.SALES_ORDER_ID',
                    'search_line' => 'R.SALES_LINE_ID', 'search_product' => 'R.PRODUCT_ID', 'search_line_number' => 'S.LINE_NUMBER'] as $name => $column) {
                    $search[] = $column . ' = :' . $name;
                    $params[$name] = (int) $filters['q'];
                }
            }
            $where[] = '(' . implode(' OR ', $search) . ')';
        }
        foreach (['sku' => 'P.SKU', 'document_number' => 'D.DOCUMENT_NUMBER'] as $key => $column) {
            if ($filters[$key] === '') { continue; }
            $where[] = 'UPPER(' . $column . ') LIKE :' . $key . " ESCAPE '!'";
            $params[$key] = $contains($filters[$key]);
        }
        if ($filters['status'] !== '') {
            $where[] = 'R.RESERVATION_STATUS = :status';
            $params['status'] = $filters['status'];
        }
        $page = filter_var($query['page'] ?? '1', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000000]]);
        $size = filter_var($query['page_size'] ?? '20', FILTER_VALIDATE_INT);
        if ($page === false || !in_array($size, [10, 20, 50], true)) {
            throw new AppError('VALIDATION_ERROR', 'Choose a valid page and a page size of 10, 20 or 50.');
        }
        $predicate = implode(' AND ', $where);
        $from = ' FROM ME_INV_RESERVATION R
            LEFT JOIN ME_OE_DOCUMENTS D ON D.CNY# = R.COMPANY_ID AND D.RECORD# = R.SALES_ORDER_ID
            LEFT JOIN ME_OE_DOCUMENT_LINES S ON S.CNY# = R.COMPANY_ID
                AND S.DOCUMENT_RECORD# = R.SALES_ORDER_ID AND S.RECORD# = R.SALES_LINE_ID
            LEFT JOIN ME_INV_PRODUCTS P ON P.COMPANY_ID = R.COMPANY_ID AND P.PRODUCT_ID = R.PRODUCT_ID';
        $total = (int) $tx->query('SELECT COUNT(*) AS TOTAL' . $from . ' WHERE ' . $predicate, $params)[0]['TOTAL'];
        $pages = max(1, (int) ceil($total / $size));
        $page = min($page, $pages);
        $rows = $tx->query(
            'SELECT R.RESERVATION_ID, R.SALES_ORDER_ID, R.SALES_LINE_ID, R.PRODUCT_ID,
                    R.RESERVED_QUANTITY, R.RESERVATION_STATUS, R.TRIP_ID, R.TRIP_LINE_ID,
                    D.DOCUMENT_NUMBER, S.LINE_NUMBER, P.SKU, P.UNIT_LABEL' . $from . ' WHERE ' . $predicate . '
             ORDER BY R.RESERVATION_ID DESC OFFSET :row_offset ROWS FETCH NEXT :page_size ROWS ONLY',
            $params + ['row_offset' => ($page - 1) * $size, 'page_size' => $size],
        );
        $items = [];
        foreach ($rows as $row) {
            $status = $row['RESERVATION_STATUS'];
            $quantity = (int) $row['RESERVED_QUANTITY'];
            if ($quantity < 1 || !in_array($status, ['ACTIVE', 'RELEASED', 'DISPATCHED'], true)) {
                throw new AppError('INVALID_STATE', 'A reservation has invalid stored quantity or status.', 409);
            }
            $active = $status === 'ACTIVE' ? $quantity : 0;
            $claimed = $this->plannedQuantity === null ? null : 0;
            if ($status === 'ACTIVE' && $this->plannedQuantity !== null) {
                $claimed = ($this->plannedQuantity)($actor, $tx, (int) $row['SALES_ORDER_ID'], (int) $row['SALES_LINE_ID']);
                if (!is_int($claimed) || $claimed < 0 || $claimed > $active) {
                    throw new AppError('QUANTITY_CONFLICT', 'Logistics returned an invalid or inconsistent claimed base quantity.', 409);
                }
            }
            $items[] = $this->referenceLabels($row) + ['reservation_id' => (int) $row['RESERVATION_ID'], 'sales_order_id' => (int) $row['SALES_ORDER_ID'],
                'sales_line_id' => (int) $row['SALES_LINE_ID'], 'product_id' => (int) $row['PRODUCT_ID'],
                'reserved_quantity' => $quantity, 'active_quantity' => $active,
                'status' => $status, 'claimed_quantity' => $claimed, 'releasable_quantity' => $status === 'ACTIVE' ? ($claimed === null ? null : $active - $claimed) : 0];
        }
        return ['items' => $items, 'filters' => $filters, 'total' => $total, 'page' => $page, 'page_size' => $size, 'pages' => $pages,
            'first' => $total === 0 ? 0 : ($page - 1) * $size + 1, 'last' => min($page * $size, $total),
            'claim_check_available' => $this->plannedQuantity !== null];
    }

    public function getReferences(Actor $actor, TransactionContext $tx, int $salesOrderId, int $salesLineId, int $productId): array
    {
        (new Authorization($this->connection))->requirePermission($actor, 'inventory.reservations', 'read');
        $rows = $tx->query('SELECT D.DOCUMENT_NUMBER, S.LINE_NUMBER, P.SKU, P.UNIT_LABEL
            FROM ME_OE_DOCUMENTS D
            LEFT JOIN ME_OE_DOCUMENT_LINES S ON S.CNY# = D.CNY# AND S.DOCUMENT_RECORD# = D.RECORD# AND S.RECORD# = :sales_line_id
            LEFT JOIN ME_INV_PRODUCTS P ON P.COMPANY_ID = D.CNY# AND P.PRODUCT_ID = :product_id
            WHERE D.CNY# = :company_id AND D.RECORD# = :sales_order_id',
            ['company_id' => $actor->companyId, 'sales_order_id' => $salesOrderId, 'sales_line_id' => $salesLineId, 'product_id' => $productId]);
        return $this->referenceLabels($rows[0] ?? []);
    }

    private function referenceLabels(array $row): array
    {
        return ['document_number' => isset($row['DOCUMENT_NUMBER']) ? (string) $row['DOCUMENT_NUMBER'] : 'Unavailable',
            'line_number' => isset($row['LINE_NUMBER']) ? (int) $row['LINE_NUMBER'] : null,
            'sku' => isset($row['SKU']) ? (string) $row['SKU'] : 'Unavailable',
            'unit_label' => isset($row['UNIT_LABEL']) ? (string) $row['UNIT_LABEL'] : 'Unavailable'];
    }
}
