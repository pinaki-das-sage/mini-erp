<?php
declare(strict_types=1);

namespace MiniErp\sales\invoices;

use MiniErp\sales\SalesPermissions;
use MiniErp\shared\AppError;
use MiniErp\shared\database\Connection;
use MiniErp\shared\database\TransactionContext;
use MiniErp\shared\security\Actor;
use MiniErp\shared\security\Authorization;

/** Internal Order integration only; public Invoice APIs remain owned by the Invoice backend.
 * Caller holds Quote -> Order locks (and Customer on Submit) and owns commit/rollback, replay and audit.
 * No Inventory mutation or Customer debt change occurs for a Draft Invoice.
 */
final readonly class OrderInvoiceService
{
    public function __construct(private Connection $connection) {}

    public function createDraftFromSubmittedOrder(TransactionContext $tx, Actor $actor, string $orderId): string
    {
        $this->permissions()->requireDocument($actor, 'invoice', 'create');
        $order = $this->header($tx, $actor, $orderId, 'SALES_ORDER');
        if ($order['STATUS'] !== 'SUBMITTED' || $this->links($tx, $actor, $orderId) !== []) {
            throw new AppError('INVALID_STATE', 'Invoice creation requires an unused Submitted Order.', 409);
        }
        $days = match ($order['PAYMENT_TERMS']) {
            'DUE_ON_RECEIPT' => 0, 'NET_15' => 15, 'NET_30' => 30,
            default => throw new AppError('INVALID_STATE', 'Order payment terms are invalid.', 409),
        };
        // Use the saved Order date consistently, including future-dated Orders.
        $due = (new \DateTimeImmutable($order['DATE_TEXT']))->modify("+$days days")->format('Y-m-d');
        $next = $tx->query('SELECT ME_OE_INVOICE_NO_SEQ.NEXTVAL AS NEXT_NUMBER FROM DUAL')[0]['NEXT_NUMBER'] ?? null;
        if (!preg_match('/\A[1-9][0-9]{0,14}\z/D', (string) $next)) {
            throw new AppError('NUMBERING_UNAVAILABLE', 'Invoice numbering is unavailable.', 503);
        }
        $number = 'SI-' . str_pad((string) $next, 15, '0', STR_PAD_LEFT);
        $tx->execute("INSERT INTO ME_OE_DOCUMENTS
            (CNY#, DOCUMENT_TYPE, DOCUMENT_NUMBER, CUSTOMER_RECORD#, WAREHOUSE_ID, DOCUMENT_DATE,
             DUE_DATE, SHIPPING_ADDRESS, PAYMENT_TERMS, NOTES, STATUS, VERSION)
            VALUES (:company_id, 'SALES_INVOICE', :document_number, :customer_record, :warehouse_id,
             TO_DATE(:order_date, 'YYYY-MM-DD'), TO_DATE(:due_date, 'YYYY-MM-DD'), :shipping_address,
             :payment_terms, :notes, 'DRAFT', 1)",
            ['company_id' => $actor->companyId, 'document_number' => $number,
                'customer_record' => $order['CUSTOMER_RECORD#'], 'warehouse_id' => $order['WAREHOUSE_ID'],
                'order_date' => $order['DATE_TEXT'], 'due_date' => $due, 'shipping_address' => $order['SHIPPING_ADDRESS'],
                'payment_terms' => $order['PAYMENT_TERMS'], 'notes' => $order['NOTES'] ?? '']);
        $invoice = $tx->query('SELECT RECORD# FROM ME_OE_DOCUMENTS WHERE CNY# = :company_id
            AND DOCUMENT_TYPE = :document_type AND DOCUMENT_NUMBER = :document_number',
            ['company_id' => $actor->companyId, 'document_type' => 'SALES_INVOICE', 'document_number' => $number]);
        if (count($invoice) !== 1) { throw new AppError('INVOICE_UNAVAILABLE', 'Draft Invoice could not be saved.', 503); }
        $invoiceId = (string) $invoice[0]['RECORD#'];
        $lines = $tx->query("SELECT L.*, TO_CHAR(QUANTITY, 'FM999999999') AS QUANTITY_TEXT,
            TO_CHAR(UNIT_PRICE, 'FM9999999990D00', 'NLS_NUMERIC_CHARACTERS=''.,''') AS PRICE_TEXT
            FROM ME_OE_DOCUMENT_LINES L WHERE CNY# = :company_id AND DOCUMENT_RECORD# = :id ORDER BY LINE_NUMBER",
            ['company_id' => $actor->companyId, 'id' => $orderId]);
        if ($lines === []) { throw new AppError('INVALID_STATE', 'Order must contain saved lines.', 409); }
        foreach ($lines as $line) {
            $tx->execute("INSERT INTO ME_OE_DOCUMENT_LINES
                (CNY#, DOCUMENT_RECORD#, LINE_NUMBER, PRODUCT_ID, DESCRIPTION, QUANTITY, UNIT_PRICE, SOURCE_LINE_RECORD#)
                VALUES (:company_id, :id, :line_number, TO_NUMBER(:product_id), :description, TO_NUMBER(:quantity),
                TO_NUMBER(:unit_price, '9999999999D99', 'NLS_NUMERIC_CHARACTERS=''.,'''), :source_line_id)",
                ['company_id' => $actor->companyId, 'id' => $invoiceId, 'line_number' => $line['LINE_NUMBER'],
                    'product_id' => (string) $line['PRODUCT_ID'], 'description' => $line['DESCRIPTION'] ?? '',
                    'quantity' => $line['QUANTITY_TEXT'], 'unit_price' => $line['PRICE_TEXT'],
                    'source_line_id' => (string) $line['RECORD#']]);
        }
        $tx->execute('INSERT INTO ME_OE_SOURCE_LINKS (CNY#, SOURCE_DOCUMENT_RECORD#, TARGET_DOCUMENT_RECORD#)
            VALUES (:company_id, :source_id, :target_id)',
            ['company_id' => $actor->companyId, 'source_id' => $orderId, 'target_id' => $invoiceId]);
        return $number;
    }

    public function canReturnToDraft(TransactionContext $tx, Actor $actor, string $orderId): bool
    {
        try { $this->returnableInvoice($tx, $actor, $orderId, false); return true; }
        catch (AppError $error) {
            if (in_array($error->status, [403, 404, 409], true)) { return false; }
            throw $error;
        }
    }

    public function deleteDraftForOrderReturn(TransactionContext $tx, Actor $actor, string $orderId): void
    {
        $invoice = $this->returnableInvoice($tx, $actor, $orderId, true);
        // Orders submitted before automatic Invoice creation can still release their reservations.
        if ($invoice === null) { return; }
        $binds = ['company_id' => $actor->companyId, 'id' => $invoice['RECORD#']];
        $tx->execute('DELETE FROM ME_OE_SOURCE_LINKS WHERE CNY# = :company_id AND TARGET_DOCUMENT_RECORD# = :target_id',
            ['company_id' => $actor->companyId, 'target_id' => $invoice['RECORD#']]);
        $tx->execute('DELETE FROM ME_OE_DOCUMENT_LINES WHERE CNY# = :company_id AND DOCUMENT_RECORD# = :id', $binds);
        if ($tx->execute("DELETE FROM ME_OE_DOCUMENTS WHERE CNY# = :company_id AND RECORD# = :id
            AND DOCUMENT_TYPE = 'SALES_INVOICE' AND STATUS = 'DRAFT'", $binds) !== 1) {
            throw new AppError('INVALID_STATE', 'Draft Invoice could not be removed.', 409);
        }
    }

    private function returnableInvoice(TransactionContext $tx, Actor $actor, string $orderId, bool $lock): ?array
    {
        $links = $this->links($tx, $actor, $orderId);
        if ($links === []) { return null; }
        if (count($links) !== 1) { throw new AppError('INVALID_STATE', 'Order has unexpected downstream documents.', 409); }
        $this->permissions()->requireDocument($actor, 'invoice', 'delete');
        $invoice = $this->header($tx, $actor, (string) $links[0]['TARGET_DOCUMENT_RECORD#'], 'SALES_INVOICE', $lock);
        $binds = ['company_id' => $actor->companyId, 'id' => $invoice['RECORD#']];
        if ($invoice['STATUS'] !== 'DRAFT' || $this->links($tx, $actor, (string) $invoice['RECORD#']) !== [] ||
            $tx->query('SELECT RECORD# FROM ME_OE_PAYMENTS WHERE CNY# = :company_id AND INVOICE_RECORD# = :id FETCH FIRST 1 ROWS ONLY', $binds) !== [] ||
            $tx->query('SELECT X.RECORD# FROM ME_OE_DOCUMENT_LINES X WHERE X.CNY# = :company_id
                AND X.SOURCE_LINE_RECORD# IN (SELECT L.RECORD# FROM ME_OE_DOCUMENT_LINES L
                    WHERE L.CNY# = X.CNY# AND L.DOCUMENT_RECORD# = :id) FETCH FIRST 1 ROWS ONLY', $binds) !== []) {
            throw new AppError('INVALID_STATE', 'Move to Draft requires a Draft Invoice without payments or downstream references.', 409);
        }
        return $invoice;
    }

    private function header(TransactionContext $tx, Actor $actor, string $id, string $type, bool $lock = true): array
    {
        $rows = $tx->query("SELECT D.*, TO_CHAR(DOCUMENT_DATE, 'YYYY-MM-DD') AS DATE_TEXT FROM ME_OE_DOCUMENTS D
            WHERE CNY# = :company_id AND RECORD# = :id AND DOCUMENT_TYPE = :document_type" . ($lock ? ' FOR UPDATE' : ''),
            ['company_id' => $actor->companyId, 'id' => $id, 'document_type' => $type]);
        if (count($rows) !== 1) { throw new AppError('INVALID_STATE', 'Order or linked Invoice is unavailable.', 409); }
        return $rows[0];
    }

    private function links(TransactionContext $tx, Actor $actor, string $id): array
    {
        return $tx->query('SELECT TARGET_DOCUMENT_RECORD# FROM ME_OE_SOURCE_LINKS
            WHERE CNY# = :company_id AND SOURCE_DOCUMENT_RECORD# = :source_id',
            ['company_id' => $actor->companyId, 'source_id' => $id]);
    }

    private function permissions(): SalesPermissions { return new SalesPermissions(new Authorization($this->connection)); }
}
