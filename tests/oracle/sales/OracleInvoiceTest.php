<?php
declare(strict_types=1);

namespace MiniErp\Tests\oracle\sales;

use MiniErp\sales\invoices\InvoiceService;
use MiniErp\shared\database\OciConnection;
use MiniErp\shared\database\TransactionContext;
use MiniErp\shared\database\TransactionManager;
use MiniErp\shared\security\Actor;
use PHPUnit\Framework\TestCase;

/** Explicit opt-in only. Own Customer/action fixtures are cleaned; no shared Invoice is edited. */
final class OracleInvoiceTest extends TestCase
{
    public function testOrderBackedInvoicePersistenceAndBalanceLifecycle(): void
    {
        if (getenv('LAB_ORACLE_TEST') !== '1' && ($_ENV['LAB_ORACLE_TEST'] ?? '') !== '1') {
            self::markTestSkipped('Invoice Oracle acceptance requires an explicitly enabled disposable _TEST schema.');
        }
        $expected = $_ENV['ORACLE_TEST_SCHEMA'] ?? getenv('ORACLE_TEST_SCHEMA');
        self::assertIsString($expected);
        self::assertStringEndsWith('_TEST', strtoupper($expected));
        $config = require dirname(__DIR__, 3) . '/bootstrap.php';
        $db = OciConnection::connect($config);
        self::assertSame(strtoupper($expected), $db->query('SELECT USER AS SCHEMA_NAME FROM DUAL')[0]['SCHEMA_NAME']);
        $admins = $db->query('SELECT U.USER_ID AS ID, U.USERNAME, U.COMPANY_ID FROM ME_USERS U
            JOIN ME_COMPANY C ON C.ID = U.COMPANY_ID WHERE U.IS_ADMIN = 1 AND U.ACTIVE = 1
            AND U.MUST_CHANGE_PASSWORD = 0 AND C.ACTIVE = 1 ORDER BY U.COMPANY_ID, U.USER_ID FETCH FIRST 1 ROWS ONLY');
        self::assertNotEmpty($admins, 'Prepare an active administrator and the shared/Sales/Inventory migrations.');
        $actor = new Actor($admins[0]['ID'], $admins[0]['USERNAME'], [], (int) $admins[0]['COMPANY_ID'], true);
        $products = $db->query("SELECT PRODUCT_ID, WAREHOUSE_ID FROM ME_INV_PRODUCTS
            WHERE COMPANY_ID = :company_id AND IS_ACTIVE = 'Y'
            AND LENGTH(WAREHOUSE_ID) <= 20 ORDER BY PRODUCT_ID FETCH FIRST 1 ROWS ONLY", ['company_id' => $actor->companyId]);
        self::assertNotEmpty($products, 'Prepare an active Inventory product/warehouse in the disposable schema.');
        $prefix = 'it-' . bin2hex(random_bytes(6));
        $numbers = [];
        $txs = new TransactionManager($db);
        try {
            $txs->run(static fn (TransactionContext $tx) => $tx->execute("INSERT INTO ME_OE_CUSTOMERS
                (CNY#, CUSTOMER_CODE, CUSTOMER_NAME, BILLING_ADDRESS_LINE_1, CITY, COUNTRY)
                VALUES (:company_id, :code, 'Invoice acceptance fixture', 'Test address', 'Test city', 'India')",
                ['company_id' => $actor->companyId, 'code' => $prefix]));
            $service = new InvoiceService($db, static fn (): string => '2026-11-24');
            SubmittedOrderFixture::create($db, $actor, $prefix, $products[0]);
            $lookup = ['customer_code' => $prefix];
            self::assertSame([$prefix], array_column($service->eligibleOrders($actor, $lookup)['items'], 'order_number'));
            foreach (['DRAFT', 'IN_PROGRESS', 'CLOSED'] as $status) {
                $txs->run(static fn (TransactionContext $tx) => $tx->execute("UPDATE ME_OE_DOCUMENTS SET STATUS = :status
                    WHERE CNY# = :company_id AND DOCUMENT_TYPE = 'SALES_ORDER' AND DOCUMENT_NUMBER = :document_number",
                    ['company_id' => $actor->companyId, 'document_number' => $prefix, 'status' => $status]));
                self::assertSame([], $service->eligibleOrders($actor, $lookup)['items']);
            }
            $txs->run(static fn (TransactionContext $tx) => $tx->execute("UPDATE ME_OE_DOCUMENTS SET STATUS = 'SUBMITTED'
                WHERE CNY# = :company_id AND DOCUMENT_TYPE = 'SALES_ORDER' AND DOCUMENT_NUMBER = :document_number",
                ['company_id' => $actor->companyId, 'document_number' => $prefix]));
            $input = ['action_reference' => $prefix . '-create', 'source_order_number' => $prefix];
            $saved = $service->create($actor, $input);
            $number = $saved['invoice_number'];
            $numbers[] = $number;
            self::assertSame([], $service->eligibleOrders($actor, $lookup)['items'], 'A Draft Invoice already claims its Order.');
            self::assertSame('20.50', $saved['document_total']);
            $observer = OciConnection::connect($config);
            self::assertSame($saved, (new InvoiceService($observer))->get($actor, $number));
            $submit = ['action_reference' => $prefix . '-submit', 'expected_version' => 1];
            $submitted = $service->action($actor, $number, 'submit', $submit);
            self::assertSame('Submitted', $submitted['status']);
            self::assertSame($submitted, $service->action($actor, $number, 'submit', $submit));
            $balance = static fn () => $observer->query('SELECT TO_CHAR(AMOUNT_CUSTOMER_OWES_US,
                \'FM9999999999999990D00\', \'NLS_NUMERIC_CHARACTERS=\'\'.,\'\'\') AS BALANCE
                FROM ME_OE_CUSTOMERS WHERE CNY# = :company_id AND CUSTOMER_CODE = :code',
                ['company_id' => $actor->companyId, 'code' => $prefix])[0]['BALANCE'];
            self::assertSame('20.50', $balance());
            $returned = $service->action($actor, $number, 'return_to_draft', ['action_reference' => $prefix . '-return', 'expected_version' => 2]);
            self::assertNull($returned['payment_status']);
            self::assertSame('0.00', $balance());
            $deleted = $service->action($actor, $number, 'delete', ['action_reference' => $prefix . '-delete', 'expected_version' => 3]);
            self::assertTrue($deleted['deleted']);
            self::assertSame([$prefix], array_column($service->eligibleOrders($actor, $lookup)['items'], 'order_number'));
        } finally {
            $txs->run(static function (TransactionContext $tx) use ($actor, $prefix, $numbers): void {
                foreach ($numbers as $number) {
                    $binds = ['company_id' => $actor->companyId, 'document_number' => $number];
                    $rows = $tx->query("SELECT RECORD# FROM ME_OE_DOCUMENTS WHERE CNY# = :company_id
                        AND DOCUMENT_NUMBER = :document_number AND DOCUMENT_TYPE = 'SALES_INVOICE'", $binds);
                    foreach ($rows as $row) {
                        $ids = ['company_id' => $actor->companyId, 'id' => $row['RECORD#']];
                        $tx->execute('DELETE FROM ME_OE_SOURCE_LINKS WHERE CNY# = :company_id AND TARGET_DOCUMENT_RECORD# = :id', $ids);
                        $tx->execute('DELETE FROM ME_OE_DOCUMENT_LINES WHERE CNY# = :company_id AND DOCUMENT_RECORD# = :id', $ids);
                        $tx->execute("DELETE FROM ME_OE_DOCUMENTS WHERE CNY# = :company_id AND DOCUMENT_NUMBER = :document_number AND DOCUMENT_TYPE = 'SALES_INVOICE'", $binds);
                    }
                }
                SubmittedOrderFixture::delete($tx, $actor, $prefix);
                foreach (['ME_AUDIT', 'ME_ACTIONS'] as $table) {
                    $tx->execute("DELETE FROM $table WHERE COMPANY_ID = :company_id AND ACTION_REFERENCE LIKE :pattern",
                        ['company_id' => $actor->companyId, 'pattern' => $prefix . '%']);
                }
                $tx->execute('DELETE FROM ME_OE_CUSTOMERS WHERE CNY# = :company_id AND CUSTOMER_CODE = :code',
                    ['company_id' => $actor->companyId, 'code' => $prefix]);
            });
        }
    }
}
