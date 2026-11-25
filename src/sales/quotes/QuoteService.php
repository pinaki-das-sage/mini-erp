<?php
declare(strict_types=1);

namespace MiniErp\sales\quotes;

use MiniErp\inventory\QuoteInventoryValidator;
use MiniErp\sales\DocumentRules;
use MiniErp\sales\SalesPermissions;
use MiniErp\sales\Validation;

use MiniErp\shared\Api;
use MiniErp\shared\AppError;
use MiniErp\shared\database\Connection;
use MiniErp\shared\database\TransactionContext;
use MiniErp\shared\database\TransactionManager;
use MiniErp\shared\security\Actor;
use MiniErp\shared\security\Authorization;

final readonly class QuoteService
{
    private const FIELDS = ['customer_code', 'warehouse_id', 'quote_date', 'valid_until', 'requested_delivery_date', 'shipping_address', 'payment_terms', 'notes'];
    private QuoteInventory $inventory;
    private \Closure $businessDate;

    public function __construct(private Connection $connection, ?QuoteInventory $inventory = null, ?callable $businessDate = null)
    {
        $this->inventory = $inventory ?? new QuoteInventoryValidator();
        $this->businessDate = $businessDate === null
            ? static fn (): string => date('Y-m-d') : \Closure::fromCallable($businessDate);
    }

    public function create(Actor $actor, array $input): array
    {
        $input = $this->normalizeLines($input);
        return $this->write($actor, 'create', null, $input, function (TransactionContext $tx) use ($actor, $input): array {
            $this->requestFields($input, [...self::FIELDS, 'lines', 'action_reference']);
            [$header, $lines] = $this->validate($tx, $actor, $input);
            $sequence = $tx->query('SELECT ME_OE_QUOTE_NO_SEQ.NEXTVAL AS NEXT_NUMBER FROM DUAL')[0]['NEXT_NUMBER'];
            $number = 'SQ-' . str_pad((string) $sequence, 15, '0', STR_PAD_LEFT);
            $tx->execute("INSERT INTO ME_OE_DOCUMENTS (CNY#, DOCUMENT_TYPE, DOCUMENT_NUMBER, CUSTOMER_RECORD#, WAREHOUSE_ID, DOCUMENT_DATE, VALID_UNTIL, REQUESTED_DELIVERY_DATE, SHIPPING_ADDRESS, PAYMENT_TERMS, NOTES)
                VALUES (:company_id, 'SALES_QUOTE', :document_number, :customer_record, :warehouse_id, TO_DATE(:quote_date, 'YYYY-MM-DD'), TO_DATE(:valid_until, 'YYYY-MM-DD'), TO_DATE(:requested_delivery_date, 'YYYY-MM-DD'), :shipping_address, :payment_terms, :notes)",
                ['company_id' => $actor->companyId, 'document_number' => $number, ...$header]);
            $row = $this->row($tx, $actor, $number);
            $this->saveLines($tx, $actor, $row, $lines);
            return $this->present($tx, $actor, $row);
        });
    }

    public function get(Actor $actor, string $number): array
    {
        $this->permissions()->requireDocument($actor, 'quote', 'read');
        // A parent lock keeps header and lines from different revisions out of a response.
        return (new TransactionManager($this->connection))->run(fn (TransactionContext $tx): array =>
            $this->present($tx, $actor, $this->row($tx, $actor, $number)));
    }

    public function list(Actor $actor, array $query = []): array
    {
        $this->permissions()->requireDocument($actor, 'quote', 'read');
        $page = Api::pagination($query, ['customer_code', 'warehouse_id', 'status', 'from_date', 'to_date']);
        $where = "D.CNY# = :company_id AND D.DOCUMENT_TYPE = 'SALES_QUOTE'";
        $binds = ['company_id' => $actor->companyId, 'offset_rows' => $page['offset'], 'limit_rows' => $page['page_size']];
        foreach ($page['filters'] as $key => $value) {
            if (in_array($key, ['from_date', 'to_date'], true)) {
                Validation::date($value, $key);
                $where .= ' AND D.DOCUMENT_DATE ' . ($key === 'from_date' ? '>=' : '<=') . " TO_DATE(:$key, 'YYYY-MM-DD')";
            } elseif ($key === 'status') {
                if (!in_array($value, ['Draft', 'Submitted'], true)) {
                    throw new AppError('VALIDATION_ERROR', 'Select a valid Quote status.');
                }
                $where .= ' AND D.STATUS = :status';
                $value = strtoupper($value);
            } else {
                $value = Validation::text($value, $key, 20);
                $where .= ' AND ' . ($key === 'customer_code' ? 'C.CUSTOMER_CODE' : 'D.WAREHOUSE_ID') . " = :$key";
            }
            $binds[$key] = $value;
        }
        if (isset($binds['from_date'], $binds['to_date'])) {
            Validation::dateRange($binds['to_date'], $binds['from_date'], 'To Date', 'From Date');
        }
        return (new TransactionManager($this->connection))->run(function (TransactionContext $tx) use ($actor, $page, $where, $binds): array {
            $rows = $tx->query("SELECT D.DOCUMENT_NUMBER FROM ME_OE_DOCUMENTS D JOIN ME_OE_CUSTOMERS C ON C.CNY# = D.CNY# AND C.RECORD# = D.CUSTOMER_RECORD#
                WHERE $where ORDER BY D.DOCUMENT_DATE, D.DOCUMENT_NUMBER OFFSET :offset_rows ROWS FETCH NEXT :limit_rows ROWS ONLY", $binds);
            $items = [];
            foreach ($rows as $row) {
                // Recheck under the lock in case a document was deleted after the list query.
                try {
                    $item = $this->present($tx, $actor, $this->row($tx, $actor, $row['DOCUMENT_NUMBER']));
                } catch (AppError $error) {
                    if ($error->errorCode === 'NOT_FOUND') { continue; }
                    throw $error;
                }
                unset($item['lines']);
                $items[] = $item;
            }
            return ['items' => $items, 'page' => $page['page'], 'page_size' => $page['page_size']];
        });
    }

    public function update(Actor $actor, string $number, array $input): array
    {
        $input = $this->normalizeLines($input);
        return $this->write($actor, 'edit', $number, $input, function (TransactionContext $tx) use ($actor, $number, $input): array {
            $this->requestFields($input, [...self::FIELDS, 'lines', 'action_reference', 'expected_version']);
            $row = $this->row($tx, $actor, $number);
            $this->guard($row, $input, 'edit');
            $saved = $this->present($tx, $actor, $row);
            $replaceLines = array_key_exists('lines', $input);
            $savedLines = array_map(static fn (array $line): array => array_intersect_key($line,
                array_flip(['line_id', 'product_id', 'description', 'quantity', 'unit_price'])), $saved['lines']);
            // Omitted lines retain the saved collection; explicit null/empty values still fail validation.
            [$header, $lines] = $this->validate($tx, $actor, [
                ...array_intersect_key($saved, array_flip(self::FIELDS)), 'lines' => $savedLines, ...$input,
            ], $saved['lines']);
            $tx->execute("UPDATE ME_OE_DOCUMENTS SET CUSTOMER_RECORD# = :customer_record, WAREHOUSE_ID = :warehouse_id, DOCUMENT_DATE = TO_DATE(:quote_date, 'YYYY-MM-DD'),
                VALID_UNTIL = TO_DATE(:valid_until, 'YYYY-MM-DD'), REQUESTED_DELIVERY_DATE = TO_DATE(:requested_delivery_date, 'YYYY-MM-DD'), SHIPPING_ADDRESS = :shipping_address,
                PAYMENT_TERMS = :payment_terms, NOTES = :notes, VERSION = VERSION + 1, WHENMODIFIED = CURRENT_TIMESTAMP
                WHERE CNY# = :company_id AND RECORD# = :id", ['company_id' => $actor->companyId, 'id' => $row['RECORD#'], ...$header]);
            if ($replaceLines) { $this->saveLines($tx, $actor, $row, $lines); }
            return $this->present($tx, $actor, $this->row($tx, $actor, $number));
        });
    }

    public function action(Actor $actor, string $number, string $action, array $input): array
    {
        if (!in_array($action, ['submit', 'return_to_draft', 'delete'], true)) {
            throw new AppError('VALIDATION_ERROR', 'Select a valid Quote action.');
        }
        return $this->write($actor, $action, $number, $input, function (TransactionContext $tx) use ($actor, $number, $action, $input): array {
            Validation::allowedFields($input, ['action_reference', 'expected_version']);
            $row = $this->row($tx, $actor, $number);
            $this->guard($row, $input, $action, $this->used($tx, $actor, $row));
            $binds = ['company_id' => $actor->companyId, 'id' => $row['RECORD#']];
            if ($action === 'delete') {
                $tx->execute('DELETE FROM ME_OE_DOCUMENT_LINES WHERE CNY# = :company_id AND DOCUMENT_RECORD# = :id', $binds);
                $tx->execute("DELETE FROM ME_OE_DOCUMENTS WHERE CNY# = :company_id AND RECORD# = :id AND DOCUMENT_TYPE = 'SALES_QUOTE'", $binds);
                return ['quote_number' => $number, 'deleted' => true, 'current_version' => null];
            }
            if ($action === 'submit') {
                $saved = $this->present($tx, $actor, $row);
                [$header, $lines] = $this->validate($tx, $actor, [...array_intersect_key($saved, array_flip(self::FIELDS)),
                    'lines' => array_map(static fn (array $line): array => array_intersect_key($line, array_flip(['line_id', 'product_id', 'description', 'quantity', 'unit_price'])), $saved['lines'])], $saved['lines']);
                $this->inventory->validate($tx, $actor, $header['warehouse_id'], array_values(array_unique(array_column($lines, 'product_id'))));
            }
            $tx->execute('UPDATE ME_OE_DOCUMENTS SET STATUS = :status, VERSION = VERSION + 1, WHENMODIFIED = CURRENT_TIMESTAMP WHERE CNY# = :company_id AND RECORD# = :id',
                [...$binds, 'status' => $action === 'submit' ? 'SUBMITTED' : 'DRAFT']);
            return $this->present($tx, $actor, $this->row($tx, $actor, $number));
        });
    }

    private function validate(TransactionContext $tx, Actor $actor, array $input, array $existing = []): array
    {
        $code = Validation::text($input['customer_code'] ?? '', 'Customer Code', 20);
        if (!preg_match('/\A[A-Za-z0-9-]+\z/D', $code)) { throw new AppError('VALIDATION_ERROR', 'Enter a valid Customer Code.'); }
        $customers = $tx->query('SELECT * FROM ME_OE_CUSTOMERS WHERE CNY# = :company_id AND CUSTOMER_CODE = :customer_code FOR UPDATE', ['company_id' => $actor->companyId, 'customer_code' => $code]);
        if ($customers === []) { throw new AppError('VALIDATION_ERROR', 'Select a valid Customer available to your company.'); }
        if ($customers[0]['IS_ACTIVE'] !== 'T') { throw new AppError('VALIDATION_ERROR', 'Selected Customer is inactive. Select an active record.'); }
        $customer = $customers[0];
        $address = implode(', ', array_filter(array_map(static fn (string $key): string => (string) ($customer[$key] ?? ''),
            ['BILLING_ADDRESS_LINE_1', 'BILLING_ADDRESS_LINE_2', 'CITY', 'STATE_PROVINCE', 'POSTAL_CODE', 'COUNTRY']), static fn (string $part): bool => $part !== ''));
        $header = ['customer_record' => $customer['RECORD#'],
            'warehouse_id' => Validation::text($input['warehouse_id'] ?? '', 'Warehouse ID', 20),
            'quote_date' => Validation::date(array_key_exists('quote_date', $input) ? $input['quote_date'] : ($this->businessDate)(), 'Quote Date'),
            'shipping_address' => Validation::text(array_key_exists('shipping_address', $input) ? $input['shipping_address'] : $address, 'Shipping Address', 500),
            'payment_terms' => $input['payment_terms'] ?? $customer['PAYMENT_TERMS'],
            'notes' => Validation::text($input['notes'] ?? '', 'Notes', 500, false)];
        if ($header['warehouse_id'] !== 'W01') { throw new AppError('VALIDATION_ERROR', 'Select Warehouse W01.'); }
        if (array_key_exists('payment_terms', $input) && $input['payment_terms'] === null) { throw new AppError('VALIDATION_ERROR', 'Select valid Payment Terms.'); }
        if (array_key_exists('notes', $input) && $input['notes'] === null) { throw new AppError('VALIDATION_ERROR', 'Notes must be text.'); }
        if (!in_array($header['payment_terms'], ['DUE_ON_RECEIPT', 'NET_15', 'NET_30'], true)) { throw new AppError('VALIDATION_ERROR', 'Select valid Payment Terms.'); }
        foreach (['valid_until' => 'Valid Until', 'requested_delivery_date' => 'Requested Delivery Date'] as $key => $label) {
            $header[$key] = ($input[$key] ?? null) === null ? null : Validation::date($input[$key], $label);
            if ($header[$key] !== null) { Validation::dateRange($header[$key], $header['quote_date'], $label, 'Quote Date'); }
        }
        if (!isset($input['lines']) || !is_array($input['lines']) || !array_is_list($input['lines']) || $input['lines'] === []) { throw new AppError('VALIDATION_ERROR', 'Add at least one item line.'); }
        $known = array_column($existing, null, 'line_id');
        $seen = [];
        $lines = [];
        $total = '0';
        foreach ($input['lines'] as $index => $line) {
            try {
                if (!is_array($line) || array_is_list($line)) { throw new AppError('VALIDATION_ERROR', 'Each Quote line must be an object.'); }
                if (array_key_exists('line_amount', $line)) { throw new AppError('VALIDATION_ERROR', 'Line Amount is calculated and cannot be edited.'); }
                if (array_key_exists('warehouse_id', $line)) { throw new AppError('VALIDATION_ERROR', 'Warehouse is inherited from the document and cannot be edited on a line.'); }
                Validation::allowedFields($line, ['line_id', 'product_id', 'description', 'quantity', 'unit_price']);
                if (array_key_exists('line_id', $line)) {
                    $id = $line['line_id'];
                    if (!is_string($id) || !isset($known[$id])) { throw new AppError('VALIDATION_ERROR', 'Select a valid line belonging to this document.'); }
                    if (isset($seen[$id])) { throw new AppError('VALIDATION_ERROR', 'Duplicate line identifier.'); }
                    $seen[$id] = true;
                }
                // Inventory PRODUCT_ID is NUMBER(10); accept an exact decimal-string identifier.
                $line['product_id'] = Validation::text($line['product_id'] ?? '', 'Product ID', 10);
                if (!preg_match('/\A[1-9][0-9]{0,9}\z/D', $line['product_id'])) { throw new AppError('VALIDATION_ERROR', 'Enter a valid Inventory Product ID.'); }
                $line['description'] = Validation::text($line['description'] ?? '', 'Description', 200);
                if (!is_string($line['quantity'] ?? null) || !preg_match('/\A(?=[0-9]*[1-9])[0-9]{1,9}\z/D', $line['quantity'])) { throw new AppError('VALIDATION_ERROR', 'Enter a whole-number Quantity greater than 0, with up to 9 digits.'); }
                if (!is_string($line['unit_price'] ?? null) || !preg_match('/\A[0-9]{1,10}(?:\.[0-9]{1,2})?\z/D', $line['unit_price'])) { throw new AppError('VALIDATION_ERROR', 'Enter Unit Price of 0 or more, with up to 10 whole digits and 2 decimal places.'); }
                $line['unit_price'] = QuoteMoney::format(QuoteMoney::multiply('1', $line['unit_price']));
                $amount = QuoteMoney::multiply($line['quantity'], $line['unit_price']);
                $total = QuoteMoney::add($total, $amount);
                $lines[] = $line;
            } catch (AppError $error) {
                throw new AppError($error->errorCode, 'Line ' . ($index + 1) . ': ' . $error->getMessage(), $error->status);
            }
        }
        return [$header, $lines];
    }

    private function row(TransactionContext $tx, Actor $actor, string $number): array
    {
        if (!preg_match('/\A[A-Za-z0-9_-]{1,20}\z/D', $number)) { throw new AppError('NOT_FOUND', 'Quote not found.', 404); }
        $rows = $tx->query("SELECT D.*, TO_CHAR(DOCUMENT_DATE, 'YYYY-MM-DD') AS QUOTE_DATE, TO_CHAR(VALID_UNTIL, 'YYYY-MM-DD') AS VALID_UNTIL_TEXT,
            TO_CHAR(REQUESTED_DELIVERY_DATE, 'YYYY-MM-DD') AS DELIVERY_DATE_TEXT FROM ME_OE_DOCUMENTS D
            WHERE CNY# = :company_id AND DOCUMENT_TYPE = 'SALES_QUOTE' AND DOCUMENT_NUMBER = :document_number FOR UPDATE", ['company_id' => $actor->companyId, 'document_number' => $number]);
        if ($rows === []) { throw new AppError('NOT_FOUND', 'Quote not found.', 404); }
        return $rows[0];
    }

    private function used(TransactionContext $tx, Actor $actor, array $row): bool
    {
        // Document links are authoritative even before downstream lines are copied.
        // Order services must hold this same Quote parent lock while claiming/releasing it.
        return $tx->query('SELECT RECORD# FROM ME_OE_SOURCE_LINKS WHERE CNY# = :company_id AND SOURCE_DOCUMENT_RECORD# = :id FETCH FIRST 1 ROWS ONLY',
            ['company_id' => $actor->companyId, 'id' => $row['RECORD#']]) !== [];
    }

    private function present(TransactionContext $tx, Actor $actor, array $row): array
    {
        $customer = $tx->query('SELECT CUSTOMER_CODE, CUSTOMER_NAME FROM ME_OE_CUSTOMERS WHERE CNY# = :company_id AND RECORD# = :id', ['company_id' => $actor->companyId, 'id' => $row['CUSTOMER_RECORD#']])[0];
        $rows = $tx->query("SELECT L.*, TO_CHAR(QUANTITY, 'FM999999999') AS QUANTITY_TEXT,
            TO_CHAR(UNIT_PRICE, 'FM9999999990D00', 'NLS_NUMERIC_CHARACTERS=''.,''') AS PRICE_TEXT
            FROM ME_OE_DOCUMENT_LINES L WHERE CNY# = :company_id AND DOCUMENT_RECORD# = :id ORDER BY LINE_NUMBER", ['company_id' => $actor->companyId, 'id' => $row['RECORD#']]);
        $total = '0';
        $lines = [];
        foreach ($rows as $line) {
            $amount = QuoteMoney::multiply($line['QUANTITY_TEXT'], $line['PRICE_TEXT']);
            $total = QuoteMoney::add($total, $amount);
            $lines[] = ['line_id' => (string) $line['RECORD#'], 'line_number' => (int) $line['LINE_NUMBER'], 'warehouse_id' => $row['WAREHOUSE_ID'],
                'product_id' => (string) $line['PRODUCT_ID'], 'description' => $line['DESCRIPTION'], 'quantity' => $line['QUANTITY_TEXT'],
                'unit_price' => $line['PRICE_TEXT'], 'line_amount' => QuoteMoney::format($amount)];
        }
        $used = $this->used($tx, $actor, $row);
        return ['quote_number' => $row['DOCUMENT_NUMBER'], 'customer_code' => $customer['CUSTOMER_CODE'], 'customer_name' => $customer['CUSTOMER_NAME'], 'warehouse_id' => $row['WAREHOUSE_ID'],
            'quote_date' => $row['QUOTE_DATE'], 'valid_until' => $row['VALID_UNTIL_TEXT'], 'requested_delivery_date' => $row['DELIVERY_DATE_TEXT'],
            'shipping_address' => $row['SHIPPING_ADDRESS'], 'payment_terms' => $row['PAYMENT_TERMS'], 'notes' => $row['NOTES'] ?? '',
            'status' => $row['STATUS'] === 'DRAFT' ? 'Draft' : 'Submitted', 'current_version' => (int) $row['VERSION'],
            'document_total' => QuoteMoney::format($total), 'lines' => $lines,
            'available_actions' => $this->availableActions($actor, $row['STATUS'] === 'DRAFT' ? ['edit', 'submit', ...($used ? [] : ['delete'])] : ($used ? [] : ['move_to_draft']))];
    }

    private function saveLines(TransactionContext $tx, Actor $actor, array $row, array $lines): void
    {
        $binds = ['company_id' => $actor->companyId, 'id' => $row['RECORD#']];
        $old = $tx->query('SELECT RECORD# FROM ME_OE_DOCUMENT_LINES WHERE CNY# = :company_id AND DOCUMENT_RECORD# = :id', $binds);
        $retained = array_column($lines, 'line_id');
        foreach ($old as $line) {
            if (!in_array((string) $line['RECORD#'], $retained, true)) {
                $tx->execute('DELETE FROM ME_OE_DOCUMENT_LINES WHERE CNY# = :company_id AND DOCUMENT_RECORD# = :id AND RECORD# = :line_id', [...$binds, 'line_id' => $line['RECORD#']]);
            }
        }
        // Stage saved positions above the occupied range to allow arbitrary reordering.
        $tx->execute('UPDATE ME_OE_DOCUMENT_LINES SET LINE_NUMBER = LINE_NUMBER + :shift WHERE CNY# = :company_id AND DOCUMENT_RECORD# = :id', [...$binds, 'shift' => count($old) + count($lines)]);
        foreach ($lines as $index => $line) {
            $values = [...$binds, 'line_number' => $index + 1, 'product_id' => $line['product_id'], 'description' => $line['description'], 'quantity' => $line['quantity'], 'unit_price' => $line['unit_price']];
            $price = "TO_NUMBER(:unit_price, '9999999999D99', 'NLS_NUMERIC_CHARACTERS=''.,''')";
            if (isset($line['line_id'])) {
                $tx->execute("UPDATE ME_OE_DOCUMENT_LINES SET LINE_NUMBER = :line_number, PRODUCT_ID = TO_NUMBER(:product_id), DESCRIPTION = :description, QUANTITY = TO_NUMBER(:quantity), UNIT_PRICE = $price, WHENMODIFIED = CURRENT_TIMESTAMP
                    WHERE CNY# = :company_id AND DOCUMENT_RECORD# = :id AND RECORD# = :line_id", [...$values, 'line_id' => $line['line_id']]);
            } else {
                $tx->execute("INSERT INTO ME_OE_DOCUMENT_LINES (CNY#, DOCUMENT_RECORD#, LINE_NUMBER, PRODUCT_ID, DESCRIPTION, QUANTITY, UNIT_PRICE)
                    VALUES (:company_id, :id, :line_number, TO_NUMBER(:product_id), :description, TO_NUMBER(:quantity), $price)", $values);
            }
        }
    }

    private function guard(array $row, array $input, string $action, bool $used = false): void
    {
        DocumentRules::requireVersion((int) $row['VERSION'], Api::integer($input, 'expected_version'));
        DocumentRules::requireAction('quote', $action, $row['STATUS'] === 'DRAFT' ? 'Draft' : 'Submitted', $used);
    }

    private function permissions(): SalesPermissions { return new SalesPermissions(new Authorization($this->connection)); }

    private function requestFields(array $input, array $allowed): void
    {
        if (array_key_exists('document_total', $input)) {
            throw new AppError('VALIDATION_ERROR', 'Document Total is calculated and cannot be edited.');
        }
        if (array_key_exists('status', $input)) {
            throw new AppError('VALIDATION_ERROR', 'Use the available action to change Document Status.');
        }
        Validation::allowedFields($input, $allowed);
    }

    private function availableActions(Actor $actor, array $actions): array
    {
        return array_values(array_filter($actions, function (string $action) use ($actor): bool {
            try {
                $this->permissions()->requireDocument($actor, 'quote', $action === 'move_to_draft' ? 'return_to_draft' : $action);
                return true;
            } catch (AppError $error) {
                if ($error->errorCode !== 'FORBIDDEN') { throw $error; }
                return false;
            }
        }));
    }

    /** The HTTP decoder preserves nested JSON objects as stdClass instances.
     * Normalize line objects before both request fingerprinting and validation.
     * Keep invalid scalars and list containers intact so validation rejects them.
     */
    private function normalizeLines(array $input): array
    {
        if (isset($input['lines']) && is_array($input['lines']) && array_is_list($input['lines'])) {
            $input['lines'] = array_map(static fn (mixed $line): mixed => $line instanceof \stdClass ? get_object_vars($line) : $line, $input['lines']);
        }
        return $input;
    }

    private function write(Actor $actor, string $action, ?string $number, array $input, callable $operation): array
    {
        return (new QuoteActions(new TransactionManager($this->connection), $this->permissions()))->run($actor, $action, $number, $input, $operation);
    }
}
