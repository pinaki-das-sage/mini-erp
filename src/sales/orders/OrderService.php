<?php
declare(strict_types=1);

namespace MiniErp\sales\orders;

use MiniErp\sales\DocumentRules;
use MiniErp\sales\SalesPermissions;
use MiniErp\sales\Validation;
use MiniErp\sales\quotes\QuoteMoney;
use MiniErp\sales\invoices\OrderInvoiceService;
use MiniErp\shared\Api;
use MiniErp\shared\AppError;
use MiniErp\shared\database\Connection;
use MiniErp\shared\database\DatabaseError;
use MiniErp\shared\database\TransactionContext;
use MiniErp\shared\database\TransactionManager;
use MiniErp\shared\security\Actor;
use MiniErp\shared\security\Authorization;

/** Quote-backed Orders only. All source claims/releases lock Quote before Order before Customer.
 * Lifecycle providers must join these locks/transaction; missing providers never imply success.
 */
final readonly class OrderService
{
    private const FIELDS = ['source_quote_number', 'customer_code', 'warehouse_id', 'order_date',
        'requested_delivery_date', 'customer_po_number', 'shipping_address', 'payment_terms', 'notes'];
    private const STATUSES = ['DRAFT' => 'Draft', 'SUBMITTED' => 'Submitted', 'IN_PROGRESS' => 'In Progress', 'CLOSED' => 'Closed'];
    private \Closure $businessDate;
    private OrderInvoiceService $invoices;

    public function __construct(
        private Connection $connection,
        private ?OrderInventory $inventory = null,
        private ?OrderLogistics $logistics = null,
        ?callable $businessDate = null,
        ?OrderInvoiceService $invoices = null,
    ) {
        $this->businessDate = $businessDate === null ? static fn (): string => date('Y-m-d') : \Closure::fromCallable($businessDate);
        $this->invoices = $invoices ?? new OrderInvoiceService($connection);
    }

    public function create(Actor $actor, array $input): array
    {
        // Replays still require the caller's current source-read permission.
        $this->permissions()->requireDocument($actor, 'quote', 'read');
        return $this->write($actor, 'create', null, $input, function (TransactionContext $tx) use ($actor, $input): array {
            Validation::allowedFields($input, ['source_quote_number', 'order_date', 'requested_delivery_date',
                'customer_po_number', 'notes', 'action_reference']);
            $this->permissions()->requireDocument($actor, 'quote', 'read');
            $sourceNumber = $this->sourceNumber($input['source_quote_number'] ?? null);
            $source = $this->document($tx, $actor, $sourceNumber, 'SALES_QUOTE');
            $this->requireSubmittedSource($source);
            if ($this->used($tx, $actor, (string) $source['RECORD#'])) {
                throw new AppError('INVALID_STATE', 'Selected Quote is already used by another Order.', 409);
            }
            $sourceLines = $this->lines($tx, $actor, $source);
            $customer = $this->customer($tx, $actor, $source, true);
            $this->validateInheritedHeader($input, $source, $customer);
            // Source business values are read under the Quote lock, never supplied by the caller.
            $copiedLines = array_map(static fn (array $line): array => [
                'source_line_id' => (string) $line['RECORD#'], 'product_id' => (string) $line['PRODUCT_ID'],
                'description' => $line['DESCRIPTION'] ?? '', 'quantity' => $line['QUANTITY_TEXT'],
                'unit_price' => $line['PRICE_TEXT']], $sourceLines);
            $lines = $this->validateCopiedLines($copiedLines, $sourceLines);
            $header = $this->header($input, $source);
            try {
                $next = $tx->query('SELECT ME_OE_ORDER_NO_SEQ.NEXTVAL AS NEXT_NUMBER FROM DUAL')[0]['NEXT_NUMBER'];
            } catch (DatabaseError $error) {
                if (!in_array($error->oracleCode, [2289, 8004], true)) { throw $error; }
                throw new AppError('NUMBERING_UNAVAILABLE', 'Order numbering is unavailable.', 503);
            }
            if (!preg_match('/\A[1-9][0-9]{0,14}\z/D', (string) $next)) {
                throw new AppError('NUMBERING_UNAVAILABLE', 'Order numbering is unavailable.', 503);
            }
            $number = 'SO-' . str_pad((string) $next, 15, '0', STR_PAD_LEFT);
            $tx->execute("INSERT INTO ME_OE_DOCUMENTS (CNY#, DOCUMENT_TYPE, DOCUMENT_NUMBER, CUSTOMER_RECORD#, WAREHOUSE_ID,
                DOCUMENT_DATE, REQUESTED_DELIVERY_DATE, CUSTOMER_PO_NUMBER, SHIPPING_ADDRESS, PAYMENT_TERMS, NOTES)
                VALUES (:company_id, 'SALES_ORDER', :document_number, :customer_record, :warehouse_id,
                TO_DATE(:order_date, 'YYYY-MM-DD'), TO_DATE(:requested_delivery_date, 'YYYY-MM-DD'), :customer_po_number,
                :shipping_address, :payment_terms, :notes)",
                ['company_id' => $actor->companyId, 'document_number' => $number, 'customer_record' => $source['CUSTOMER_RECORD#'],
                    'warehouse_id' => $source['WAREHOUSE_ID'], 'shipping_address' => $source['SHIPPING_ADDRESS'],
                    'payment_terms' => $source['PAYMENT_TERMS'], ...$header]);
            $row = $this->document($tx, $actor, $number, 'SALES_ORDER');
            foreach ($lines as $index => $line) {
                $tx->execute("INSERT INTO ME_OE_DOCUMENT_LINES (CNY#, DOCUMENT_RECORD#, LINE_NUMBER, PRODUCT_ID,
                    DESCRIPTION, QUANTITY, UNIT_PRICE, SOURCE_LINE_RECORD#)
                    VALUES (:company_id, :id, :line_number, TO_NUMBER(:product_id), :description, TO_NUMBER(:quantity),
                    TO_NUMBER(:unit_price, '9999999999D99', 'NLS_NUMERIC_CHARACTERS=''.,'''), :source_line_id)",
                    ['company_id' => $actor->companyId, 'id' => $row['RECORD#'], 'line_number' => $index + 1, ...$line]);
            }
            $tx->execute('INSERT INTO ME_OE_SOURCE_LINKS (CNY#, SOURCE_DOCUMENT_RECORD#, TARGET_DOCUMENT_RECORD#)
                VALUES (:company_id, :source_id, :target_id)',
                ['company_id' => $actor->companyId, 'source_id' => $source['RECORD#'], 'target_id' => $row['RECORD#']]);
            return $this->present($tx, $actor, $row, $source);
        });
    }

    public function get(Actor $actor, string $number): array
    {
        $this->permissions()->requireDocument($actor, 'order', 'read');
        return (new TransactionManager($this->connection))->run(function (TransactionContext $tx) use ($actor, $number): array {
            [$row, $source] = $this->lockOrder($tx, $actor, $number);
            DocumentRules::requireAction('order', 'read', self::STATUSES[$row['STATUS']]);
            return $this->present($tx, $actor, $row, $source);
        });
    }

    public function list(Actor $actor, array $query = []): array
    {
        $this->permissions()->requireDocument($actor, 'order', 'read');
        return $this->listing($actor, $query, false);
    }

    public function eligibleQuotes(Actor $actor, array $query = []): array
    {
        $this->permissions()->requireDocument($actor, 'order', 'create');
        $this->permissions()->requireDocument($actor, 'quote', 'read');
        return $this->listing($actor, $query, true);
    }

    private function listing(Actor $actor, array $query, bool $sources): array
    {
        $page = Api::pagination($query, ['customer_code', 'warehouse_id', 'from_date', 'to_date', 'search', ...($sources ? [] : ['status'])]);
        $where = 'D.CNY# = :company_id AND D.DOCUMENT_TYPE = :document_type';
        $binds = ['company_id' => $actor->companyId, 'document_type' => $sources ? 'SALES_QUOTE' : 'SALES_ORDER',
            'offset_rows' => $page['offset'], 'limit_rows' => $page['page_size']];
        if ($sources) {
            $where .= " AND D.STATUS = :status AND NOT EXISTS (SELECT 1 FROM ME_OE_SOURCE_LINKS S WHERE S.CNY# = D.CNY# AND S.SOURCE_DOCUMENT_RECORD# = D.RECORD#)";
            $binds['status'] = 'SUBMITTED';
        } else {
            // This API lists Quote-backed Orders only. Filter legacy standalone/wrong-type sources before
            // pagination so one unsupported record cannot fail a whole page or displace valid results.
            $where .= " AND EXISTS (SELECT 1 FROM ME_OE_SOURCE_LINKS QL
                JOIN ME_OE_DOCUMENTS QS ON QS.CNY# = QL.CNY# AND QS.RECORD# = QL.SOURCE_DOCUMENT_RECORD#
                WHERE QL.CNY# = D.CNY# AND QL.TARGET_DOCUMENT_RECORD# = D.RECORD#
                AND QS.DOCUMENT_TYPE = 'SALES_QUOTE')";
        }
        foreach ($page['filters'] as $key => $value) {
            if ($key === 'search') {
                $search = Validation::text($value, 'Search', 100, false);
                if ($search !== '') {
                    $where .= ' AND (UPPER(D.DOCUMENT_NUMBER) LIKE :search OR UPPER(C.CUSTOMER_CODE) LIKE :search OR UPPER(C.CUSTOMER_NAME) LIKE :search)';
                    $binds['search'] = '%' . mb_strtoupper($search, 'UTF-8') . '%';
                }
                continue;
            } elseif (in_array($key, ['from_date', 'to_date'], true)) {
                Validation::date($value, $key);
                $where .= ' AND D.DOCUMENT_DATE ' . ($key === 'from_date' ? '>=' : '<=') . " TO_DATE(:$key, 'YYYY-MM-DD')";
            } elseif ($key === 'status') {
                $value = array_search($value, self::STATUSES, true);
                if ($value === false) { throw new AppError('VALIDATION_ERROR', 'Select a valid Order status.'); }
                $where .= ' AND D.STATUS = :status';
            } else {
                $value = Validation::text($value, $key, 20);
                $where .= ' AND ' . ($key === 'customer_code' ? 'C.CUSTOMER_CODE' : 'D.WAREHOUSE_ID') . " = :$key";
            }
            $binds[$key] = $value;
        }
        if (isset($binds['from_date'], $binds['to_date'])) { Validation::dateRange($binds['to_date'], $binds['from_date'], 'To Date', 'From Date'); }
        $rows = $this->connection->query("SELECT D.DOCUMENT_NUMBER FROM ME_OE_DOCUMENTS D
            JOIN ME_OE_CUSTOMERS C ON C.CNY# = D.CNY# AND C.RECORD# = D.CUSTOMER_RECORD# WHERE $where
            ORDER BY D.DOCUMENT_DATE, D.DOCUMENT_NUMBER OFFSET :offset_rows ROWS FETCH NEXT :limit_rows ROWS ONLY", $binds);
        $items = [];
        foreach ($rows as $row) {
            // Release each parent's locks before the next record; lists must not accumulate reversed lock orders.
            try {
                if ($sources) {
                    $item = (new TransactionManager($this->connection))->run(function (TransactionContext $tx) use ($actor, $row): ?array {
                        $source = $this->document($tx, $actor, $row['DOCUMENT_NUMBER'], 'SALES_QUOTE');
                        if ($source['STATUS'] !== 'SUBMITTED' || $this->used($tx, $actor, (string) $source['RECORD#'])) { return null; }
                        return $this->quoteSummary($tx, $actor, $source);
                    });
                } else { $item = $this->get($actor, $row['DOCUMENT_NUMBER']); }
            } catch (AppError $error) {
                if ($error->errorCode !== 'NOT_FOUND') { throw $error; }
                continue;
            }
            if ($item !== null) { unset($item['lines']); $items[] = $item; }
        }
        return ['items' => $items, 'page' => $page['page'], 'page_size' => $page['page_size']];
    }

    public function update(Actor $actor, string $number, array $input): array
    {
        $input = $this->normalizeLines($input);
        return $this->write($actor, 'edit', $number, $input, function (TransactionContext $tx) use ($actor, $number, $input): array {
            Validation::allowedFields($input, [...self::FIELDS, 'lines', 'action_reference', 'expected_version']);
            [$row, $source] = $this->lockOrder($tx, $actor, $number);
            $this->guard($row, $input, 'edit');
            $this->requireSubmittedSource($source);
            $customer = $this->customer($tx, $actor, $row, true);
            $this->validateInheritedHeader($input, $source, $customer);
            $existing = $this->lines($tx, $actor, $row);
            if ($this->used($tx, $actor, (string) $row['RECORD#']) || $this->assigned($existing)) {
                throw new AppError('INVALID_STATE', 'Referenced or Trip-assigned Orders cannot be edited.', 409);
            }
            $lines = $this->validateOrderLines(array_key_exists('lines', $input) ? $input['lines'] : $this->lineInputs($existing), $existing);
            $saved = ['order_date' => $row['DATE_TEXT'], 'requested_delivery_date' => $row['DELIVERY_DATE_TEXT'],
                'customer_po_number' => $row['CUSTOMER_PO_NUMBER'] ?? '', 'notes' => $row['NOTES'] ?? ''];
            $header = $this->header([...$saved, ...$input], $source);
            $tx->execute("UPDATE ME_OE_DOCUMENTS SET DOCUMENT_DATE = TO_DATE(:order_date, 'YYYY-MM-DD'),
                REQUESTED_DELIVERY_DATE = TO_DATE(:requested_delivery_date, 'YYYY-MM-DD'), CUSTOMER_PO_NUMBER = :customer_po_number,
                NOTES = :notes, VERSION = VERSION + 1, WHENMODIFIED = CURRENT_TIMESTAMP
                WHERE CNY# = :company_id AND RECORD# = :id AND DOCUMENT_TYPE = 'SALES_ORDER'",
                ['company_id' => $actor->companyId, 'id' => $row['RECORD#'], ...$header]);
            if (array_key_exists('lines', $input)) { $this->saveLines($tx, $actor, $row, $lines, $existing); }
            return $this->present($tx, $actor, $this->document($tx, $actor, $number, 'SALES_ORDER'), $source);
        });
    }

    public function action(Actor $actor, string $number, string $action, array $input): array
    {
        if (!in_array($action, ['submit', 'return_to_draft', 'delete'], true)) { throw new AppError('VALIDATION_ERROR', 'Select a valid Order action.'); }
        if ($action === 'submit') {
            $this->permissions()->requireDocument($actor, 'quote', 'read');
            $this->permissions()->requireDocument($actor, 'invoice', 'create');
        }
        return $this->write($actor, $action, $number, $input, function (TransactionContext $tx) use ($actor, $number, $action, $input): array {
            Validation::allowedFields($input, ['action_reference', 'expected_version']);
            [$row, $source] = $this->lockOrder($tx, $actor, $number);
            $used = $this->used($tx, $actor, (string) $row['RECORD#']);
            $this->guard($row, $input, $action, $action === 'return_to_draft' ? false : $used);
            $lines = $this->lines($tx, $actor, $row);
            if ($this->assigned($lines)) { throw new AppError('INVALID_STATE', 'This Order has a valid Logistics Trip assignment.', 409); }
            $binds = ['company_id' => $actor->companyId, 'id' => $row['RECORD#']];
            if ($action === 'delete') {
                // Drafts have never reserved stock. All future Logistics writers must acquire this parent lock.
                $tx->execute('DELETE FROM ME_OE_DOCUMENT_LINES WHERE CNY# = :company_id AND DOCUMENT_RECORD# = :id', $binds);
                $tx->execute('DELETE FROM ME_OE_SOURCE_LINKS WHERE CNY# = :company_id AND TARGET_DOCUMENT_RECORD# = :target_id',
                    ['company_id' => $actor->companyId, 'target_id' => $row['RECORD#']]);
                $tx->execute("DELETE FROM ME_OE_DOCUMENTS WHERE CNY# = :company_id AND RECORD# = :id AND DOCUMENT_TYPE = 'SALES_ORDER'", $binds);
                return ['order_number' => $number, 'deleted' => true, 'current_version' => null];
            }
            if ($action === 'submit') {
                $this->permissions()->requireDocument($actor, 'quote', 'read');
                $this->requireSubmittedSource($source);
                $customer = $this->customer($tx, $actor, $row, true);
                $saved = $this->present($tx, $actor, $row, $source);
                $this->validateInheritedHeader($saved, $source, $customer);
                $this->header(array_intersect_key($saved, array_flip(self::FIELDS)), $source);
                // Submit reserves the final saved Order, which may legitimately differ from its source Quote.
                $this->validateOrderLines($this->lineInputs($lines), $lines);
                if ($this->inventory === null) { throw new AppError('INVENTORY_UNAVAILABLE', 'Inventory reservation service is not connected.', 503); }
                $demand = array_map(static fn (array $line): array => ['line_id' => (string) $line['RECORD#'],
                    'product_id' => (string) $line['PRODUCT_ID'], 'quantity' => $line['QUANTITY_TEXT']], $lines);
                try { $this->inventory->reserve($tx, $actor, (string) $row['RECORD#'], $row['WAREHOUSE_ID'], $demand); }
                catch (DatabaseError) { throw new AppError('INVENTORY_UNAVAILABLE', 'Unable to reserve Inventory for this Order.', 503); }
            } else {
                if ($this->logistics === null) { throw new AppError('LOGISTICS_UNAVAILABLE', 'Logistics assignment service is not connected.', 503); }
                try { $this->logistics->assertUnassigned($tx, $actor, (string) $row['RECORD#']); }
                catch (DatabaseError) { throw new AppError('LOGISTICS_UNAVAILABLE', 'Unable to verify Logistics assignments.', 503); }
                try { $this->invoices->deleteDraftForOrderReturn($tx, $actor, (string) $row['RECORD#']); }
                catch (DatabaseError) { throw new AppError('INVOICE_UNAVAILABLE', 'Unable to remove the linked Draft Invoice.', 503); }
                if ($this->inventory === null) { throw new AppError('INVENTORY_UNAVAILABLE', 'Inventory release service is not connected.', 503); }
                try { $this->inventory->release($tx, $actor, (string) $row['RECORD#']); }
                catch (DatabaseError) { throw new AppError('INVENTORY_UNAVAILABLE', 'Unable to release Inventory reservations.', 503); }
            }
            $tx->execute("UPDATE ME_OE_DOCUMENTS SET STATUS = :status, VERSION = VERSION + 1, WHENMODIFIED = CURRENT_TIMESTAMP
                WHERE CNY# = :company_id AND RECORD# = :id AND DOCUMENT_TYPE = 'SALES_ORDER'",
                [...$binds, 'status' => $action === 'submit' ? 'SUBMITTED' : 'DRAFT']);
            $invoiceNumber = null;
            if ($action === 'submit') {
                try { $invoiceNumber = $this->invoices->createDraftFromSubmittedOrder($tx, $actor, (string) $row['RECORD#']); }
                catch (DatabaseError) { throw new AppError('INVOICE_UNAVAILABLE', 'Unable to create the linked Draft Invoice.', 503); }
            }
            $result = $this->present($tx, $actor, $this->document($tx, $actor, $number, 'SALES_ORDER'), $source);
            if ($invoiceNumber !== null) { $result['invoice_number'] = $invoiceNumber; }
            return $result;
        });
    }

    private function document(TransactionContext $tx, Actor $actor, string $number, string $type): array
    {
        if (!preg_match('/\A[A-Za-z0-9_-]{1,20}\z/D', $number)) { throw new AppError('NOT_FOUND', 'Document not found.', 404); }
        $rows = $tx->query("SELECT D.*, TO_CHAR(DOCUMENT_DATE, 'YYYY-MM-DD') AS DATE_TEXT,
            TO_CHAR(REQUESTED_DELIVERY_DATE, 'YYYY-MM-DD') AS DELIVERY_DATE_TEXT,
            TO_CHAR(VALID_UNTIL, 'YYYY-MM-DD') AS VALID_UNTIL_TEXT FROM ME_OE_DOCUMENTS D
            WHERE CNY# = :company_id AND DOCUMENT_TYPE = :document_type AND DOCUMENT_NUMBER = :document_number FOR UPDATE",
            ['company_id' => $actor->companyId, 'document_type' => $type, 'document_number' => $number]);
        if ($rows === []) { throw new AppError('NOT_FOUND', 'Document not found.', 404); }
        return $rows[0];
    }

    /** Discover immutable source without locks, then acquire Quote -> Order and recheck the link. */
    private function lockOrder(TransactionContext $tx, Actor $actor, string $number): array
    {
        $rows = $tx->query("SELECT RECORD# FROM ME_OE_DOCUMENTS WHERE CNY# = :company_id
            AND DOCUMENT_TYPE = :document_type AND DOCUMENT_NUMBER = :document_number",
            ['company_id' => $actor->companyId, 'document_type' => 'SALES_ORDER', 'document_number' => $number]);
        if ($rows === []) { throw new AppError('NOT_FOUND', 'Order not found.', 404); }
        $links = $this->sourceLink($tx, $actor, (string) $rows[0]['RECORD#']);
        if ($links === []) { throw new AppError('INVALID_STATE', 'Order must retain its Quote source.', 409); }
        $sources = $tx->query('SELECT DOCUMENT_NUMBER FROM ME_OE_DOCUMENTS WHERE CNY# = :company_id AND RECORD# = :id AND DOCUMENT_TYPE = :document_type',
            ['company_id' => $actor->companyId, 'id' => $links[0]['SOURCE_DOCUMENT_RECORD#'], 'document_type' => 'SALES_QUOTE']);
        if ($sources === []) { throw new AppError('INVALID_STATE', 'Order Quote source is invalid.', 409); }
        $source = $this->document($tx, $actor, $sources[0]['DOCUMENT_NUMBER'], 'SALES_QUOTE');
        $row = $this->document($tx, $actor, $number, 'SALES_ORDER');
        $current = $this->sourceLink($tx, $actor, (string) $row['RECORD#']);
        if ($current === [] || (string) $current[0]['SOURCE_DOCUMENT_RECORD#'] !== (string) $source['RECORD#']) {
            throw new AppError('INVALID_STATE', 'Order Quote source has changed.', 409);
        }
        return [$row, $source];
    }

    private function sourceLink(TransactionContext $tx, Actor $actor, string $id): array
    {
        return $tx->query('SELECT SOURCE_DOCUMENT_RECORD#, TARGET_DOCUMENT_RECORD# FROM ME_OE_SOURCE_LINKS WHERE CNY# = :company_id AND TARGET_DOCUMENT_RECORD# = :target_id',
            ['company_id' => $actor->companyId, 'target_id' => $id]);
    }

    private function used(TransactionContext $tx, Actor $actor, string $id): bool
    {
        return $tx->query('SELECT RECORD# FROM ME_OE_SOURCE_LINKS WHERE CNY# = :company_id AND SOURCE_DOCUMENT_RECORD# = :source_id FETCH FIRST 1 ROWS ONLY',
            ['company_id' => $actor->companyId, 'source_id' => $id]) !== [];
    }

    private function customer(TransactionContext $tx, Actor $actor, array $row, bool $active = false): array
    {
        $rows = $tx->query('SELECT RECORD#, CUSTOMER_CODE, CUSTOMER_NAME, IS_ACTIVE FROM ME_OE_CUSTOMERS
            WHERE CNY# = :company_id AND RECORD# = :id' . ($active ? ' FOR UPDATE' : ''),
            ['company_id' => $actor->companyId, 'id' => $row['CUSTOMER_RECORD#']]);
        if ($rows === [] || $active && $rows[0]['IS_ACTIVE'] !== 'T') { throw new AppError('VALIDATION_ERROR', 'Select an active Customer available to your company.'); }
        return $rows[0];
    }

    private function lines(TransactionContext $tx, Actor $actor, array $row): array
    {
        return $tx->query("SELECT L.*, TO_CHAR(QUANTITY, 'FM999999999') AS QUANTITY_TEXT,
            TO_CHAR(UNIT_PRICE, 'FM9999999990D00', 'NLS_NUMERIC_CHARACTERS=''.,''') AS PRICE_TEXT
            FROM ME_OE_DOCUMENT_LINES L WHERE CNY# = :company_id AND DOCUMENT_RECORD# = :id ORDER BY LINE_NUMBER",
            ['company_id' => $actor->companyId, 'id' => $row['RECORD#']]);
    }

    private function sourceNumber(mixed $number): string
    {
        if (!is_string($number) || !preg_match('/\A[A-Za-z0-9_-]{1,20}\z/D', $number)) {
            throw new AppError('VALIDATION_ERROR', 'Select a Submitted Quote; standalone Orders are not supported.');
        }
        return $number;
    }

    private function requireSubmittedSource(array $source): void
    {
        if ($source['STATUS'] !== 'SUBMITTED') { throw new AppError('INVALID_STATE', 'Select a Submitted Quote.', 409); }
    }

    private function validateInheritedHeader(array $input, array $source, array $customer): void
    {
        $expected = ['source_quote_number' => $source['DOCUMENT_NUMBER'], 'customer_code' => $customer['CUSTOMER_CODE'],
            'warehouse_id' => $source['WAREHOUSE_ID'], 'shipping_address' => $source['SHIPPING_ADDRESS'], 'payment_terms' => $source['PAYMENT_TERMS']];
        foreach ($expected as $field => $value) {
            if (array_key_exists($field, $input) && $input[$field] !== $value) { throw new AppError('VALIDATION_ERROR', 'Source-linked ' . $field . ' cannot be changed.'); }
        }
        if ($source['WAREHOUSE_ID'] !== 'W01') { throw new AppError('VALIDATION_ERROR', 'Source Quote must use Warehouse W01.'); }
    }

    /** Creation alone copies the complete authoritative Quote line set. Draft edits use Order validation. */
    private function validateCopiedLines(mixed $input, array $source): array
    {
        if (!is_array($input) || !array_is_list($input) || $input === [] || count($input) !== count($source)) {
            throw new AppError('VALIDATION_ERROR', 'Supply every source Quote line once, in source order.');
        }
        $result = []; $total = '0';
        foreach ($input as $index => $line) {
            try {
                if (!is_array($line) || array_is_list($line)) { throw new AppError('VALIDATION_ERROR', 'Each Order line must be an object.'); }
                Validation::allowedFields($line, ['source_line_id', 'product_id', 'description', 'quantity', 'unit_price']);
                $original = $source[$index];
                $expected = ['source_line_id' => (string) $original['RECORD#'], 'product_id' => (string) $original['PRODUCT_ID'],
                    'description' => $original['DESCRIPTION'] ?? '', 'quantity' => $original['QUANTITY_TEXT'], 'unit_price' => $original['PRICE_TEXT']];
                if (!is_string($line['quantity'] ?? null) || !preg_match('/\A(?=[0-9]*[1-9])[0-9]{1,9}\z/D', $line['quantity']) ||
                    !is_string($line['unit_price'] ?? null) || !preg_match('/\A[0-9]{1,10}(?:\.[0-9]{1,2})?\z/D', $line['unit_price'])) {
                    throw new AppError('VALIDATION_ERROR', 'Use positive whole-number quantities and prices with up to two decimals.');
                }
                $line['quantity'] = ltrim($line['quantity'], '0');
                $line['unit_price'] = QuoteMoney::format(QuoteMoney::multiply('1', $line['unit_price']));
                foreach ($expected as $field => $value) {
                    if (($line[$field] ?? null) !== $value) { throw new AppError('VALIDATION_ERROR', 'Inherited source line values cannot be changed.'); }
                }
                $total = QuoteMoney::add($total, QuoteMoney::multiply($expected['quantity'], $expected['unit_price']));
                $result[] = $expected;
            } catch (AppError $error) { throw new AppError($error->errorCode, 'Line ' . ($index + 1) . ': ' . $error->getMessage(), $error->status); }
        }
        return $result;
    }

    /** @return list<array<string, mixed>> */
    private function lineInputs(array $rows): array
    {
        return array_map(static fn (array $line): array => ['line_id' => (string) $line['RECORD#'],
            'source_line_id' => $line['SOURCE_LINE_RECORD#'] === null ? null : (string) $line['SOURCE_LINE_RECORD#'],
            'product_id' => (string) $line['PRODUCT_ID'], 'description' => $line['DESCRIPTION'] ?? '',
            'quantity' => $line['QUANTITY_TEXT'], 'unit_price' => $line['PRICE_TEXT']], $rows);
    }

    /** Complete replacement when supplied: retain IDs to edit, omit an ID to add, omit a saved row to remove. */
    private function validateOrderLines(mixed $input, array $existing): array
    {
        if (!is_array($input) || !array_is_list($input) || $input === []) {
            throw new AppError('VALIDATION_ERROR', 'Add at least one Order line.');
        }
        $known = array_column($existing, null, 'RECORD#'); $seen = []; $result = []; $total = '0';
        foreach ($input as $index => $line) {
            try {
                if (!is_array($line) || array_is_list($line)) { throw new AppError('VALIDATION_ERROR', 'Each Order line must be an object.'); }
                Validation::allowedFields($line, ['line_id', 'source_line_id', 'product_id', 'description', 'quantity', 'unit_price']);
                $old = null; $id = null;
                if (array_key_exists('line_id', $line)) {
                    $id = $line['line_id'];
                    if (!is_string($id) || !isset($known[$id]) || isset($seen[$id])) {
                        throw new AppError('VALIDATION_ERROR', 'Select a unique saved line belonging to this Order.');
                    }
                    $old = $known[$id]; $seen[$id] = true;
                }
                $origin = $old === null || $old['SOURCE_LINE_RECORD#'] === null ? null : (string) $old['SOURCE_LINE_RECORD#'];
                if (array_key_exists('source_line_id', $line) && $line['source_line_id'] !== $origin) {
                    throw new AppError('VALIDATION_ERROR', 'Source line references are maintained by the server.');
                }
                $product = $line['product_id'] ?? null;
                if (!is_string($product) || !preg_match('/\A[1-9][0-9]{0,9}\z/D', $product)) {
                    throw new AppError('VALIDATION_ERROR', 'Enter a valid numeric Inventory Product ID.');
                }
                if (!is_string($line['description'] ?? null)) { throw new AppError('VALIDATION_ERROR', 'Description must be text.'); }
                $description = Validation::text($line['description'], 'Description', 200, false);
                if (!is_string($line['quantity'] ?? null) || !preg_match('/\A(?=[0-9]*[1-9])[0-9]{1,9}\z/D', $line['quantity'])) {
                    throw new AppError('VALIDATION_ERROR', 'Enter a positive whole-number Quantity with up to 9 digits.');
                }
                if (!is_string($line['unit_price'] ?? null) || !preg_match('/\A[0-9]{1,10}(?:\.[0-9]{1,2})?\z/D', $line['unit_price'])) {
                    throw new AppError('VALIDATION_ERROR', 'Enter Unit Price of 0 or more, with up to 10 whole digits and 2 decimal places.');
                }
                $quantity = ltrim($line['quantity'], '0');
                $price = QuoteMoney::format(QuoteMoney::multiply('1', $line['unit_price']));
                $total = QuoteMoney::add($total, QuoteMoney::multiply($quantity, $price));
                // A replaced Product no longer represents the original Quote Product. Never invent lineage
                // for a new line; quantity/price/description edits keep the existing origin as provenance.
                if ($old !== null && $product !== (string) $old['PRODUCT_ID']) { $origin = null; }
                $result[] = [...($id === null ? [] : ['line_id' => $id]), 'source_line_id' => $origin,
                    'product_id' => $product, 'description' => $description, 'quantity' => $quantity, 'unit_price' => $price];
            } catch (AppError $error) { throw new AppError($error->errorCode, 'Line ' . ($index + 1) . ': ' . $error->getMessage(), $error->status); }
        }
        return $result;
    }

    private function saveLines(TransactionContext $tx, Actor $actor, array $row, array $lines, array $existing): void
    {
        $binds = ['company_id' => $actor->companyId, 'id' => $row['RECORD#']];
        $retained = array_column($lines, 'line_id');
        try {
            foreach ($existing as $line) {
                if (!in_array((string) $line['RECORD#'], $retained, true)) {
                    $tx->execute('DELETE FROM ME_OE_DOCUMENT_LINES WHERE CNY# = :company_id AND DOCUMENT_RECORD# = :id AND RECORD# = :line_id',
                        [...$binds, 'line_id' => $line['RECORD#']]);
                }
            }
            // Stage positions outside the actual saved range, preserving the unique position constraint on swaps.
            $max = $existing === [] ? 0 : max(array_map(static fn (array $line): int => (int) $line['LINE_NUMBER'], $existing));
            $shift = $max + count($lines) + 1;
            if ($max + $shift > 9999999999) { throw new AppError('INVALID_STATE', 'Order line position capacity exhausted.', 409); }
            $tx->execute('UPDATE ME_OE_DOCUMENT_LINES SET LINE_NUMBER = LINE_NUMBER + :shift WHERE CNY# = :company_id AND DOCUMENT_RECORD# = :id', [...$binds, 'shift' => $shift]);
            foreach ($lines as $index => $line) {
                $values = [...$binds, 'line_number' => $index + 1, ...$line];
                $price = "TO_NUMBER(:unit_price, '9999999999D99', 'NLS_NUMERIC_CHARACTERS=''.,''')";
                if (isset($line['line_id'])) {
                    $tx->execute("UPDATE ME_OE_DOCUMENT_LINES SET LINE_NUMBER = :line_number, PRODUCT_ID = TO_NUMBER(:product_id),
                        DESCRIPTION = :description, QUANTITY = TO_NUMBER(:quantity), UNIT_PRICE = $price,
                        SOURCE_LINE_RECORD# = :source_line_id, WHENMODIFIED = CURRENT_TIMESTAMP
                        WHERE CNY# = :company_id AND DOCUMENT_RECORD# = :id AND RECORD# = :line_id", $values);
                } else {
                    $tx->execute("INSERT INTO ME_OE_DOCUMENT_LINES (CNY#, DOCUMENT_RECORD#, LINE_NUMBER, PRODUCT_ID, DESCRIPTION,
                        QUANTITY, UNIT_PRICE, SOURCE_LINE_RECORD#) VALUES (:company_id, :id, :line_number, TO_NUMBER(:product_id),
                        :description, TO_NUMBER(:quantity), $price, :source_line_id)", $values);
                }
            }
        } catch (DatabaseError $error) {
            if ($error->oracleCode === 2291) { throw new AppError('VALIDATION_ERROR', 'Select existing Products available to your company.'); }
            if ($error->oracleCode === 2292) { throw new AppError('INVALID_STATE', 'An Order line is referenced by another document.', 409); }
            throw $error;
        }
    }

    private function header(array $input, array $source): array
    {
        $date = Validation::date(array_key_exists('order_date', $input) ? $input['order_date'] : ($this->businessDate)(), 'Order Date');
        Validation::dateRange($date, $source['DATE_TEXT'], 'Order Date', 'Quote Date');
        $deliverySupplied = array_key_exists('requested_delivery_date', $input);
        $delivery = $deliverySupplied ? $input['requested_delivery_date'] : $source['DELIVERY_DATE_TEXT'];
        if ($delivery !== null) {
            $delivery = Validation::date($delivery, 'Requested Delivery Date');
            // An old Quote's requested date is no longer a usable default for a newly dated Order.
            // Leave it unset rather than inventing a new delivery commitment or changing the Quote.
            if (!$deliverySupplied && strcmp($delivery, $date) < 0) { $delivery = null; }
            else { Validation::dateRange($delivery, $date, 'Requested Delivery Date', 'Order Date'); }
        }
        foreach (['notes', 'customer_po_number'] as $key) {
            if (array_key_exists($key, $input) && !is_string($input[$key])) { throw new AppError('VALIDATION_ERROR', $key . ' must be text.'); }
        }
        return ['order_date' => $date, 'requested_delivery_date' => $delivery,
            'customer_po_number' => Validation::text($input['customer_po_number'] ?? '', 'Customer PO Number', 30, false),
            'notes' => Validation::text($input['notes'] ?? '', 'Notes', 500, false)];
    }

    private function assigned(array $lines): bool
    {
        foreach ($lines as $line) { if (SalesTripAssignmentGuard::hasTrip($line)) { return true; } }
        return false;
    }

    private function present(TransactionContext $tx, Actor $actor, array $row, array $source): array
    {
        $customer = $this->customer($tx, $actor, $row);
        $rows = $this->lines($tx, $actor, $row); $total = '0'; $lines = [];
        foreach ($rows as $line) {
            $amount = QuoteMoney::multiply($line['QUANTITY_TEXT'], $line['PRICE_TEXT']); $total = QuoteMoney::add($total, $amount);
            $assigned = $this->assigned([$line]);
            $lines[] = ['line_id' => (string) $line['RECORD#'], 'line_number' => (int) $line['LINE_NUMBER'], 'warehouse_id' => $row['WAREHOUSE_ID'],
                'source_line_id' => $line['SOURCE_LINE_RECORD#'] === null ? null : (string) $line['SOURCE_LINE_RECORD#'], 'product_id' => (string) $line['PRODUCT_ID'],
                'description' => $line['DESCRIPTION'] ?? '', 'quantity' => $line['QUANTITY_TEXT'], 'unit_price' => $line['PRICE_TEXT'],
                'line_amount' => QuoteMoney::format($amount), 'trip_assigned' => $assigned,
                'trip_reference' => $assigned ? (string) $line['TRIP_ID'] : null, 'trip_status' => $assigned ? $line['TRIP_STATUS'] : ($line['TRIP_STATUS'] === 'Cancelled' ? 'Cancelled' : null)];
        }
        $used = $this->used($tx, $actor, (string) $row['RECORD#']);
        $actions = match ($row['STATUS']) {
            'DRAFT' => $used || $this->assigned($rows) ? [] : ['edit', 'submit', 'delete'],
            'SUBMITTED' => !$this->assigned($rows) && $this->inventory !== null && $this->logistics !== null
                && $this->invoices->canReturnToDraft($tx, $actor, (string) $row['RECORD#']) ? ['move_to_draft'] : [],
            default => [],
        };
        return ['order_number' => $row['DOCUMENT_NUMBER'], 'source_quote_number' => $source['DOCUMENT_NUMBER'],
            'customer_code' => $customer['CUSTOMER_CODE'], 'warehouse_id' => $row['WAREHOUSE_ID'], 'order_date' => $row['DATE_TEXT'],
            'requested_delivery_date' => $row['DELIVERY_DATE_TEXT'], 'customer_po_number' => $row['CUSTOMER_PO_NUMBER'] ?? '',
            'shipping_address' => $row['SHIPPING_ADDRESS'], 'payment_terms' => $row['PAYMENT_TERMS'], 'notes' => $row['NOTES'] ?? '',
            'status' => self::STATUSES[$row['STATUS']], 'current_version' => (int) $row['VERSION'],
            'document_total' => QuoteMoney::format($total), 'lines' => $lines, 'available_actions' => $this->availableActions($actor, $actions)];
    }

    private function quoteSummary(TransactionContext $tx, Actor $actor, array $row): array
    {
        $customer = $this->customer($tx, $actor, $row); $total = '0';
        foreach ($this->lines($tx, $actor, $row) as $line) { $total = QuoteMoney::add($total, QuoteMoney::multiply($line['QUANTITY_TEXT'], $line['PRICE_TEXT'])); }
        $actions = [];
        try { $this->permissions()->requireDocument($actor, 'quote', 'return_to_draft'); $actions[] = 'move_to_draft'; }
        catch (AppError $error) { if ($error->errorCode !== 'FORBIDDEN') { throw $error; } }
        return ['quote_number' => $row['DOCUMENT_NUMBER'], 'customer_code' => $customer['CUSTOMER_CODE'], 'customer_name' => $customer['CUSTOMER_NAME'],
            'warehouse_id' => $row['WAREHOUSE_ID'], 'quote_date' => $row['DATE_TEXT'], 'valid_until' => $row['VALID_UNTIL_TEXT'] ?? null,
            'requested_delivery_date' => $row['DELIVERY_DATE_TEXT'], 'shipping_address' => $row['SHIPPING_ADDRESS'],
            'payment_terms' => $row['PAYMENT_TERMS'], 'notes' => $row['NOTES'] ?? '', 'status' => 'Submitted',
            'current_version' => (int) $row['VERSION'], 'document_total' => QuoteMoney::format($total), 'available_actions' => $actions];
    }

    private function guard(array $row, array $input, string $action, bool $used = false): void
    {
        DocumentRules::requireVersion((int) $row['VERSION'], Api::integer($input, 'expected_version'));
        DocumentRules::requireAction('order', $action, self::STATUSES[$row['STATUS']], $used);
    }

    private function permissions(): SalesPermissions { return new SalesPermissions(new Authorization($this->connection)); }

    private function availableActions(Actor $actor, array $actions): array
    {
        return array_values(array_filter($actions, function (string $action) use ($actor): bool {
            try {
                $this->permissions()->requireDocument($actor, 'order', $action === 'move_to_draft' ? 'return_to_draft' : $action);
                if ($action === 'submit') {
                    $this->permissions()->requireDocument($actor, 'quote', 'read');
                    $this->permissions()->requireDocument($actor, 'invoice', 'create');
                }
                return true;
            } catch (AppError $error) { if ($error->errorCode !== 'FORBIDDEN') { throw $error; } return false; }
        }));
    }

    private function normalizeLines(array $input): array
    {
        if (isset($input['lines']) && is_array($input['lines']) && array_is_list($input['lines'])) {
            $input['lines'] = array_map(static fn (mixed $line): mixed => $line instanceof \stdClass ? get_object_vars($line) : $line, $input['lines']);
        }
        return $input;
    }

    private function write(Actor $actor, string $action, ?string $number, array $input, callable $operation): array
    {
        return (new OrderActions(new TransactionManager($this->connection), $this->permissions()))->run($actor, $action, $number, $input, $operation);
    }
}
