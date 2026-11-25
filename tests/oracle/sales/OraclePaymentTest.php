<?php
declare(strict_types=1);

namespace MiniErp\Tests\oracle\sales;

use MiniErp\sales\InvoiceService;
use MiniErp\sales\PaymentService;
use MiniErp\shared\AppError;
use MiniErp\shared\database\Connection;
use MiniErp\shared\database\DatabaseError;
use MiniErp\shared\database\OciConnection;
use MiniErp\shared\database\TransactionContext;
use MiniErp\shared\database\TransactionManager;
use MiniErp\shared\security\Actor;
use PHPUnit\Framework\TestCase;

/** Two-session persistence/rollback acceptance; not a simultaneous concurrency test. */
final class OraclePaymentTest extends TestCase
{
    public function testPaymentsPersistReplayAndRollBackTogether(): void
    {
        if (getenv('LAB_ORACLE_TEST') !== '1' && ($_ENV['LAB_ORACLE_TEST'] ?? '') !== '1') {
            self::markTestSkipped('Payment Oracle acceptance requires an explicitly enabled disposable _TEST schema.');
        }
        $expected = $_ENV['ORACLE_TEST_SCHEMA'] ?? getenv('ORACLE_TEST_SCHEMA');
        self::assertIsString($expected);
        self::assertStringEndsWith('_TEST', strtoupper($expected));
        $config = require dirname(__DIR__, 3) . '/bootstrap.php';
        $db = OciConnection::connect($config);
        self::assertSame(strtoupper($expected), $db->query('SELECT USER AS SCHEMA_NAME FROM DUAL')[0]['SCHEMA_NAME']);
        $admins = $db->query('SELECT U.USER_ID AS ID, U.USERNAME, U.COMPANY_ID FROM ME_USERS U JOIN ME_COMPANY C ON C.ID = U.COMPANY_ID
            WHERE U.IS_ADMIN = 1 AND U.ACTIVE = 1 AND U.MUST_CHANGE_PASSWORD = 0 AND C.ACTIVE = 1
            ORDER BY U.COMPANY_ID, U.USER_ID FETCH FIRST 1 ROWS ONLY');
        self::assertNotEmpty($admins, 'Prepare the shared foundation and an active administrator.');
        $actor = new Actor($admins[0]['ID'], $admins[0]['USERNAME'], [], (int) $admins[0]['COMPANY_ID'], true);
        $products = $db->query("SELECT SKU, WAREHOUSE_ID FROM ME_INV_PRODUCTS WHERE COMPANY_ID = :company_id
            AND IS_ACTIVE = 'Y' AND LENGTH(SKU) <= 30 AND LENGTH(WAREHOUSE_ID) <= 20 ORDER BY PRODUCT_ID FETCH FIRST 1 ROWS ONLY",
            ['company_id' => $actor->companyId]);
        self::assertNotEmpty($products, 'Prepare an active Product/Warehouse in the disposable schema.');
        $observer = OciConnection::connect($config);
        $prefix = 'pt-' . bin2hex(random_bytes(6));
        $invoiceNumber = null;
        $clock = static fn (): string => '2026-11-25';
        try {
            (new TransactionManager($db))->run(static fn (TransactionContext $tx) => $tx->execute("INSERT INTO ME_OE_CUSTOMERS
                (CNY#, CUSTOMER_CODE, CUSTOMER_NAME, BILLING_ADDRESS_LINE_1, CITY, COUNTRY)
                VALUES (:company_id, :code, 'Payment test fixture', 'Test address', 'Test city', 'India')",
                ['company_id' => $actor->companyId, 'code' => $prefix]));
            $invoices = new InvoiceService($db, $clock);
            $created = $invoices->create($actor, ['action_reference' => $prefix . '-create', 'customer_code' => $prefix,
                'warehouse_id' => $products[0]['WAREHOUSE_ID'], 'lines' => [[
                    'item_code' => $products[0]['SKU'], 'description' => 'Payment test', 'quantity' => '2', 'unit_price' => '10.25',
                ]]]);
            $invoiceNumber = $created['invoice_number'];
            $invoices->action($actor, $invoiceNumber, 'submit', ['action_reference' => $prefix . '-submit', 'expected_version' => 1]);
            (new TransactionManager($db))->run(static fn (TransactionContext $tx) => $tx->execute("UPDATE ME_OE_CUSTOMERS SET IS_ACTIVE = 'F'
                WHERE CNY# = :company_id AND CUSTOMER_CODE = :code", ['company_id' => $actor->companyId, 'code' => $prefix]));
            $service = new PaymentService($db, $clock);
            $input = ['action_reference' => $prefix . '-partial', 'expected_version' => 2, 'payment_amount' => '7.25',
                'payment_date' => '2026-11-25', 'payment_reference' => 'TEST-RECEIPT'];
            $partial = $service->record($actor, $invoiceNumber, $input);
            self::assertSame('13.25', $partial['invoice']['amount_due']);
            self::assertSame((string) $actor->id, $partial['payment']['created_by']);
            self::assertStringEndsWith('Z', $partial['payment']['created_at']);
            self::assertSame($partial, $service->record($actor, $invoiceNumber, $input));
            self::assertCount(1, (new PaymentService($observer, $clock))->list($actor, $invoiceNumber)['items']);

            $fault = new class ($db) implements Connection {
                public function __construct(private Connection $inner) {}
                public function query(string $sql, array $parameters = []): array { return $this->inner->query($sql, $parameters); }
                public function execute(string $sql, array $parameters = []): int
                {
                    if (str_starts_with($sql, 'INSERT INTO ME_AUDIT')) { throw new DatabaseError(3113); }
                    return $this->inner->execute($sql, $parameters);
                }
                public function executeSchema(string $sql): void { throw new \LogicException('No DDL during payment tests.'); }
                public function begin(): void { $this->inner->begin(); }
                public function commit(): void { $this->inner->commit(); }
                public function rollback(): void { $this->inner->rollback(); }
            };
            $finalInput = [...$input, 'action_reference' => $prefix . '-final', 'expected_version' => 3, 'payment_amount' => '13.25'];
            try {
                (new PaymentService($fault, $clock))->record($actor, $invoiceNumber, $finalInput);
                self::fail('Forced audit failure must roll back payment, balance, version and retry result.');
            } catch (DatabaseError $error) { self::assertSame(3113, $error->oracleCode); }
            $afterFailure = (new InvoiceService($observer))->get($actor, $invoiceNumber);
            self::assertSame(3, $afterFailure['current_version']);
            self::assertSame('13.25', $afterFailure['amount_due']);
            self::assertSame([], $observer->query('SELECT ACTION_REFERENCE FROM ME_ACTIONS WHERE COMPANY_ID = :company_id AND ACTION_REFERENCE = :reference',
                ['company_id' => $actor->companyId, 'reference' => $finalInput['action_reference']]));
            $final = $service->record($actor, $invoiceNumber, $finalInput);
            self::assertSame('Paid', $final['invoice']['payment_status']);
            self::assertSame(4, $final['invoice']['current_version']);
            self::assertSame('0.00', $final['invoice']['amount_due']);
            self::assertSame($partial, $service->record($actor, $invoiceNumber, $input));
            self::assertSame($final, $service->record($actor, $invoiceNumber, $finalInput));
            self::assertCount(2, (new PaymentService($observer))->list($actor, $invoiceNumber)['items']);
            $balance = $observer->query("SELECT AMOUNT_CUSTOMER_OWES_US AS BALANCE FROM ME_OE_CUSTOMERS
                WHERE CNY# = :company_id AND CUSTOMER_CODE = :code", ['company_id' => $actor->companyId, 'code' => $prefix])[0]['BALANCE'];
            self::assertSame(0.0, (float) $balance);
            try {
                $invoices->action($actor, $invoiceNumber, 'return_to_draft', ['action_reference' => $prefix . '-return', 'expected_version' => 4]);
                self::fail('Payments must block return to Draft.');
            } catch (AppError $error) { self::assertSame('INVALID_STATE', $error->errorCode); }
        } finally {
            (new TransactionManager($db))->run(static function (TransactionContext $tx) use ($actor, $prefix, $invoiceNumber): void {
                if ($invoiceNumber !== null) {
                    $headers = $tx->query("SELECT RECORD# FROM ME_OE_DOCUMENTS WHERE CNY# = :company_id
                        AND DOCUMENT_NUMBER = :document_number AND DOCUMENT_TYPE = 'SALES_INVOICE'",
                        ['company_id' => $actor->companyId, 'document_number' => $invoiceNumber]);
                    foreach ($headers as $row) {
                        $binds = ['company_id' => $actor->companyId, 'id' => $row['RECORD#']];
                        $tx->execute('DELETE FROM ME_OE_PAYMENTS WHERE CNY# = :company_id AND INVOICE_RECORD# = :id', $binds);
                        $tx->execute('DELETE FROM ME_OE_SOURCE_LINKS WHERE CNY# = :company_id AND TARGET_DOCUMENT_RECORD# = :id', $binds);
                        $tx->execute('DELETE FROM ME_OE_DOCUMENT_LINES WHERE CNY# = :company_id AND DOCUMENT_RECORD# = :id', $binds);
                        $tx->execute("DELETE FROM ME_OE_DOCUMENTS WHERE CNY# = :company_id AND RECORD# = :id AND DOCUMENT_TYPE = 'SALES_INVOICE'", $binds);
                    }
                }
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
