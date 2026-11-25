<?php
declare(strict_types=1);

namespace MiniErp\Tests\unit\sales;

use MiniErp\Tests\unit\sales\fixtures\InvoiceMemoryConnection;
use MiniErp\sales\invoices\InvoiceService;
use MiniErp\shared\Api;
use MiniErp\shared\AppError;
use MiniErp\shared\Config;
use MiniErp\shared\database\Connection;
use MiniErp\shared\database\DatabaseError;
use MiniErp\shared\security\Actor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class InvoiceWorkflowTest extends TestCase
{
    private function actor(int $company = 7, bool $admin = true): Actor
    {
        return new Actor('admin', 'admin', [], $company, $admin);
    }

    private function service(InvoiceMemoryConnection $db): InvoiceService
    {
        return new InvoiceService($db, static fn (): string => '2026-11-24');
    }

    private function input(string $reference = 'create'): array
    {
        return ['action_reference' => $reference, 'customer_code' => 'CUST-0001', 'warehouse_id' => 'W01',
            'lines' => [['product_id' => '163', 'description' => 'Item A', 'quantity' => '2', 'unit_price' => '10']]];
    }

    private function reject(callable $operation, string $code, int $status = 400): void
    {
        try {
            $operation();
            self::fail('Expected ' . $code);
        } catch (AppError $error) {
            self::assertSame($code, $error->errorCode);
            self::assertSame($status, $error->status);
        }
    }

    public function testCreateGetDefaultsAndExactLineAmountsDoNotAffectBalance(): void
    {
        $db = new InvoiceMemoryConnection();
        $service = $this->service($db);
        $saved = $service->create($this->actor(), $this->input());
        self::assertSame('SI-000000000000001', $saved['invoice_number']);
        self::assertSame('Draft', $saved['status']);
        self::assertSame(1, $saved['current_version']);
        self::assertSame('2026-11-24', $saved['invoice_date']);
        self::assertSame('2026-12-24', $saved['due_date']);
        self::assertSame('20.00', $saved['document_total']);
        self::assertSame('20.00', $saved['amount_due']);
        self::assertSame('0.00', $saved['total_paid']);
        self::assertNull($saved['payment_status']);
        self::assertSame('10.00', $saved['lines'][0]['unit_price']);
        self::assertSame('163', $saved['lines'][0]['product_id']);
        self::assertArrayNotHasKey('item_code', $saved['lines'][0]);
        self::assertSame('163', (string) array_values($db->lines)[0]['PRODUCT_ID']);
        self::assertSame($saved, $service->get($this->actor(), $saved['invoice_number']));
        self::assertSame('0.00', $db->customers[1]['BALANCE']);
        self::assertCount(1, $db->audit);
    }

    public function testReplayReturnsOriginalSnapshotAndDoesNotConsumeAnotherNumber(): void
    {
        $db = new InvoiceMemoryConnection();
        $service = $this->service($db);
        $original = $service->create($this->actor(), $this->input());
        $service->update($this->actor(), $original['invoice_number'], [
            'action_reference' => 'update', 'expected_version' => 1, 'notes' => 'Changed', 'lines' => $this->editable($original),
        ]);
        self::assertSame($original, $service->create($this->actor(), array_reverse($this->input(), true)));
        self::assertSame(2, $db->nextNumber);
        $this->reject(fn () => $service->create($this->actor(), [...$this->input(), 'notes' => 'Other']), 'DUPLICATE_ACTION_CONFLICT', 409);
        self::assertCount(1, $db->documents);
    }

    public function testProductMustBelongToCompanyAndWarehouseAndBeActive(): void
    {
        foreach (['COMPANY_ID' => 8, 'WAREHOUSE_ID' => 'W02', 'IS_ACTIVE' => 'N'] as $field => $value) {
            $db = new InvoiceMemoryConnection();
            $db->products[163][$field] = $value;
            $this->reject(fn () => $this->service($db)->create($this->actor(), $this->input()), 'VALIDATION_ERROR');
            self::assertSame([], $db->documents);
            self::assertSame([], $db->actions);
        }
    }

    private function editable(array $invoice): array
    {
        return array_map(static fn (array $line): array => array_intersect_key($line,
            array_flip(['line_id', 'product_id', 'description', 'quantity', 'unit_price', ...($line['source_line_id'] === null ? [] : ['source_line_id'])])), $invoice['lines']);
    }

    public function testUpdateReordersStableLinesAndRecalculatesDueDate(): void
    {
        $db = new InvoiceMemoryConnection();
        $service = $this->service($db);
        $input = $this->input();
        $input['lines'][] = ['product_id' => '164', 'description' => 'Item B', 'quantity' => '1', 'unit_price' => '0.25'];
        $saved = $service->create($this->actor(), $input);
        $lines = array_reverse($this->editable($saved));
        $updated = $service->update($this->actor(), $saved['invoice_number'], ['action_reference' => 'edit',
            'expected_version' => 1, 'invoice_date' => '2028-02-29', 'payment_terms' => 'NET_15', 'lines' => $lines]);
        self::assertSame(2, $updated['current_version']);
        self::assertSame('2028-03-15', $updated['due_date']);
        self::assertSame(array_column($lines, 'line_id'), array_column($updated['lines'], 'line_id'));
        self::assertSame([1, 2], array_column($updated['lines'], 'line_number'));
        self::assertSame('20.25', $updated['document_total']);
        $this->reject(fn () => $service->update($this->actor(), $saved['invoice_number'], [
            'action_reference' => 'stale', 'expected_version' => 1, 'lines' => $lines]), 'STALE_VERSION', 409);
        self::assertArrayNotHasKey('7:stale', $db->actions);
    }

    public function testUpdateCanRemoveAddLinesAndPreserveExplicitDueDate(): void
    {
        $db = new InvoiceMemoryConnection();
        $service = $this->service($db);
        $saved = $service->create($this->actor(), $this->input());
        $updated = $service->update($this->actor(), $saved['invoice_number'], ['action_reference' => 'replace',
            'expected_version' => 1, 'due_date' => '2027-01-01', 'lines' => [[
                'product_id' => '164', 'description' => 'Replacement', 'quantity' => '1', 'unit_price' => '0.10',
            ]]]);
        self::assertNotSame($saved['lines'][0]['line_id'], $updated['lines'][0]['line_id']);
        self::assertSame('0.10', $updated['document_total']);
        self::assertSame('2027-01-01', $updated['due_date']);
        self::assertCount(1, $db->lines);
    }

    public function testSubmitReturnResubmitAndRetriesMaintainBalanceExactlyOnce(): void
    {
        $db = new InvoiceMemoryConnection();
        $db->customers[1]['BALANCE'] = '100.01';
        $service = $this->service($db);
        $saved = $service->create($this->actor(), $this->input());
        $submit = ['action_reference' => 'submit', 'expected_version' => 1];
        $submitted = $service->action($this->actor(), $saved['invoice_number'], 'submit', $submit);
        self::assertSame('Submitted', $submitted['status']);
        self::assertSame('Unpaid', $submitted['payment_status']);
        self::assertSame('120.01', $db->customers[1]['BALANCE']);
        self::assertSame($submitted, $service->action($this->actor(), $saved['invoice_number'], 'submit', $submit));
        $returned = $service->action($this->actor(), $saved['invoice_number'], 'return_to_draft', [
            'action_reference' => 'return', 'expected_version' => 2]);
        self::assertSame('Draft', $returned['status']);
        self::assertNull($returned['payment_status']);
        self::assertSame('100.01', $db->customers[1]['BALANCE']);
        $service->action($this->actor(), $saved['invoice_number'], 'submit', ['action_reference' => 'resubmit', 'expected_version' => 3]);
        self::assertSame('120.01', $db->customers[1]['BALANCE']);
        self::assertCount(4, $db->audit);
    }

    public function testZeroTotalDraftCannotSubmitAndCustomerCapacityCannotOverflow(): void
    {
        $db = new InvoiceMemoryConnection();
        $service = $this->service($db);
        $input = $this->input();
        $input['lines'][0]['unit_price'] = '0';
        $saved = $service->create($this->actor(), $input);
        $this->reject(fn () => $service->action($this->actor(), $saved['invoice_number'], 'submit',
            ['action_reference' => 'zero', 'expected_version' => 1]), 'VALIDATION_ERROR');
        self::assertSame('0.00', $db->customers[1]['BALANCE']);
        $next = $service->create($this->actor(), $this->input('next'));
        $db->customers[1]['BALANCE'] = '9999999999999999.99';
        $this->reject(fn () => $service->action($this->actor(), $next['invoice_number'], 'submit',
            ['action_reference' => 'overflow', 'expected_version' => 1]), 'VALIDATION_ERROR');
        self::assertSame('DRAFT', $db->documents[2]['STATUS']);
        self::assertArrayNotHasKey('7:overflow', $db->actions);
    }

    public function testPaymentsAndSubmittedStateBlockReturnOrDeletion(): void
    {
        $db = new InvoiceMemoryConnection();
        $service = $this->service($db);
        $saved = $service->create($this->actor(), $this->input());
        $service->action($this->actor(), $saved['invoice_number'], 'submit', ['action_reference' => 'submit', 'expected_version' => 1]);
        $db->payments[] = ['CNY#' => 7, 'INVOICE_RECORD#' => 1, 'PAYMENT_AMOUNT' => '5.00'];
        foreach (['return_to_draft', 'delete'] as $action) {
            $this->reject(fn () => $service->action($this->actor(), $saved['invoice_number'], $action,
                ['action_reference' => $action, 'expected_version' => 2]), 'INVALID_STATE', 409);
        }
        self::assertCount(2, $db->audit);
    }

    public function testDeleteDraftIsReplayableAndDoesNotReuseNumber(): void
    {
        $db = new InvoiceMemoryConnection();
        $service = $this->service($db);
        $saved = $service->create($this->actor(), $this->input());
        $input = ['action_reference' => 'delete', 'expected_version' => 1];
        $deleted = $service->action($this->actor(), $saved['invoice_number'], 'delete', $input);
        self::assertTrue($deleted['deleted']);
        self::assertNull($deleted['current_version']);
        self::assertSame($deleted, $service->action($this->actor(), $saved['invoice_number'], 'delete', $input));
        self::assertSame([], $db->lines);
        $this->reject(fn () => $service->get($this->actor(), $saved['invoice_number']), 'NOT_FOUND', 404);
        self::assertNotSame($saved['invoice_number'], $service->create($this->actor(), $this->input('new'))['invoice_number']);
    }

    public function testLaterLineFailureAndAuditFailureRollBackAllEffects(): void
    {
        $db = new InvoiceMemoryConnection();
        $service = $this->service($db);
        $input = $this->input();
        $input['lines'][] = ['product_id' => '164', 'description' => 'B', 'quantity' => '1', 'unit_price' => '1'];
        $db->failLine = 2;
        try {
            $service->create($this->actor(), $input);
            self::fail('Later line failure must propagate.');
        } catch (DatabaseError) {}
        self::assertSame([], $db->documents);
        self::assertSame([], $db->lines);
        self::assertSame([], $db->actions);
        $db->failLine = 0;
        $saved = $service->create($this->actor(), $this->input());
        $db->failAudit = true;
        $submit = ['action_reference' => 'submit', 'expected_version' => 1];
        try {
            $service->action($this->actor(), $saved['invoice_number'], 'submit', $submit);
            self::fail('Audit failure must propagate.');
        } catch (DatabaseError) {}
        self::assertSame('0.00', $db->customers[1]['BALANCE']);
        self::assertSame('Draft', $service->get($this->actor(), $saved['invoice_number'])['status']);
        $db->failAudit = false;
        $service->action($this->actor(), $saved['invoice_number'], 'submit', $submit);
        self::assertSame('20.00', $db->customers[1]['BALANCE']);
    }

    private function linked(InvoiceMemoryConnection $db, string $number = 'SO-1', int $id = 100): array
    {
        $db->documents[$id] = $db->document($id, $number, 'SALES_ORDER', 'IN_PROGRESS');
        $db->lines[$id] = $db->line($id, $id, 1, 163, 'Item A', '2', '10.00');
        return ['action_reference' => 'linked-' . $id, 'source_order_number' => $number,
            'lines' => [['source_line_id' => (string) $id, 'product_id' => '163', 'description' => 'Item A',
                'quantity' => '2', 'unit_price' => '10.00']]];
    }

    public function testLinkedCreateClaimsOrderAndRejectsSecondTargetOrChangedContents(): void
    {
        $db = new InvoiceMemoryConnection();
        $input = $this->linked($db);
        $service = $this->service($db);
        $saved = $service->create($this->actor(), $input);
        self::assertSame('SO-1', $saved['source_order_number']);
        self::assertSame('100', $saved['lines'][0]['source_line_id']);
        self::assertCount(1, $db->links);
        $this->reject(fn () => $service->create($this->actor(), [...$input, 'action_reference' => 'duplicate']), 'SOURCE_IN_USE', 409);
        $lines = $this->editable($saved);
        $lines[0]['quantity'] = '1';
        $this->reject(fn () => $service->update($this->actor(), $saved['invoice_number'], ['action_reference' => 'changed',
            'expected_version' => 1, 'lines' => $lines]), 'VALIDATION_ERROR');
        $submitted = $service->action($this->actor(), $saved['invoice_number'], 'submit', ['action_reference' => 'submit', 'expected_version' => 1]);
        self::assertSame('Submitted', $submitted['status']);
        $service->action($this->actor(), $saved['invoice_number'], 'return_to_draft', ['action_reference' => 'return', 'expected_version' => 2]);
        self::assertCount(1, $db->links, 'Return to Draft retains source claim.');
        $service->action($this->actor(), $saved['invoice_number'], 'delete', ['action_reference' => 'delete', 'expected_version' => 3]);
        self::assertSame([], $db->links);
    }

    public function testFailedSourceReplacementPreservesOriginalClaimAndLines(): void
    {
        $db = new InvoiceMemoryConnection();
        $input = $this->linked($db);
        $other = $this->linked($db, 'SO-2', 101);
        $service = $this->service($db);
        $saved = $service->create($this->actor(), $input);
        $db->failAudit = true;
        try {
            $service->update($this->actor(), $saved['invoice_number'], [...$other, 'action_reference' => 'replace', 'expected_version' => 1]);
            self::fail('Injected source replacement failure must roll back.');
        } catch (DatabaseError) {}
        self::assertSame($saved, $service->get($this->actor(), $saved['invoice_number']));
        self::assertSame(100, (int) array_values($db->links)[0]['SOURCE_DOCUMENT_RECORD#']);
    }

    public function testCompanyIsolationAndAuthorizationBeforeReplay(): void
    {
        $db = new InvoiceMemoryConnection();
        $service = $this->service($db);
        $saved = $service->create($this->actor(), $this->input());
        $this->reject(fn () => $service->get($this->actor(8), $saved['invoice_number']), 'NOT_FOUND', 404);
        $this->reject(fn () => $service->update($this->actor(8), $saved['invoice_number'], [
            'action_reference' => 'foreign', 'expected_version' => 1, 'lines' => $this->editable($saved)]), 'NOT_FOUND', 404);
        $this->reject(fn () => $service->create($this->actor(7, false), $this->input()), 'FORBIDDEN', 403);
        self::assertCount(1, $db->audit);
    }

    public function testRegisteredRoutesReturnCorrectEnvelopesAndRetryOriginalStatus(): void
    {
        $db = new InvoiceMemoryConnection();
        $api = new Api($db, Config::fromArray([]));
        $headers = ['authorization' => 'Bearer ' . str_repeat('a', 64), 'content-type' => 'application/json'];
        self::assertSame(401, $api->handle('POST', '/api/v1/sales/invoices', $this->input())['status']);
        // Match public/index.php: the outer body is an array; nested line objects are stdClass.
        $input = get_object_vars(json_decode(json_encode([...$this->input(), 'invoice_date' => '2026-11-24']), false));
        $created = $api->handle('POST', '/api/v1/sales/invoices', $input, $headers);
        self::assertSame(201, $created['status']);
        $body = json_decode($created['body'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('create', $body['operation_id']);
        self::assertSame(1, $body['current_version']);
        $number = $body['result']['invoice_number'];
        $url = '/api/v1/sales/invoices/' . $number;
        self::assertSame(200, $api->handle('GET', $url, [], $headers)['status']);
        $updated = $api->handle('POST', $url . '/update', ['action_reference' => 'edit', 'expected_version' => 1,
            'notes' => 'Edited', 'lines' => $this->editable($body['result'])], $headers);
        self::assertSame(200, $updated['status']);
        $submitted = $api->handle('POST', $url . '/submit', ['action_reference' => 'submit', 'expected_version' => 2], $headers);
        self::assertSame(200, $submitted['status']);
        self::assertSame(3, json_decode($submitted['body'], true)['current_version']);
        self::assertSame($created['body'], $api->handle('POST', '/api/v1/sales/invoices', $input, $headers)['body']);
        $reordered = $input;
        $reordered['lines'][0] = (object) array_reverse(get_object_vars($input['lines'][0]), true);
        self::assertSame($created['body'], $api->handle('POST', '/api/v1/sales/invoices', $reordered, $headers)['body']);
        self::assertSame(200, $api->handle('POST', $url . '/move-to-draft', ['action_reference' => 'return', 'expected_version' => 3], $headers)['status']);
        $deleted = $api->handle('POST', $url . '/delete', ['action_reference' => 'delete', 'expected_version' => 4], $headers);
        self::assertSame(200, $deleted['status']);
        self::assertNull(json_decode($deleted['body'], true)['current_version']);
    }

    #[DataProvider('invalidCreates')]
    public function testInvalidCreatesDoNotPersist(array $changes): void
    {
        $db = new InvoiceMemoryConnection();
        $this->reject(fn () => $this->service($db)->create($this->actor(), [...$this->input(), ...$changes]), 'VALIDATION_ERROR');
        self::assertSame([], $db->documents);
        self::assertSame([], $db->actions);
    }

    public static function invalidCreates(): array
    {
        $line = ['product_id' => '163', 'description' => 'A', 'quantity' => '1', 'unit_price' => '1'];
        return [[['lines' => []]], [['invoice_date' => '2026-02-30']], [['due_date' => '2026-11-23']],
            [['notes' => null]], [['payment_terms' => 'INVALID']], [['document_total' => '1.00']],
            [['warehouse_id' => 'OTHER']], [['lines' => [[...$line, 'quantity' => '0']]]],
            [['lines' => [[...$line, 'product_id' => 163]]]],
            [['lines' => [[...$line, 'product_id' => '0']]]],
            [['lines' => [[...$line, 'product_id' => '0163']]]],
            [['lines' => [[...$line, 'product_id' => '163.0']]]],
            [['lines' => [[...$line, 'product_id' => '10000000000']]]],
            [['lines' => [[...$line, 'product_id' => '999']]]],
            [['lines' => [[...$line, 'item_code' => 'SKU-A']]]],
            [['lines' => [[...$line, 'quantity' => '1.5']]]], [['lines' => [[...$line, 'unit_price' => '1.001']]]],
            [['lines' => [[...$line, 'unit_price' => '9999999999.99', 'quantity' => '999999999']]]],
            [['lines' => [[...$line, 'source_line_id' => '100']]]], [['lines' => [[...$line, 'line_id' => '1']]]]];
    }
}

