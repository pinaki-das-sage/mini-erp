<?php
declare(strict_types=1);

namespace MiniErp\sales\invoices;

use MiniErp\sales\SalesPermissions;
use MiniErp\sales\Validation;

use MiniErp\shared\AppError;
use MiniErp\shared\database\TransactionContext;
use MiniErp\shared\security\Actor;

/** Validates the complete intended Draft, including inherited Order lines and Inventory references. */
final readonly class InvoiceDraft
{
    public function __construct(private InvoiceStore $store, private \Closure $businessDate, private SalesPermissions $permissions) {}

    public function prepare(TransactionContext $tx, Actor $actor, array $input, ?array $saved = null): array
    {
        $oldSource = $saved === null ? null : $this->store->source($tx, $actor, $saved['RECORD#']);
        $sourceNumber = array_key_exists('source_order_number', $input)
            ? $input['source_order_number'] : ($oldSource['DOCUMENT_NUMBER'] ?? null);
        if ($sourceNumber !== null) {
            $this->permissions->requireDocument($actor, 'order', 'read');
            $sourceNumber = Validation::text($sourceNumber, 'Source Order Number', 20);
        }
        $source = $sourceNumber === null ? null : $this->store->header($tx, $actor, $sourceNumber, 'SALES_ORDER', false);
        // A stable order avoids source-swap deadlocks between two Invoice edits.
        $locks = [];
        foreach ([$oldSource, $source] as $row) {
            if ($row !== null) {
                $locks[(int) $row['RECORD#']] = $row['DOCUMENT_NUMBER'];
            }
        }
        ksort($locks, SORT_NUMERIC);
        foreach ($locks as $number) {
            $locked = $this->store->header($tx, $actor, $number, 'SALES_ORDER');
            if ($number === $sourceNumber) {
                $source = $locked;
            }
        }
        if ($source !== null) {
            if (!in_array($source['STATUS'], ['SUBMITTED', 'IN_PROGRESS', 'CLOSED'], true)) {
                throw new AppError('INVALID_STATE', 'Select a Submitted, In Progress or Closed Order, or leave Source blank for a standalone Invoice.', 409);
            }
            $claims = $tx->query('SELECT TARGET_DOCUMENT_RECORD# FROM ME_OE_SOURCE_LINKS WHERE CNY# = :company_id AND SOURCE_DOCUMENT_RECORD# = :id',
                ['company_id' => $actor->companyId, 'id' => $source['RECORD#']]);
            foreach ($claims as $claim) {
                if ((string) $claim['TARGET_DOCUMENT_RECORD#'] !== (string) ($saved['RECORD#'] ?? '')) {
                    throw new AppError('SOURCE_IN_USE', 'Selected Order is already used by another Invoice.', 409);
                }
            }
        }

        if ($source !== null) {
            $customerId = $source['CUSTOMER_RECORD#'];
        } elseif (array_key_exists('customer_code', $input)) {
            $code = Validation::text($input['customer_code'], 'Customer Code', 20);
            $customers = $tx->query('SELECT RECORD# FROM ME_OE_CUSTOMERS WHERE CNY# = :company_id AND CUSTOMER_CODE = :customer_code',
                ['company_id' => $actor->companyId, 'customer_code' => $code]);
            if ($customers === []) {
                throw new AppError('NOT_FOUND', 'Customer not found.', 404);
            }
            $customerId = $customers[0]['RECORD#'];
        } elseif ($saved !== null) {
            $customerId = $saved['CUSTOMER_RECORD#'];
        } else {
            throw new AppError('VALIDATION_ERROR', 'Enter Customer Code.');
        }
        $customer = $this->store->customer($tx, $actor, $customerId, true, true);
        $warehouse = Validation::text($source['WAREHOUSE_ID'] ?? $input['warehouse_id'] ?? $saved['WAREHOUSE_ID'] ?? '', 'Warehouse', 20);
        $terms = $source['PAYMENT_TERMS'] ?? $input['payment_terms'] ?? $saved['PAYMENT_TERMS'] ?? $customer['PAYMENT_TERMS'];
        if ($source !== null) {
            foreach (['customer_code' => $customer['CUSTOMER_CODE'], 'warehouse_id' => $warehouse, 'payment_terms' => $terms] as $field => $expected) {
                if (array_key_exists($field, $input) && (!is_string($input[$field]) || trim($input[$field]) !== $expected)) {
                    throw new AppError('VALIDATION_ERROR', 'Source-linked business values cannot be changed.');
                }
            }
        }
        if (!in_array($terms, ['DUE_ON_RECEIPT', 'NET_15', 'NET_30'], true)) {
            throw new AppError('VALIDATION_ERROR', 'Select valid Payment Terms.');
        }
        $date = Validation::date($input['invoice_date'] ?? $saved['SAVED_DATE'] ?? ($this->businessDate)(), 'Invoice Date');
        if ($source !== null) {
            Validation::dateRange($date, $source['SAVED_DATE'], 'Invoice Date', 'Order Date');
        }
        $days = ['DUE_ON_RECEIPT' => 0, 'NET_15' => 15, 'NET_30' => 30][$terms];
        $derivedDue = (new \DateTimeImmutable($date))->modify("+$days days")->format('Y-m-d');
        $keepDue = $saved !== null && $date === $saved['SAVED_DATE'] && $terms === $saved['PAYMENT_TERMS'];
        $due = Validation::date($input['due_date'] ?? ($keepDue ? $saved['SAVED_DUE_DATE'] : $derivedDue), 'Due Date');
        Validation::dateRange($due, $date, 'Due Date', 'Invoice Date');
        $notes = array_key_exists('notes', $input)
            ? Validation::text($input['notes'], 'Notes', 500, false) : ($saved['NOTES'] ?? '');
        $rawLines = $input['lines'] ?? null;
        if (!is_array($rawLines) || !array_is_list($rawLines) || $rawLines === []) {
            throw new AppError('VALIDATION_ERROR', 'Add at least one item line.');
        }
        $sourceLines = $source === null ? [] : $this->store->lines($tx, $actor, $source['RECORD#']);
        if ($source !== null && count($sourceLines) !== count($rawLines)) {
            throw new AppError('VALIDATION_ERROR', 'Linked Invoice lines must match the complete Order in source order.');
        }
        $existing = [];
        foreach ($saved === null ? [] : $this->store->lines($tx, $actor, $saved['RECORD#']) as $line) {
            $existing[$line['LINE_ID']] = $line;
        }
        $lines = [];
        $ids = [];
        $total = 0;
        foreach ($rawLines as $index => $line) {
            if ($line instanceof \stdClass) {
                $line = get_object_vars($line);
            }
            if (!is_array($line)) {
                throw new AppError('VALIDATION_ERROR', 'Each Invoice line must be an object.');
            }
            Validation::allowedFields($line, ['product_id', 'description', 'quantity', 'unit_price',
                ...($saved === null ? [] : ['line_id']), ...($source === null ? [] : ['source_line_id'])]);
            $money = InvoiceMoney::line($line['quantity'] ?? null, $line['unit_price'] ?? null);
            $productId = $line['product_id'] ?? null;
            if (!is_string($productId) || preg_match('/\A[1-9][0-9]{0,9}\z/D', $productId) !== 1) {
                throw new AppError('VALIDATION_ERROR', 'Product ID must be a positive numeric string such as "163".');
            }
            $description = Validation::text($line['description'] ?? '', 'Description', 200);
            $id = $line['line_id'] ?? null;
            if (array_key_exists('line_id', $line) && (!is_string($id) || !isset($existing[$id]) || isset($ids[$id]))) {
                throw new AppError('VALIDATION_ERROR', 'Line ID must identify a distinct existing line of this Invoice.');
            }
            if ($id !== null) {
                $ids[$id] = true;
            }
            $sourceId = null;
            if ($source !== null) {
                $origin = $sourceLines[$index];
                $sourceId = $origin['LINE_ID'];
                if (($line['source_line_id'] ?? null) !== $sourceId || $productId !== (string) $origin['PRODUCT_ID']
                    || $description !== ($origin['DESCRIPTION'] ?? '') || $money['quantity'] !== $origin['SAVED_QUANTITY']
                    || $money['unit_price'] !== $origin['SAVED_PRICE']) {
                    throw new AppError('VALIDATION_ERROR', 'Linked Invoice lines must match the complete Order in source order.');
                }
            }
            $total = InvoiceMoney::add($total, $money['cents']);
            $lines[] = ['id' => $id, 'source_id' => $sourceId, 'product_id' => $productId,
                'description' => $description, ...$money];
        }
        // Inventory owns products and warehouses. Lock distinct product IDs in a stable order.
        $products = array_values(array_unique(array_column($lines, 'product_id')));
        sort($products, SORT_NUMERIC);
        foreach ($products as $productId) {
            $rows = $tx->query('SELECT PRODUCT_ID, SKU, WAREHOUSE_ID, IS_ACTIVE FROM ME_INV_PRODUCTS
                WHERE COMPANY_ID = :company_id AND PRODUCT_ID = :product_id AND WAREHOUSE_ID = :warehouse FOR UPDATE',
                ['company_id' => $actor->companyId, 'product_id' => $productId, 'warehouse' => $warehouse]);
            if (count($rows) !== 1 || $rows[0]['IS_ACTIVE'] !== 'Y') {
                throw new AppError('VALIDATION_ERROR', 'Select a valid active Item in the selected Warehouse available to your company.');
            }
        }
        return ['customer_id' => $customerId, 'warehouse' => $warehouse, 'date' => $date, 'due' => $due,
            'terms' => $terms, 'notes' => $notes, 'shipping' => $source['SHIPPING_ADDRESS'] ?? null,
            'source_id' => $source['RECORD#'] ?? null, 'lines' => $lines, 'total' => $total, 'existing' => $existing];
    }
}
