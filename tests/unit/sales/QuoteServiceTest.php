<?php
declare(strict_types=1);

namespace MiniErp\Tests\unit\sales;

use MiniErp\sales\quotes\QuoteInventory;
use MiniErp\sales\quotes\QuoteMoney;
use MiniErp\sales\quotes\QuoteService;
use MiniErp\shared\AppError;
use MiniErp\shared\Api;
use MiniErp\shared\Config;
use MiniErp\shared\database\Connection;
use MiniErp\shared\database\DatabaseError;
use MiniErp\shared\database\TransactionContext;
use MiniErp\shared\security\Actor;
use PHPUnit\Framework\TestCase;

final class QuoteServiceTest extends TestCase
{
    private function actor(int $company = 7, bool $admin = true): Actor { return new Actor('admin', 'admin', [], $company, $admin); }
    private function input(): array
    {
        return ['customer_code' => 'CUST-001', 'warehouse_id' => 'W01', 'lines' => [
            ['product_id' => '163', 'description' => 'Product', 'quantity' => '3', 'unit_price' => '12.50'],
            ['product_id' => '163', 'description' => 'Free sample', 'quantity' => '1', 'unit_price' => '0']]];
    }
    private function service(QuoteMemoryConnection $db): QuoteService
    {
        return new QuoteService($db, new class implements QuoteInventory {
            public function validate(TransactionContext $tx, Actor $actor, string $warehouseId, array $productIds): void
            {
                if ($warehouseId !== 'W01' || $productIds !== ['163']) { throw new AppError('VALIDATION_ERROR', 'Unknown Inventory reference.'); }
            }
        }, static fn (): string => '2026-11-23');
    }
    private function reject(callable $operation, string $code): void
    {
        try { $operation(); self::fail('Expected ' . $code); }
        catch (AppError $error) { self::assertSame($code, $error->errorCode); }
    }

    public function testCreateDefaultsReadListAndUnkeyedRequests(): void
    {
        $db = new QuoteMemoryConnection(); $service = $this->service($db);
        $saved = $service->create($this->actor(), $this->input());
        self::assertSame('SQ-000000000000001', $saved['quote_number']);
        self::assertSame('Draft', $saved['status']);
        self::assertSame('2026-11-23', $saved['quote_date']);
        self::assertSame('Market Road, Bengaluru, India', $saved['shipping_address']);
        self::assertSame('NET_30', $saved['payment_terms']);
        self::assertSame('Example Traders', $saved['customer_name']);
        self::assertSame('37.50', $saved['document_total']);
        self::assertSame('0.00', $saved['lines'][1]['line_amount']);
        self::assertSame($saved, $service->get($this->actor(), $saved['quote_number']));
        self::assertSame(1, $saved['current_version']);
        self::assertArrayNotHasKey('lines', $service->list($this->actor())['items'][0]);
        self::assertNotSame($saved['quote_number'], $service->create($this->actor(), $this->input())['quote_number']);
        self::assertCount(2, $db->audit);
        self::assertStringNotContainsString('Market Road', $db->audit[0]['details']);
        $this->reject(fn () => $service->get($this->actor(8), $saved['quote_number']), 'NOT_FOUND');
    }

    public function testEditPreservesIdsReordersAddsRemovesAndRejectsStaleVersion(): void
    {
        $db = new QuoteMemoryConnection(); $service = $this->service($db);
        $saved = $service->create($this->actor(), $this->input()); $number = $saved['quote_number'];
        $line = $this->input()['lines'][0];
        $edit = ['expected_version' => 1, 'lines' => [
            [...$line, 'line_id' => $saved['lines'][1]['line_id'], 'quantity' => '2'],
            [...$line, 'line_id' => $saved['lines'][0]['line_id']], $line], 'notes' => 'Edited'];
        $updated = $service->update($this->actor(), $number, $edit);
        self::assertSame(2, $updated['current_version']);
        self::assertSame($saved['lines'][1]['line_id'], $updated['lines'][0]['line_id']);
        self::assertSame([1, 2, 3], array_column($updated['lines'], 'line_number'));
        self::assertSame('100.00', $updated['document_total']);
        $this->reject(fn () => $service->update($this->actor(), $number, $edit), 'STALE_VERSION');
        $updated = $service->update($this->actor(), $number, ['expected_version' => 2, 'lines' => [$line]]);
        self::assertCount(1, $updated['lines']);
        self::assertSame('Edited', $updated['notes']);
        self::assertSame('37.50', $updated['document_total']);
    }

    public function testSubmitReturnDeleteAndDownstreamGuards(): void
    {
        $db = new QuoteMemoryConnection(); $service = $this->service($db);
        $saved = $service->create($this->actor(), $this->input()); $number = $saved['quote_number'];
        $submitted = $service->action($this->actor(), $number, 'submit', ['expected_version' => 1]);
        self::assertSame('Submitted', $submitted['status']);
        $this->reject(fn () => $service->update($this->actor(), $number, ['expected_version' => 2, 'lines' => $this->input()['lines']]), 'INVALID_STATE');
        $db->used = true;
        $this->reject(fn () => $service->action($this->actor(), $number, 'return_to_draft', ['expected_version' => 2]), 'INVALID_STATE');
        self::assertSame([], $service->get($this->actor(), $number)['available_actions']);
        $db->used = false;
        $draft = $service->action($this->actor(), $number, 'return_to_draft', ['expected_version' => 2]);
        self::assertSame('Draft', $draft['status']);
        self::assertSame(3, $draft['current_version']);
        $deleted = $service->action($this->actor(), $number, 'delete', ['expected_version' => 3]);
        self::assertTrue($deleted['deleted']);
        self::assertNull($deleted['current_version']);
        self::assertSame([], $db->lines);
        $this->reject(fn () => $service->get($this->actor(), $number), 'NOT_FOUND');
        self::assertNotSame($number, $service->create($this->actor(), $this->input())['quote_number']);
    }

    public function testReplayResolvesBeforeVersionAndRejectsChangedPayloadAndPermission(): void
    {
        $db = new QuoteMemoryConnection(); $service = $this->service($db);
        $input = [...$this->input(), 'action_reference' => 'create-1'];
        $saved = $service->create($this->actor(), $input);
        self::assertSame($saved, $service->create($this->actor(), $input));
        $number = $saved['quote_number'];
        $submit = ['expected_version' => 1, 'action_reference' => 'submit-1'];
        $submitted = $service->action($this->actor(), $number, 'submit', $submit);
        self::assertSame($submitted, $service->action($this->actor(), $number, 'submit', $submit));
        self::assertCount(2, $db->audit);
        $this->reject(fn () => $service->create($this->actor(), [...$input, 'notes' => 'changed']), 'DUPLICATE_ACTION_CONFLICT');
        $this->reject(fn () => $service->create($this->actor(7, false), $input), 'FORBIDDEN');
    }

    public function testInvalidInputsNeverLeavePartialWrites(): void
    {
        $db = new QuoteMemoryConnection(); $service = $this->service($db);
        foreach ([['lines' => []], ['lines' => [['product_id' => '163', 'description' => 'X', 'quantity' => '1.000', 'unit_price' => '1']]],
            ['customer_code' => 'FOREIGN'], ['quote_date' => '2026-02-30'], ['valid_until' => '2026-11-22'],
            ['shipping_address' => null], ['status' => 'Submitted'], ['action_reference' => null], ['payment_terms' => null]] as $change) {
            $this->reject(fn () => $service->create($this->actor(), [...$this->input(), ...$change]), 'VALIDATION_ERROR');
        }
        self::assertSame([], $db->documents); self::assertSame([], $db->actions);
        $db->failAudit = true;
        try { $service->create($this->actor(), $this->input()); self::fail('Audit should fail'); }
        catch (DatabaseError) { self::assertSame([], $db->documents); self::assertSame([], $db->lines); self::assertSame([], $db->actions); }
    }

    public function testForeignDuplicateOrExplicitlyInvalidLineCollectionsAreRejected(): void
    {
        $db = new QuoteMemoryConnection(); $service = $this->service($db);
        $saved = $service->create($this->actor(), $this->input()); $number = $saved['quote_number'];
        $line = [...$this->input()['lines'][0], 'line_id' => $saved['lines'][0]['line_id']];
        foreach ([['lines' => []], ['lines' => null], ['lines' => 'invalid'], ['lines' => [$line, $line]], ['lines' => [[...$line, 'line_id' => '9999']]]] as $change) {
            $this->reject(fn () => $service->update($this->actor(), $number, ['expected_version' => 1, ...$change]), 'VALIDATION_ERROR');
        }
        self::assertSame($saved, $service->get($this->actor(), $number));
    }

    public function testDateOnlyApiUpdateAfterReturnToDraftPreservesEverySavedLine(): void
    {
        $db = new QuoteMemoryConnection(); $service = $this->service($db);
        $saved = $service->create($this->actor(), [...$this->input(), 'quote_date' => '2026-11-26',
            'valid_until' => '2026-11-26', 'requested_delivery_date' => '2026-11-29']);
        $number = $saved['quote_number'];
        $service->action($this->actor(), $number, 'submit', ['expected_version' => 1]);
        $draft = $service->action($this->actor(), $number, 'return_to_draft', ['expected_version' => 2]);
        $lines = $db->lines; $writes = $db->lineWrites; $queries = $db->inventoryQueries;
        $db->inventoryUnavailable = true; // Draft header edits must not depend on Inventory availability.
        $api = new Api($db, Config::fromArray([]));
        $headers = ['authorization' => 'Bearer ' . str_repeat('a', 64), 'content-type' => 'application/json'];
        $url = '/api/v1/sales/quotes/' . $number . '/update';
        $request = ['expected_version' => 3, 'quote_date' => '2026-11-27',
            'valid_until' => '2026-11-27', 'requested_delivery_date' => '2026-11-29'];
        $response = $api->handle('POST', $url, $request, $headers);
        self::assertSame(200, $response['status'], $response['body']);
        $result = json_decode($response['body'], true)['result'];
        self::assertSame('2026-11-27', $result['quote_date']); self::assertSame('2026-11-27', $result['valid_until']);
        self::assertSame('2026-11-29', $result['requested_delivery_date']); self::assertSame(4, $result['current_version']);
        self::assertSame($draft['lines'], $result['lines']); self::assertSame($draft['document_total'], $result['document_total']);
        self::assertSame($lines, $db->lines); self::assertSame($writes, $db->lineWrites);
        self::assertSame($queries, $db->inventoryQueries);
        self::assertSame(409, $api->handle('POST', $url, $request, $headers)['status']);
    }

    public function testNotesOnlyUpdatePreservesLinesAndRetainsReplayAndStateGuards(): void
    {
        $db = new QuoteMemoryConnection(); $service = $this->service($db);
        $saved = $service->create($this->actor(), $this->input()); $number = $saved['quote_number'];
        $writes = $db->lineWrites;
        $input = ['expected_version' => 1, 'notes' => 'Header only', 'action_reference' => 'header-only-edit'];
        $updated = $service->update($this->actor(), $number, $input);
        self::assertSame('Header only', $updated['notes']); self::assertSame($saved['lines'], $updated['lines']);
        self::assertSame($saved['document_total'], $updated['document_total']); self::assertSame(2, $updated['current_version']);
        self::assertSame($updated, $service->update($this->actor(), $number, $input));
        self::assertSame($writes, $db->lineWrites); self::assertCount(2, $db->audit);
        $this->reject(fn () => $service->update($this->actor(), $number, [...$input, 'notes' => 'Different']), 'DUPLICATE_ACTION_CONFLICT');
        $this->reject(fn () => $service->update($this->actor(7, false), $number, ['expected_version' => 2, 'notes' => 'Denied']), 'FORBIDDEN');
        $this->reject(fn () => $service->update($this->actor(8), $number, ['expected_version' => 2, 'notes' => 'Foreign']), 'NOT_FOUND');
        $service->action($this->actor(), $number, 'submit', ['expected_version' => 2]);
        $this->reject(fn () => $service->update($this->actor(), $number, ['expected_version' => 3, 'notes' => 'Submitted']), 'INVALID_STATE');
    }

    public function testHeaderOnlyInvalidDatesAndAuditFailurePreserveHeaderAndLines(): void
    {
        $db = new QuoteMemoryConnection(); $service = $this->service($db);
        $saved = $service->create($this->actor(), [...$this->input(), 'quote_date' => '2026-11-26',
            'valid_until' => '2026-11-26', 'requested_delivery_date' => '2026-11-29']);
        $number = $saved['quote_number']; $lines = $db->lines; $writes = $db->lineWrites;
        foreach ([['quote_date' => '2026-11-27'], ['valid_until' => '2026-11-25'],
            ['requested_delivery_date' => '2026-11-25'], ['quote_date' => 'not-a-date']] as $change) {
            $this->reject(fn () => $service->update($this->actor(), $number, ['expected_version' => 1, ...$change]), 'VALIDATION_ERROR');
            self::assertSame($saved, $service->get($this->actor(), $number));
        }
        $db->failAudit = true;
        try { $service->update($this->actor(), $number, ['expected_version' => 1, 'notes' => 'Must roll back']); self::fail('Audit must fail.'); }
        catch (DatabaseError) { self::assertSame($saved, $service->get($this->actor(), $number)); }
        self::assertSame($lines, $db->lines); self::assertSame($writes, $db->lineWrites);
        self::assertCount(1, $db->actions); self::assertCount(1, $db->audit);
    }

    public function testDraftSavesWithoutInventoryAndFailedSubmissionPreservesDraft(): void
    {
        $db = new QuoteMemoryConnection();
        $db->inventoryUnavailable = true;
        $service = new QuoteService($db);
        $saved = $service->create($this->actor(), $this->input());
        $saved = $service->update($this->actor(), $saved['quote_number'], ['expected_version' => 1, 'lines' => $this->input()['lines'], 'notes' => 'Draft edit']);
        self::assertSame('Draft', $saved['status']);
        self::assertSame(2, $saved['current_version']);
        $this->reject(fn () => $service->action($this->actor(), $saved['quote_number'], 'submit', ['expected_version' => 2]), 'INVENTORY_UNAVAILABLE');
        self::assertSame($saved, $service->get($this->actor(), $saved['quote_number']));
        self::assertCount(2, $db->actions); self::assertCount(2, $db->audit);
    }

    public function testInventoryReferencesAreValidatedOnSubmit(): void
    {
        $db = new QuoteMemoryConnection(); $service = $this->service($db);
        $input = $this->input();
        $input['lines'][0]['product_id'] = '999';
        $saved = $service->create($this->actor(), $input);
        $this->reject(fn () => $service->action($this->actor(), $saved['quote_number'], 'submit', ['expected_version' => 1]), 'VALIDATION_ERROR');
        self::assertSame($saved, $service->get($this->actor(), $saved['quote_number']));
    }

    public function testApiRoutesAuthenticationMediaTypeAndPostUpdate(): void
    {
        $db = new QuoteMemoryConnection(); $service = $this->service($db);
        $saved = $service->create($this->actor(), $this->input());
        $api = new Api($db, Config::fromArray([]));
        $headers = ['authorization' => 'Bearer ' . str_repeat('a', 64), 'content-type' => 'application/json'];
        $url = '/api/v1/sales/quotes/' . $saved['quote_number'];
        self::assertSame(401, $api->handle('GET', $url, [])['status']);
        self::assertSame(415, $api->handle('POST', '/api/v1/sales/quotes', $this->input(), ['authorization' => $headers['authorization']])['status']);
        $read = $api->handle('GET', $url, [], $headers);
        self::assertSame(200, $read['status']);
        self::assertSame($saved, json_decode($read['body'], true)['result']);
        self::assertSame(200, $api->handle('GET', '/api/v1/sales/quotes', [], $headers)['status']);
        self::assertSame(409, $api->handle('POST', $url . '/update', ['expected_version' => 99, 'lines' => $this->input()['lines']], $headers)['status']);
        self::assertSame(404, $api->handle('PATCH', $url, ['expected_version' => 1, 'lines' => $this->input()['lines']], $headers)['status']);
        self::assertSame(201, $api->handle('POST', '/api/v1/sales/quotes', $this->input(), $headers)['status']);
        $db->inventoryUnavailable = true;
        self::assertSame(503, $api->handle('POST', $url . '/submit', ['expected_version' => 1], $headers)['status']);
        $deleted = $api->handle('POST', $url . '/delete', ['expected_version' => 1], $headers);
        self::assertSame(200, $deleted['status']);
        self::assertNull(json_decode($deleted['body'], true)['operation_id']);
        self::assertSame(404, $api->handle('GET', $url, [], $headers)['status']);
    }

    public function testHttpDecodedJsonLinesCanBeCreatedAndUpdated(): void
    {
        $db = new QuoteMemoryConnection();
        $api = new Api($db, Config::fromArray([]));
        $headers = ['authorization' => 'Bearer ' . str_repeat('a', 64), 'content-type' => 'application/json'];
        // Match public/index.php: only the top JSON object is converted to an array.
        $input = get_object_vars(json_decode(json_encode($this->input(), JSON_THROW_ON_ERROR), false, 32, JSON_THROW_ON_ERROR));
        self::assertInstanceOf(\stdClass::class, $input['lines'][0]);
        $created = $api->handle('POST', '/api/v1/sales/quotes', $input, $headers);
        self::assertSame(201, $created['status'], $created['body']);
        $saved = json_decode($created['body'], true, 512, JSON_THROW_ON_ERROR)['result'];
        self::assertSame('37.50', $saved['document_total']);
        $line = [...$this->input()['lines'][0], 'line_id' => $saved['lines'][0]['line_id'], 'quantity' => '4'];
        $update = get_object_vars(json_decode(json_encode(['expected_version' => 1, 'lines' => [$line]], JSON_THROW_ON_ERROR), false, 32, JSON_THROW_ON_ERROR));
        $updated = $api->handle('POST', '/api/v1/sales/quotes/' . $saved['quote_number'] . '/update', $update, $headers);
        self::assertSame(200, $updated['status'], $updated['body']);
        $result = json_decode($updated['body'], true, 512, JSON_THROW_ON_ERROR)['result'];
        self::assertSame('50.00', $result['document_total']);
        self::assertSame($saved['lines'][0]['line_id'], $result['lines'][0]['line_id']);
        self::assertSame(2, $result['current_version']);
    }

    public function testDecodedLinePropertyOrderDoesNotChangeRetryIdentity(): void
    {
        $db = new QuoteMemoryConnection(); $service = $this->service($db);
        $input = [...$this->input(), 'action_reference' => 'json-lines'];
        $decoded = get_object_vars(json_decode(json_encode($input, JSON_THROW_ON_ERROR), false, 32, JSON_THROW_ON_ERROR));
        $saved = $service->create($this->actor(), $decoded);
        $input['lines'] = array_map(static fn (array $line): array => array_reverse($line, true), $input['lines']);
        $reordered = get_object_vars(json_decode(json_encode($input, JSON_THROW_ON_ERROR), false, 32, JSON_THROW_ON_ERROR));
        self::assertSame($saved, $service->create($this->actor(), $reordered));
        self::assertCount(1, $db->audit);
    }

    public function testInvalidJsonLineTypesRemainRejected(): void
    {
        $db = new QuoteMemoryConnection(); $service = $this->service($db);
        foreach ([['string'], [null], [123], [['163', 'Product', '5', '125.50']], (object) ['0' => (object) $this->input()['lines'][0]]] as $lines) {
            $this->reject(fn () => $service->create($this->actor(), [...$this->input(), 'lines' => $lines]), 'VALIDATION_ERROR');
        }
        self::assertSame([], $db->documents);
    }

    public function testExactAmountsAndCapacityWithoutFloatRounding(): void
    {
        self::assertSame('0.30', QuoteMoney::format(QuoteMoney::multiply('3', '0.10')));
        self::assertSame('9999999999999999.99', QuoteMoney::format(QuoteMoney::add('999999999999999998', '1')));
        self::assertSame('9999999980000000.01', QuoteMoney::format(QuoteMoney::multiply('999999999', '9999999.99')));
        $this->reject(fn () => QuoteMoney::multiply('999999999', '9999999999.99'), 'VALIDATION_ERROR');
        $this->reject(fn () => QuoteMoney::add('999999999999999999', '1'), 'VALIDATION_ERROR');
    }

    public function testUpdateGrantAuthorizesQuoteWorkflowAndReadOnlyCannotMutate(): void
    {
        $db = new QuoteMemoryConnection(); $service = $this->service($db);
        $saved = $service->create($this->actor(), $this->input()); $number = $saved['quote_number'];
        $api = new Api($db, Config::fromArray([]));
        $db->apiAdmin = false;
        $db->grants = ['sales.quotes:read', 'sales.quotes:update'];
        $headers = ['authorization' => 'Bearer ' . str_repeat('a', 64), 'content-type' => 'application/json'];
        // A provider query failure proves Update authorized the route without changing the Draft.
        $db->inventoryUnavailable = true;
        self::assertSame(503, $api->handle('POST', '/api/v1/sales/quotes/' . $number . '/submit', ['expected_version' => 1], $headers)['status']);
        $db->inventoryUnavailable = false;
        foreach ([1, 2] as $version) {
            self::assertSame(200, $api->handle('POST', '/api/v1/sales/quotes/' . $number . '/update', ['expected_version' => $version, 'lines' => $this->input()['lines']], $headers)['status']);
        }
        $submitted = $service->action($this->actor(7, false), $number, 'submit', ['expected_version' => 3]);
        self::assertSame('Submitted', $submitted['status']);
        $returned = $api->handle('POST', '/api/v1/sales/quotes/' . $number . '/move-to-draft', ['expected_version' => 4], $headers);
        self::assertSame(200, $returned['status']);
        $db->grants = ['sales.quotes:read'];
        self::assertSame([], $service->get($this->actor(7, false), $number)['available_actions']);
        foreach (['submit', 'move-to-draft', 'delete'] as $action) {
            self::assertSame(403, $api->handle('POST', '/api/v1/sales/quotes/' . $number . '/' . $action, ['expected_version' => 3], $headers)['status']);
        }
    }

    public function testLineErrorsIdentifyTheAffectedLineAndRollback(): void
    {
        $db = new QuoteMemoryConnection(); $service = $this->service($db);
        $input = $this->input();
        foreach ([['quantity' => '1.5'], ['unit_price' => '1.234'], ['description' => ' '], ['line_id' => '999'], ['line_amount' => '1.00']] as $change) {
            $invalid = $input;
            $invalid['lines'][1] = [...$input['lines'][1], ...$change];
            try { $service->create($this->actor(), $invalid); self::fail('Expected line validation'); }
            catch (AppError $error) { self::assertStringStartsWith('Line 2: ', $error->getMessage()); }
            self::assertSame([], $db->documents); self::assertSame([], $db->actions);
        }
    }

    public function testRealInventoryValidatorThroughSubmitReturnAndFailureRollback(): void
    {
        $db = new QuoteMemoryConnection();
        $api = new Api($db, Config::fromArray([]));
        $headers = ['authorization' => 'Bearer ' . str_repeat('a', 64), 'content-type' => 'application/json'];
        $created = $api->handle('POST', '/api/v1/sales/quotes', $this->input(), $headers);
        self::assertSame(201, $created['status']);
        self::assertSame(0, $db->inventoryQueries);
        $number = json_decode($created['body'], true)['result']['quote_number'];
        $url = '/api/v1/sales/quotes/' . $number;
        $input = ['expected_version' => 1, 'action_reference' => 'real-submit'];
        $submitted = $api->handle('POST', $url . '/submit', $input, $headers);
        self::assertSame(200, $submitted['status'], $submitted['body']);
        self::assertSame('Submitted', json_decode($submitted['body'], true)['result']['status']);
        self::assertSame(2, json_decode($submitted['body'], true)['current_version']);
        self::assertSame($submitted['body'], $api->handle('POST', $url . '/submit', $input, $headers)['body']);
        self::assertSame(1, $db->inventoryQueries);
        $returned = $api->handle('POST', $url . '/move-to-draft', ['expected_version' => 2], $headers);
        self::assertSame(200, $returned['status']);
        self::assertSame('Draft', json_decode($returned['body'], true)['result']['status']);
        $draft = json_decode($returned['body'], true)['result'];
        foreach ([
            [[], 400, 'VALIDATION_ERROR'],
            [[['COMPANY_ID' => 7, 'PRODUCT_ID' => '163', 'WAREHOUSE_ID' => 'W01', 'IS_ACTIVE' => 'N']], 409, 'INVALID_STATE'],
            [[['COMPANY_ID' => 8, 'PRODUCT_ID' => '163', 'WAREHOUSE_ID' => 'W01', 'IS_ACTIVE' => 'Y']], 400, 'VALIDATION_ERROR'],
            [[['COMPANY_ID' => 7, 'PRODUCT_ID' => '163', 'WAREHOUSE_ID' => 'W02', 'IS_ACTIVE' => 'Y']], 400, 'VALIDATION_ERROR'],
        ] as [$products, $status, $code]) {
            $db->inventoryProducts = $products;
            $failed = $api->handle('POST', $url . '/submit', ['expected_version' => 3], $headers);
            self::assertSame($status, $failed['status']);
            self::assertSame($code, json_decode($failed['body'], true)['error']['code']);
            self::assertSame($draft, json_decode($api->handle('GET', $url, [], $headers)['body'], true)['result']);
            self::assertCount(3, $db->audit);
            self::assertCount(3, $db->actions);
        }
        $db->inventoryUnavailable = true;
        $failed = $api->handle('POST', $url . '/submit', ['expected_version' => 3], $headers);
        self::assertSame(503, $failed['status']);
        self::assertSame('INVENTORY_UNAVAILABLE', json_decode($failed['body'], true)['error']['code']);
        self::assertSame($draft, json_decode($api->handle('GET', $url, [], $headers)['body'], true)['result']);
        self::assertCount(3, $db->audit);
    }


    public function testProductReferenceRejectsSkuAndInvalidNumericIdsWithoutSaving(): void
    {
        $db = new QuoteMemoryConnection(); $service = $this->service($db);
        foreach (['ITEM-001', '0', '-1', '1.5', '1e2', '10000000000'] as $productId) {
            $input = $this->input();
            $input['lines'][0]['product_id'] = $productId;
            $this->reject(fn () => $service->create($this->actor(), $input), 'VALIDATION_ERROR');
            self::assertSame([], $db->documents);
            self::assertSame([], $db->lines);
        }
        $saved = $service->create($this->actor(), $this->input());
        self::assertSame('163', $saved['lines'][0]['product_id']);
        self::assertSame('163', array_values($db->lines)[0]['PRODUCT_ID']);
    }

    public function testLegacyItemCodeAndUnsupportedWarehouseAreRejected(): void
    {
        $db = new QuoteMemoryConnection(); $service = $this->service($db);
        $input = $this->input();
        unset($input['lines'][0]['product_id']);
        $input['lines'][0]['item_code'] = '163';
        $this->reject(fn () => $service->create($this->actor(), $input), 'VALIDATION_ERROR');
        $this->reject(fn () => $service->create($this->actor(), [...$this->input(), 'warehouse_id' => 'WH-001']), 'VALIDATION_ERROR');
        self::assertSame([], $db->documents);
        self::assertSame([], $db->lines);
    }

}

/** Transactional behavior double; does not verify Oracle syntax or concurrent locks. */
final class QuoteMemoryConnection implements Connection
{
    public array $documents = [], $lines = [], $actions = [], $audit = [];
    public array $grants = [];
    public bool $apiAdmin = true;
    public bool $used = false, $failAudit = false;
    public array $inventoryProducts = [['COMPANY_ID' => 7, 'PRODUCT_ID' => '163', 'WAREHOUSE_ID' => 'W01', 'IS_ACTIVE' => 'Y']];
    public bool $inventoryUnavailable = false;
    public int $inventoryQueries = 0;
    public int $lineWrites = 0;
    private int $sequence = 0, $lineSequence = 0;
    private ?array $snapshot = null;
    public function begin(): void { if ($this->snapshot !== null) { throw new \LogicException('Nested transaction'); } $this->snapshot = [$this->documents, $this->lines, $this->actions, $this->audit]; }
    public function commit(): void { $this->snapshot = null; }
    public function rollback(): void { [$this->documents, $this->lines, $this->actions, $this->audit] = $this->snapshot; $this->snapshot = null; }
    public function executeSchema(string $sql): void { throw new \LogicException('No DDL'); }
    public function query(string $sql, array $p = []): array
    {
        if (str_contains($sql, 'FROM ME_INV_PRODUCTS')) {
            $this->inventoryQueries++;
            if ($this->inventoryUnavailable) { throw new DatabaseError(942); }
            $company = $p['company_id'];
            unset($p['company_id']);
            return array_values(array_filter($this->inventoryProducts, static fn (array $row): bool =>
                $row['COMPANY_ID'] === $company && in_array((int) $row['PRODUCT_ID'], array_values($p), true)));
        }
        if (str_contains($sql, 'FROM ME_API_TOKENS')) { return [['USER_ID' => 'admin', 'COMPANY_ID' => 7, 'SECURITY_VERSION' => 1, 'EXPIRES_AT' => time() + 3600, 'REVOKED_AT' => null]]; }
        if (str_contains($sql, 'JOIN ME_COMPANY')) { return [['ID' => 'admin', 'USERNAME' => 'admin', 'IS_ADMIN' => $this->apiAdmin ? 1 : 0, 'COMPANY_ID' => 7, 'SECURITY_VERSION' => 1, 'MUST_CHANGE_PASSWORD' => 0, 'COMPANY_NAME' => 'Test']]; }
        if (str_contains($sql, 'FROM ME_USER_ROLES')) { return []; }
        if (str_contains($sql, 'FROM ME_OBJECTS')) { return [['ACTIVE' => 1, 'ALLOW_CREATE' => 1, 'ALLOW_READ' => 1, 'ALLOW_UPDATE' => 1, 'ALLOW_DELETE' => 1]]; }
        if (str_contains($sql, 'FROM ME_OBJECT_ACTIONS')) { return [['ACTION_CODE' => $p['operation']]]; }
        if (str_contains($sql, 'FROM ME_USER_PERMISSIONS')) { return in_array($p['object'] . ':' . $p['operation'], $this->grants, true) ? [['USER_ID' => $p['user_id']]] : []; }
        if (str_contains($sql, '.NEXTVAL')) { return [['NEXT_NUMBER' => ++$this->sequence]]; }
        if (!isset($p['company_id']) || !str_contains($sql, 'CNY# = :company_id') && !str_contains($sql, 'COMPANY_ID = :company_id')) { throw new \LogicException('Unscoped read: ' . $sql); }
        if (str_contains($sql, 'FROM ME_ACTIONS')) { $row = $this->actions[$p['company_id'] . ':' . $p['reference']] ?? null; return $row ? [$row] : []; }
        if (str_contains($sql, 'FROM ME_OE_CUSTOMERS')) {
            if ($p['company_id'] !== 7 || isset($p['customer_code']) && $p['customer_code'] !== 'CUST-001') { return []; }
            return [['RECORD#' => 1, 'CUSTOMER_CODE' => 'CUST-001', 'CUSTOMER_NAME' => 'Example Traders', 'IS_ACTIVE' => 'T', 'BILLING_ADDRESS_LINE_1' => 'Market Road', 'CITY' => 'Bengaluru', 'COUNTRY' => 'India', 'PAYMENT_TERMS' => 'NET_30']];
        }
        if (str_contains($sql, 'FROM ME_OE_SOURCE_LINKS')) { return $this->used ? [['RECORD#' => 99]] : []; }
        if (str_contains($sql, 'FROM ME_OE_DOCUMENT_LINES')) {
            $lines = array_values(array_filter($this->lines, fn ($r) => $r['CNY#'] === $p['company_id'] && $r['DOCUMENT_RECORD#'] === $p['id']));
            usort($lines, fn ($a, $b) => $a['LINE_NUMBER'] <=> $b['LINE_NUMBER']); return $lines;
        }
        if (str_contains($sql, 'FROM ME_OE_DOCUMENTS')) {
            return array_values(array_filter($this->documents, fn ($r) => $r['CNY#'] === $p['company_id'] && (!isset($p['document_number']) || $r['DOCUMENT_NUMBER'] === $p['document_number'])));
        }
        throw new \LogicException('Unexpected query: ' . $sql);
    }
    public function execute(string $sql, array $p = []): int
    {
        if ($this->snapshot === null || !isset($p['company_id'])) { throw new \LogicException('Unscoped/nontransactional write'); }
        if (preg_match('/\A(?:INSERT INTO|UPDATE|DELETE FROM) ME_OE_DOCUMENT_LINES\b/', $sql)) { $this->lineWrites++; }
        $key = $p['company_id'] . ':' . ($p['reference'] ?? '');
        if (str_starts_with($sql, 'INSERT INTO ME_ACTIONS')) {
            if (isset($this->actions[$key])) { throw new DatabaseError(1); }
            $this->actions[$key] = ['ACTOR_ID' => $p['actor'], 'PAYLOAD_HASH' => $p['hash'], 'RESULT_JSON' => null];
        } elseif (str_starts_with($sql, 'UPDATE ME_ACTIONS')) { $this->actions[$key]['RESULT_JSON'] = $p['result'];
        } elseif (str_starts_with($sql, 'INSERT INTO ME_AUDIT')) {
            if ($this->failAudit) { throw new DatabaseError(3113); } $this->audit[] = $p;
        } elseif (str_starts_with($sql, 'INSERT INTO ME_OE_DOCUMENTS') || str_starts_with($sql, 'UPDATE ME_OE_DOCUMENTS')) {
            $id = $p['id'] ?? $this->sequence; $old = $this->documents[$id] ?? [];
            if (isset($p['customer_record'])) {
                $this->documents[$id] = [...$old, 'RECORD#' => $id, 'CNY#' => $p['company_id'], 'DOCUMENT_NUMBER' => $p['document_number'] ?? $old['DOCUMENT_NUMBER'],
                    'CUSTOMER_RECORD#' => $p['customer_record'], 'WAREHOUSE_ID' => $p['warehouse_id'], 'QUOTE_DATE' => $p['quote_date'], 'VALID_UNTIL_TEXT' => $p['valid_until'],
                    'DELIVERY_DATE_TEXT' => $p['requested_delivery_date'], 'SHIPPING_ADDRESS' => $p['shipping_address'], 'PAYMENT_TERMS' => $p['payment_terms'], 'NOTES' => $p['notes'], 'STATUS' => $old['STATUS'] ?? 'DRAFT', 'VERSION' => ($old['VERSION'] ?? 0) + 1];
            } else { $this->documents[$id]['STATUS'] = $p['status']; $this->documents[$id]['VERSION']++; }
        } elseif (str_starts_with($sql, 'DELETE FROM ME_OE_DOCUMENTS')) { unset($this->documents[$p['id']]);
        } elseif (str_starts_with($sql, 'DELETE FROM ME_OE_DOCUMENT_LINES')) {
            foreach ($this->lines as $id => $r) { if ($r['CNY#'] === $p['company_id'] && $r['DOCUMENT_RECORD#'] === $p['id'] && (!isset($p['line_id']) || $id == $p['line_id'])) { unset($this->lines[$id]); } }
        } elseif (isset($p['shift'])) {
            foreach ($this->lines as &$r) { if ($r['CNY#'] === $p['company_id'] && $r['DOCUMENT_RECORD#'] === $p['id']) { $r['LINE_NUMBER'] += $p['shift']; } }
        } elseif (str_contains($sql, 'ME_OE_DOCUMENT_LINES')) {
            $id = $p['line_id'] ?? ++$this->lineSequence;
            $this->lines[$id] = ['RECORD#' => $id, 'CNY#' => $p['company_id'], 'DOCUMENT_RECORD#' => $p['id'], 'LINE_NUMBER' => $p['line_number'], 'PRODUCT_ID' => $p['product_id'],
                'DESCRIPTION' => $p['description'], 'QUANTITY_TEXT' => $p['quantity'], 'PRICE_TEXT' => QuoteMoney::format(QuoteMoney::multiply('1', $p['unit_price']))];
        } else { throw new \LogicException('Unexpected write: ' . $sql); }
        return 1;
    }
}
