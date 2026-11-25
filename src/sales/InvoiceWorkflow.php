<?php
declare(strict_types=1);

namespace MiniErp\sales;

use MiniErp\shared\Api;
use MiniErp\shared\AppError;
use MiniErp\shared\database\Connection;
use MiniErp\shared\database\DatabaseError;
use MiniErp\shared\database\TransactionContext;
use MiniErp\shared\database\TransactionManager;
use MiniErp\shared\security\Actor;
use MiniErp\shared\security\Authorization;

/** Invoice writes share one transaction across header, lines, source, balance, audit and replay. */
final readonly class InvoiceWorkflow
{
    private InvoiceStore $store;
    private InvoiceDraft $draft;
    private InvoiceActions $actions;
    private SalesPermissions $permissions;

    public function __construct(private Connection $connection, ?\Closure $businessDate = null)
    {
        $this->store = new InvoiceStore();
        $this->permissions = new SalesPermissions(new Authorization($connection));
        $this->actions = new InvoiceActions(new TransactionManager($connection), $this->permissions);
        $clock = $businessDate ?? BusinessDate::today(...);
        $this->draft = new InvoiceDraft($this->store, $clock, $this->permissions);
    }

    public function get(Actor $actor, string $number): array
    {
        $this->permissions->requireDocument($actor, 'invoice', 'read');
        return (new TransactionManager($this->connection))->run(function (TransactionContext $tx) use ($actor, $number): array {
            // Holding the header lock keeps header/lines/payment reads consistent with participating writers.
            return $this->snapshot($tx, $actor, $this->store->header($tx, $actor, $this->number($number)));
        });
    }

    public function create(Actor $actor, array $input): array
    {
        return $this->actions->run($actor, 'create', null, $input, function (TransactionContext $tx) use ($actor, $input): array {
            $this->fields($input, true);
            $draft = $this->draft->prepare($tx, $actor, $input);
            $value = $tx->query("SELECT TO_CHAR(ME_OE_INVOICE_NO_SEQ.NEXTVAL, 'FM999999999999999') AS NUMBER_VALUE FROM DUAL")[0]['NUMBER_VALUE'] ?? null;
            if (!is_string($value) || !preg_match('/\A[1-9][0-9]{0,14}\z/D', $value)) {
                throw new AppError('NUMBERING_UNAVAILABLE', 'Invoice Number could not be generated.', 503);
            }
            $number = 'SI-' . str_pad($value, 15, '0', STR_PAD_LEFT);
            $tx->execute("INSERT INTO ME_OE_DOCUMENTS
                (CNY#, DOCUMENT_TYPE, DOCUMENT_NUMBER, CUSTOMER_RECORD#, WAREHOUSE_ID, DOCUMENT_DATE,
                 STATUS, PAYMENT_TERMS, DUE_DATE, NOTES, SHIPPING_ADDRESS, VERSION)
                VALUES (:company_id, 'SALES_INVOICE', :document_number, :customer_id, :warehouse,
                    TO_DATE(:invoice_date, 'YYYY-MM-DD'), 'DRAFT', :terms,
                    TO_DATE(:due_date, 'YYYY-MM-DD'), :notes, :shipping, 1)",
                ['company_id' => $actor->companyId, 'document_number' => $number, ...$this->headerBinds($draft)]);
            $header = $this->store->header($tx, $actor, $number);
            $this->saveLines($tx, $actor, $header, $draft);
            $this->saveSource($tx, $actor, $header['RECORD#'], $draft['source_id']);
            return $this->snapshot($tx, $actor, $header);
        });
    }

    public function update(Actor $actor, string $number, array $input): array
    {
        return $this->actions->run($actor, 'edit', $number, $input, function (TransactionContext $tx) use ($actor, $number, $input): array {
            $this->fields($input, false);
            $header = $this->store->header($tx, $actor, $this->number($number));
            $this->version($header, $input);
            DocumentRules::requireAction('invoice', 'edit', $this->status($header));
            $this->unused($tx, $actor, $header, 'edit');
            $draft = $this->draft->prepare($tx, $actor, $input, $header);
            $this->saveLines($tx, $actor, $header, $draft);
            $this->saveSource($tx, $actor, $header['RECORD#'], $draft['source_id']);
            $changed = $tx->execute("UPDATE ME_OE_DOCUMENTS SET CUSTOMER_RECORD# = :customer_id,
                WAREHOUSE_ID = :warehouse, DOCUMENT_DATE = TO_DATE(:invoice_date, 'YYYY-MM-DD'),
                PAYMENT_TERMS = :terms, DUE_DATE = TO_DATE(:due_date, 'YYYY-MM-DD'), NOTES = :notes,
                SHIPPING_ADDRESS = :shipping, VERSION = VERSION + 1, WHENMODIFIED = CURRENT_TIMESTAMP
                WHERE CNY# = :company_id AND RECORD# = :id AND VERSION = :version AND DOCUMENT_TYPE = 'SALES_INVOICE'",
                ['company_id' => $actor->companyId, 'id' => $header['RECORD#'], 'version' => (int) $header['VERSION'], ...$this->headerBinds($draft)]);
            $this->changed($changed);
            return $this->snapshot($tx, $actor, $this->store->header($tx, $actor, $number));
        });
    }

    public function action(Actor $actor, string $number, string $action, array $input): array
    {
        if (!in_array($action, ['submit', 'return_to_draft', 'delete'], true)) {
            throw new AppError('FORBIDDEN', 'This Invoice action is not supported.', 403);
        }
        return $this->actions->run($actor, $action, $number, $input, function (TransactionContext $tx) use ($actor, $number, $action, $input): array {
            Validation::allowedFields($input, ['action_reference', 'expected_version']);
            $header = $this->store->header($tx, $actor, $this->number($number));
            $this->version($header, $input, $action !== 'delete');
            $payments = $this->store->payments($tx, $actor, $header['RECORD#']);
            $hasPayments = (int) $payments['PAYMENT_COUNT'] > 0;
            $downstream = $this->store->downstream($tx, $actor, $header['RECORD#']);
            DocumentRules::requireAction('invoice', $action, $this->status($header), $downstream, $hasPayments);
            if ($action === 'delete') {
                $source = $this->store->source($tx, $actor, $header['RECORD#']);
                if ($source !== null) {
                    $this->store->header($tx, $actor, $source['DOCUMENT_NUMBER'], 'SALES_ORDER');
                }
                $this->saveSource($tx, $actor, $header['RECORD#'], null);
                $binds = ['company_id' => $actor->companyId, 'id' => $header['RECORD#']];
                $tx->execute('DELETE FROM ME_OE_DOCUMENT_LINES WHERE CNY# = :company_id AND DOCUMENT_RECORD# = :id', $binds);
                $this->changed($tx->execute("DELETE FROM ME_OE_DOCUMENTS WHERE CNY# = :company_id AND RECORD# = :id
                    AND DOCUMENT_TYPE = 'SALES_INVOICE' AND VERSION = :version", [...$binds, 'version' => (int) $header['VERSION']]));
                return ['invoice_number' => $number, 'deleted' => true, 'current_version' => null];
            }
            $lines = $this->store->lines($tx, $actor, $header['RECORD#']);
            $total = $this->store->total($lines);
            if ($action === 'submit') {
                if ($hasPayments) {
                    throw new AppError('INVALID_STATE', 'A Draft Invoice cannot have recorded payments.', 409);
                }
                // Validate the authoritative saved Draft, not caller-supplied replacement contents.
                $this->draft->prepare($tx, $actor, ['lines' => array_map(static fn (array $line): array => [
                    'line_id' => $line['LINE_ID'], 'item_code' => $line['ITEM_CODE'],
                    'description' => $line['DESCRIPTION'] ?? '', 'quantity' => $line['SAVED_QUANTITY'], 'unit_price' => $line['SAVED_PRICE'],
                    ...($line['SOURCE_LINE_ID'] === null ? [] : ['source_line_id' => $line['SOURCE_LINE_ID']]),
                ], $lines)], $header);
                if ($total === 0) {
                    throw new AppError('VALIDATION_ERROR', 'Invoice Total must be greater than 0.');
                }
                $this->balance($tx, $actor, $header, $total, true);
                $nextStatus = 'SUBMITTED';
            } else {
                $this->balance($tx, $actor, $header, $total, false);
                $nextStatus = 'DRAFT';
            }
            $this->changed($tx->execute("UPDATE ME_OE_DOCUMENTS SET STATUS = :status, VERSION = VERSION + 1,
                WHENMODIFIED = CURRENT_TIMESTAMP WHERE CNY# = :company_id AND RECORD# = :id
                AND VERSION = :version AND DOCUMENT_TYPE = 'SALES_INVOICE'",
                ['status' => $nextStatus, 'company_id' => $actor->companyId, 'id' => $header['RECORD#'], 'version' => (int) $header['VERSION']]));
            return $this->snapshot($tx, $actor, $this->store->header($tx, $actor, $number));
        });
    }

    private function fields(array $input, bool $create): void
    {
        Validation::allowedFields($input, ['action_reference', 'customer_code', 'warehouse_id', 'invoice_date',
            'payment_terms', 'due_date', 'notes', 'source_order_number', 'lines', ...($create ? [] : ['expected_version'])]);
        foreach (['customer_code', 'warehouse_id', 'invoice_date', 'payment_terms', 'due_date', 'notes'] as $field) {
            if (array_key_exists($field, $input) && !is_string($input[$field])) {
                throw new AppError('VALIDATION_ERROR', $field . ' must be text.');
            }
        }
    }

    private function number(string $number): string
    {
        if (!preg_match('/\A[A-Za-z0-9_-]{1,20}\z/D', $number)) {
            throw new AppError('NOT_FOUND', 'Invoice not found.', 404);
        }
        return $number;
    }

    private function status(array $header): string
    {
        return match ($header['STATUS']) {
            'DRAFT' => 'Draft', 'SUBMITTED' => 'Submitted',
            default => throw new AppError('INVALID_INVOICE_DATA', 'Invalid Invoice status.', 503),
        };
    }

    private function version(array $header, array $input, bool $increment = true): void
    {
        DocumentRules::requireVersion((int) $header['VERSION'], Api::integer($input, 'expected_version'));
        if ($increment && (int) $header['VERSION'] >= 9999999999) {
            throw new AppError('INVALID_STATE', 'Invoice version capacity exhausted.', 409);
        }
    }

    private function unused(TransactionContext $tx, Actor $actor, array $header, string $action): void
    {
        if ($this->store->downstream($tx, $actor, $header['RECORD#'])
            || (int) $this->store->payments($tx, $actor, $header['RECORD#'])['PAYMENT_COUNT'] > 0) {
            throw new AppError('INVALID_STATE', 'An Invoice with downstream references or payments cannot be ' . $action . 'ed.', 409);
        }
    }

    private function changed(int $count): void
    {
        if ($count !== 1) {
            throw new AppError('STALE_VERSION', 'This document has changed. Refresh and try again.', 409);
        }
    }

    private function headerBinds(array $draft): array
    {
        return ['customer_id' => $draft['customer_id'], 'warehouse' => $draft['warehouse'], 'invoice_date' => $draft['date'],
            'terms' => $draft['terms'], 'due_date' => $draft['due'], 'notes' => $draft['notes'], 'shipping' => $draft['shipping']];
    }

    private function saveSource(TransactionContext $tx, Actor $actor, string|int $id, string|int|null $source): void
    {
        $previous = $this->store->source($tx, $actor, $id);
        if ((string) ($previous['RECORD#'] ?? '') === (string) ($source ?? '')) {
            return;
        }
        $tx->execute('DELETE FROM ME_OE_SOURCE_LINKS WHERE CNY# = :company_id AND TARGET_DOCUMENT_RECORD# = :id',
            ['company_id' => $actor->companyId, 'id' => $id]);
        if ($source !== null) {
            try {
                $tx->execute('INSERT INTO ME_OE_SOURCE_LINKS (CNY#, SOURCE_DOCUMENT_RECORD#, TARGET_DOCUMENT_RECORD#)
                    VALUES (:company_id, :source_id, :target_id)',
                    ['company_id' => $actor->companyId, 'source_id' => $source, 'target_id' => $id]);
            } catch (DatabaseError $error) {
                if ($error->oracleCode === 1) {
                    throw new AppError('SOURCE_IN_USE', 'Selected Order is already used by another Invoice.', 409);
                }
                throw $error;
            }
        }
    }

    private function saveLines(TransactionContext $tx, Actor $actor, array $header, array $draft): void
    {
        $binds = ['company_id' => $actor->companyId, 'document_id' => $header['RECORD#']];
        $retained = array_filter(array_column($draft['lines'], 'id'), static fn ($id): bool => $id !== null);
        $max = 0;
        foreach ($draft['existing'] as $line) {
            $max = max($max, (int) $line['LINE_NUMBER']);
        }
        $offset = $max + count($draft['lines']) + 1;
        if ($draft['existing'] !== [] && $max + $offset > 9999999999) {
            throw new AppError('VALIDATION_ERROR', 'Invoice line position capacity exhausted.');
        }
        // Move old positions into a disjoint positive range; preserve stable line IDs during reordering.
        if ($draft['existing'] !== []) {
            $tx->execute('UPDATE ME_OE_DOCUMENT_LINES SET LINE_NUMBER = LINE_NUMBER + :position_offset
                WHERE CNY# = :company_id AND DOCUMENT_RECORD# = :document_id', [...$binds, 'position_offset' => $offset]);
        }
        foreach ($draft['existing'] as $line) {
            if (!in_array($line['LINE_ID'], $retained, true)) {
                $tx->execute('DELETE FROM ME_OE_DOCUMENT_LINES WHERE CNY# = :company_id AND DOCUMENT_RECORD# = :document_id AND RECORD# = :line_id',
                    [...$binds, 'line_id' => $line['LINE_ID']]);
            }
        }
        foreach ($draft['lines'] as $index => $line) {
            $values = [...$binds, 'position' => $index + 1, 'product_id' => $line['product_id'],
                'description' => $line['description'], 'quantity' => (int) $line['quantity'],
                'unit_price' => $line['unit_price'], 'source_id' => $line['source_id']];
            $price = "TO_NUMBER(:unit_price, '9999999990D99', 'NLS_NUMERIC_CHARACTERS=''.,''')";
            if ($line['id'] === null) {
                $tx->execute("INSERT INTO ME_OE_DOCUMENT_LINES
                    (CNY#, DOCUMENT_RECORD#, LINE_NUMBER, PRODUCT_ID, DESCRIPTION, QUANTITY, UNIT_PRICE, SOURCE_LINE_RECORD#)
                    VALUES (:company_id, :document_id, :position, :product_id, :description, :quantity, $price, :source_id)", $values);
            } else {
                $this->changed($tx->execute("UPDATE ME_OE_DOCUMENT_LINES SET LINE_NUMBER = :position, PRODUCT_ID = :product_id,
                    DESCRIPTION = :description, QUANTITY = :quantity, UNIT_PRICE = $price, SOURCE_LINE_RECORD# = :source_id,
                    WHENMODIFIED = CURRENT_TIMESTAMP WHERE CNY# = :company_id AND DOCUMENT_RECORD# = :document_id AND RECORD# = :line_id",
                    [...$values, 'line_id' => $line['id']]));
            }
        }
    }

    private function balance(TransactionContext $tx, Actor $actor, array $header, int $amount, bool $increase): void
    {
        $customer = $this->store->customer($tx, $actor, $header['CUSTOMER_RECORD#'], false, true);
        $current = InvoiceMoney::cents($customer['BALANCE'], 'Customer balance');
        if (!$increase && $current < $amount) {
            throw new AppError('INVALID_INVOICE_DATA', 'Customer balance is inconsistent with this Invoice.', 503);
        }
        $balance = $increase ? InvoiceMoney::add($current, $amount) : $current - $amount;
        $this->changed($tx->execute("UPDATE ME_OE_CUSTOMERS SET AMOUNT_CUSTOMER_OWES_US =
            TO_NUMBER(:balance, '9999999999999990D99', 'NLS_NUMERIC_CHARACTERS=''.,'''), WHENMODIFIED = CURRENT_TIMESTAMP
            WHERE CNY# = :company_id AND RECORD# = :customer_id",
            ['balance' => InvoiceMoney::format($balance), 'company_id' => $actor->companyId, 'customer_id' => $header['CUSTOMER_RECORD#']]));
    }

    private function snapshot(TransactionContext $tx, Actor $actor, array $header): array
    {
        $result = $this->store->snapshot($tx, $actor, $header);
        $downstream = $this->store->downstream($tx, $actor, $header['RECORD#']);
        $hasPayments = (int) $this->store->payments($tx, $actor, $header['RECORD#'])['PAYMENT_COUNT'] > 0;
        $candidates = $header['STATUS'] === 'DRAFT'
            ? ($downstream || $hasPayments ? [] : ['edit', 'submit', 'delete'])
            : ($downstream || $hasPayments ? [] : ['return_to_draft']);
        if ($result['document_total'] === '0.00') {
            $candidates = array_values(array_diff($candidates, ['submit']));
        }
        if ($header['STATUS'] === 'SUBMITTED' && $result['amount_due'] !== '0.00') {
            $candidates[] = 'record_payment';
        }
        foreach ($candidates as $action) {
            try {
                $this->permissions->requireDocument($actor, 'invoice', $action);
                $result['available_actions'][] = $action === 'return_to_draft' ? 'move_to_draft' : $action;
            } catch (AppError $error) {
                if ($error->status !== 403) {
                    throw $error;
                }
            }
        }
        return $result;
    }
}
