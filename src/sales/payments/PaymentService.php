<?php
declare(strict_types=1);

namespace MiniErp\sales\payments;

use MiniErp\sales\SalesPermissions;
use MiniErp\sales\Validation;
use MiniErp\sales\BusinessDate;
use MiniErp\sales\invoices\InvoiceStore;
use MiniErp\sales\invoices\InvoiceMoney;
use MiniErp\sales\invoices\InvoiceActions;

use MiniErp\shared\Api;
use MiniErp\shared\AppError;
use MiniErp\shared\database\Connection;
use MiniErp\shared\database\TransactionContext;
use MiniErp\shared\database\TransactionManager;
use MiniErp\shared\security\Actor;
use MiniErp\shared\security\Authorization;

/** Append-only receipts; Invoice header locks serialize payment/return/version changes. */
final readonly class PaymentService
{
    private InvoiceStore $store;
    private SalesPermissions $permissions;
    private \Closure $businessDate;

    public function __construct(private Connection $connection, ?\Closure $businessDate = null)
    {
        $this->store = new InvoiceStore();
        $this->permissions = new SalesPermissions(new Authorization($connection));
        $this->businessDate = $businessDate ?? BusinessDate::today(...);
    }

    public function record(Actor $actor, string $number, array $input): array
    {
        // The public retry reference is optional, but every receipt retains an internal action identity.
        // Without an explicit reference, retries are checked against the locked Invoice version.
        if (!array_key_exists('action_reference', $input)) {
            $input['action_reference'] = bin2hex(random_bytes(16));
        }
        $actions = new InvoiceActions(new TransactionManager($this->connection), $this->permissions);
        return $actions->run($actor, 'record_payment', $number, $input, function (TransactionContext $tx) use ($actor, $number, $input): array {
            Validation::allowedFields($input, ['action_reference', 'expected_version', 'payment_amount', 'payment_date', 'payment_reference']);
            $header = $this->store->header($tx, $actor, $this->number($number));
            if ($header['STATUS'] !== 'SUBMITTED') {
                throw new AppError('INVALID_STATE', 'Submit the Invoice before recording a payment.', 409);
            }
            $before = $this->summary($tx, $actor, $header);
            $due = InvoiceMoney::cents($before['amount_due']);
            if ((int) $header['VERSION'] !== Api::integer($input, 'expected_version')) {
                throw new AppError('STALE_VERSION', 'Amount Due has changed to ' . $before['amount_due'] . '. Review the amount and try again.', 409);
            }
            if ($due === 0) {
                throw new AppError('INVALID_STATE', 'This Invoice is fully paid. No further payment can be recorded.', 409);
            }
            if ((int) $header['VERSION'] >= 9999999999) {
                throw new AppError('INVALID_STATE', 'Invoice version capacity exhausted.', 409);
            }
            $amount = $this->amount($input['payment_amount'] ?? null);
            if ($amount > $due) {
                throw new AppError('VALIDATION_ERROR', 'Payment Amount cannot exceed Amount Due (' . $before['amount_due'] . ').');
            }
            $date = $input['payment_date'] ?? null;
            if ($date === null || $date === '') {
                throw new AppError('VALIDATION_ERROR', 'Enter Payment Date.');
            }
            $date = Validation::date($date, 'Payment Date');
            Validation::dateRange($date, $header['SAVED_DATE'], 'Payment Date', 'Invoice Date');
            Validation::notFuture($date, ($this->businessDate)(), 'Payment Date');
            $reference = Validation::text($input['payment_reference'] ?? '', 'Payment Reference', 50, false);

            // Payments on an existing Invoice are allowed even if its Customer is now inactive.
            // This shares the Invoice -> Customer lock order used by submit/return-to-Draft.
            $customer = $this->store->customer($tx, $actor, $header['CUSTOMER_RECORD#'], false, true);
            $balance = InvoiceMoney::cents($customer['BALANCE'], 'Customer balance');
            if ($balance < $amount) {
                throw new AppError('INVALID_INVOICE_DATA', 'Customer balance is inconsistent with this Invoice.', 503);
            }
            $sequence = $tx->query("SELECT TO_CHAR(ME_OE_PAYMENT_NO_SEQ.NEXTVAL, 'FM999999999999999') AS SEQUENCE_VALUE FROM DUAL")[0]['SEQUENCE_VALUE'] ?? null;
            if (!is_string($sequence) || !preg_match('/\A[1-9][0-9]{0,14}\z/D', $sequence)) {
                throw new AppError('NUMBERING_UNAVAILABLE', 'Payment Number could not be generated.', 503);
            }
            $paymentNumber = 'PAY-' . str_pad($sequence, 15, '0', STR_PAD_LEFT);
            $tx->execute("INSERT INTO ME_OE_PAYMENTS
                (CNY#, PAYMENT_NUMBER, INVOICE_RECORD#, PAYMENT_AMOUNT, PAYMENT_DATE, PAYMENT_REFERENCE, ACTION_REFERENCE)
                VALUES (:company_id, :payment_number, :invoice_id,
                    TO_NUMBER(:payment_amount, '9999999999999990D99', 'NLS_NUMERIC_CHARACTERS=''.,'''),
                    TO_DATE(:payment_date, 'YYYY-MM-DD'), :payment_reference, :action_reference)",
                ['company_id' => $actor->companyId, 'payment_number' => $paymentNumber, 'invoice_id' => $header['RECORD#'],
                    'payment_amount' => InvoiceMoney::format($amount), 'payment_date' => $date,
                    'payment_reference' => $reference, 'action_reference' => $input['action_reference']]);
            $this->changed($tx->execute("UPDATE ME_OE_CUSTOMERS SET AMOUNT_CUSTOMER_OWES_US =
                TO_NUMBER(:balance, '9999999999999990D99', 'NLS_NUMERIC_CHARACTERS=''.,'''), WHENMODIFIED = CURRENT_TIMESTAMP
                WHERE CNY# = :company_id AND RECORD# = :customer_id",
                ['company_id' => $actor->companyId, 'customer_id' => $header['CUSTOMER_RECORD#'], 'balance' => InvoiceMoney::format($balance - $amount)]));
            $this->changed($tx->execute("UPDATE ME_OE_DOCUMENTS SET VERSION = VERSION + 1, WHENMODIFIED = CURRENT_TIMESTAMP
                WHERE CNY# = :company_id AND RECORD# = :invoice_id AND VERSION = :expected_version
                  AND DOCUMENT_TYPE = 'SALES_INVOICE' AND STATUS = 'SUBMITTED'",
                ['company_id' => $actor->companyId, 'invoice_id' => $header['RECORD#'], 'expected_version' => (int) $header['VERSION']]));
            $header['VERSION'] = (int) $header['VERSION'] + 1;
            $saved = $this->rows($tx, $actor, $header, $input['action_reference']);
            if (count($saved) !== 1) {
                throw new AppError('INVALID_INVOICE_DATA', 'Saved payment is unavailable.', 503);
            }
            return ['payment' => $saved[0], 'invoice' => $this->summary($tx, $actor, $header),
                'current_version' => (int) $header['VERSION']];
        });
    }

    public function list(Actor $actor, string $number, array $query = []): array
    {
        $this->permissions->requirePayment($actor, 'read');
        $page = Api::pagination($query);
        return (new TransactionManager($this->connection))->run(function (TransactionContext $tx) use ($actor, $number, $page): array {
            // Empty Draft/unpaid history is valid; absent/wrong-type/foreign Invoices are 404.
            $header = $this->store->header($tx, $actor, $this->number($number));
            return ['items' => $this->rows($tx, $actor, $header, null, $page),
                'page' => $page['page'], 'page_size' => $page['page_size']];
        });
    }

    private function number(string $number): string
    {
        if (!preg_match('/\A[A-Za-z0-9_-]{1,20}\z/D', $number)) {
            throw new AppError('NOT_FOUND', 'Invoice not found.', 404);
        }
        return $number;
    }

    private function amount(mixed $value): int
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            throw new AppError('VALIDATION_ERROR', 'Enter Payment Amount.');
        }
        if (!is_string($value) || !preg_match('/\A-?[0-9]+(?:\.[0-9]+)?\z/D', $value)) {
            throw new AppError('VALIDATION_ERROR', 'Enter a valid Payment Amount.');
        }
        if (str_starts_with($value, '-') || !preg_match('/[1-9]/', $value)) {
            throw new AppError('VALIDATION_ERROR', 'Payment Amount must be greater than 0.');
        }
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        if (strlen($fraction) > 2) {
            throw new AppError('VALIDATION_ERROR', 'Payment Amount can have at most 2 decimal places.');
        }
        if (strlen($whole) > 16) {
            throw new AppError('VALIDATION_ERROR', 'Payment Amount exceeds the allowed numeric limit.');
        }
        return InvoiceMoney::cents($value, 'Payment Amount');
    }

    private function changed(int $count): void
    {
        if ($count !== 1) {
            throw new AppError('INVALID_INVOICE_DATA', 'Payment state changed unexpectedly. Refresh and try again.', 409);
        }
    }

    /** Monetary facts only: no Inventory, Logistics or source-eligibility dependency. */
    private function summary(TransactionContext $tx, Actor $actor, array $header): array
    {
        $lines = $tx->query('SELECT COUNT(*) AS LINE_COUNT, TO_CHAR(NVL(SUM(ROUND(QUANTITY * UNIT_PRICE, 2)), 0), '
            . InvoiceStore::MONEY . ') AS DOCUMENT_TOTAL FROM ME_OE_DOCUMENT_LINES
            WHERE CNY# = :company_id AND DOCUMENT_RECORD# = :invoice_id',
            ['company_id' => $actor->companyId, 'invoice_id' => $header['RECORD#']])[0];
        $total = InvoiceMoney::cents($lines['DOCUMENT_TOTAL']);
        $paid = InvoiceMoney::cents($this->store->payments($tx, $actor, $header['RECORD#'])['TOTAL_PAID']);
        if ((int) $lines['LINE_COUNT'] === 0 || $total === 0 || $paid > $total) {
            throw new AppError('INVALID_INVOICE_DATA', 'Invoice state or totals are inconsistent.', 503);
        }
        return ['invoice_number' => $header['DOCUMENT_NUMBER'], 'status' => 'Submitted', 'current_version' => (int) $header['VERSION'],
            'document_total' => InvoiceMoney::format($total), 'total_paid' => InvoiceMoney::format($paid),
            'amount_due' => InvoiceMoney::format($total - $paid), 'payment_status' => $paid === 0 ? 'Unpaid' : ($paid === $total ? 'Paid' : 'Partially Paid')];
    }

    private function rows(TransactionContext $tx, Actor $actor, array $header, ?string $reference = null, ?array $page = null): array
    {
        $binds = ['company_id' => $actor->companyId, 'invoice_id' => $header['RECORD#']];
        $where = '';
        if ($reference !== null) {
            $where = ' WHERE R.ACTION_REFERENCE = :action_reference';
            $binds['action_reference'] = $reference;
        }
        // Compute running totals over all receipts in recording order BEFORE filtering/pagination.
        // Payment dates may be backdated; recorded receipt order and exact Oracle NUMBER values are authoritative.
        $sql = 'SELECT R.PAYMENT_NUMBER, TO_CHAR(R.PAYMENT_AMOUNT, ' . InvoiceStore::MONEY . ') AS PAYMENT_AMOUNT,
            TO_CHAR(R.PAYMENT_DATE, \'YYYY-MM-DD\') AS PAYMENT_DATE, R.PAYMENT_REFERENCE, R.CREATED_BY,
            TO_CHAR(R.RECORDED_AT AT TIME ZONE \'UTC\', \'YYYY-MM-DD"T"HH24:MI:SS.FF6"Z"\') AS CREATED_AT,
            TO_CHAR(R.DOCUMENT_TOTAL - R.PAID_TO_DATE, ' . InvoiceStore::MONEY . ') AS AMOUNT_DUE,
            CASE WHEN R.DOCUMENT_TOTAL = R.PAID_TO_DATE THEN \'Paid\' ELSE \'Partially Paid\' END AS PAYMENT_STATUS
            FROM (
                SELECT P.PAYMENT_NUMBER, P.PAYMENT_AMOUNT, P.PAYMENT_DATE, P.PAYMENT_REFERENCE, P.ACTION_REFERENCE,
                    A.ACTOR_ID AS CREATED_BY, A.CREATED_AT AS RECORDED_AT,
                    SUM(P.PAYMENT_AMOUNT) OVER (PARTITION BY P.CNY#, P.INVOICE_RECORD#
                        ORDER BY A.CREATED_AT, P.PAYMENT_NUMBER ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW) AS PAID_TO_DATE,
                    (SELECT NVL(SUM(ROUND(L.QUANTITY * L.UNIT_PRICE, 2)), 0) FROM ME_OE_DOCUMENT_LINES L
                        WHERE L.CNY# = P.CNY# AND L.DOCUMENT_RECORD# = P.INVOICE_RECORD#) AS DOCUMENT_TOTAL
                FROM ME_OE_PAYMENTS P JOIN ME_ACTIONS A
                  ON A.COMPANY_ID = P.CNY# AND A.ACTION_REFERENCE = P.ACTION_REFERENCE
                WHERE P.CNY# = :company_id AND P.INVOICE_RECORD# = :invoice_id
            ) R' . $where . ' ORDER BY R.RECORDED_AT, R.PAYMENT_NUMBER';
        if ($page !== null) {
            $sql .= ' OFFSET :offset_rows ROWS FETCH NEXT :limit_rows ROWS ONLY';
            $binds['offset_rows'] = $page['offset'];
            $binds['limit_rows'] = $page['page_size'];
        }
        return array_map(static fn (array $row): array => [
            'payment_number' => $row['PAYMENT_NUMBER'], 'invoice_number' => $header['DOCUMENT_NUMBER'],
            'payment_amount' => InvoiceMoney::format(InvoiceMoney::cents($row['PAYMENT_AMOUNT'])),
            'payment_date' => $row['PAYMENT_DATE'], 'payment_reference' => $row['PAYMENT_REFERENCE'] ?? '',
            'amount_due' => InvoiceMoney::format(InvoiceMoney::cents($row['AMOUNT_DUE'], 'Amount due after payment')),
            'payment_status' => $row['PAYMENT_STATUS'],
            'created_by' => (string) $row['CREATED_BY'], 'created_at' => $row['CREATED_AT'],
        ], $tx->query($sql, $binds));
    }
}
