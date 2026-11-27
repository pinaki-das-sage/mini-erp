<?php
declare(strict_types=1);

namespace MiniErp\purchasing\Model;

use MiniErp\shared\AppError;
use MiniErp\purchasing\Quantity;
use MiniErp\shared\database\Connection;
use MiniErp\shared\database\DatabaseError;
use MiniErp\shared\database\TransactionContext;
use MiniErp\shared\database\TransactionManager;
use MiniErp\shared\security\Actor;
use MiniErp\shared\security\Authorization;

final readonly class PurchasingDocumentModel
{
    public const WAREHOUSE = 'W01';
    private const DOCUMENT_SELECT = "D.CNYID AS DOC_CNYID, D.ID AS DOC_ID, D.VENDOR_ID, D.DOCNO,
        TO_CHAR(D.DOC_DATE, 'YYYY-MM-DD\"T\"HH24:MI:SS\"Z\"') AS DOC_DATE,
        D.SOURCE_DOC_ID, D.NOTES AS DOC_NOTES, D.VENDOR_REF, D.DELIVERY_ADDR, D.PAYMENT_TERMS, D.STATUS, D.ORDER_ID, D.RECEIVER_ID,
        D.INVOICE_ID, TO_CHAR(D.DUEDATE, 'YYYY-MM-DD') AS DUEDATE, D.RECORDTYPE, D.AMOUNTDUE,
        D.TOTAL, D.CREATEDBY AS DOC_CREATEDBY, D.MODIFIEDBY AS DOC_MODIFIEDBY,
        TO_CHAR(D.WHENCREATED, 'YYYY-MM-DD\"T\"HH24:MI:SS.FF3\"Z\"') AS DOC_WHENCREATED,
        TO_CHAR(D.WHENMODIFIED, 'YYYY-MM-DD\"T\"HH24:MI:SS.FF3\"Z\"') AS DOC_WHENMODIFIED,
        E.CNYID AS ENTRY_CNYID, E.ID AS ENTRY_ID, E.DOC_ID AS ENTRY_DOC_ID, E.LINENO,
        E.PRODUCT_ID, E.DESCRIPTION, E.QUANTITY, E.UNITCOST, E.LINEAMOUNT, E.NOTES,
        E.CREATEDBY AS ENTRY_CREATEDBY, E.MODIFIEDBY AS ENTRY_MODIFIEDBY,
        TO_CHAR(E.WHENCREATED, 'YYYY-MM-DD\"T\"HH24:MI:SS.FF3\"Z\"') AS ENTRY_WHENCREATED,
        TO_CHAR(E.WHENMODIFIED, 'YYYY-MM-DD\"T\"HH24:MI:SS.FF3\"Z\"') AS ENTRY_WHENMODIFIED";

    public function __construct(private Connection $connection) {}

    /** @param array<string, mixed> $document
     *  @return 'docno'|'order_id'|'receiver_id'|'invoice_id'|null
     */
    public function duplicateKey(Actor $actor, array $document): ?string
    {
        // Column names come only from this allowlist; values are always bound.
        foreach (['docno', 'order_id', 'receiver_id', 'invoice_id'] as $field) {
            if (!isset($document[$field])) {
                continue;
            }
            $column = strtoupper($field);
            $rows = $this->connection->query(
                'SELECT ID FROM ME_PUR_DOC
                 WHERE CNYID = :company_id AND ' . $column . ' = :key_value
                 FETCH FIRST 1 ROWS ONLY',
                ['company_id' => $actor->companyId, 'key_value' => $document[$field]],
            );
            if ($rows !== []) {
                return $field;
            }
        }
        return null;
    }

    /** @param array<string, mixed> $document
     *  @param list<array<string, mixed>> $entries
     *  @return array{id: string, cnyid: int, name: string, message: string}
     */
    public function create(Actor $actor, array $document, array $entries): array
    {
        if (($document['status'] ?? 'draft') !== 'draft') {
            throw new AppError('VALIDATION_ERROR', 'New documents must start in Draft.');
        }
        $document['status'] = 'draft';
        $sourceId = $document['source_document_id'] ?? null;
        if ($document['recordtype'] !== 'order') {
            if ($sourceId === null) {
                throw new AppError('VALIDATION_ERROR', 'Select the source document when creating a receiver or invoice.');
            }
            $auth = new Authorization($this->connection);
            $auth->requirePermission($actor, 'purchasing.document', 'read');
            $auth->requirePermission($actor, 'purchasing.document',
                $document['recordtype'] === 'receiver' ? 'create_receiver' : 'create_invoice');
        } elseif ($sourceId !== null) {
            throw new AppError('VALIDATION_ERROR', 'A purchase order cannot use a source document.');
        }
        $id = bin2hex(random_bytes(16));
        $typeField = match ($document['recordtype']) {
            'order' => 'order_id',
            'receiver' => 'receiver_id',
            'invoice' => 'invoice_id',
        };
        try {
            (new TransactionManager($this->connection))->run(
                function (TransactionContext $tx) use ($actor, $document, $entries, $id, $typeField): void {
                    if (($document['source_document_id'] ?? null) !== null) {
                        [$document, $entries] = self::sourceDraft($tx, $actor, $document);
                    } else {
                        self::activeVendor($tx, $actor, $document['vendor_id']);
                    }
                    $total = self::total($entries);
                    $typeId = $document[$typeField] ?? $id;
                    $tx->execute(
                        'INSERT INTO ME_PUR_DOC
                            (CNYID, ID, VENDOR_ID, DOCNO, SOURCE_DOC_ID, NOTES, DOC_DATE, VENDOR_REF, DELIVERY_ADDR, PAYMENT_TERMS,
                             STATUS, ORDER_ID, RECEIVER_ID, INVOICE_ID, DUEDATE, RECORDTYPE, AMOUNTDUE, TOTAL,
                             CREATEDBY, MODIFIEDBY, WHENCREATED, WHENMODIFIED)
                         VALUES
                            (:company_id, :id, :vendor_id, :docno, :source_doc_id, :document_notes, TO_DATE(:doc_date, \'YYYY-MM-DD\'), :vendor_ref,
                             :delivery_addr, :payment_terms, :status, :order_id, :receiver_id, :invoice_id,
                             TO_DATE(:duedate, \'YYYY-MM-DD\'), :recordtype, :amountdue, :total, :created_by,
                             :modified_by, SYSTIMESTAMP, SYSTIMESTAMP)',
                        [
                            'company_id' => $actor->companyId, 'id' => $id,
                            'vendor_id' => $document['vendor_id'], 'docno' => $document['docno'],
                            'source_doc_id' => $document['source_document_id'] ?? null, 'document_notes' => $document['notes'] ?? null,
                            'doc_date' => $document['doc_date'], 'vendor_ref' => $document['vendor_ref'],
                            'delivery_addr' => $document['delivery_addr'], 'payment_terms' => $document['payment_terms'],
                            'status' => $document['status'],
                            'order_id' => $document['recordtype'] === 'order' ? $typeId : null,
                            'receiver_id' => $document['recordtype'] === 'receiver' ? $typeId : null,
                            'invoice_id' => $document['recordtype'] === 'invoice' ? $typeId : null,
                            'duedate' => $document['duedate'], 'recordtype' => $document['recordtype'],
                            'amountdue' => $document['recordtype'] === 'invoice' ? '0.00' : null,
                            'total' => $total, 'created_by' => $actor->id, 'modified_by' => $actor->id,
                        ],
                    );
                    foreach ($entries as $entry) {
                        if (isset($entry['doc_id']) && $entry['doc_id'] !== $id) {
                            throw new AppError('VALIDATION_ERROR', 'entry doc_id must match the generated document ID.');
                        }
                        self::insertEntry($tx, $actor, $id, $entry);
                    }
                },
            );
        } catch (DatabaseError $error) {
            if ($error->oracleCode === 1) {
                throw new AppError('CONFLICT', 'The document number, order_id, receiver_id, invoice_id or line number already exists in this company.', 409);
            }
            throw $error;
        }
        return [
            'id' => $id,
            'cnyid' => $actor->companyId,
            'name' => $document['docno'],
            'message' => ucfirst($document['recordtype']) . ' created successfully.',
        ];
    }

    public static function receivingAvailable(): bool
    {
        return class_exists(\MiniErp\inventory\StockReceiptService::class);
    }

    /** Purchasing owns the transaction; Inventory joins it for each receipt line. */
    public function receive(Actor $actor, string $id): array
    {
        $auth = new Authorization($this->connection);
        $auth->requirePermission($actor, 'purchasing.document', 'update');
        $auth->requirePermission($actor, 'inventory.stock', 'receive');
        if (!self::receivingAvailable()) {
            throw new AppError('DEPENDENCY_UNAVAILABLE', 'Deploy Inventory StockReceiptService before receiving goods.', 503);
        }
        return (new TransactionManager($this->connection))->run(
            function (TransactionContext $tx) use ($actor, $id): array {
                $lookup = $tx->query(
                    'SELECT RECORDTYPE, SOURCE_DOC_ID FROM ME_PUR_DOC WHERE CNYID = :company_id AND ID = :id',
                    ['company_id' => $actor->companyId, 'id' => $id],
                );
                if ($lookup === [] || strtolower((string) $lookup[0]['RECORDTYPE']) !== 'receiver') {
                    throw new AppError('NOT_FOUND', 'Receiver not found.', 404);
                }
                if ($lookup[0]['SOURCE_DOC_ID'] === null) {
                    throw new AppError('CONFLICT',
                        'This receiver has no source purchase order. Open a Submitted purchase order and use Create draft receiver, then receive that new document.', 409);
                }
                $sourceId = (string) $lookup[0]['SOURCE_DOC_ID'];
                // Lock the source first, matching the source-document creation lock order.
                $orders = $tx->query(
                    "SELECT RECORDTYPE, STATUS, TO_CHAR(DOC_DATE, 'YYYY-MM-DD') AS DOC_DATE
                     FROM ME_PUR_DOC WHERE CNYID = :company_id AND ID = :id FOR UPDATE",
                    ['company_id' => $actor->companyId, 'id' => $sourceId],
                );
                $receivers = $tx->query(
                    "SELECT RECORDTYPE, STATUS, SOURCE_DOC_ID, TO_CHAR(DOC_DATE, 'YYYY-MM-DD') AS DOC_DATE
                     FROM ME_PUR_DOC WHERE CNYID = :company_id AND ID = :id FOR UPDATE",
                    ['company_id' => $actor->companyId, 'id' => $id],
                );
                if ($orders === [] || $receivers === []
                    || strtolower((string) $orders[0]['RECORDTYPE']) !== 'order'
                    || strtolower((string) $receivers[0]['RECORDTYPE']) !== 'receiver'
                    || $receivers[0]['SOURCE_DOC_ID'] !== $sourceId) {
                    throw new AppError('CONFLICT', 'Receiver source does not match its purchase order.', 409);
                }
                $order = $orders[0];
                $receiver = $receivers[0];
                if (strtolower((string) $receiver['STATUS']) === 'received'
                    && strtolower((string) $order['STATUS']) === 'received') {
                    return ['id' => $id, 'status' => 'received', 'warehouse_id' => self::WAREHOUSE,
                        'message' => 'This receiver is already Received. Stock was not posted again.'];
                }
                if (strtolower((string) $receiver['STATUS']) !== 'draft'
                    || strtolower((string) $order['STATUS']) !== 'submitted') {
                    throw new AppError('CONFLICT', 'Receiving requires a Draft receiver and its Submitted order.', 409);
                }
                if ($receiver['DOC_DATE'] === null || $order['DOC_DATE'] === null
                    || $receiver['DOC_DATE'] < $order['DOC_DATE']) {
                    throw new AppError('VALIDATION_ERROR', 'Receipt date must be on or after the order date.');
                }
                $sourceLines = $tx->query(
                    'SELECT LINENO, PRODUCT_ID, DESCRIPTION, QUANTITY, UNITCOST, LINEAMOUNT FROM ME_PUR_DOC_ENTRY
                     WHERE CNYID = :company_id AND DOC_ID = :doc_id ORDER BY LINENO',
                    ['company_id' => $actor->companyId, 'doc_id' => $sourceId],
                );
                $lines = $tx->query(
                    'SELECT ID, LINENO, PRODUCT_ID, DESCRIPTION, QUANTITY, UNITCOST, LINEAMOUNT FROM ME_PUR_DOC_ENTRY
                     WHERE CNYID = :company_id AND DOC_ID = :doc_id ORDER BY PRODUCT_ID, LINENO, ID',
                    ['company_id' => $actor->companyId, 'doc_id' => $id],
                );
                if ($lines === [] || count($lines) !== count($sourceLines)) {
                    throw new AppError('VALIDATION_ERROR', 'Receiver lines must match every source order line.');
                }
                $sourceMap = [];
                foreach ($sourceLines as $line) {
                    if (isset($sourceMap[(int) $line['LINENO']])) {
                        throw new AppError('VALIDATION_ERROR', 'Source line numbers must be unique.');
                    }
                    $sourceMap[(int) $line['LINENO']] = $line;
                }
                $seen = [];
                foreach ($lines as $line) {
                    $number = (int) $line['LINENO'];
                    $source = $sourceMap[$number] ?? null;
                    if ($number < 1 || isset($seen[$number]) || $source === null
                        || (int) $line['PRODUCT_ID'] !== (int) $source['PRODUCT_ID']
                        || (float) $line['QUANTITY'] !== (float) $source['QUANTITY']
                        || number_format((float) $line['UNITCOST'], 2, '.', '') !== number_format((float) $source['UNITCOST'], 2, '.', '')
                        || number_format((float) $line['LINEAMOUNT'], 2, '.', '') !== number_format((float) $source['LINEAMOUNT'], 2, '.', '')
                        || $line['DESCRIPTION'] !== $source['DESCRIPTION']) {
                        throw new AppError('VALIDATION_ERROR', 'Receiver product, quantity, price and description must match the source order.');
                    }
                    $seen[$number] = true;
                    self::validLine($line['QUANTITY'], $line['UNITCOST']);
                    self::requireProduct($tx, $actor, (int) $line['PRODUCT_ID']);
                }
                // Round matching legacy quantities and recalculate both snapshots before
                // posting stock. A failure rolls these changes back with Inventory.
                self::normalizeDocumentLines($tx, $actor, $sourceId);
                self::normalizeDocumentLines($tx, $actor, $id);
                $inventory = new \MiniErp\inventory\StockReceiptService($this->connection);
                foreach ($lines as $line) {
                    // The stable receiver ENTRY ID is the Inventory movement's idempotency key.
                    $inventory->receivePurchase($tx, $actor, (string) $line['ID'],
                        (int) $line['PRODUCT_ID'], Quantity::fromStored($line['QUANTITY']));
                }
                $tx->execute(
                    "UPDATE ME_PUR_DOC SET STATUS = 'received', MODIFIEDBY = :modified_by, WHENMODIFIED = SYSTIMESTAMP
                     WHERE CNYID = :company_id AND ID IN (:order_id, :receiver_id)",
                    ['modified_by' => $actor->id, 'company_id' => $actor->companyId,
                        'order_id' => $sourceId, 'receiver_id' => $id],
                );
                return ['id' => $id, 'status' => 'received', 'warehouse_id' => self::WAREHOUSE,
                    'message' => 'All items received into W01. Order, receiver and stock committed together.'];
            },
        );
    }

    /** @param array<string, mixed> $changes
     *  @return array<string, mixed>
     */
    public function update(Actor $actor, string $id, array $changes): array
    {
        if (($changes['status'] ?? null) === 'received') {
            if (array_keys($changes) !== ['status']) {
                throw new AppError('VALIDATION_ERROR', 'Save Draft changes before receiving goods.');
            }
            return $this->receive($actor, $id);
        }
        try {
            return (new TransactionManager($this->connection))->run(
                function (TransactionContext $tx) use ($actor, $id, $changes): array {
                    $rows = $tx->query(
                        'SELECT RECORDTYPE, STATUS, VENDOR_ID, DOCNO,
                                TO_CHAR(DOC_DATE, \'YYYY-MM-DD\') AS DOC_DATE,
                                PAYMENT_TERMS, VENDOR_REF, DELIVERY_ADDR, SOURCE_DOC_ID
                         FROM ME_PUR_DOC WHERE CNYID = :company_id AND ID = :id FOR UPDATE',
                        ['company_id' => $actor->companyId, 'id' => $id],
                    );
                    if ($rows === []) {
                        throw new AppError('NOT_FOUND', 'Purchasing document not found.', 404);
                    }
                    $saved = $rows[0];
                    $type = strtolower((string) $saved['RECORDTYPE']);
                    if (strtolower((string) $saved['STATUS']) !== 'draft') {
                        throw new AppError('CONFLICT', 'Only Draft documents can be edited or submitted.', 409);
                    }
                    $nextStatus = $changes['status'] ?? 'draft';
                    if ($nextStatus !== 'draft') {
                        if (!in_array($type, ['order', 'invoice'], true) || $nextStatus !== 'submitted') {
                            throw new AppError('CONFLICT',
                                $type === 'receiver' ? 'Use Receive all items to receive a Draft receiver.'
                                    : 'A Draft order or invoice can only move to Submitted.', 409);
                        }
                        if (array_keys($changes) !== ['status']) {
                            throw new AppError('VALIDATION_ERROR', 'Save Draft changes before submitting the document.');
                        }
                        (new Authorization($this->connection))->requirePermission($actor, 'purchasing.document', 'submit');
                        $prefix = $type === 'invoice' ? 'INV-' : 'PO-';
                        if ($saved['DOC_DATE'] === null || !str_starts_with((string) $saved['DOCNO'], $prefix)
                            || strlen((string) $saved['DOCNO']) <= strlen($prefix)) {
                            throw new AppError('VALIDATION_ERROR', 'Enter a valid document number and date before submitting.');
                        }
                        if ($type === 'order') {
                            self::activeVendor($tx, $actor, $saved['VENDOR_ID']);
                        } else {
                            self::validateInvoiceSubmission($tx, $actor, $id, $saved);
                        }
                        $lines = $tx->query(
                            'SELECT LINENO, PRODUCT_ID, DESCRIPTION, QUANTITY, UNITCOST FROM ME_PUR_DOC_ENTRY
                             WHERE CNYID = :company_id AND DOC_ID = :doc_id ORDER BY LINENO',
                            ['company_id' => $actor->companyId, 'doc_id' => $id],
                        );
                        if ($lines === []) {
                            throw new AppError('VALIDATION_ERROR', 'Add at least one document line before submitting.');
                        }
                        foreach ($lines as $line) {
                            if ((int) $line['LINENO'] < 1 || trim((string) $line['DESCRIPTION']) === '') {
                                throw new AppError('VALIDATION_ERROR', 'Every order line needs a positive line number and description.');
                            }
                            self::validLine($line['QUANTITY'], $line['UNITCOST']);
                            if ($type === 'order') {
                                self::requireProduct($tx, $actor, (int) $line['PRODUCT_ID']);
                            }
                        }
                    }
                    if ($type !== 'order' && array_diff(array_keys($changes), ['doc_date', 'notes', 'status']) !== []) {
                        throw new AppError('VALIDATION_ERROR', 'Copied header and line values are read-only. Only the date and document notes can change.');
                    }
                    if (isset($changes['vendor_id'])) {
                        self::activeVendor($tx, $actor, $changes['vendor_id']);
                    }
                    if (isset($changes['doc_date']) && $saved['SOURCE_DOC_ID'] !== null) {
                        $source = $tx->query(
                            "SELECT TO_CHAR(DOC_DATE, 'YYYY-MM-DD') AS SOURCE_DATE FROM ME_PUR_DOC
                             WHERE CNYID = :company_id AND ID = :id",
                            ['company_id' => $actor->companyId, 'id' => $saved['SOURCE_DOC_ID']],
                        );
                        if ($source === [] || $changes['doc_date'] < $source[0]['SOURCE_DATE']) {
                            throw new AppError('VALIDATION_ERROR', 'Document date must be on or after the source document date.');
                        }
                    }
                    $sets = ['MODIFIEDBY = :modified_by', 'WHENMODIFIED = SYSTIMESTAMP'];
                    $bindings = ['company_id' => $actor->companyId, 'id' => $id, 'modified_by' => $actor->id];
                    foreach (['vendor_id', 'payment_terms', 'vendor_ref', 'delivery_addr', 'notes', 'status'] as $field) {
                        if (array_key_exists($field, $changes)) {
                            $sets[] = strtoupper($field) . ' = :' . $field;
                            $bindings[$field] = $changes[$field];
                        }
                    }
                    if (isset($changes['doc_date'])) {
                        $sets[] = "DOC_DATE = TO_DATE(:doc_date, 'YYYY-MM-DD')";
                        $bindings['doc_date'] = $changes['doc_date'];
                    }
                    $tx->execute('UPDATE ME_PUR_DOC SET ' . implode(', ', $sets)
                        . ' WHERE CNYID = :company_id AND ID = :id', $bindings);
                    if ($type === 'invoice') {
                        $tx->execute(
                            "UPDATE ME_PUR_DOC SET DUEDATE = DOC_DATE + CASE PAYMENT_TERMS
                                WHEN 'due_on_receipt' THEN 0 WHEN 'net_15' THEN 15 ELSE 30 END,
                                AMOUNTDUE = 0
                             WHERE CNYID = :company_id AND ID = :id",
                            ['company_id' => $actor->companyId, 'id' => $id],
                        );
                    }
                    foreach ($changes['remove_entry_ids'] ?? [] as $entryId) {
                        $deleted = $tx->execute(
                            'DELETE FROM ME_PUR_DOC_ENTRY WHERE CNYID = :company_id AND DOC_ID = :doc_id AND ID = :id',
                            ['company_id' => $actor->companyId, 'doc_id' => $id, 'id' => $entryId],
                        );
                        if ($deleted !== 1) {
                            throw new AppError('NOT_FOUND', 'Document entry not found in this document.', 404);
                        }
                    }
                    foreach ($changes['entries'] ?? [] as $entry) {
                        if (!isset($entry['id'])) {
                            if (isset($entry['doc_id']) && $entry['doc_id'] !== $id) {
                                throw new AppError('VALIDATION_ERROR', 'entry doc_id must match this document.');
                            }
                            self::insertEntry($tx, $actor, $id, $entry);
                            continue;
                        }
                        $rows = $tx->query(
                            'SELECT LINENO, PRODUCT_ID, DESCRIPTION, QUANTITY, UNITCOST, NOTES FROM ME_PUR_DOC_ENTRY
                             WHERE CNYID = :company_id AND DOC_ID = :doc_id AND ID = :id FOR UPDATE',
                            ['company_id' => $actor->companyId, 'doc_id' => $id, 'id' => $entry['id']],
                        );
                        if ($rows === []) {
                            throw new AppError('NOT_FOUND', 'Document entry not found in this document.', 404);
                        }
                        $line = $rows[0];
                        if (isset($entry['lineno']) && $entry['lineno'] !== (int) $line['LINENO']) {
                            throw new AppError('VALIDATION_ERROR', 'lineno must match the saved document line.');
                        }
                        $product = $entry['product_id'] ?? (int) $line['PRODUCT_ID'];
                        $quantity = array_key_exists('quantity', $entry)
                            ? Quantity::fromInput($entry['quantity']) : Quantity::fromStored($line['QUANTITY']);
                        $cost = $entry['unitcost'] ?? $line['UNITCOST'];
                        self::validLine($quantity, $cost);
                        self::requireProduct($tx, $actor, $product);
                        $amount = number_format(round((float) $quantity * (float) $cost, 2, PHP_ROUND_HALF_UP), 2, '.', '');
                        $tx->execute(
                            'UPDATE ME_PUR_DOC_ENTRY SET PRODUCT_ID = :product_id, DESCRIPTION = :description,
                                QUANTITY = :quantity, UNITCOST = :unitcost, LINEAMOUNT = :lineamount,
                                NOTES = :notes, MODIFIEDBY = :modified_by, WHENMODIFIED = SYSTIMESTAMP
                             WHERE CNYID = :company_id AND DOC_ID = :doc_id AND ID = :id',
                            ['product_id' => $product, 'description' => $entry['description'] ?? $line['DESCRIPTION'],
                                'quantity' => (string) $quantity, 'unitcost' => (string) $cost, 'lineamount' => $amount,
                                'notes' => array_key_exists('notes', $entry) ? $entry['notes'] : $line['NOTES'],
                                'modified_by' => $actor->id, 'company_id' => $actor->companyId, 'doc_id' => $id, 'id' => $entry['id']],
                        );
                    }
                    if ($type === 'order') {
                        self::normalizeDocumentLines($tx, $actor, $id);
                    }
                    $totals = $tx->query(
                        'SELECT COUNT(*) AS LINE_COUNT, COUNT(DISTINCT LINENO) AS UNIQUE_LINES, NVL(SUM(LINEAMOUNT), 0) AS TOTAL
                         FROM ME_PUR_DOC_ENTRY WHERE CNYID = :company_id AND DOC_ID = :doc_id',
                        ['company_id' => $actor->companyId, 'doc_id' => $id],
                    );
                    if ((int) $totals[0]['LINE_COUNT'] === 0) {
                        throw new AppError('VALIDATION_ERROR', 'A document must have at least one line.');
                    }
                    if ((int) $totals[0]['UNIQUE_LINES'] !== (int) $totals[0]['LINE_COUNT']) {
                        throw new AppError('VALIDATION_ERROR', 'Every document line must have a unique line number.');
                    }
                    $tx->execute(
                        'UPDATE ME_PUR_DOC SET TOTAL = :total WHERE CNYID = :company_id AND ID = :id',
                        ['total' => number_format((float) $totals[0]['TOTAL'], 2, '.', ''),
                            'company_id' => $actor->companyId, 'id' => $id],
                    );
                    if ($type === 'invoice' && $nextStatus === 'submitted') {
                        // This is an invoice's own outstanding amount, not a vendor/GL ledger entry.
                        $tx->execute(
                            'UPDATE ME_PUR_DOC SET AMOUNTDUE = :amount_due WHERE CNYID = :company_id AND ID = :id',
                            ['amount_due' => number_format((float) $totals[0]['TOTAL'], 2, '.', ''),
                                'company_id' => $actor->companyId, 'id' => $id],
                        );
                    }
                    return ['id' => $id, 'status' => $nextStatus,
                        'message' => $nextStatus === 'submitted'
                            ? ($type === 'invoice'
                                ? 'Invoice submitted. It is now read-only. No ledger entries were created.'
                                : 'Order submitted. Header and lines are now read-only.')
                            : 'Draft saved.'];
                },
            );
        } catch (DatabaseError $error) {
            if ($error->oracleCode === 1) {
                throw new AppError('CONFLICT', 'The document number, source or line number already exists in this company.', 409);
            }
            if ($error->oracleCode === 2291) {
                throw new AppError('VALIDATION_ERROR', 'The selected vendor or product no longer exists in this company.');
            }
            throw $error;
        }
    }

    /** @return list<array<string, mixed>> */
    public function list(Actor $actor): array
    {
        return self::assemble($this->connection->query(
            'SELECT ' . self::DOCUMENT_SELECT . '
             FROM ME_PUR_DOC D
             LEFT JOIN ME_PUR_DOC_ENTRY E ON E.CNYID = D.CNYID AND E.DOC_ID = D.ID
             WHERE D.CNYID = :company_id
             ORDER BY D.DOC_DATE DESC, D.ID, E.LINENO',
            ['company_id' => $actor->companyId],
        ));
    }

    /** @return array<string, mixed>|null */
    public function find(Actor $actor, string $id): ?array
    {
        $documents = self::assemble($this->connection->query(
            'SELECT ' . self::DOCUMENT_SELECT . '
             FROM ME_PUR_DOC D
             LEFT JOIN ME_PUR_DOC_ENTRY E ON E.CNYID = D.CNYID AND E.DOC_ID = D.ID
             WHERE D.CNYID = :company_id AND D.ID = :id
             ORDER BY E.LINENO',
            ['company_id' => $actor->companyId, 'id' => $id],
        ));
        return $documents[0] ?? null;
    }

    /** @return list<array<string, mixed>> */
    public function listEntries(Actor $actor, ?string $documentId): array
    {
        $where = 'CNYID = :company_id';
        $bindings = ['company_id' => $actor->companyId];
        if ($documentId !== null) {
            $where .= ' AND DOC_ID = :document_id';
            $bindings['document_id'] = $documentId;
        }
        return array_map(self::mapEntry(...), $this->connection->query(
            "SELECT CNYID, ID, DOC_ID, LINENO, PRODUCT_ID, DESCRIPTION, QUANTITY, UNITCOST, LINEAMOUNT,
                    NOTES, CREATEDBY, MODIFIEDBY,
                    TO_CHAR(WHENCREATED, 'YYYY-MM-DD\"T\"HH24:MI:SS.FF3\"Z\"') AS WHENCREATED,
                    TO_CHAR(WHENMODIFIED, 'YYYY-MM-DD\"T\"HH24:MI:SS.FF3\"Z\"') AS WHENMODIFIED
             FROM ME_PUR_DOC_ENTRY WHERE $where ORDER BY DOC_ID, LINENO",
            $bindings,
        ));
    }

    /** @return array<string, mixed>|null */
    public function findEntry(Actor $actor, string $id): ?array
    {
        $rows = $this->connection->query(
            "SELECT CNYID, ID, DOC_ID, LINENO, PRODUCT_ID, DESCRIPTION, QUANTITY, UNITCOST, LINEAMOUNT,
                    NOTES, CREATEDBY, MODIFIEDBY,
                    TO_CHAR(WHENCREATED, 'YYYY-MM-DD\"T\"HH24:MI:SS.FF3\"Z\"') AS WHENCREATED,
                    TO_CHAR(WHENMODIFIED, 'YYYY-MM-DD\"T\"HH24:MI:SS.FF3\"Z\"') AS WHENMODIFIED
             FROM ME_PUR_DOC_ENTRY WHERE CNYID = :company_id AND ID = :id",
            ['company_id' => $actor->companyId, 'id' => $id],
        );
        return $rows === [] ? null : self::mapEntry($rows[0]);
    }

    /** @param list<array<string, mixed>> $rows
     *  @return list<array<string, mixed>>
     */
    private static function assemble(array $rows): array
    {
        $documents = [];
        foreach ($rows as $row) {
            $id = (string) $row['DOC_ID'];
            if (!isset($documents[$id])) {
                $type = strtolower((string) $row['RECORDTYPE']);
                $documents[$id] = [
                    'cnyid' => (int) $row['DOC_CNYID'],
                    'id' => $id,
                    'docno' => $row['DOCNO'],
                    'vendor_id' => $row['VENDOR_ID'],
                    'doc_date' => $row['DOC_DATE'],
                    'vendor_ref' => $row['VENDOR_REF'],
                    'delivery_addr' => $row['DELIVERY_ADDR'],
                    'payment_terms' => $row['PAYMENT_TERMS'],
                    'status' => $row['STATUS'] === null ? null : strtolower((string) $row['STATUS']),
                    'duedate' => $row['DUEDATE'],
                    'recordtype' => $type,
                    'source_document_id' => $row['SOURCE_DOC_ID'],
                    'warehouse_id' => self::WAREHOUSE,
                    'notes' => $row['DOC_NOTES'],
                    'amountdue' => self::numberOrNull($row['AMOUNTDUE']),
                    'total' => self::numberOrNull($row['TOTAL']),
                    'createdby' => $row['DOC_CREATEDBY'],
                    'modifiedby' => $row['DOC_MODIFIEDBY'],
                    'whencreated' => $row['DOC_WHENCREATED'],
                    'whenmodified' => $row['DOC_WHENMODIFIED'],
                    'entries' => [],
                ];
                $typeField = match ($type) {
                    'order' => 'order_id',
                    'receiver' => 'receiver_id',
                    'invoice' => 'invoice_id',
                    default => null,
                };
                if ($typeField !== null) {
                    $dbField = strtoupper($typeField);
                    $documents[$id][$typeField] = $row[$dbField] === null ? $id : (string) $row[$dbField];
                }
            }
            if ($row['ENTRY_ID'] !== null) {
                $documents[$id]['entries'][] = self::mapJoinedEntry($row);
            }
        }
        return array_values($documents);
    }

    /** @return array<string, mixed> */
    private static function mapJoinedEntry(array $row): array
    {
        return [
            'cnyid' => (int) $row['ENTRY_CNYID'],
            'id' => (string) $row['ENTRY_ID'],
            'doc_id' => (string) $row['ENTRY_DOC_ID'],
            'lineno' => $row['LINENO'] === null ? null : (int) $row['LINENO'],
            'product_id' => $row['PRODUCT_ID'] === null ? null : (int) $row['PRODUCT_ID'],
            'description' => $row['DESCRIPTION'],
            'quantity' => self::numberOrNull($row['QUANTITY']),
            'unitcost' => self::numberOrNull($row['UNITCOST']),
            'lineamount' => self::numberOrNull($row['LINEAMOUNT']),
            'notes' => $row['NOTES'],
            'createdby' => $row['ENTRY_CREATEDBY'],
            'modifiedby' => $row['ENTRY_MODIFIEDBY'],
            'whencreated' => $row['ENTRY_WHENCREATED'],
            'whenmodified' => $row['ENTRY_WHENMODIFIED'],
        ];
    }

    /** @return array<string, mixed> */
    private static function mapEntry(array $row): array
    {
        return [
            'cnyid' => (int) $row['CNYID'],
            'id' => (string) $row['ID'],
            'doc_id' => (string) $row['DOC_ID'],
            'lineno' => $row['LINENO'] === null ? null : (int) $row['LINENO'],
            'product_id' => $row['PRODUCT_ID'] === null ? null : (int) $row['PRODUCT_ID'],
            'description' => $row['DESCRIPTION'],
            'quantity' => self::numberOrNull($row['QUANTITY']),
            'unitcost' => self::numberOrNull($row['UNITCOST']),
            'lineamount' => self::numberOrNull($row['LINEAMOUNT']),
            'notes' => $row['NOTES'],
            'createdby' => $row['CREATEDBY'],
            'modifiedby' => $row['MODIFIEDBY'],
            'whencreated' => $row['WHENCREATED'],
            'whenmodified' => $row['WHENMODIFIED'],
        ];
    }

    /** @return array{0: array<string, mixed>, 1: list<array<string, mixed>>} */
    private static function sourceDraft(TransactionContext $tx, Actor $actor, array $document): array
    {
        $type = $document['recordtype'];
        $rows = $tx->query(
            "SELECT ID, VENDOR_ID, RECORDTYPE, STATUS, PAYMENT_TERMS, VENDOR_REF, DELIVERY_ADDR,
                    TO_CHAR(DOC_DATE, 'YYYY-MM-DD') AS SOURCE_DATE
             FROM ME_PUR_DOC WHERE CNYID = :company_id AND ID = :id FOR UPDATE",
            ['company_id' => $actor->companyId, 'id' => $document['source_document_id']],
        );
        if ($rows === []) {
            throw new AppError('NOT_FOUND', 'Source document not found.', 404);
        }
        $source = $rows[0];
        $sourceType = $type === 'receiver' ? 'order' : 'receiver';
        $sourceStatus = $type === 'receiver' ? 'submitted' : 'received';
        if (strtolower((string) $source['RECORDTYPE']) !== $sourceType
            || strtolower((string) $source['STATUS']) !== $sourceStatus) {
            throw new AppError('CONFLICT', 'Select a ' . $sourceStatus . ' ' . $sourceType . ' as the source.', 409);
        }
        if ($source['SOURCE_DATE'] === null || $document['doc_date'] < $source['SOURCE_DATE']) {
            throw new AppError('VALIDATION_ERROR', 'Document date must be on or after the source document date.');
        }
        if ($tx->query(
            'SELECT ID FROM ME_PUR_DOC WHERE CNYID = :company_id AND SOURCE_DOC_ID = :source_id',
            ['company_id' => $actor->companyId, 'source_id' => $source['ID']],
        ) !== []) {
            throw new AppError('CONFLICT', 'A downstream document already exists for this source.', 409);
        }
        if ($type === 'receiver') {
            self::normalizeDocumentLines($tx, $actor, (string) $source['ID']);
        }
        $rows = $tx->query(
            'SELECT LINENO, PRODUCT_ID, DESCRIPTION, QUANTITY, UNITCOST, LINEAMOUNT, NOTES
             FROM ME_PUR_DOC_ENTRY WHERE CNYID = :company_id AND DOC_ID = :doc_id ORDER BY LINENO',
            ['company_id' => $actor->companyId, 'doc_id' => $source['ID']],
        );
        if ($rows === []) {
            throw new AppError('VALIDATION_ERROR', 'The source document has no lines.');
        }
        $entries = [];
        foreach ($rows as $row) {
            if ($type === 'invoice' && (float) $row['QUANTITY'] !== (float) Quantity::fromStored($row['QUANTITY'])) {
                throw new AppError('VALIDATION_ERROR', 'The already Received source contains decimal quantities. Review that receipt before creating an invoice.');
            }
            if ((int) $row['LINENO'] < 1 || trim((string) $row['DESCRIPTION']) === '') {
                throw new AppError('VALIDATION_ERROR', 'The source has an invalid line number or description.');
            }
            self::validLine($row['QUANTITY'], $row['UNITCOST']);
            $entries[] = [
                'lineno' => (int) $row['LINENO'], 'product_id' => (int) $row['PRODUCT_ID'],
                'description' => $row['DESCRIPTION'], 'quantity' => Quantity::fromStored($row['QUANTITY']),
                'unitcost' => (string) $row['UNITCOST'], 'lineamount' => (string) $row['LINEAMOUNT'],
                'notes' => $row['NOTES'],
            ];
        }
        $document['vendor_id'] = $source['VENDOR_ID'];
        $document['vendor_ref'] = $source['VENDOR_REF'];
        $document['delivery_addr'] = $source['DELIVERY_ADDR'];
        $document['payment_terms'] = $source['PAYMENT_TERMS'];
        $document['duedate'] = null;
        if ($type === 'invoice') {
            $days = match ($document['payment_terms']) { 'due_on_receipt' => 0, 'net_15' => 15, default => 30 };
            $document['duedate'] = (new \DateTimeImmutable($document['doc_date']))->modify('+' . $days . ' days')->format('Y-m-d');
        }
        return [$document, $entries];
    }

    /** Caller holds the document header lock. No schema change or commit is needed. */
    private static function normalizeDocumentLines(TransactionContext $tx, Actor $actor, string $documentId): void
    {
        $lines = $tx->query(
            'SELECT ID, QUANTITY, UNITCOST, LINEAMOUNT FROM ME_PUR_DOC_ENTRY
             WHERE CNYID = :company_id AND DOC_ID = :doc_id ORDER BY LINENO, ID FOR UPDATE',
            ['company_id' => $actor->companyId, 'doc_id' => $documentId],
        );
        $total = 0.0;
        foreach ($lines as $line) {
            $quantity = Quantity::fromStored($line['QUANTITY']);
            self::validLine($quantity, $line['UNITCOST']);
            $amount = number_format(round($quantity * (float) $line['UNITCOST'], 2, PHP_ROUND_HALF_UP), 2, '.', '');
            $total += (float) $amount;
            if ((float) $line['QUANTITY'] !== (float) $quantity
                || number_format((float) $line['LINEAMOUNT'], 2, '.', '') !== $amount) {
                $tx->execute(
                    'UPDATE ME_PUR_DOC_ENTRY SET QUANTITY = :quantity, LINEAMOUNT = :amount,
                        MODIFIEDBY = :modified_by, WHENMODIFIED = SYSTIMESTAMP
                     WHERE CNYID = :company_id AND DOC_ID = :doc_id AND ID = :id',
                    ['quantity' => $quantity, 'amount' => $amount, 'modified_by' => $actor->id,
                        'company_id' => $actor->companyId, 'doc_id' => $documentId, 'id' => $line['ID']],
                );
            }
        }
        if (!is_finite($total) || abs($total) >= 1e18) {
            throw new AppError('VALIDATION_ERROR', 'The rounded document total exceeds the supported amount.');
        }
        $tx->execute(
            'UPDATE ME_PUR_DOC SET TOTAL = :total, MODIFIEDBY = :modified_by, WHENMODIFIED = SYSTIMESTAMP
             WHERE CNYID = :company_id AND ID = :id',
            ['total' => number_format($total, 2, '.', ''), 'modified_by' => $actor->id,
                'company_id' => $actor->companyId, 'id' => $documentId],
        );
    }

    /** Submission finalizes this invoice document only; it never posts a ledger. */
    private static function validateInvoiceSubmission(TransactionContext $tx, Actor $actor, string $id, array $invoice): void
    {
        if ($invoice['SOURCE_DOC_ID'] === null) {
            throw new AppError('CONFLICT', 'Create this invoice from a Received receiver before submitting.', 409);
        }
        $sources = $tx->query(
            "SELECT RECORDTYPE, STATUS, VENDOR_ID, PAYMENT_TERMS, VENDOR_REF, DELIVERY_ADDR,
                    TO_CHAR(DOC_DATE, 'YYYY-MM-DD') AS DOC_DATE
             FROM ME_PUR_DOC WHERE CNYID = :company_id AND ID = :id",
            ['company_id' => $actor->companyId, 'id' => $invoice['SOURCE_DOC_ID']],
        );
        if ($sources === [] || strtolower((string) $sources[0]['RECORDTYPE']) !== 'receiver'
            || strtolower((string) $sources[0]['STATUS']) !== 'received') {
            throw new AppError('CONFLICT', 'The invoice source must be a Received receiver.', 409);
        }
        $source = $sources[0];
        if ($invoice['DOC_DATE'] === null || $source['DOC_DATE'] === null
            || $invoice['DOC_DATE'] < $source['DOC_DATE']) {
            throw new AppError('VALIDATION_ERROR', 'Invoice date must be on or after the receipt date.');
        }
        foreach (['VENDOR_ID', 'PAYMENT_TERMS', 'VENDOR_REF', 'DELIVERY_ADDR'] as $field) {
            if ((string) $invoice[$field] !== (string) $source[$field]) {
                throw new AppError('VALIDATION_ERROR', 'Invoice header values must match its source receiver.');
            }
        }
        $sql = 'SELECT LINENO, PRODUCT_ID, DESCRIPTION, QUANTITY, UNITCOST, LINEAMOUNT
                FROM ME_PUR_DOC_ENTRY WHERE CNYID = :company_id AND DOC_ID = :doc_id ORDER BY LINENO, ID';
        $sourceLines = $tx->query($sql, ['company_id' => $actor->companyId, 'doc_id' => $invoice['SOURCE_DOC_ID']]);
        $lines = $tx->query($sql, ['company_id' => $actor->companyId, 'doc_id' => $id]);
        if ($lines === [] || count($lines) !== count($sourceLines)) {
            throw new AppError('VALIDATION_ERROR', 'Invoice lines must match every source receiver line.');
        }
        $total = 0.0;
        foreach ($lines as $index => $line) {
            $original = $sourceLines[$index];
            if ((int) $line['LINENO'] !== (int) $original['LINENO']
                || (int) $line['PRODUCT_ID'] !== (int) $original['PRODUCT_ID']
                || (float) $line['QUANTITY'] !== (float) $original['QUANTITY']
                || $line['DESCRIPTION'] !== $original['DESCRIPTION']
                || number_format((float) $line['UNITCOST'], 2, '.', '') !== number_format((float) $original['UNITCOST'], 2, '.', '')
                || number_format((float) $line['LINEAMOUNT'], 2, '.', '') !== number_format((float) $original['LINEAMOUNT'], 2, '.', '')) {
                throw new AppError('VALIDATION_ERROR', 'Invoice item values must match the source receiver.');
            }
            self::validLine($line['QUANTITY'], $line['UNITCOST']);
            $expected = round(Quantity::fromStored($line['QUANTITY']) * (float) $line['UNITCOST'], 2, PHP_ROUND_HALF_UP);
            if (number_format($expected, 2, '.', '') !== number_format((float) $line['LINEAMOUNT'], 2, '.', '')) {
                throw new AppError('VALIDATION_ERROR', 'Invoice line amounts must match quantity multiplied by unit cost.');
            }
            $total += (float) $line['LINEAMOUNT'];
        }
        if (!is_finite($total) || $total <= 0 || $total >= 1e18) {
            throw new AppError('VALIDATION_ERROR', 'Invoice total must be positive and within the supported amount.');
        }
    }

    private static function activeVendor(TransactionContext $tx, Actor $actor, string $vendorId): void
    {
        $rows = $tx->query(
            'SELECT STATUS FROM ME_PUR_VENDOR WHERE CNYID = :company_id AND ID = :id FOR UPDATE',
            ['company_id' => $actor->companyId, 'id' => $vendorId],
        );
        if ($rows === [] || strtolower((string) $rows[0]['STATUS']) !== 'active') {
            throw new AppError('CONFLICT', 'Select an active vendor before creating or submitting an order.', 409);
        }
    }

    private static function validLine(mixed $quantity, mixed $cost): void
    {
        $quantity = Quantity::fromStored($quantity);
        $amount = (float) $quantity * (float) $cost;
        if ((float) $quantity <= 0 || (float) $cost < 0 || !is_finite($amount) || $amount >= 1e36) {
            throw new AppError('VALIDATION_ERROR', 'Quantity must be positive and unit cost must be zero or more, within the supported range.');
        }
    }

    private static function requireProduct(TransactionContext $tx, Actor $actor, int $productId): void
    {
        $rows = $tx->query(
            'SELECT PRODUCT_ID, WAREHOUSE_ID FROM ME_INV_PRODUCTS
             WHERE COMPANY_ID = :company_id AND PRODUCT_ID = :product_id',
            ['company_id' => $actor->companyId, 'product_id' => $productId],
        );
        if ($rows === []) {
            throw new AppError('VALIDATION_ERROR', 'product_id must reference an existing inventory product in this company.');
        }
        if ($rows[0]['WAREHOUSE_ID'] !== self::WAREHOUSE) {
            throw new AppError('VALIDATION_ERROR', 'Purchasing supports warehouse W01 only.');
        }
    }

    private static function numberOrNull(mixed $value): int|float|null
    {
        return $value === null ? null : (0 + $value);
    }

    /** @param array<string, mixed> $entry */
    private static function insertEntry(TransactionContext $tx, Actor $actor, string $documentId, array $entry): void
    {
        $entry['quantity'] = Quantity::fromInput($entry['quantity']);
        self::validLine($entry['quantity'], $entry['unitcost']);
        self::requireProduct($tx, $actor, (int) $entry['product_id']);
        $entry['lineamount'] = number_format(round($entry['quantity'] * (float) $entry['unitcost'], 2, PHP_ROUND_HALF_UP), 2, '.', '');

        try {
            $tx->execute(
                'INSERT INTO ME_PUR_DOC_ENTRY
                    (CNYID, ID, DOC_ID, LINENO, PRODUCT_ID, DESCRIPTION, QUANTITY, UNITCOST, LINEAMOUNT,
                     NOTES, CREATEDBY, MODIFIEDBY, WHENCREATED, WHENMODIFIED)
                 VALUES
                    (:company_id, :id, :doc_id, :lineno, :product_id, :description, :quantity, :unitcost,
                     :lineamount, :notes, :created_by, :modified_by, SYSTIMESTAMP, SYSTIMESTAMP)',
                [
                    'company_id' => $actor->companyId, 'id' => bin2hex(random_bytes(16)),
                    'doc_id' => $documentId, 'lineno' => $entry['lineno'], 'product_id' => $entry['product_id'],
                    'description' => $entry['description'], 'quantity' => $entry['quantity'],
                    'unitcost' => $entry['unitcost'], 'lineamount' => $entry['lineamount'],
                    'notes' => $entry['notes'] ?? null, 'created_by' => $actor->id, 'modified_by' => $actor->id,
                ],
            );
        } catch (DatabaseError $error) {
            // The reference can disappear after the lookup; the FK remains authoritative.
            if ($error->oracleCode === 2291) {
                throw new AppError('VALIDATION_ERROR',
                    'The product or document reference no longer exists in this company. Reload and try again.');
            }
            throw $error;
        }
    }

    /** @param list<array<string, mixed>> $entries */
    private static function total(array $entries): string
    {
        $total = 0.0;
        foreach ($entries as $entry) {
            $total += (float) $entry['lineamount'];
        }
        return number_format(round($total, 2, PHP_ROUND_HALF_UP), 2, '.', '');
    }
}
