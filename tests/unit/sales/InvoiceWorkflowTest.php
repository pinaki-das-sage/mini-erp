<?php
declare(strict_types=1);

namespace MiniErp\Tests\unit\sales;

use MiniErp\sales\invoices\InvoiceService;
use MiniErp\shared\Api;
use MiniErp\shared\AppError;
use MiniErp\shared\Config;
use MiniErp\shared\database\DatabaseError;
use MiniErp\shared\security\Actor;
use MiniErp\Tests\unit\sales\fixtures\InvoiceMemoryConnection;
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
        return new InvoiceService($db, static fn (): string => '2026-11-25');
    }

    private function source(InvoiceMemoryConnection $db): array
    {
        $db->documents[100] = [...$db->document(100, 'SO-1', 'SALES_ORDER', 'SUBMITTED'),
            'NOTES' => 'Order notes', 'SHIPPING_ADDRESS' => 'Saved delivery address'];
        $db->lines[101] = $db->line(101, 100, 1, 163, 'A', '2', '10.25');
        $db->lines[102] = $db->line(102, 100, 2, 164, 'B', '3', '1.00');
        return ['source_order_number' => 'SO-1', 'action_reference' => 'create'];
    }

    private function reject(callable $operation, string $code, int $status = 400): void
    {
        try { $operation(); self::fail('Expected ' . $code); }
        catch (AppError $error) {
            self::assertSame($code, $error->errorCode);
            self::assertSame($status, $error->status);
        }
    }

    public function testNumberAloneCopiesCompleteOrderAndDoesNotCreateDebt(): void
    {
        $db = new InvoiceMemoryConnection();
        $this->source($db);
        $saved = $this->service($db)->create($this->actor(), ['source_order_number' => 'SO-1']);
        self::assertSame('SI-000000000000001', $saved['invoice_number']);
        self::assertSame('SO-1', $saved['source_order_number']);
        self::assertSame('CUST-0001', $saved['customer_code']);
        self::assertSame('W01', $saved['warehouse_id']);
        self::assertSame('NET_30', $saved['payment_terms']);
        self::assertSame('Order notes', $saved['notes']);
        self::assertSame('Saved delivery address', $saved['shipping_address']);
        self::assertSame('2026-11-24', $saved['invoice_date']);
        self::assertSame('2026-12-24', $saved['due_date']);
        self::assertSame('Draft', $saved['status']);
        self::assertSame(1, $saved['current_version']);
        self::assertSame('23.50', $saved['document_total']);
        self::assertSame(['163', '164'], array_column($saved['lines'], 'product_id'));
        self::assertSame(['101', '102'], array_column($saved['lines'], 'source_line_id'));
        self::assertSame(['2', '3'], array_column($saved['lines'], 'quantity'));
        self::assertSame('0.00', $db->customers[1]['BALANCE']);
        self::assertCount(1, $db->links);
        self::assertSame($saved, $this->service($db)->get($this->actor(), $saved['invoice_number']));
    }

    public function testReplayAndSecondRequestCannotCreateDuplicateInvoices(): void
    {
        $db = new InvoiceMemoryConnection();
        $input = $this->source($db);
        $service = $this->service($db);
        $saved = $service->create($this->actor(), $input);
        self::assertSame($saved, $service->create($this->actor(), array_reverse($input, true)));
        $this->reject(fn () => $service->create($this->actor(), ['source_order_number' => 'SO-1']), 'SOURCE_IN_USE', 409);
        self::assertSame(2, $db->nextNumber);
        self::assertCount(2, $db->documents);
        self::assertCount(1, $db->audit);
        self::assertCount(1, $db->actions);
    }

    #[DataProvider('invalidSources')]
    public function testOnlySubmittedSameCompanyOrdersAreEligible(string $status, string $type, int $company, string $code, int $http): void
    {
        $db = new InvoiceMemoryConnection();
        $input = $this->source($db);
        $db->documents[100] = [...$db->documents[100], 'STATUS' => $status, 'DOCUMENT_TYPE' => $type, 'CNY#' => $company];
        $this->reject(fn () => $this->service($db)->create($this->actor(), $input), $code, $http);
        self::assertCount(1, $db->documents);
        self::assertSame([], $db->actions);
        self::assertSame([], $db->links);
    }

    public static function invalidSources(): array
    {
        return [['DRAFT', 'SALES_ORDER', 7, 'INVALID_STATE', 409],
            ['IN_PROGRESS', 'SALES_ORDER', 7, 'INVALID_STATE', 409],
            ['CLOSED', 'SALES_ORDER', 7, 'INVALID_STATE', 409],
            ['SUBMITTED', 'SALES_QUOTE', 7, 'NOT_FOUND', 404],
            ['SUBMITTED', 'SALES_ORDER', 8, 'NOT_FOUND', 404]];
    }

    public function testMissingSourceAndCallerSuppliedBusinessValuesAreRejected(): void
    {
        $db = new InvoiceMemoryConnection();
        $input = $this->source($db);
        foreach ([[], ['source_order_number' => null], ['source_order_number' => ''],
            [...$input, 'lines' => []], [...$input, 'customer_code' => 'CUST-0001'],
            [...$input, 'invoice_date' => '2026-11-25'], [...$input, 'notes' => 'Override']] as $bad) {
            $this->reject(fn () => $this->service($db)->create($this->actor(), $bad), 'VALIDATION_ERROR');
        }
        self::assertSame([], $db->actions);
        self::assertSame(1, $db->nextNumber);
    }

    public function testSourcePermissionIsRequiredEvenOnReplay(): void
    {
        $db = new InvoiceMemoryConnection();
        $input = $this->source($db);
        $actor = $this->actor(7, false);
        $db->grants = ['7:admin:sales.invoices:create', '7:admin:sales.orders:read'];
        $this->service($db)->create($actor, $input);
        $db->grants = ['7:admin:sales.invoices:create'];
        $this->reject(fn () => $this->service($db)->create($actor, $input), 'FORBIDDEN', 403);
        self::assertCount(1, $db->audit);
    }

    public function testEmptyOrderAndInactiveProductCannotCreateInvoice(): void
    {
        foreach (['empty', 'inactive'] as $fault) {
            $db = new InvoiceMemoryConnection();
            $input = $this->source($db);
            if ($fault === 'empty') { $db->lines = []; }
            else { $db->products[163]['IS_ACTIVE'] = 'N'; }
            $this->reject(fn () => $this->service($db)->create($this->actor(), $input), 'VALIDATION_ERROR');
            self::assertCount(1, $db->documents);
            self::assertSame([], $db->links);
        }
    }

    public function testLineAndAuditFailuresRollBackInvoiceAndClaim(): void
    {
        foreach (['line', 'audit'] as $fault) {
            $db = new InvoiceMemoryConnection();
            $input = $this->source($db);
            if ($fault === 'line') { $db->failLine = 2; }
            else { $db->failAudit = true; }
            try { $this->service($db)->create($this->actor(), $input); self::fail('Failure must propagate.'); }
            catch (DatabaseError) {}
            self::assertCount(1, $db->documents);
            self::assertCount(2, $db->lines);
            self::assertSame([], $db->links);
            self::assertSame([], $db->actions);
            self::assertSame('0.00', $db->customers[1]['BALANCE']);
        }
    }

    public function testDraftUpdateKeepsOrderLineageAndAllowsInvoiceNotes(): void
    {
        $db = new InvoiceMemoryConnection();
        $input = $this->source($db);
        $db->lines[101]['DESCRIPTION'] = '';
        $service = $this->service($db);
        $saved = $service->create($this->actor(), $input);
        self::assertSame('', $saved['lines'][0]['description']);
        $lines = array_map(static fn (array $line): array => array_intersect_key($line,
            array_flip(['line_id', 'source_line_id', 'product_id', 'description', 'quantity', 'unit_price'])), $saved['lines']);
        $edit = ['action_reference' => 'edit', 'expected_version' => 1, 'notes' => 'Invoice notes'];
        foreach ([null, 'SO-OTHER'] as $source) {
            $this->reject(fn () => $service->update($this->actor(), $saved['invoice_number'],
                [...$edit, 'source_order_number' => $source]), 'VALIDATION_ERROR');
        }
        $changed = $lines;
        $changed[0]['quantity'] = '1';
        $this->reject(fn () => $service->update($this->actor(), $saved['invoice_number'],
            [...$edit, 'lines' => $changed]), 'VALIDATION_ERROR');
        $updated = $service->update($this->actor(), $saved['invoice_number'], $edit);
        self::assertSame(2, $updated['current_version']);
        self::assertSame('Invoice notes', $updated['notes']);
        self::assertSame($saved['lines'], $updated['lines']);
        self::assertSame($updated, $service->update($this->actor(), $saved['invoice_number'], $edit));
    }

    public function testDatesAndNotesUpdateWithoutReferenceOrLinesAndRejectStaleVersion(): void
    {
        $db = new InvoiceMemoryConnection();
        $input = $this->source($db);
        $service = $this->service($db);
        $saved = $service->create($this->actor(), $input);
        $lines = $db->lines;
        $links = $db->links;
        $edit = ['expected_version' => 1, 'invoice_date' => '2026-11-25',
            'due_date' => '2026-11-25', 'notes' => 'Payment due on receipt.'];
        $updated = $service->update($this->actor(), $saved['invoice_number'], $edit);
        self::assertSame(2, $updated['current_version']);
        self::assertSame($edit['invoice_date'], $updated['invoice_date']);
        self::assertSame($edit['due_date'], $updated['due_date']);
        self::assertSame($edit['notes'], $updated['notes']);
        self::assertSame($lines, $db->lines);
        self::assertSame($links, $db->links);
        self::assertSame('0.00', $db->customers[1]['BALANCE']);
        $this->reject(fn () => $service->update($this->actor(), $saved['invoice_number'], $edit), 'STALE_VERSION', 409);
        foreach ([['invoice_date' => '2026-11-23'], ['due_date' => '2026-11-24'],
            ['invoice_date' => null], ['notes' => null], ['action_reference' => '']] as $bad) {
            $this->reject(fn () => $service->update($this->actor(), $saved['invoice_number'],
                [...$edit, 'expected_version' => 2, ...$bad]), 'VALIDATION_ERROR');
        }
    }

    public function testSubmitReturnDeleteAndRetriesPreserveBalanceAndSource(): void
    {
        $db = new InvoiceMemoryConnection();
        $input = $this->source($db);
        $service = $this->service($db);
        $saved = $service->create($this->actor(), $input);
        $number = $saved['invoice_number'];
        $submit = ['action_reference' => 'submit', 'expected_version' => 1];
        $submitted = $service->action($this->actor(), $number, 'submit', $submit);
        self::assertSame($submitted, $service->action($this->actor(), $number, 'submit', $submit));
        self::assertSame('23.50', $db->customers[1]['BALANCE']);
        $service->action($this->actor(), $number, 'return_to_draft', ['action_reference' => 'return', 'expected_version' => 2]);
        self::assertSame('0.00', $db->customers[1]['BALANCE']);
        self::assertCount(1, $db->links);
        $delete = ['action_reference' => 'delete', 'expected_version' => 3];
        $deleted = $service->action($this->actor(), $number, 'delete', $delete);
        self::assertSame($deleted, $service->action($this->actor(), $number, 'delete', $delete));
        self::assertSame([], $db->links);
        self::assertCount(2, $db->lines, 'Source Order lines survive deletion.');
    }

    public function testHttpCreateAcceptsOnlyOrderNumberAndSupportsExplicitReplay(): void
    {
        $db = new InvoiceMemoryConnection();
        $input = $this->source($db);
        $api = new Api($db, Config::fromArray([]));
        $headers = ['authorization' => 'Bearer ' . str_repeat('a', 64), 'content-type' => 'application/json'];
        $created = $api->handle('POST', '/api/v1/sales/invoices', $input, $headers);
        self::assertSame(201, $created['status']);
        $body = json_decode($created['body'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(1, $body['current_version']);
        self::assertSame('SO-1', $body['result']['source_order_number']);
        self::assertSame($created['body'], $api->handle('POST', '/api/v1/sales/invoices', $input, $headers)['body']);
    }

    public function testLifecycleActionsWithoutReferencesRetainVersionAndDebtProtection(): void
    {
        $db = new InvoiceMemoryConnection();
        $service = $this->service($db);
        $saved = $service->create($this->actor(), $this->source($db));
        $number = $saved['invoice_number'];
        foreach (['submit', 'return_to_draft', 'delete'] as $action) {
            $this->reject(fn () => $service->action($this->actor(), $number, $action, []), 'VALIDATION_ERROR');
            $this->reject(fn () => $service->action($this->actor(), $number, $action,
                ['expected_version' => 1, 'action_reference' => '']), 'VALIDATION_ERROR');
        }
        $submitted = $service->action($this->actor(), $number, 'submit', ['expected_version' => 1]);
        self::assertSame('Submitted', $submitted['status']);
        self::assertSame(2, $submitted['current_version']);
        self::assertSame('23.50', $db->customers[1]['BALANCE']);
        $this->reject(fn () => $service->action($this->actor(), $number, 'submit', ['expected_version' => 1]), 'STALE_VERSION', 409);
        self::assertSame('23.50', $db->customers[1]['BALANCE']);
        $returned = $service->action($this->actor(), $number, 'return_to_draft', ['expected_version' => 2]);
        self::assertSame('Draft', $returned['status']);
        self::assertSame(3, $returned['current_version']);
        self::assertSame('0.00', $db->customers[1]['BALANCE']);
        self::assertCount(1, $db->links);
        $this->reject(fn () => $service->action($this->actor(), $number, 'return_to_draft', ['expected_version' => 2]), 'STALE_VERSION', 409);
        $this->reject(fn () => $service->action($this->actor(), $number, 'delete', ['expected_version' => 2]), 'STALE_VERSION', 409);
        $deleted = $service->action($this->actor(), $number, 'delete', ['expected_version' => 3]);
        self::assertTrue($deleted['deleted']);
        self::assertSame([], $db->links);
        self::assertCount(1, $db->documents, 'Source Order is retained.');
        self::assertCount(4, $db->audit, 'Only successful create/submit/return/delete are audited.');
        self::assertSame('0.00', $db->customers[1]['BALANCE']);
    }
}
