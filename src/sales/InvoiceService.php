<?php
declare(strict_types=1);

namespace MiniErp\sales;

use MiniErp\shared\Api;
use MiniErp\shared\AppError;
use MiniErp\shared\database\Connection;
use MiniErp\shared\security\Actor;
use MiniErp\shared\security\Authorization;

final readonly class InvoiceService
{
    public function __construct(private Connection $connection, private ?\Closure $businessDate = null) {}

    public function create(Actor $actor, array $input): array
    {
        return $this->workflow()->create($actor, $input);
    }

    public function get(Actor $actor, string $number): array
    {
        return $this->workflow()->get($actor, $number);
    }

    public function update(Actor $actor, string $number, array $input): array
    {
        return $this->workflow()->update($actor, $number, $input);
    }

    public function action(Actor $actor, string $number, string $action, array $input): array
    {
        return $this->workflow()->action($actor, $number, $action, $input);
    }

    private function workflow(): InvoiceWorkflow
    {
        return new InvoiceWorkflow($this->connection, $this->businessDate);
    }

    public function list(Actor $actor, array $query = []): array
    {
        $permissions = new SalesPermissions(
            new Authorization($this->connection),
        );
        $permissions->requireDocument($actor, 'invoice', 'read');

        $page = Api::pagination($query, [
            'customer_code',
            'warehouse_id',
            'status',
            'from_date',
            'to_date',
            'payment_status',
        ]);

        $filters = $page['filters'];

        $binds = [
            'company_id' => $actor->companyId,
            'line_company_id' => $actor->companyId,
            'payment_company_id' => $actor->companyId,
            'offset_rows' => $page['offset'],
            'limit_rows' => $page['page_size'],
        ];

        $where = [
            'D.CNY# = :company_id',
            "D.DOCUMENT_TYPE = 'SALES_INVOICE'",
        ];

        foreach ([
            'customer_code' => 'C.CUSTOMER_CODE',
            'warehouse_id' => 'D.WAREHOUSE_ID',
        ] as $field => $column) {
            if (isset($filters[$field])) {
                $binds[$field] = Validation::text(
                    $filters[$field],
                    $field === 'customer_code' ? 'Customer Code' : 'Warehouse',
                    20,
                );
                $where[] = "$column = :$field";
            }
        }

        if (isset($filters['status'])) {
            $statuses = [
                'Draft' => 'DRAFT',
                'Submitted' => 'SUBMITTED',
            ];

            if (!isset($statuses[$filters['status']])) {
                throw new AppError(
                    'VALIDATION_ERROR',
                    'Select a valid Invoice status.',
                );
            }

            $binds['status'] = $statuses[$filters['status']];
            $where[] = 'D.STATUS = :status';
        }

        foreach (['from_date', 'to_date'] as $field) {
            if (isset($filters[$field])) {
                $binds[$field] = Validation::date(
                    $filters[$field],
                    $field === 'from_date' ? 'From Date' : 'To Date',
                );
            }
        }

        if (isset($binds['from_date'], $binds['to_date'])) {
            Validation::dateRange(
                $binds['to_date'],
                $binds['from_date'],
                'To Date',
                'From Date',
            );
        }

        if (isset($binds['from_date'])) {
            $where[] = "D.DOCUMENT_DATE >= TO_DATE(:from_date, 'YYYY-MM-DD')";
        }

        if (isset($binds['to_date'])) {
            $where[] = "D.DOCUMENT_DATE < TO_DATE(:to_date, 'YYYY-MM-DD') + 1";
        }

        $total = 'NVL(L.DOCUMENT_TOTAL, 0)';
        $paid = 'NVL(P.TOTAL_PAID, 0)';
        $due = "($total - $paid)";

        $paymentStatus = "
            CASE
                WHEN D.STATUS = 'DRAFT' THEN NULL
                WHEN $paid = 0 THEN 'Unpaid'
                WHEN $due = 0 THEN 'Paid'
                ELSE 'Partially Paid'
            END
        ";

        if (isset($filters['payment_status'])) {
            if (!in_array(
                $filters['payment_status'],
                ['Unpaid', 'Partially Paid', 'Paid'],
                true,
            )) {
                throw new AppError(
                    'VALIDATION_ERROR',
                    'Select a valid payment status.',
                );
            }

            $binds['payment_status'] = $filters['payment_status'];
            $where[] = "D.STATUS = 'SUBMITTED'";
            $where[] = "($paymentStatus) = :payment_status";
        }

        // Format Oracle NUMBER values without converting money to PHP floats.
        $formatMoney = static fn (string $expression): string =>
            "TO_CHAR(
                $expression,
                'FM9999999999999990D00',
                'NLS_NUMERIC_CHARACTERS=''.,'''
            )";

        $totalOutput = $formatMoney($total);
        $paidOutput = $formatMoney($paid);
        $dueOutput = $formatMoney($due);
        $conditions = implode(' AND ', $where);

        $sql = "
            WITH LINE_TOTALS AS (
                SELECT CNY#, DOCUMENT_RECORD#,
                       SUM(ROUND(QUANTITY * UNIT_PRICE, 2)) AS DOCUMENT_TOTAL
                FROM ME_OE_DOCUMENT_LINES
                WHERE CNY# = :line_company_id
                GROUP BY CNY#, DOCUMENT_RECORD#
            ),
            PAYMENT_TOTALS AS (
                SELECT CNY#, INVOICE_RECORD#,
                       SUM(PAYMENT_AMOUNT) AS TOTAL_PAID
                FROM ME_OE_PAYMENTS
                WHERE CNY# = :payment_company_id
                GROUP BY CNY#, INVOICE_RECORD#
            )
            SELECT
                D.DOCUMENT_NUMBER AS INVOICE_NUMBER,
                D.STATUS,
                D.VERSION,
                C.CUSTOMER_CODE,
                D.WAREHOUSE_ID,
                TO_CHAR(D.DOCUMENT_DATE, 'YYYY-MM-DD') AS INVOICE_DATE,
                TO_CHAR(D.DUE_DATE, 'YYYY-MM-DD') AS DUE_DATE,
                D.PAYMENT_TERMS,
                D.NOTES,
                D.SHIPPING_ADDRESS,
                S.DOCUMENT_NUMBER AS SOURCE_ORDER_NUMBER,
                $totalOutput AS DOCUMENT_TOTAL,
                $paidOutput AS TOTAL_PAID,
                $dueOutput AS AMOUNT_DUE,
                $paymentStatus AS PAYMENT_STATUS
            FROM ME_OE_DOCUMENTS D
            JOIN ME_OE_CUSTOMERS C
              ON C.CNY# = D.CNY#
             AND C.RECORD# = D.CUSTOMER_RECORD#
            LEFT JOIN LINE_TOTALS L
              ON L.CNY# = D.CNY#
             AND L.DOCUMENT_RECORD# = D.RECORD#
            LEFT JOIN PAYMENT_TOTALS P
              ON P.CNY# = D.CNY#
             AND P.INVOICE_RECORD# = D.RECORD#
            LEFT JOIN ME_OE_SOURCE_LINKS SL
              ON SL.CNY# = D.CNY#
             AND SL.TARGET_DOCUMENT_RECORD# = D.RECORD#
            LEFT JOIN ME_OE_DOCUMENTS S
              ON S.CNY# = SL.CNY#
             AND S.RECORD# = SL.SOURCE_DOCUMENT_RECORD#
             AND S.DOCUMENT_TYPE = 'SALES_ORDER'
            WHERE $conditions
            ORDER BY D.DOCUMENT_DATE, D.DOCUMENT_NUMBER
            OFFSET :offset_rows ROWS
            FETCH NEXT :limit_rows ROWS ONLY
        ";

        $rows = $this->connection->query($sql, $binds);

        return [
            'items' => array_map(
                fn (array $row): array => $this->present($row),
                $rows,
            ),
            'page' => $page['page'],
            'page_size' => $page['page_size'],
        ];
    }

    private function present(array $row): array
    {
        foreach (['DOCUMENT_TOTAL', 'TOTAL_PAID', 'AMOUNT_DUE'] as $field) {
            if (!is_string($row[$field] ?? null)
                || !preg_match(
                    '/\A[0-9]{1,16}\.[0-9]{2}\z/D',
                    $row[$field],
                )) {
                throw new AppError(
                    'INVALID_INVOICE_DATA',
                    'Invoice monetary totals are unavailable.',
                    503,
                );
            }
        }

        $status = match ($row['STATUS']) {
            'DRAFT' => 'Draft',
            'SUBMITTED' => 'Submitted',
            default => throw new AppError(
                'INVALID_INVOICE_DATA',
                'Invoice status is unavailable.',
                503,
            ),
        };

        if ($status === 'Draft' && $row['TOTAL_PAID'] !== '0.00') {
            throw new AppError(
                'INVALID_INVOICE_DATA',
                'A Draft Invoice has recorded payments.',
                503,
            );
        }

        return [
            'invoice_number' => $row['INVOICE_NUMBER'],
            'customer_code' => $row['CUSTOMER_CODE'],
            'warehouse_id' => $row['WAREHOUSE_ID'],
            'invoice_date' => $row['INVOICE_DATE'],
            'due_date' => $row['DUE_DATE'],
            'payment_terms' => $row['PAYMENT_TERMS'],
            'notes' => $row['NOTES'] ?? '',
            'shipping_address' => $row['SHIPPING_ADDRESS'],
            'source_order_number' => $row['SOURCE_ORDER_NUMBER'],
            'status' => $status,
            'current_version' => (int) $row['VERSION'],
            'document_total' => $row['DOCUMENT_TOTAL'],
            'total_paid' => $row['TOTAL_PAID'],
            'amount_due' => $row['AMOUNT_DUE'],
            'payment_status' => $row['PAYMENT_STATUS'],

            // List results omit mutation hints; full detail checks current permissions and eligibility.
            'available_actions' => [],
        ];
    }
}
