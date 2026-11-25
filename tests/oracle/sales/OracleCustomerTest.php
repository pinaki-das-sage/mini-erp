<?php
declare(strict_types=1);

namespace MiniErp\Tests\oracle\sales;

use MiniErp\sales\CustomerService;
use MiniErp\shared\AppError;
use MiniErp\shared\database\Connection;
use MiniErp\shared\database\DatabaseError;
use MiniErp\shared\database\OciConnection;
use MiniErp\shared\database\TransactionContext;
use MiniErp\shared\database\TransactionManager;
use MiniErp\shared\security\Actor;
use PHPUnit\Framework\TestCase;

final class OracleCustomerTest extends TestCase
{
    public function testCustomerPersistenceReplayIsolationAndRollback(): void
    {
        if (getenv('LAB_ORACLE_TEST') !== '1' && ($_ENV['LAB_ORACLE_TEST'] ?? '') !== '1') {
            self::markTestSkipped('Oracle Customer acceptance requires an explicitly enabled disposable _TEST schema.');
        }
        $expected = $_ENV['ORACLE_TEST_SCHEMA'] ?? getenv('ORACLE_TEST_SCHEMA');
        self::assertIsString($expected);
        self::assertStringEndsWith('_TEST', strtoupper($expected));
        $config = require dirname(__DIR__, 3) . '/bootstrap.php';
        $db = OciConnection::connect($config);
        self::assertSame(strtoupper($expected), $db->query('SELECT USER AS SCHEMA_NAME FROM DUAL')[0]['SCHEMA_NAME']);
        $admins = $db->query('SELECT U.USER_ID AS ID, U.USERNAME, U.COMPANY_ID FROM ME_USERS U JOIN ME_COMPANY C ON C.ID = U.COMPANY_ID WHERE U.IS_ADMIN = 1 AND U.ACTIVE = 1 AND U.MUST_CHANGE_PASSWORD = 0 AND C.ACTIVE = 1 ORDER BY U.COMPANY_ID, U.USER_ID FETCH FIRST 1 ROWS ONLY');
        self::assertNotEmpty($admins, 'Prepare the shared foundation and an active administrator in the test schema.');
        $actor = new Actor($admins[0]['ID'], $admins[0]['USERNAME'], [], (int) $admins[0]['COMPANY_ID'], true);
        $prefix = 'ct-' . bin2hex(random_bytes(6));
        $input = ['action_reference' => $prefix . '-create', 'customer_name' => $prefix,
            'billing_address_line_1' => 'Test address', 'city' => 'Test city', 'country' => 'India', 'postal_code' => '001234'];
        $service = new CustomerService($db);
        $observer = OciConnection::connect($config);
        $createdIds = [];
        try {
            $created = $service->create($actor, $input);
            $id = (string) $created['id'];
            $createdIds[] = $id;
            self::assertMatchesRegularExpression('/\ACUST-[0-9]{4,15}\z/', $created['customer_code']);
            self::assertSame($created, (new CustomerService($observer))->get($actor, $id));
            self::assertSame('001234', $created['postal_code']);
            self::assertSame('0.00', $created['amount_customer_owes_us']);
            $stored = $observer->query('SELECT RECORD#, CNY#, WHENCREATED, WHENMODIFIED FROM ME_OE_CUSTOMERS WHERE CNY# = :company_id AND RECORD# = :id',
                ['company_id' => $actor->companyId, 'id' => $id]);
            self::assertCount(1, $stored);
            self::assertNotNull($stored[0]['WHENCREATED']);
            self::assertNotNull($stored[0]['WHENMODIFIED']);
            self::assertSame($created, $service->create($actor, $input));
            $second = (new CustomerService($observer))->create($actor,
                [...$input, 'action_reference' => $prefix . '-second-create', 'customer_name' => $prefix . '-other']);
            $createdIds[] = (string) $second['id'];
            self::assertNotSame($created['customer_code'], $second['customer_code']);
            // The real unique constraint still protects a generated code from accidental reuse.
            try {
                (new TransactionManager($db))->run(static fn (TransactionContext $tx) => $tx->execute(
                    'UPDATE ME_OE_CUSTOMERS SET CUSTOMER_CODE = :code WHERE CNY# = :company_id AND RECORD# = :id',
                    ['code' => $created['customer_code'], 'company_id' => $actor->companyId, 'id' => $second['id']],
                ));
                self::fail('The real company/code constraint must reject a duplicate.');
            } catch (DatabaseError $error) {
                self::assertSame(1, $error->oracleCode);
            }
            $patch = ['action_reference' => $prefix . '-patch', 'is_active' => 'F', 'notes' => ''];
            $updated = $service->update($actor, $id, $patch);
            self::assertArrayNotHasKey('current_version', $updated);
            self::assertSame('', $service->get($actor, $id)['notes']);
            self::assertSame($updated, $service->update($actor, $id, $patch));
            $updated = $service->update($actor, $id, ['action_reference' => $prefix . '-second', 'city' => 'Updated city']);
            self::assertSame('Updated city', $updated['city']);
            self::assertSame('F', $updated['is_active']);
            self::assertSame($updated, (new CustomerService($observer))->get($actor, $id));
            $foreign = new Actor($actor->id, $actor->username, [], $actor->companyId === 1 ? 2 : 1, true);
            try {
                $service->get($foreign, $id);
                self::fail('Other companies must not see the Customer.');
            } catch (AppError $error) {
                self::assertSame(404, $error->status);
            }
            $matches = $service->list($actor, ['search' => $prefix, 'is_active' => 'F']);
            self::assertCount(1, $matches['items']);
            // Fail audit persistence after the Customer update on the real shared transaction.
            $failing = new CustomerService(new class ($db) implements Connection {
                public function __construct(private Connection $inner) {}
                public function query(string $sql, array $parameters = []): array { return $this->inner->query($sql, $parameters); }
                public function execute(string $sql, array $parameters = []): int
                {
                    if (str_starts_with($sql, 'INSERT INTO ME_AUDIT')) {
                        throw new DatabaseError(3113);
                    }
                    return $this->inner->execute($sql, $parameters);
                }
                public function executeSchema(string $sql): void { $this->inner->executeSchema($sql); }
                public function begin(): void { $this->inner->begin(); }
                public function commit(): void { $this->inner->commit(); }
                public function rollback(): void { $this->inner->rollback(); }
            });
            try {
                $failing->update($actor, $id, ['action_reference' => $prefix . '-rollback', 'city' => 'Must roll back']);
                self::fail('Injected failure must roll back the write and request identity.');
            } catch (DatabaseError $error) {
                self::assertSame(3113, $error->oracleCode);
            }
            self::assertSame($updated, (new CustomerService($observer))->get($actor, $id));
            self::assertSame([], $observer->query('SELECT ACTION_REFERENCE FROM ME_ACTIONS WHERE COMPANY_ID = :company_id AND ACTION_REFERENCE = :reference',
                ['company_id' => $actor->companyId, 'reference' => $prefix . '-rollback']));
            self::assertCount(4, $observer->query('SELECT ID FROM ME_AUDIT WHERE COMPANY_ID = :company_id AND ACTION_REFERENCE LIKE :pattern',
                ['company_id' => $actor->companyId, 'pattern' => $prefix . '%']));
        } finally {
            (new TransactionManager($db))->run(static function (TransactionContext $tx) use ($actor, $prefix, $createdIds): void {
                foreach (['ME_AUDIT', 'ME_ACTIONS'] as $table) {
                    $tx->execute("DELETE FROM $table WHERE COMPANY_ID = :company_id AND ACTION_REFERENCE LIKE :pattern",
                        ['company_id' => $actor->companyId, 'pattern' => $prefix . '%']);
                }
                foreach ($createdIds as $createdId) {
                    $tx->execute('DELETE FROM ME_OE_CUSTOMERS WHERE CNY# = :company_id AND RECORD# = :id',
                        ['company_id' => $actor->companyId, 'id' => $createdId]);
                }
            });
        }
    }
}
