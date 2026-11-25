<?php
declare(strict_types=1);

namespace MiniErp\Tests\unit\sales;

use MiniErp\sales\customers\CustomerService;
use MiniErp\shared\Api;
use MiniErp\shared\AppError;
use MiniErp\shared\Config;
use MiniErp\shared\database\Connection;
use MiniErp\shared\database\DatabaseError;
use MiniErp\shared\security\Actor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CustomerServiceTest extends TestCase
{
    private function actor(int $company = 7, string $user = 'admin'): Actor
    {
        return new Actor($user, $user, [], $company, true);
    }

    private function input(string $reference = 'create-1'): array
    {
        return ['action_reference' => $reference, 'customer_name' => ' Example Traders ',
            'billing_address_line_1' => '12 Market Road', 'city' => 'Bengaluru', 'country' => 'India', 'postal_code' => '001234'];
    }

    private function rejection(callable $operation, string $code, int $status = 400): void
    {
        try {
            $operation();
            self::fail('Expected rejection: ' . $code);
        } catch (AppError $error) {
            self::assertSame($code, $error->errorCode);
            self::assertSame($status, $error->status);
        }
    }

    public function testCreateDefaultsAndGetReturnOnlyPublicFields(): void
    {
        $db = new CustomerMemoryConnection();
        $service = new CustomerService($db);
        $saved = $service->create($this->actor(), $this->input());
        self::assertSame(1, $saved['id']);
        self::assertSame('CUST-001', $saved['customer_code']);
        self::assertSame('Example Traders', $saved['customer_name']);
        self::assertSame('001234', $saved['postal_code']);
        self::assertSame('NET_30', $saved['payment_terms']);
        self::assertSame('T', $saved['is_active']);
        self::assertSame('', $saved['contact_name']);
        self::assertArrayNotHasKey('current_version', $saved);
        self::assertArrayNotHasKey('company_id', $saved);
        self::assertSame($saved, $service->get($this->actor(), '1'));
        self::assertSame(1, $db->commits);
        self::assertCount(1, $db->audit);
        self::assertStringNotContainsString('Market Road', $db->audit[0]['details']);
    }

    public function testPartialUpdatePreservesOmittedFieldsAndClearsOptionalText(): void
    {
        $db = new CustomerMemoryConnection();
        $service = new CustomerService($db);
        $service->create($this->actor(), $this->input());
        $saved = $service->update($this->actor(), '1', ['action_reference' => 'update-1', 'is_active' => 'F', 'postal_code' => '']);
        self::assertSame('F', $saved['is_active']);
        self::assertSame('', $saved['postal_code']);
        self::assertSame('Example Traders', $saved['customer_name']);
        self::assertSame('12 Market Road', $saved['billing_address_line_1']);
        self::assertSame($saved, $service->get($this->actor(), '1'));
        self::assertTrue($db->locked);
        self::assertSame('T', $service->update($this->actor(), '1', ['action_reference' => 'activate', 'is_active' => 'T'])['is_active']);
    }

    public function testIdenticalRetriesReturnOriginalResultAfterLaterChanges(): void
    {
        $db = new CustomerMemoryConnection();
        $service = new CustomerService($db);
        $original = $service->create($this->actor(), $this->input());
        $patch = ['action_reference' => 'update-1', 'customer_name' => 'New name'];
        $updated = $service->update($this->actor(), '1', $patch);
        $service->update($this->actor(), '1', ['action_reference' => 'update-2', 'is_active' => 'F']);
        self::assertSame($original, $service->create($this->actor(), array_reverse($this->input(), true)));
        self::assertSame($updated, $service->update($this->actor(), '1', $patch));
        self::assertCount(1, $db->customers);
        self::assertCount(3, $db->audit);
        self::assertSame('F', $service->get($this->actor(), '1')['is_active']);
        self::assertSame(2, $db->nextCode, 'Replays and updates must not consume Customer Codes.');
    }

    public function testChangedPayloadActorAndOperationCannotReuseReference(): void
    {
        $db = new CustomerMemoryConnection();
        $service = new CustomerService($db);
        $service->create($this->actor(), $this->input());
        $this->rejection(fn () => $service->create($this->actor(), [...$this->input(), 'city' => 'Delhi']), 'DUPLICATE_ACTION_CONFLICT', 409);
        $this->rejection(fn () => $service->create($this->actor(7, 'other-admin'), $this->input()), 'DUPLICATE_ACTION_CONFLICT', 409);
        $this->rejection(fn () => $service->update($this->actor(), '1', ['action_reference' => 'create-1', 'city' => 'Delhi']), 'DUPLICATE_ACTION_CONFLICT', 409);
        self::assertCount(1, $db->audit);
    }

    public function testGeneratedCodesAreSharedAcrossCompaniesAndCollisionRollsBackClaim(): void
    {
        $db = new CustomerMemoryConnection();
        $service = new CustomerService($db);
        $service->create($this->actor(), $this->input());
        $db->nextCode = 1; // Simulate a sequence that was incorrectly reset.
        $this->rejection(fn () => $service->create($this->actor(), $this->input('duplicate')), 'DUPLICATE_CUSTOMER', 409);
        self::assertArrayNotHasKey('7:duplicate', $db->actions);
        $other = $service->create($this->actor(8), $this->input());
        self::assertSame(2, $other['id']);
        self::assertSame('CUST-002', $other['customer_code']);
        self::assertCount(2, $db->customers);
    }

    public function testCodesGrowPastPaddingAndStopAtColumnCapacity(): void
    {
        $db = new CustomerMemoryConnection();
        $db->nextCode = 999;
        $service = new CustomerService($db);
        self::assertSame('CUST-999', $service->create($this->actor(), $this->input('last-padded'))['customer_code']);
        self::assertSame('CUST-1000', $service->create($this->actor(), $this->input('first-unpadded'))['customer_code']);
        $db->nextCode = 999999999999999;
        self::assertSame('CUST-999999999999999', $service->create($this->actor(), $this->input('last-code'))['customer_code']);
        $this->rejection(fn () => $service->create($this->actor(), $this->input('exhausted')), 'NUMBERING_UNAVAILABLE', 503);
        self::assertCount(3, $db->customers);
        self::assertArrayNotHasKey('7:exhausted', $db->actions);
    }

    #[DataProvider('unavailableNumbers')]
    public function testMissingOrInvalidNumberingRollsBackClaim(mixed $number): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(static function (string $sql) use ($number): array {
            if (str_contains($sql, 'FROM ME_OBJECTS')) {
                return [['ACTIVE' => 1, 'ALLOW_CREATE' => 1]];
            }
            if ($number instanceof DatabaseError) {
                throw $number;
            }
            return [['CODE_NUMBER' => $number]];
        });
        $db->expects(self::once())->method('begin');
        $db->expects(self::once())->method('execute')->willReturnCallback(static function (string $sql): int {
            self::assertStringStartsWith('INSERT INTO ME_ACTIONS', $sql);
            return 1;
        });
        $db->expects(self::once())->method('rollback');
        $db->expects(self::never())->method('commit');
        $this->rejection(fn () => (new CustomerService($db))->create($this->actor(), $this->input()), 'NUMBERING_UNAVAILABLE', 503);
    }

    public static function unavailableNumbers(): array
    {
        return [[null], [1], ['0'], ['-1'], ['1e3'], ['1000000000000000'], ['###############'], [new DatabaseError(2289)]];
    }

    public function testLastCommittedFieldChangeWinsAndOmittedFieldsStayUnchanged(): void
    {
        $db = new CustomerMemoryConnection();
        $service = new CustomerService($db);
        $service->create($this->actor(), $this->input());
        $service->update($this->actor(), '1', ['action_reference' => 'first', 'city' => 'Delhi', 'notes' => 'Keep me']);
        $service->update($this->actor(), '1', ['action_reference' => 'second', 'city' => 'Mumbai']);
        $saved = $service->get($this->actor(), '1');
        self::assertSame('Mumbai', $saved['city']);
        self::assertSame('Keep me', $saved['notes']);
        self::assertCount(3, $db->audit);
        self::assertArrayHasKey('7:second', $db->actions);
    }

    public function testAuditFailureRollsBackCustomerAndAllowsOriginalRequestRetry(): void
    {
        $db = new CustomerMemoryConnection();
        $db->failAudit = true;
        $service = new CustomerService($db);
        try {
            $service->create($this->actor(), $this->input());
            self::fail('Injected audit failure must propagate.');
        } catch (DatabaseError $error) {
            self::assertSame(3113, $error->oracleCode);
        }
        self::assertSame([], $db->customers);
        self::assertSame([], $db->actions);
        self::assertSame([], $db->audit);
        self::assertSame(1, $db->rollbacks);
        $db->failAudit = false;
        self::assertSame('CUST-002', $service->create($this->actor(), $this->input())['customer_code']);
    }

    public function testForeignCompanyReadsAndWritesAreNotFound(): void
    {
        $db = new CustomerMemoryConnection();
        $service = new CustomerService($db);
        $service->create($this->actor(), $this->input());
        $this->rejection(fn () => $service->get($this->actor(8), '1'), 'NOT_FOUND', 404);
        $this->rejection(fn () => $service->update($this->actor(8), '1', ['action_reference' => 'foreign', 'is_active' => 'F']), 'NOT_FOUND', 404);
        self::assertSame('T', $service->get($this->actor(), '1')['is_active']);
    }

    public function testPasswordChangeRestrictionAppliesBeforeAnyDatabaseAccess(): void
    {
        foreach ([new Actor('u', 'u', [], 7, true, 1, true)] as $actor) {
            $db = $this->createMock(Connection::class);
            $db->expects(self::never())->method('query');
            $db->expects(self::never())->method('begin');
            $service = new CustomerService($db);
            foreach ([fn () => $service->create($actor, $this->input()), fn () => $service->get($actor, '1'),
                fn () => $service->list($actor), fn () => $service->update($actor, '1', [])] as $operation) {
                $this->rejection($operation, $actor->mustChangePassword ? 'PASSWORD_CHANGE_REQUIRED' : 'FORBIDDEN', 403);
            }
        }
    }

    #[DataProvider('invalidFields')]
    public function testInvalidCreateDoesNotPersistAnything(array $fields): void
    {
        $db = new CustomerMemoryConnection();
        $service = new CustomerService($db);
        $this->rejection(fn () => $service->create($this->actor(), [...$this->input(), ...$fields]), 'VALIDATION_ERROR');
        self::assertSame([], $db->customers);
        self::assertSame([], $db->actions);
        self::assertSame([], $db->audit);
    }

    public static function invalidFields(): array
    {
        return [
            [['customer_name' => '   ']], [['customer_name' => str_repeat('x', 101)]],
            [['customer_code' => 'A/B']], [['customer_code' => 'CUST-001']], [['customer_code' => null]],
            [['email_address' => 'not-email']], [['phone_number' => '123abc']],
            [['postal_code' => 123]], [['country' => null]], [['is_active' => true]], [['payment_terms' => '']],
            [['notes' => null]], [['company_id' => 8]], [['id' => 5]], [['amount_customer_owes_us' => '20.00']],
        ];
    }

    public function testPatchRejectsImmutableCalculatedEmptyAndUnsupportedInputs(): void
    {
        $db = new CustomerMemoryConnection();
        $service = new CustomerService($db);
        $service->create($this->actor(), $this->input());
        foreach ([['customer_code' => 'CUST-001'], ['amount_customer_owes_us' => '1.00'], [], ['expected_version' => 1, 'city' => 'Delhi'], ['city' => null]] as $fields) {
            $this->rejection(fn () => $service->update($this->actor(), '1', ['action_reference' => 'invalid', ...$fields]), 'VALIDATION_ERROR');
        }
        self::assertCount(1, $db->actions);
        self::assertSame('Bengaluru', $service->get($this->actor(), '1')['city']);
    }

    public function testMissingRequiredFieldsUseBusinessLabels(): void
    {
        foreach (['customer_name' => 'Customer Name', 'billing_address_line_1' => 'Billing Address Line 1', 'city' => 'City', 'country' => 'Country'] as $key => $label) {
            $db = new CustomerMemoryConnection();
            $input = $this->input();
            unset($input[$key]);
            try {
                (new CustomerService($db))->create($this->actor(), $input);
                self::fail('Missing required field must fail.');
            } catch (AppError $error) {
                self::assertSame('Enter ' . $label . '.', $error->getMessage());
            }
            self::assertSame([], $db->actions);
        }
    }

    public function testFailedUpdateAuditRollsBackSavedFields(): void
    {
        $db = new CustomerMemoryConnection();
        $service = new CustomerService($db);
        $original = $service->create($this->actor(), $this->input());
        $db->failAudit = true;
        try {
            $service->update($this->actor(), '1', ['action_reference' => 'fail-patch', 'city' => 'Delhi']);
            self::fail('Audit failure must roll back the saved fields.');
        } catch (DatabaseError $error) {
            self::assertSame(3113, $error->oracleCode);
        }
        self::assertSame($original, $service->get($this->actor(), '1'));
        self::assertArrayNotHasKey('7:fail-patch', $db->actions);
        self::assertCount(1, $db->audit);
    }

    public function testListBindsLiteralSearchAndPaginationAndScopesCompany(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::exactly(2))->method('query')->willReturnCallback(static function (string $sql, array $binds): array {
            if (str_contains($sql, 'FROM ME_OBJECTS')) {
                return [['ACTIVE' => 1, 'ALLOW_READ' => 1]];
            }
            self::assertStringContainsString('CNY# = :company_id', $sql);
            self::assertStringContainsString('ORDER BY CUSTOMER_CODE, RECORD#', $sql);
            self::assertStringNotContainsString('Acme', $sql);
            self::assertSame(7, $binds['company_id']);
            self::assertSame('%Acme!%!_!!%', $binds['search_code']);
            self::assertSame($binds['search_code'], $binds['search_name']);
            self::assertSame('T', $binds['is_active']);
            self::assertSame(10, $binds['offset_rows']);
            self::assertSame(10, $binds['limit_rows']);
            return [];
        });
        self::assertSame(['items' => [], 'page' => 2, 'page_size' => 10], (new CustomerService($db))->list($this->actor(),
            ['search' => 'Acme%_!', 'is_active' => 'T', 'page' => '2', 'page_size' => '10']));
    }

    public function testBadFiltersAndIdsDoNotQueryBusinessData(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('query')->willReturnCallback(static function (string $sql): array {
            self::assertStringContainsString('FROM ME_OBJECTS', $sql);
            return [['ACTIVE' => 1, 'ALLOW_READ' => 1]];
        });
        $service = new CustomerService($db);
        foreach ([['status' => 'ACTIVE'], ['is_active' => 'ACTIVE'], ['page_size' => '101']] as $query) {
            $this->rejection(fn () => $service->list($this->actor(), $query), 'VALIDATION_ERROR');
        }
        foreach (['0', '-1', '1 OR 1=1', '1000000000000000', 'abc'] as $id) {
            $this->rejection(fn () => $service->get($this->actor(), $id), 'NOT_FOUND', 404);
        }
    }

    #[DataProvider('storedBalances')]
    public function testStoredBalanceIsReturnedExactlyAndPreservedOnMetadataUpdate(string $stored, string $expected): void
    {
        $db = new CustomerMemoryConnection();
        $service = new CustomerService($db);
        $service->create($this->actor(), $this->input());
        $db->customers[1]['AMOUNT_CUSTOMER_OWES_US'] = $stored;
        self::assertSame($expected, $service->get($this->actor(), '1')['amount_customer_owes_us']);
        self::assertSame($expected, $service->list($this->actor())['items'][0]['amount_customer_owes_us']);
        $createdAt = $db->customers[1]['WHENCREATED'];
        $modifiedAt = $db->customers[1]['WHENMODIFIED'];
        $patch = ['action_reference' => 'balance-preserved', 'city' => 'Delhi'];
        self::assertSame($expected, $service->update($this->actor(), '1', $patch)['amount_customer_owes_us']);
        self::assertSame($stored, $db->customers[1]['AMOUNT_CUSTOMER_OWES_US']);
        self::assertSame($createdAt, $db->customers[1]['WHENCREATED']);
        self::assertNotSame($modifiedAt, $db->customers[1]['WHENMODIFIED']);
        $modifiedAt = $db->customers[1]['WHENMODIFIED'];
        $service->update($this->actor(), '1', $patch);
        self::assertSame($modifiedAt, $db->customers[1]['WHENMODIFIED'], 'Replay must not update the timestamp again.');
    }

    public static function storedBalances(): array
    {
        return [['0', '0.00'], ['700', '700.00'], ['700.1', '700.10'], ['.25', '0.25'],
            ['9999999999999999.99', '9999999999999999.99']];
    }

    public function testInvalidStoredBalanceRejectsAndRollsBackMetadataUpdate(): void
    {
        foreach ([null, '-1', '1.234', '1e3', '10000000000000000', 1.25] as $stored) {
            $db = new CustomerMemoryConnection();
            $service = new CustomerService($db);
            $service->create($this->actor(), $this->input());
            $db->customers[1]['AMOUNT_CUSTOMER_OWES_US'] = $stored;
            $this->rejection(fn () => $service->get($this->actor(), '1'), 'BALANCE_UNAVAILABLE', 503);
            $this->rejection(fn () => $service->update($this->actor(), '1', ['action_reference' => 'bad-balance', 'city' => 'Delhi']), 'BALANCE_UNAVAILABLE', 503);
            self::assertSame('Bengaluru', $db->customers[1]['CITY']);
            self::assertArrayNotHasKey('7:bad-balance', $db->actions);
        }
    }

    public function testFifteenDigitCustomerIdMapsToRecordColumnWithinCompany(): void
    {
        $db = new CustomerMemoryConnection();
        $service = new CustomerService($db);
        $service->create($this->actor(), $this->input());
        $row = $db->customers[1];
        unset($db->customers[1]);
        $id = 999999999999999;
        $db->customers[$id] = [...$row, 'RECORD#' => $id];
        self::assertSame($id, $service->get($this->actor(), (string) $id)['id']);
        self::assertSame($id, $service->update($this->actor(), (string) $id, ['city' => 'Delhi'])['id']);
        $this->rejection(fn () => $service->get($this->actor(8), (string) $id), 'NOT_FOUND', 404);
    }

    public function testFourRoutesUseBearerAndExpectedEnvelopes(): void
    {
        $db = new CustomerMemoryConnection();
        $api = new Api($db, Config::fromArray([]));
        $headers = ['authorization' => 'Bearer ' . str_repeat('a', 64), 'content-type' => 'application/json'];
        self::assertSame(401, $api->handle('GET', '/api/v1/sales/customers', [])['status']);
        $create = $api->handle('POST', '/api/v1/sales/customers', $this->input(), $headers);
        self::assertSame(201, $create['status']);
        $body = json_decode($create['body'], true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($body['success']);
        self::assertSame('create-1', $body['operation_id']);
        self::assertNull($body['current_version']);
        self::assertArrayNotHasKey('current_version', $body['result']);
        self::assertSame(200, $api->handle('GET', '/api/v1/sales/customers/1', [], $headers)['status']);
        self::assertSame(200, $api->handle('GET', '/api/v1/sales/customers', [], $headers)['status']);
        $patch = ['action_reference' => 'patch', 'is_active' => 'F'];
        $changed = $api->handle('POST', '/api/v1/sales/customers/1/update', $patch, $headers);
        self::assertSame(200, $changed['status']);
        self::assertNull(json_decode($changed['body'], true)['current_version']);
        self::assertSame(200, $api->handle('POST', '/api/v1/sales/customers/1/update', [...$patch, 'action_reference' => 'next', 'is_active' => 'T'], $headers)['status']);
        self::assertSame(404, $api->handle('DELETE', '/api/v1/sales/customers/1', [], $headers)['status']);
        $db->admin = false;
        self::assertSame(403, $api->handle('GET', '/api/v1/sales/customers', [], $headers)['status']);
    }

    public function testCreateOnlyGrantAndRevocationBeforeReplay(): void
    {
        $db = new CustomerMemoryConnection();
        $actor = new Actor('u', 'user', ['sales', 'admin'], 7);
        $service = new CustomerService($db);
        $this->rejection(fn () => $service->create($actor, $this->input()), 'FORBIDDEN', 403);
        self::assertSame([], $db->actions);
        $db->grants = ['8:u:sales.customers:create', '7:other:sales.customers:create'];
        $this->rejection(fn () => $service->create($actor, $this->input()), 'FORBIDDEN', 403);
        $db->grants = ['7:u:sales.customers:create'];
        $saved = $service->create($actor, $this->input());
        self::assertSame($saved, $service->create($actor, $this->input()));
        $this->rejection(fn () => $service->get($actor, '1'), 'FORBIDDEN', 403);
        $this->rejection(fn () => $service->list($actor), 'FORBIDDEN', 403);
        $this->rejection(fn () => $service->update($actor, '1', ['action_reference' => 'patch', 'city' => 'Delhi']), 'FORBIDDEN', 403);
        $db->grants = [];
        $this->rejection(fn () => $service->create($actor, $this->input()), 'FORBIDDEN', 403);
        self::assertCount(1, $db->audit);
    }

    public function testApiNonAdminGrantsAllowOnlyTheirOperations(): void
    {
        $db = new CustomerMemoryConnection();
        $db->admin = false;
        $db->grants = ['7:admin:sales.customers:create'];
        $api = new Api($db, Config::fromArray([]));
        $headers = ['authorization' => 'Bearer ' . str_repeat('a', 64), 'content-type' => 'application/json'];
        self::assertSame(201, $api->handle('POST', '/api/v1/sales/customers', $this->input(), $headers)['status']);
        self::assertSame(403, $api->handle('GET', '/api/v1/sales/customers', [], $headers)['status']);
        $db->grants = ['7:admin:sales.customers:read'];
        self::assertSame(200, $api->handle('GET', '/api/v1/sales/customers', [], $headers)['status']);
        self::assertSame(403, $api->handle('POST', '/api/v1/sales/customers', $this->input(), $headers)['status']);
        $db->grants = ['7:admin:sales.customers:update'];
        $patch = ['action_reference' => 'granted-update', 'city' => 'Delhi'];
        self::assertSame(200, $api->handle('POST', '/api/v1/sales/customers/1/update', $patch, $headers)['status']);
        self::assertSame(200, $api->handle('POST', '/api/v1/sales/customers/1/update', $patch, $headers)['status']);
        $db->grants = [];
        self::assertSame(403, $api->handle('POST', '/api/v1/sales/customers/1/update', $patch, $headers)['status']);
        self::assertCount(2, $db->audit);
    }

    public function testCustomerWritesWithoutReferencesUseIndependentInternalReferences(): void
    {
        $db = new CustomerMemoryConnection();
        $api = new Api($db, Config::fromArray([]));
        $headers = ['authorization' => 'Bearer ' . str_repeat('a', 64), 'content-type' => 'application/json'];
        $input = $this->input();
        unset($input['action_reference']);
        $created = $api->handle('POST', '/api/v1/sales/customers', $input, $headers);
        self::assertSame(201, $created['status']);
        self::assertNull(json_decode($created['body'], true)['operation_id']);
        foreach (['Delhi', 'Mumbai', 'Delhi'] as $city) {
            $response = $api->handle('POST', '/api/v1/sales/customers/1/update', ['city' => $city], $headers);
            self::assertSame(200, $response['status']);
            self::assertSame($city, json_decode($response['body'], true)['result']['city']);
        }
        self::assertSame(200, $api->handle('POST', '/api/v1/sales/customers/1/update', ['is_active' => 'F'], $headers)['status']);
        self::assertSame(5, count($db->actions));
        self::assertCount(5, $db->audit);
        foreach (array_keys($db->actions) as $key) {
            self::assertMatchesRegularExpression('/\A7:[a-f0-9]{32}\z/', $key);
        }
        $second = $api->handle('POST', '/api/v1/sales/customers', $input, $headers);
        self::assertSame(201, $second['status']);
        self::assertSame('CUST-002', json_decode($second['body'], true)['result']['customer_code']);
        self::assertCount(6, $db->actions);
    }

    public function testExplicitInvalidReferencesAreRejectedRatherThanReplaced(): void
    {
        $db = new CustomerMemoryConnection();
        $service = new CustomerService($db);
        foreach (['', null, 123, 'invalid reference'] as $reference) {
            $this->rejection(fn () => $service->create($this->actor(), [...$this->input(), 'action_reference' => $reference]), 'VALIDATION_ERROR');
        }
        self::assertSame([], $db->actions);
        self::assertSame([], $db->customers);
    }

    public function testPostUpdateValidationAuthorizationAndRetryIdentity(): void
    {
        $db = new CustomerMemoryConnection();
        $api = new Api($db, Config::fromArray([]));
        $headers = ['authorization' => 'Bearer ' . str_repeat('a', 64), 'content-type' => 'application/json'];
        $api->handle('POST', '/api/v1/sales/customers', $this->input(), $headers);
        $url = '/api/v1/sales/customers/1/update';
        $input = ['action_reference' => 'customer-update-001', 'is_active' => 'F', 'billing_address_line_1' => 'BTM Layout 2nd Stage'];
        self::assertSame(401, $api->handle('POST', $url, $input)['status']);
        $updated = $api->handle('POST', $url, $input, $headers);
        self::assertSame(200, $updated['status']);
        $body = json_decode($updated['body'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('customer-update-001', $body['operation_id']);
        self::assertNull($body['current_version']);
        self::assertSame('F', $body['result']['is_active']);
        self::assertSame('BTM Layout 2nd Stage', $body['result']['billing_address_line_1']);
        self::assertSame('Example Traders', $body['result']['customer_name']);
        self::assertSame($updated['body'], $api->handle('POST', $url, $input, $headers)['body']);
        self::assertSame(404, $api->handle('PATCH', '/api/v1/sales/customers/1', $input, $headers)['status']);
        self::assertCount(2, $db->audit);
        self::assertSame(409, $api->handle('POST', $url, [...$input, 'is_active' => 'T'], $headers)['status']);
        self::assertSame(400, $api->handle('POST', $url, ['action_reference' => 'bad-field', 'amount_customer_owes_us' => '1.00'], $headers)['status']);
        self::assertSame(404, $api->handle('POST', '/api/v1/sales/customers/999/update', [...$input, 'action_reference' => 'missing'], $headers)['status']);
        $db->admin = false;
        self::assertSame(403, $api->handle('POST', $url, $input, $headers)['status']);
        self::assertCount(2, $db->audit);
    }
}

/** Transactional double: verifies service behavior, not Oracle syntax or locking. */
final class CustomerMemoryConnection implements Connection
{
    public array $customers = [];
    public array $actions = [];
    public array $audit = [];
    public bool $failAudit = false;
    public bool $admin = true;
    public array $grants = [];
    public bool $locked = false;
    public int $commits = 0;
    public int $rollbacks = 0;
    public int $nextCode = 1;
    private ?array $snapshot = null;
    private int $nextId = 1;

    public function begin(): void
    {
        if ($this->snapshot !== null) {
            throw new \LogicException('Nested transaction.');
        }
        $this->snapshot = [$this->customers, $this->actions, $this->audit];
    }

    public function commit(): void { $this->snapshot = null; $this->commits++; }
    public function rollback(): void
    {
        [$this->customers, $this->actions, $this->audit] = $this->snapshot;
        $this->snapshot = null;
        $this->rollbacks++;
    }
    public function executeSchema(string $sql): void { throw new \LogicException('No DDL in service tests.'); }

    public function execute(string $sql, array $parameters = []): int
    {
        if ($this->snapshot === null) {
            throw new \LogicException('Write outside transaction.');
        }
        if (!isset($parameters['company_id'])) {
            throw new \LogicException('Unscoped write.');
        }
        $key = $parameters['company_id'] . ':' . ($parameters['reference'] ?? '');
        if (str_starts_with($sql, 'INSERT INTO ME_ACTIONS')) {
            if (isset($this->actions[$key])) {
                throw new DatabaseError(1);
            }
            $this->actions[$key] = ['ACTOR_ID' => $parameters['actor'], 'PAYLOAD_HASH' => $parameters['hash'], 'RESULT_JSON' => null];
        } elseif (str_starts_with($sql, 'UPDATE ME_ACTIONS')) {
            $this->actions[$key]['RESULT_JSON'] = $parameters['result'];
        } elseif (str_starts_with($sql, 'INSERT INTO ME_AUDIT')) {
            if ($this->failAudit) {
                throw new DatabaseError(3113);
            }
            $this->audit[] = $parameters;
        } elseif (str_starts_with($sql, 'INSERT INTO ME_OE_CUSTOMERS')) {
            if (!str_contains($sql, '(CNY#,')) {
                throw new \LogicException('Customer insert must use the new company column.');
            }
            foreach ($this->customers as $row) {
                if ($row['CNY#'] === $parameters['company_id'] && $row['CUSTOMER_CODE'] === $parameters['customer_code']) {
                    throw new DatabaseError(1);
                }
            }
            $id = $this->nextId++;
            $values = $parameters;
            unset($values['company_id']);
            $this->customers[$id] = ['RECORD#' => $id, 'CNY#' => $parameters['company_id'],
                'AMOUNT_CUSTOMER_OWES_US' => '0', 'WHENCREATED' => 'created', 'WHENMODIFIED' => 'created',
                ...array_change_key_case($values, CASE_UPPER)];
        } elseif (str_starts_with($sql, 'UPDATE ME_OE_CUSTOMERS')) {
            if (!str_contains($sql, 'WHERE CNY# = :company_id AND RECORD# = :id')
                || !str_contains($sql, 'WHENMODIFIED = CURRENT_TIMESTAMP')) {
                throw new \LogicException('Customer update must scope the new keys and maintain its timestamp.');
            }
            $id = $parameters['id'];
            $row = $this->customers[$id] ?? null;
            if ($row === null || $row['CNY#'] !== $parameters['company_id']) {
                return 0;
            }
            $values = $parameters;
            unset($values['company_id'], $values['id']);
            $this->customers[$id] = [...$row, ...array_change_key_case($values, CASE_UPPER), 'WHENMODIFIED' => 'updated-' . $this->commits];
        } else {
            throw new \LogicException('Unexpected SQL: ' . $sql);
        }
        return 1;
    }

    public function query(string $sql, array $parameters = []): array
    {
        if (str_contains($sql, 'ME_OE_CUST_CODE_SEQ.NEXTVAL')) {
            if ($this->snapshot === null) {
                throw new \LogicException('Sequence must be consumed inside the create transaction.');
            }
            if ($this->nextCode > 999999999999999) {
                throw new DatabaseError(8004);
            }
            return [['CODE_NUMBER' => (string) $this->nextCode++]];
        }
        if (str_contains($sql, 'FROM ME_API_TOKENS')) {
            return [['USER_ID' => 'admin', 'COMPANY_ID' => 7, 'SECURITY_VERSION' => 1, 'EXPIRES_AT' => time() + 3600, 'REVOKED_AT' => null]];
        }
        if (str_contains($sql, 'JOIN ME_COMPANY')) {
            return [['ID' => 'admin', 'USERNAME' => 'admin', 'IS_ADMIN' => $this->admin ? 1 : 0, 'COMPANY_ID' => 7,
                'SECURITY_VERSION' => 1, 'MUST_CHANGE_PASSWORD' => 0, 'COMPANY_NAME' => 'Test']];
        }
        if (str_contains($sql, 'FROM ME_USER_ROLES')) {
            return [];
        }
        if (str_contains($sql, 'FROM ME_OBJECTS')) {
            return [['ACTIVE' => 1, 'ALLOW_CREATE' => 1, 'ALLOW_READ' => 1, 'ALLOW_UPDATE' => 1, 'ALLOW_DELETE' => 0]];
        }
        if (str_contains($sql, 'FROM ME_USER_PERMISSIONS')) {
            $key = $parameters['company_id'] . ':' . $parameters['user_id'] . ':' . $parameters['object'] . ':' . $parameters['operation'];
            return in_array($key, $this->grants, true) ? [['USER_ID' => $parameters['user_id']]] : [];
        }
        $companyColumn = str_contains($sql, 'FROM ME_OE_CUSTOMERS') ? 'CNY#' : 'COMPANY_ID';
        if (!isset($parameters['company_id']) || !str_contains($sql, $companyColumn . ' = :company_id')) {
            throw new \LogicException('Unscoped read.');
        }
        if (str_contains($sql, 'FROM ME_ACTIONS')) {
            $row = $this->actions[$parameters['company_id'] . ':' . $parameters['reference']] ?? null;
            return $row === null ? [] : [$row];
        }
        if (str_contains($sql, 'FROM ME_OE_CUSTOMERS')) {
            if (isset($parameters['id']) && !str_contains($sql, 'RECORD# = :id')) {
                throw new \LogicException('Customer lookup must use RECORD#.');
            }
            $this->locked = $this->locked || str_contains($sql, 'FOR UPDATE');
            return array_values(array_filter($this->customers, static fn (array $row): bool =>
                $row['CNY#'] === $parameters['company_id']
                && (!isset($parameters['id']) || $row['RECORD#'] === $parameters['id'])
                && (!isset($parameters['customer_code']) || $row['CUSTOMER_CODE'] === $parameters['customer_code'])));
        }
        throw new \LogicException('Unexpected SQL: ' . $sql);
    }
}
