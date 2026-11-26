<?php
declare(strict_types=1);

namespace MiniErp\Tests\unit\sales\fixtures;

use MiniErp\sales\invoices\InvoiceMoney;
use MiniErp\shared\database\Connection;
use MiniErp\shared\database\DatabaseError;

/** Behavioral double: sequence/identity allocation survives rollback, stored effects do not. */
final class InvoiceMemoryConnection implements Connection
{
    public array $documents = [];
    public array $lines = [];
    public array $links = [];
    public array $actions = [];
    public array $audit = [];
    public array $payments = [];
    public array $customers = [1 => ['RECORD#' => 1, 'CNY#' => 7, 'CUSTOMER_CODE' => 'CUST-0001',
        'IS_ACTIVE' => 'T', 'PAYMENT_TERMS' => 'NET_30', 'BALANCE' => '0.00']];
    public array $products = [163 => ['COMPANY_ID' => 7, 'PRODUCT_ID' => 163, 'SKU' => 'SKU-A', 'WAREHOUSE_ID' => 'W01', 'IS_ACTIVE' => 'Y'],
        164 => ['COMPANY_ID' => 7, 'PRODUCT_ID' => 164, 'SKU' => 'SKU-B', 'WAREHOUSE_ID' => 'W01', 'IS_ACTIVE' => 'Y']];
    public int $nextNumber = 1;
    public int $nextPaymentNumber = 1;
    public array $grants = [];
    public bool $forbidInventoryReads = false;
    public bool $failPayment = false;
    public bool $failBalance = false;
    public bool $failVersion = false;
    public array $lockedInvoices = [];
    public bool $failAudit = false;
    public int $failLine = 0;
    private int $nextHeader = 1;
    private int $nextLine = 1000;
    private int $insertedLines = 0;
    private ?array $saved = null;

    public function begin(): void
    {
        if ($this->saved !== null) { throw new \LogicException('Nested transaction.'); }
        $this->saved = [$this->documents, $this->lines, $this->links, $this->actions, $this->audit, $this->customers, $this->payments];
        $this->lockedInvoices = [];
        $this->insertedLines = 0;
    }
    public function commit(): void { $this->saved = null; }
    public function rollback(): void
    {
        [$this->documents, $this->lines, $this->links, $this->actions, $this->audit, $this->customers, $this->payments] = $this->saved;
        $this->saved = null;
    }
    public function executeSchema(string $sql): void { throw new \LogicException('No DDL during Invoice requests.'); }

    public function document(int $id, string $number, string $type = 'SALES_INVOICE', string $status = 'DRAFT'): array
    {
        return ['RECORD#' => $id, 'CNY#' => 7, 'DOCUMENT_NUMBER' => $number, 'DOCUMENT_TYPE' => $type, 'STATUS' => $status,
            'VERSION' => 1, 'CUSTOMER_RECORD#' => 1, 'WAREHOUSE_ID' => 'W01', 'SAVED_DATE' => '2026-11-24',
            'SAVED_DUE_DATE' => '2026-12-24', 'PAYMENT_TERMS' => 'NET_30', 'NOTES' => '', 'SHIPPING_ADDRESS' => null];
    }
    public function line(int $id, int $document, int $position, int $product, string $description, string $quantity, string $price, ?string $source = null): array
    {
        return ['RECORD#' => $id, 'CNY#' => 7, 'DOCUMENT_RECORD#' => $document, 'LINE_NUMBER' => $position,
            'PRODUCT_ID' => $product, 'DESCRIPTION' => $description, 'SAVED_QUANTITY' => $quantity, 'SAVED_PRICE' => $price,
            'LINE_ID' => (string) $id, 'SOURCE_LINE_ID' => $source];
    }

    public function query(string $sql, array $p = []): array
    {
        if ($this->forbidInventoryReads && (str_contains($sql, 'ME_INV_') || str_contains($sql, 'ME_OE_SOURCE_LINKS'))) {
            throw new \LogicException('Payment must not call Inventory or revalidate source eligibility.');
        }
        if (array_key_exists('number', $p)) {
            throw new \LogicException('NUMBER is an Oracle reserved bind name (ORA-01745).');
        }
        if (str_contains($sql, 'FROM ME_API_TOKENS')) {
            return [['USER_ID' => 'admin', 'COMPANY_ID' => 7, 'SECURITY_VERSION' => 1, 'EXPIRES_AT' => time() + 3600, 'REVOKED_AT' => null]];
        }
        if (str_contains($sql, 'FROM ME_USERS U JOIN ME_COMPANY')) {
            return [['ID' => 'admin', 'USERNAME' => 'admin', 'COMPANY_ID' => 7, 'IS_ADMIN' => 1,
                'SECURITY_VERSION' => 1, 'MUST_CHANGE_PASSWORD' => 0, 'COMPANY_NAME' => 'Test']];
        }
        if (str_contains($sql, 'FROM ME_USER_ROLES')) { return []; }
        if (str_contains($sql, 'FROM ME_OBJECTS')) {
            $payment = $p['object'] === 'sales.payments';
            return [['ACTIVE' => 1, 'ALLOW_READ' => 1, 'ALLOW_CREATE' => $payment ? 0 : 1,
                'ALLOW_UPDATE' => $payment ? 0 : 1, 'ALLOW_DELETE' => $payment ? 0 : 1]];
        }
        if (str_contains($sql, 'FROM ME_OBJECT_ACTIONS')) { return [['ACTION_CODE' => $p['operation']]]; }
        if (str_contains($sql, 'FROM ME_USER_PERMISSIONS')) {
            $key = $p['company_id'] . ':' . $p['user_id'] . ':' . $p['object'] . ':' . $p['operation'];
            return in_array($key, $this->grants, true) ? [['USER_ID' => $p['user_id']]] : [];
        }
        if (str_contains($sql, 'ME_OE_INVOICE_NO_SEQ.NEXTVAL')) { return [['NUMBER_VALUE' => (string) $this->nextNumber++]]; }
        if (str_contains($sql, 'ME_OE_PAYMENT_NO_SEQ.NEXTVAL')) { return [['SEQUENCE_VALUE' => (string) $this->nextPaymentNumber++]]; }
        if (!isset($p['company_id'])) { throw new \LogicException('Unscoped read: ' . $sql); }
        $company = $p['company_id'];
        if (str_contains($sql, 'FROM ME_ACTIONS')) {
            $row = $this->actions[$company . ':' . $p['reference']] ?? null;
            return $row === null ? [] : [$row];
        }
        if (str_contains($sql, 'AS ORDER_NUMBER') && str_contains($sql, 'NOT EXISTS')) {
            return [];
        }
        if (str_contains($sql, 'FROM ME_OE_DOCUMENTS D WHERE')) {
            self::requireLock($sql);
            $rows = array_values(array_filter($this->documents, static fn ($r) => $r['CNY#'] === $company
                && $r['DOCUMENT_NUMBER'] === $p['document_number'] && $r['DOCUMENT_TYPE'] === $p['document_type']));
            if (str_contains($sql, 'FOR UPDATE')) {
                foreach ($rows as $row) { $this->lockedInvoices[] = (int) $row['RECORD#']; }
            }
            return $rows;
        }
        if (str_contains($sql, 'FROM ME_OE_CUSTOMERS')) {
            return array_values(array_filter($this->customers, static fn ($r) => $r['CNY#'] === $company
                && (!isset($p['id']) || (string) $r['RECORD#'] === (string) $p['id'])
                && (!isset($p['customer_code']) || $r['CUSTOMER_CODE'] === $p['customer_code'])));
        }
        if (str_contains($sql, 'FROM ME_INV_PRODUCTS')) {
            return array_values(array_filter($this->products, static fn ($r) => $r['COMPANY_ID'] === $company && (string) $r['PRODUCT_ID'] === (string) $p['product_id'] && $r['WAREHOUSE_ID'] === $p['warehouse']));
        }
        if (str_contains($sql, 'FROM ME_OE_DOCUMENT_LINES L') && str_contains($sql, 'ORDER BY L.LINE_NUMBER')) {
            $rows = array_values(array_filter($this->lines, static fn ($r) => $r['CNY#'] === $company && (string) $r['DOCUMENT_RECORD#'] === (string) $p['id']));
            usort($rows, static fn ($a, $b) => $a['LINE_NUMBER'] <=> $b['LINE_NUMBER']);
            return $rows;
        }
        if (str_contains($sql, 'AS LINE_COUNT')) {
            if (!in_array((int) $p['invoice_id'], $this->lockedInvoices, true)) {
                throw new \LogicException('Read authoritative payment totals under an Invoice lock.');
            }
            $lines = array_filter($this->lines, static fn ($r) => $r['CNY#'] === $company && (string) $r['DOCUMENT_RECORD#'] === (string) $p['invoice_id']);
            $total = 0;
            foreach ($lines as $line) { $total = InvoiceMoney::add($total, InvoiceMoney::line($line['SAVED_QUANTITY'], $line['SAVED_PRICE'])['cents']); }
            return [['LINE_COUNT' => count($lines), 'DOCUMENT_TOTAL' => InvoiceMoney::format($total)]];
        }
        if (str_contains($sql, 'FROM ME_OE_PAYMENTS P JOIN ME_ACTIONS A')) {
            $rows = [];
            foreach ($this->payments as $payment) {
                if ($payment['CNY#'] !== $company || (string) $payment['INVOICE_RECORD#'] !== (string) $p['invoice_id']
                    || (isset($p['action_reference']) && $payment['ACTION_REFERENCE'] !== $p['action_reference'])) { continue; }
                $action = $this->actions[$company . ':' . $payment['ACTION_REFERENCE']];
                $rows[] = [...$payment, 'CREATED_BY' => $action['ACTOR_ID'], 'CREATED_AT' => $action['CREATED_AT']];
            }
            usort($rows, static fn ($a, $b) => [$a['CREATED_AT'], $a['PAYMENT_NUMBER']] <=> [$b['CREATED_AT'], $b['PAYMENT_NUMBER']]);
            return isset($p['offset_rows']) ? array_slice($rows, $p['offset_rows'], $p['limit_rows']) : $rows;
        }
        if (str_contains($sql, 'FROM ME_OE_PAYMENTS')) {
            $rows = array_filter($this->payments, static fn ($r) => $r['CNY#'] === $company && (string) $r['INVOICE_RECORD#'] === (string) $p['id']);
            $total = 0;
            foreach ($rows as $row) { $total += InvoiceMoney::cents($row['PAYMENT_AMOUNT']); }
            return [['PAYMENT_COUNT' => count($rows), 'TOTAL_PAID' => InvoiceMoney::format($total)]];
        }
        if (str_contains($sql, 'FROM ME_OE_SOURCE_LINKS L')) {
            foreach ($this->links as $link) {
                if ($link['CNY#'] === $company && (string) $link['TARGET_DOCUMENT_RECORD#'] === (string) $p['id']) {
                    $row = $this->documents[$link['SOURCE_DOCUMENT_RECORD#']];
                    return [['RECORD#' => $row['RECORD#'], 'DOCUMENT_NUMBER' => $row['DOCUMENT_NUMBER']]];
                }
            }
            return [];
        }
        if (str_contains($sql, 'FROM ME_OE_SOURCE_LINKS')) {
            return array_values(array_filter($this->links, static fn ($r) => $r['CNY#'] === $company && (string) $r['SOURCE_DOCUMENT_RECORD#'] === (string) $p['id']));
        }
        if (str_contains($sql, 'FROM ME_OE_DOCUMENT_LINES X')) {
            $ids = array_column(array_filter($this->lines, static fn ($r) => $r['CNY#'] === $company && (string) $r['DOCUMENT_RECORD#'] === (string) $p['id']), 'LINE_ID');
            return array_values(array_filter($this->lines, static fn ($r) => $r['CNY#'] === $company && in_array($r['SOURCE_LINE_ID'], $ids, true)));
        }
        throw new \LogicException('Unexpected query: ' . $sql);
    }

    private static function requireLock(string $sql): void
    {
        // Source resolution may be unlocked before all source IDs are sorted and locked.
        if (!str_contains($sql, 'DOCUMENT_TYPE = :document_type')) { throw new \LogicException('Document type missing.'); }
    }

    public function execute(string $sql, array $p = []): int
    {
        if (array_key_exists('number', $p)) {
            throw new \LogicException('NUMBER is an Oracle reserved bind name (ORA-01745).');
        }
        if ($this->saved === null || !isset($p['company_id'])) { throw new \LogicException('Unscoped/nontransactional write.'); }
        $company = $p['company_id'];
        $key = $company . ':' . ($p['reference'] ?? '');
        if (str_starts_with($sql, 'INSERT INTO ME_ACTIONS')) {
            if (isset($this->actions[$key])) { throw new DatabaseError(1); }
            $this->actions[$key] = ['ACTOR_ID' => $p['actor'], 'PAYLOAD_HASH' => $p['hash'], 'RESULT_JSON' => null,
                'CREATED_AT' => sprintf('2026-11-25T10:00:00.%06dZ', count($this->actions))];
        } elseif (str_starts_with($sql, 'UPDATE ME_ACTIONS')) {
            $this->actions[$key]['RESULT_JSON'] = $p['result'];
        } elseif (str_starts_with($sql, 'INSERT INTO ME_AUDIT')) {
            if ($this->failAudit) { throw new DatabaseError(3113); }
            $this->audit[] = $p;
        } elseif (str_starts_with($sql, 'INSERT INTO ME_OE_DOCUMENTS')) {
            $id = $this->nextHeader++;
            $this->documents[$id] = [...$this->document($id, $p['document_number']), 'CNY#' => $company,
                'CUSTOMER_RECORD#' => $p['customer_id'], 'WAREHOUSE_ID' => $p['warehouse'], 'SAVED_DATE' => $p['invoice_date'],
                'SAVED_DUE_DATE' => $p['due_date'], 'PAYMENT_TERMS' => $p['terms'], 'NOTES' => $p['notes'], 'SHIPPING_ADDRESS' => $p['shipping']];
        } elseif (str_starts_with($sql, 'UPDATE ME_OE_DOCUMENTS') || str_starts_with($sql, 'DELETE FROM ME_OE_DOCUMENTS')) {
            $id = $p['id'] ?? $p['invoice_id'];
            $row = $this->documents[$id] ?? null;
            if ($row === null || $row['CNY#'] !== $company || $row['VERSION'] !== ($p['version'] ?? $p['expected_version']) || $row['DOCUMENT_TYPE'] !== 'SALES_INVOICE') { return 0; }
            if (isset($p['expected_version']) && ($this->failVersion || $row['STATUS'] !== 'SUBMITTED')) { return 0; }
            if (str_starts_with($sql, 'DELETE')) { unset($this->documents[$id]); return 1; }
            if (isset($p['customer_id'])) {
                $row = [...$row, 'CUSTOMER_RECORD#' => $p['customer_id'], 'WAREHOUSE_ID' => $p['warehouse'], 'SAVED_DATE' => $p['invoice_date'],
                    'SAVED_DUE_DATE' => $p['due_date'], 'PAYMENT_TERMS' => $p['terms'], 'NOTES' => $p['notes'], 'SHIPPING_ADDRESS' => $p['shipping']];
            } elseif (isset($p['invoice_date'])) {
                $row = [...$row, 'SAVED_DATE' => $p['invoice_date'], 'SAVED_DUE_DATE' => $p['due_date'], 'NOTES' => $p['notes']];
            } elseif (isset($p['status'])) { $row['STATUS'] = $p['status']; }
            $row['VERSION']++;
            $this->documents[$id] = $row;
        } elseif (str_starts_with($sql, 'UPDATE ME_OE_CUSTOMERS')) {
            if ($this->failBalance) { throw new DatabaseError(3113); }
            if ($this->customers[$p['customer_id']]['CNY#'] !== $company) { return 0; }
            $this->customers[$p['customer_id']]['BALANCE'] = $p['balance'];
        } elseif (str_starts_with($sql, 'INSERT INTO ME_OE_PAYMENTS')) {
            if ($this->failPayment) { throw new DatabaseError(3113); }
            if (!in_array((int) $p['invoice_id'], $this->lockedInvoices, true)) { throw new \LogicException('Payment insert requires the Invoice lock.'); }
            foreach ($this->payments as $payment) {
                if ($payment['CNY#'] === $company && ($payment['PAYMENT_NUMBER'] === $p['payment_number'] || $payment['ACTION_REFERENCE'] === $p['action_reference'])) { throw new DatabaseError(1); }
            }
            $this->payments[] = ['CNY#' => $company, 'INVOICE_RECORD#' => $p['invoice_id'], 'PAYMENT_NUMBER' => $p['payment_number'],
                'PAYMENT_AMOUNT' => $p['payment_amount'], 'PAYMENT_DATE' => $p['payment_date'],
                'PAYMENT_REFERENCE' => $p['payment_reference'], 'ACTION_REFERENCE' => $p['action_reference']];
        } elseif (str_starts_with($sql, 'DELETE FROM ME_OE_SOURCE_LINKS')) {
            $this->links = array_filter($this->links, static fn ($r) => $r['CNY#'] !== $company || (string) $r['TARGET_DOCUMENT_RECORD#'] !== (string) $p['id']);
        } elseif (str_starts_with($sql, 'INSERT INTO ME_OE_SOURCE_LINKS')) {
            foreach ($this->links as $r) {
                if ($r['CNY#'] === $company && ((string) $r['SOURCE_DOCUMENT_RECORD#'] === (string) $p['source_id'] || (string) $r['TARGET_DOCUMENT_RECORD#'] === (string) $p['target_id'])) { throw new DatabaseError(1); }
            }
            $this->links[] = ['CNY#' => $company, 'SOURCE_DOCUMENT_RECORD#' => $p['source_id'], 'TARGET_DOCUMENT_RECORD#' => $p['target_id']];
        } elseif (str_starts_with($sql, 'DELETE FROM ME_OE_DOCUMENT_LINES')) {
            $document = $p['document_id'] ?? $p['id'];
            $this->lines = array_filter($this->lines, static fn ($r) => $r['CNY#'] !== $company || (string) $r['DOCUMENT_RECORD#'] !== (string) $document
                || (isset($p['line_id']) && $r['LINE_ID'] !== $p['line_id']));
        } elseif (isset($p['position_offset'])) {
            foreach ($this->lines as &$line) {
                if ($line['CNY#'] === $company && (string) $line['DOCUMENT_RECORD#'] === (string) $p['document_id']) { $line['LINE_NUMBER'] += $p['position_offset']; }
            }
            unset($line);
        } elseif (str_starts_with($sql, 'INSERT INTO ME_OE_DOCUMENT_LINES') || str_starts_with($sql, 'UPDATE ME_OE_DOCUMENT_LINES')) {
            if (str_starts_with($sql, 'INSERT') && ++$this->insertedLines === $this->failLine) { throw new DatabaseError(3113); }
            $id = isset($p['line_id']) ? (int) $p['line_id'] : $this->nextLine++;
            foreach ($this->lines as $r) {
                if ($r['CNY#'] === $company && (string) $r['DOCUMENT_RECORD#'] === (string) $p['document_id'] && $r['LINE_NUMBER'] === $p['position'] && $r['RECORD#'] !== $id) { throw new DatabaseError(1); }
            }
            $this->lines[$id] = [...$this->line($id, (int) $p['document_id'], $p['position'], (int) $p['product_id'],
                $p['description'], (string) $p['quantity'], $p['unit_price'], $p['source_id']), 'CNY#' => $company];
        } else { throw new \LogicException('Unexpected write: ' . $sql); }
        return 1;
    }
}
