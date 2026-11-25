<?php
declare(strict_types=1);

namespace MiniErp\Tests\oracle\sales;

use MiniErp\shared\database\Connection;
use MiniErp\shared\database\TransactionContext;
use MiniErp\shared\database\TransactionManager;
use MiniErp\shared\security\Actor;

/** Disposable-schema source data for Invoice/Payment acceptance; no stock or logistics writes. */
final class SubmittedOrderFixture
{
    public static function create(Connection $db, Actor $actor, string $number, array $product): void
    {
        (new TransactionManager($db))->run(static function (TransactionContext $tx) use ($actor, $number, $product): void {
            $customer = $tx->query('SELECT RECORD# FROM ME_OE_CUSTOMERS WHERE CNY# = :company_id AND CUSTOMER_CODE = :code',
                ['company_id' => $actor->companyId, 'code' => $number])[0]['RECORD#'];
            $tx->execute("INSERT INTO ME_OE_DOCUMENTS
                (CNY#, DOCUMENT_TYPE, DOCUMENT_NUMBER, CUSTOMER_RECORD#, WAREHOUSE_ID, DOCUMENT_DATE, STATUS, PAYMENT_TERMS)
                VALUES (:company_id, 'SALES_ORDER', :document_number, :customer_id, :warehouse,
                    TO_DATE('2026-11-24', 'YYYY-MM-DD'), 'SUBMITTED', 'NET_30')",
                ['company_id' => $actor->companyId, 'document_number' => $number, 'customer_id' => $customer,
                    'warehouse' => $product['WAREHOUSE_ID']]);
            $order = $tx->query("SELECT RECORD# FROM ME_OE_DOCUMENTS WHERE CNY# = :company_id
                AND DOCUMENT_TYPE = 'SALES_ORDER' AND DOCUMENT_NUMBER = :document_number",
                ['company_id' => $actor->companyId, 'document_number' => $number])[0]['RECORD#'];
            $tx->execute("INSERT INTO ME_OE_DOCUMENT_LINES
                (CNY#, DOCUMENT_RECORD#, LINE_NUMBER, PRODUCT_ID, DESCRIPTION, QUANTITY, UNIT_PRICE)
                VALUES (:company_id, :id, 1, :product_id, 'Acceptance line', 2,
                    TO_NUMBER('10.25', '99D99', 'NLS_NUMERIC_CHARACTERS=''.,'''))",
                ['company_id' => $actor->companyId, 'id' => $order, 'product_id' => $product['PRODUCT_ID']]);
        });
    }

    public static function delete(TransactionContext $tx, Actor $actor, string $number): void
    {
        $rows = $tx->query("SELECT RECORD# FROM ME_OE_DOCUMENTS WHERE CNY# = :company_id
            AND DOCUMENT_TYPE = 'SALES_ORDER' AND DOCUMENT_NUMBER = :document_number",
            ['company_id' => $actor->companyId, 'document_number' => $number]);
        foreach ($rows as $row) {
            $binds = ['company_id' => $actor->companyId, 'id' => $row['RECORD#']];
            $tx->execute('DELETE FROM ME_OE_DOCUMENT_LINES WHERE CNY# = :company_id AND DOCUMENT_RECORD# = :id', $binds);
            $tx->execute("DELETE FROM ME_OE_DOCUMENTS WHERE CNY# = :company_id AND RECORD# = :id AND DOCUMENT_TYPE = 'SALES_ORDER'", $binds);
        }
    }
}
