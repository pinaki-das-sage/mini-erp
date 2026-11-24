<?php
declare(strict_types=1);

namespace MiniErp\purchasing\Model;

use MiniErp\shared\AppError;
use MiniErp\shared\database\Connection;
use MiniErp\shared\database\DatabaseError;
use MiniErp\shared\database\TransactionContext;
use MiniErp\shared\database\TransactionManager;
use MiniErp\shared\security\Actor;

final readonly class PurchasingDocumentModel
{
    private const DOCUMENT_SELECT = "D.CNYID AS DOC_CNYID, D.ID AS DOC_ID, D.VENDOR_ID, D.DOCNO,
        TO_CHAR(D.DOC_DATE, 'YYYY-MM-DD\"T\"HH24:MI:SS\"Z\"') AS DOC_DATE,
        D.VENDOR_REF, D.DELIVERY_ADDR, D.PAYMENT_TERMS, D.STATUS, D.ORDER_ID, D.RECEIVER_ID,
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
     *  @param list<array<string, mixed>> $entries
     *  @return array{id: string, cnyid: int, name: string, message: string}
     */
    public function create(Actor $actor, array $document, array $entries): array
    {
        $id = bin2hex(random_bytes(16));
        $typeField = match ($document['recordtype']) {
            'order' => 'order_id',
            'receiver' => 'receiver_id',
            'invoice' => 'invoice_id',
        };
        try {
            (new TransactionManager($this->connection))->run(
                function (TransactionContext $tx) use ($actor, $document, $entries, $id, $typeField): void {
                    $vendors = $tx->query(
                        'SELECT STATUS FROM ME_PUR_VENDOR WHERE CNYID = :company_id AND ID = :vendor_id',
                        ['company_id' => $actor->companyId, 'vendor_id' => $document['vendor_id']],
                    );
                    if ($vendors === []) {
                        throw new AppError('NOT_FOUND', 'Vendor not found in this company.', 404);
                    }
                    if ($document['recordtype'] === 'order' && strtolower((string) $vendors[0]['STATUS']) !== 'active') {
                        throw new AppError('INVALID_STATE', 'A purchase order requires an active vendor.', 409);
                    }
                    $total = self::total($entries);
                    $typeId = $document[$typeField] ?? $id;
                    $tx->execute(
                        'INSERT INTO ME_PUR_DOC
                            (CNYID, ID, VENDOR_ID, DOCNO, DOC_DATE, VENDOR_REF, DELIVERY_ADDR, PAYMENT_TERMS,
                             STATUS, ORDER_ID, RECEIVER_ID, INVOICE_ID, DUEDATE, RECORDTYPE, AMOUNTDUE, TOTAL,
                             CREATEDBY, MODIFIEDBY, WHENCREATED, WHENMODIFIED)
                         VALUES
                            (:company_id, :id, :vendor_id, :docno, TO_DATE(:doc_date, \'YYYY-MM-DD\'), :vendor_ref,
                             :delivery_addr, :payment_terms, :status, :order_id, :receiver_id, :invoice_id,
                             TO_DATE(:duedate, \'YYYY-MM-DD\'), :recordtype, :amountdue, :total, :created_by,
                             :modified_by, SYSTIMESTAMP, SYSTIMESTAMP)',
                        [
                            'company_id' => $actor->companyId, 'id' => $id,
                            'vendor_id' => $document['vendor_id'], 'docno' => $document['docno'],
                            'doc_date' => $document['doc_date'], 'vendor_ref' => $document['vendor_ref'],
                            'delivery_addr' => $document['delivery_addr'], 'payment_terms' => $document['payment_terms'],
                            'status' => $document['status'],
                            'order_id' => $document['recordtype'] === 'order' ? $typeId : null,
                            'receiver_id' => $document['recordtype'] === 'receiver' ? $typeId : null,
                            'invoice_id' => $document['recordtype'] === 'invoice' ? $typeId : null,
                            'duedate' => $document['duedate'], 'recordtype' => $document['recordtype'],
                            'amountdue' => $document['recordtype'] === 'invoice' ? $total : null,
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
                throw new AppError('CONFLICT', 'The document number or line number already exists.', 409);
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

    /** @param array<string, mixed> $changes
     *  @return array<string, mixed>
     */
    public function update(Actor $actor, string $id, array $changes): array
    {
        try {
            return (new TransactionManager($this->connection))->run(
                function (TransactionContext $tx) use ($actor, $id, $changes): array {
                    $rows = $tx->query(
                        'SELECT RECORDTYPE, STATUS FROM ME_PUR_DOC WHERE CNYID = :company_id AND ID = :id FOR UPDATE',
                        ['company_id' => $actor->companyId, 'id' => $id],
                    );
                    if ($rows === []) {
                        throw new AppError('NOT_FOUND', 'Purchasing document not found.', 404);
                    }
                    $type = strtolower((string) $rows[0]['RECORDTYPE']);
                    $sets = [];
                    $bindings = ['company_id' => $actor->companyId, 'id' => $id, 'modified_by' => $actor->id];
                    foreach (['status', 'payment_terms'] as $field) {
                        if (array_key_exists($field, $changes)) {
                            $sets[] = strtoupper($field) . ' = :' . $field;
                            $bindings[$field] = $changes[$field];
                        }
                    }
                    if ($type === 'invoice' && array_key_exists('payment_terms', $changes)) {
                        $sets[] = "DUEDATE = CASE :payment_terms
                            WHEN 'due_on_receipt' THEN DOC_DATE
                            WHEN 'net_15' THEN DOC_DATE + 15
                            ELSE DOC_DATE + 30 END";
                    }
                    if ($sets !== []) {
                        $sets[] = 'MODIFIEDBY = :modified_by';
                        $sets[] = 'WHENMODIFIED = SYSTIMESTAMP';
                        $tx->execute(
                            'UPDATE ME_PUR_DOC SET ' . implode(', ', $sets) . ' WHERE CNYID = :company_id AND ID = :id',
                            $bindings,
                        );
                    }
                    $changedEntries = [];
                    foreach ($changes['entries'] ?? [] as $entry) {
                        if (isset($entry['id'])) {
                            $saved = $tx->query(
                                'SELECT LINENO, DESCRIPTION, NOTES FROM ME_PUR_DOC_ENTRY
                                 WHERE CNYID = :company_id AND DOC_ID = :doc_id AND ID = :id FOR UPDATE',
                                ['company_id' => $actor->companyId, 'doc_id' => $id, 'id' => $entry['id']],
                            );
                            if ($saved === []) {
                                throw new AppError('NOT_FOUND', 'Document entry not found in this document.', 404);
                            }
                            if (isset($entry['lineno']) && (int) $entry['lineno'] !== (int) $saved[0]['LINENO']) {
                                throw new AppError('VALIDATION_ERROR', 'lineno must match the saved document line.');
                            }
                            $tx->execute(
                                'UPDATE ME_PUR_DOC_ENTRY
                                 SET DESCRIPTION = :description, NOTES = :notes, MODIFIEDBY = :modified_by,
                                     WHENMODIFIED = SYSTIMESTAMP
                                 WHERE CNYID = :company_id AND DOC_ID = :doc_id AND ID = :id',
                                [
                                    'description' => $entry['description'] ?? $saved[0]['DESCRIPTION'] ?? null,
                                    'notes' => $entry['notes'] ?? $saved[0]['NOTES'] ?? null,
                                    'modified_by' => $actor->id, 'company_id' => $actor->companyId,
                                    'doc_id' => $id, 'id' => $entry['id'],
                                ],
                            );
                        } else {
                            if (isset($entry['doc_id']) && $entry['doc_id'] !== $id) {
                                throw new AppError('VALIDATION_ERROR', 'entry doc_id must match this document.');
                            }
                            self::insertEntry($tx, $actor, $id, $entry);
                        }
                        $changedEntries[] = $entry;
                    }
                    if ($changedEntries !== []) {
                        $totals = $tx->query(
                            'SELECT NVL(SUM(LINEAMOUNT), 0) AS TOTAL FROM ME_PUR_DOC_ENTRY
                             WHERE CNYID = :company_id AND DOC_ID = :doc_id',
                            ['company_id' => $actor->companyId, 'doc_id' => $id],
                        );
                        $total = number_format((float) $totals[0]['TOTAL'], 2, '.', '');
                        $tx->execute(
                            "UPDATE ME_PUR_DOC
                             SET TOTAL = :total,
                                 AMOUNTDUE = CASE WHEN LOWER(RECORDTYPE) = 'invoice' THEN :amountdue ELSE AMOUNTDUE END,
                                 MODIFIEDBY = :modified_by, WHENMODIFIED = SYSTIMESTAMP
                             WHERE CNYID = :company_id AND ID = :id",
                            [
                                'total' => $total, 'amountdue' => $total,
                                'modified_by' => $actor->id, 'company_id' => $actor->companyId, 'id' => $id,
                            ],
                        );
                    }
                    $result = [];
                    foreach (['payment_terms', 'status'] as $field) {
                        if (array_key_exists($field, $changes)) {
                            $result[$field] = $changes[$field];
                        }
                    }
                    if ($changedEntries !== []) {
                        $result['entries'] = $changedEntries;
                    }
                    return $result;
                },
            );
        } catch (DatabaseError $error) {
            if ($error->oracleCode === 1) {
                throw new AppError('CONFLICT', 'The document line number already exists.', 409);
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
            'product_id' => $row['PRODUCT_ID'],
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
            'product_id' => $row['PRODUCT_ID'],
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

    private static function numberOrNull(mixed $value): int|float|null
    {
        return $value === null ? null : (0 + $value);
    }

    /** @param array<string, mixed> $entry */
    private static function insertEntry(TransactionContext $tx, Actor $actor, string $documentId, array $entry): void
    {
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
