<?php
declare(strict_types=1);

namespace MiniErp\sales\invoices;

use MiniErp\shared\AppError;
use MiniErp\shared\database\TransactionContext;
use MiniErp\shared\security\Actor;

/** All queries are company-scoped. Callers hold the Invoice header lock during writes. */
final class InvoiceStore
{
    public const MONEY = "'FM9999999999999990D00', 'NLS_NUMERIC_CHARACTERS=''.,'''";

    public function header(TransactionContext $tx, Actor $actor, string $number, string $type = 'SALES_INVOICE', bool $lock = true): array
    {
        $rows = $tx->query("SELECT D.*, TO_CHAR(D.DOCUMENT_DATE, 'YYYY-MM-DD') AS SAVED_DATE,
            TO_CHAR(D.DUE_DATE, 'YYYY-MM-DD') AS SAVED_DUE_DATE
            FROM ME_OE_DOCUMENTS D WHERE D.CNY# = :company_id
            AND D.DOCUMENT_NUMBER = :document_number AND D.DOCUMENT_TYPE = :document_type" . ($lock ? ' FOR UPDATE' : ''),
            ['company_id' => $actor->companyId, 'document_number' => $number, 'document_type' => $type]);
        if ($rows === []) {
            throw new AppError('NOT_FOUND', 'Document not found.', 404);
        }
        return $rows[0];
    }

    public function customer(TransactionContext $tx, Actor $actor, string|int $id, bool $active = false, bool $lock = false): array
    {
        $rows = $tx->query('SELECT C.*, TO_CHAR(C.AMOUNT_CUSTOMER_OWES_US, ' . self::MONEY . ') AS BALANCE
            FROM ME_OE_CUSTOMERS C WHERE C.CNY# = :company_id AND C.RECORD# = :id' . ($lock ? ' FOR UPDATE' : ''),
            ['company_id' => $actor->companyId, 'id' => $id]);
        if ($rows === []) {
            throw new AppError('NOT_FOUND', 'Customer not found.', 404);
        }
        if ($active && $rows[0]['IS_ACTIVE'] !== 'T') {
            throw new AppError('VALIDATION_ERROR', 'Selected Customer is inactive. Select an active record.');
        }
        return $rows[0];
    }

    public function lines(TransactionContext $tx, Actor $actor, string|int $id): array
    {
        return $tx->query("SELECT L.*,
            TO_CHAR(L.RECORD#, 'FM999999999999999') AS LINE_ID,
            TO_CHAR(L.SOURCE_LINE_RECORD#, 'FM999999999999999') AS SOURCE_LINE_ID,
            TO_CHAR(L.QUANTITY, 'FM999999999') AS SAVED_QUANTITY,
            TO_CHAR(L.UNIT_PRICE, 'FM9999999990D00', 'NLS_NUMERIC_CHARACTERS=''.,''') AS SAVED_PRICE
            FROM ME_OE_DOCUMENT_LINES L
            WHERE L.CNY# = :company_id AND L.DOCUMENT_RECORD# = :id ORDER BY L.LINE_NUMBER",
            ['company_id' => $actor->companyId, 'id' => $id]);
    }

    public function source(TransactionContext $tx, Actor $actor, string|int $id): ?array
    {
        $rows = $tx->query("SELECT S.DOCUMENT_NUMBER, S.RECORD# FROM ME_OE_SOURCE_LINKS L
            JOIN ME_OE_DOCUMENTS S ON S.CNY# = L.CNY# AND S.RECORD# = L.SOURCE_DOCUMENT_RECORD#
            WHERE L.CNY# = :company_id AND L.TARGET_DOCUMENT_RECORD# = :id",
            ['company_id' => $actor->companyId, 'id' => $id]);
        return $rows[0] ?? null;
    }

    public function payments(TransactionContext $tx, Actor $actor, string|int $id): array
    {
        return $tx->query('SELECT COUNT(*) AS PAYMENT_COUNT, TO_CHAR(NVL(SUM(PAYMENT_AMOUNT), 0), ' . self::MONEY . ')
            AS TOTAL_PAID FROM ME_OE_PAYMENTS WHERE CNY# = :company_id AND INVOICE_RECORD# = :id',
            ['company_id' => $actor->companyId, 'id' => $id])[0];
    }

    public function downstream(TransactionContext $tx, Actor $actor, string|int $id): bool
    {
        $binds = ['company_id' => $actor->companyId, 'id' => $id];
        return $tx->query('SELECT RECORD# FROM ME_OE_SOURCE_LINKS WHERE CNY# = :company_id AND SOURCE_DOCUMENT_RECORD# = :id', $binds) !== []
            || $tx->query('SELECT X.RECORD# FROM ME_OE_DOCUMENT_LINES X WHERE X.CNY# = :company_id
                AND X.SOURCE_LINE_RECORD# IN (SELECT L.RECORD# FROM ME_OE_DOCUMENT_LINES L
                    WHERE L.CNY# = X.CNY# AND L.DOCUMENT_RECORD# = :id)', $binds) !== [];
    }

    public function total(array $lines): int
    {
        $total = 0;
        foreach ($lines as $line) {
            $total = InvoiceMoney::add($total, InvoiceMoney::line($line['SAVED_QUANTITY'], $line['SAVED_PRICE'])['cents']);
        }
        return $total;
    }

    public function snapshot(TransactionContext $tx, Actor $actor, array $header): array
    {
        $customer = $this->customer($tx, $actor, $header['CUSTOMER_RECORD#']);
        $lines = $this->lines($tx, $actor, $header['RECORD#']);
        $payments = $this->payments($tx, $actor, $header['RECORD#']);
        $source = $this->source($tx, $actor, $header['RECORD#']);
        $total = $this->total($lines);
        $paid = InvoiceMoney::cents($payments['TOTAL_PAID']);
        if ($lines === [] || $paid > $total || ($header['STATUS'] === 'DRAFT' && (int) $payments['PAYMENT_COUNT'] > 0)
            || !in_array($header['STATUS'], ['DRAFT', 'SUBMITTED'], true)) {
            throw new AppError('INVALID_INVOICE_DATA', 'Invoice state or totals are inconsistent.', 503);
        }
        return [
            'invoice_number' => $header['DOCUMENT_NUMBER'], 'customer_code' => $customer['CUSTOMER_CODE'],
            'warehouse_id' => $header['WAREHOUSE_ID'], 'invoice_date' => $header['SAVED_DATE'],
            'due_date' => $header['SAVED_DUE_DATE'], 'payment_terms' => $header['PAYMENT_TERMS'],
            'notes' => $header['NOTES'] ?? '', 'shipping_address' => $header['SHIPPING_ADDRESS'],
            'source_order_number' => $source['DOCUMENT_NUMBER'] ?? null,
            'status' => $header['STATUS'] === 'DRAFT' ? 'Draft' : 'Submitted', 'current_version' => (int) $header['VERSION'],
            'document_total' => InvoiceMoney::format($total), 'total_paid' => InvoiceMoney::format($paid),
            'amount_due' => InvoiceMoney::format($total - $paid),
            'payment_status' => $header['STATUS'] === 'DRAFT' ? null : ($paid === 0 ? 'Unpaid' : ($paid === $total ? 'Paid' : 'Partially Paid')),
            'available_actions' => [], // Workflow adds permission-aware hints.
            'lines' => array_map(static fn (array $line): array => [
                'line_id' => $line['LINE_ID'], 'source_line_id' => $line['SOURCE_LINE_ID'],
                'line_number' => (int) $line['LINE_NUMBER'], 'product_id' => (string) $line['PRODUCT_ID'],
                'description' => $line['DESCRIPTION'] ?? '', 'quantity' => $line['SAVED_QUANTITY'],
                'unit_price' => $line['SAVED_PRICE'], 'warehouse_id' => $header['WAREHOUSE_ID'],
                'line_amount' => InvoiceMoney::format(InvoiceMoney::line($line['SAVED_QUANTITY'], $line['SAVED_PRICE'])['cents']),
            ], $lines),
        ];
    }
}
