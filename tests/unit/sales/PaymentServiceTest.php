<?php
declare(strict_types=1);

namespace MiniErp\Tests\unit\sales;

use MiniErp\sales\invoices\InvoiceService;
use MiniErp\sales\payments\PaymentService;
use MiniErp\shared\Api;
use MiniErp\shared\AppError;
use MiniErp\shared\Config;
use MiniErp\shared\database\DatabaseError;
use MiniErp\shared\security\Actor;
use MiniErp\Tests\unit\sales\fixtures\InvoiceMemoryConnection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PaymentServiceTest extends TestCase
{
    private function actor(int $company = 7): Actor
    {
        return new Actor('admin', 'admin', [], $company, true);
    }

    private function draft(InvoiceMemoryConnection $db, string $reference = 'invoice', ?array $line = null): array
    {
        $id = 10000 + count($db->documents);
        $line ??= ['product_id' => '163', 'description' => 'A', 'quantity' => '2', 'unit_price' => '10.25'];
        $db->documents[$id] = $db->document($id, 'SO-' . $id, 'SALES_ORDER', 'SUBMITTED');
        $db->lines[$id] = $db->line($id, $id, 1, (int) $line['product_id'], $line['description'], $line['quantity'], $line['unit_price']);
        return (new InvoiceService($db))->create($this->actor(), [
            'action_reference' => $reference, 'source_order_number' => 'SO-' . $id,
        ]);
    }

    private function submitted(InvoiceMemoryConnection $db): array
    {
        $invoice = $this->draft($db);
        return (new InvoiceService($db))->action($this->actor(), $invoice['invoice_number'], 'submit',
            ['action_reference' => 'invoice-submit', 'expected_version' => 1]);
    }

    private function service(InvoiceMemoryConnection $db, ?\Closure $clock = null): PaymentService
    {
        return new PaymentService($db, $clock ?? static fn (): string => '2026-11-25');
    }

    private function input(string $reference = 'payment-1', string $amount = '7.25', int $version = 2): array
    {
        return ['action_reference' => $reference, 'expected_version' => $version, 'payment_amount' => $amount,
            'payment_date' => '2026-11-25', 'payment_reference' => ' BANK-001 '];
    }

    private function reject(callable $operation, string $code, int $status = 400, ?string $message = null): void
    {
        try {
            $operation();
            self::fail('Expected ' . $code);
        } catch (AppError $error) {
            self::assertSame($code, $error->errorCode);
            self::assertSame($status, $error->status);
            if ($message !== null) { self::assertSame($message, $error->getMessage()); }
        }
    }

    public function testPartialAndFinalPaymentAndOriginalSnapshotsOnReplay(): void
    {
        $db = new InvoiceMemoryConnection();
        $invoice = $this->submitted($db);
        $service = $this->service($db);
        $first = $service->record($this->actor(), $invoice['invoice_number'], $this->input());
        self::assertSame('PAY-000000000000001', $first['payment']['payment_number']);
        self::assertSame('7.25', $first['payment']['payment_amount']);
        self::assertSame('BANK-001', $first['payment']['payment_reference']);
        self::assertSame('admin', $first['payment']['created_by']);
        self::assertMatchesRegularExpression('/Z\z/', $first['payment']['created_at']);
        self::assertSame('Submitted', $first['invoice']['status']);
        self::assertSame('Partially Paid', $first['invoice']['payment_status']);
        self::assertSame('13.25', $first['invoice']['amount_due']);
        self::assertSame(3, $first['invoice']['current_version']);
        self::assertSame('13.25', $db->customers[1]['BALANCE']);
        $lastInput = $this->input('payment-2', '13.25', 3);
        $last = $service->record($this->actor(), $invoice['invoice_number'], $lastInput);
        self::assertSame('Paid', $last['invoice']['payment_status']);
        self::assertSame('20.50', $last['invoice']['total_paid']);
        self::assertSame('0.00', $last['invoice']['amount_due']);
        self::assertSame(4, $last['invoice']['current_version']);
        self::assertSame('0.00', $db->customers[1]['BALANCE']);
        $expiredClock = $this->service($db, static function (): never { throw new \LogicException('Replay must not check today.'); });
        self::assertSame($first, $expiredClock->record($this->actor(), $invoice['invoice_number'], array_reverse($this->input(), true)));
        self::assertSame($last, $expiredClock->record($this->actor(), $invoice['invoice_number'], $lastInput));
        self::assertCount(2, $db->payments);
        self::assertSame(3, $db->nextPaymentNumber);
        self::assertCount(4, $db->audit);
        self::assertStringNotContainsString('BANK-001', $db->audit[2]['details']);
        $this->reject(fn () => $service->record($this->actor(), $invoice['invoice_number'], $this->input('third', '1', 4)),
            'INVALID_STATE', 409, 'This Invoice is fully paid. No further payment can be recorded.');
        $this->reject(fn () => $service->record($this->actor(), $invoice['invoice_number'], [...$lastInput, 'payment_reference' => 'CHANGED']),
            'DUPLICATE_ACTION_CONFLICT', 409);
    }

    public function testOverpaymentAndStaleCompetingRequestCannotRecordExtraMoney(): void
    {
        $db = new InvoiceMemoryConnection();
        $invoice = $this->submitted($db);
        $service = $this->service($db);
        $this->reject(fn () => $service->record($this->actor(), $invoice['invoice_number'], $this->input('over', '20.51')),
            'VALIDATION_ERROR', 400, 'Payment Amount cannot exceed Amount Due (20.50).');
        $service->record($this->actor(), $invoice['invoice_number'], $this->input('winner', '10.00'));
        // Models the loser after waiting for the winning Invoice transaction; real races are Oracle tests.
        $this->reject(fn () => $service->record($this->actor(), $invoice['invoice_number'], $this->input('loser', '15.00')),
            'STALE_VERSION', 409, 'Amount Due has changed to 10.50. Review the amount and try again.');
        self::assertCount(1, $db->payments);
        self::assertArrayNotHasKey('7:loser', $db->actions);
        self::assertSame('10.50', $db->customers[1]['BALANCE']);
        self::assertSame(3, $db->documents[1]['VERSION']);
    }

    public function testPaymentsPreserveOtherInvoiceDebtAndAllowInactiveCustomerAndItem(): void
    {
        $db = new InvoiceMemoryConnection();
        $first = $this->submitted($db);
        $other = $this->draft($db, 'other');
        (new InvoiceService($db))->action($this->actor(), $other['invoice_number'], 'submit',
            ['action_reference' => 'other-submit', 'expected_version' => 1]);
        self::assertSame('41.00', $db->customers[1]['BALANCE']);
        $db->customers[1]['IS_ACTIVE'] = 'F';
        $db->products[163]['IS_ACTIVE'] = 'N';
        $db->forbidInventoryReads = true;
        $saved = $this->service($db)->record($this->actor(), $first['invoice_number'], $this->input('full', '20.50'));
        self::assertSame('Paid', $saved['invoice']['payment_status']);
        self::assertSame('20.50', $db->customers[1]['BALANCE']);
        self::assertSame(2, $db->documents[2]['VERSION']);
    }

    public function testLinkedInvoicePaymentDoesNotRevalidateSource(): void
    {
        $db = new InvoiceMemoryConnection();
        $db->documents[100] = $db->document(100, 'SO-1', 'SALES_ORDER', 'SUBMITTED');
        $db->lines[100] = $db->line(100, 100, 1, 163, 'A', '2', '10.25');
        $invoice = (new InvoiceService($db))->create($this->actor(), ['action_reference' => 'linked',
            'source_order_number' => 'SO-1']);
        (new InvoiceService($db))->action($this->actor(), $invoice['invoice_number'], 'submit', ['action_reference' => 'submit', 'expected_version' => 1]);
        $db->forbidInventoryReads = true;
        self::assertSame('Partially Paid', $this->service($db)->record($this->actor(), $invoice['invoice_number'], $this->input())['invoice']['payment_status']);
        self::assertCount(1, $db->links);
    }

    public function testHistoryPaginationOrderRecordingActorAndEmptyDraft(): void
    {
        $db = new InvoiceMemoryConnection();
        $invoice = $this->submitted($db);
        $service = $this->service($db);
        $first = $service->record($this->actor(), $invoice['invoice_number'], $this->input());
        $service->record($this->actor(), $invoice['invoice_number'], [...$this->input('second', '1', 3), 'payment_date' => '2026-11-24']);
        $history = $service->list($this->actor(), $invoice['invoice_number'], ['page' => '1', 'page_size' => '1']);
        self::assertSame([$first['payment']], $history['items']);
        self::assertSame(1, $history['page_size']);
        $second = $service->list($this->actor(), $invoice['invoice_number'], ['page' => '2', 'page_size' => '1'])['items'][0];
        self::assertSame('2026-11-24', $second['payment_date'], 'History sorts by recording time, not Payment Date.');
        self::assertArrayNotHasKey('action_reference', $second);
        self::assertArrayNotHasKey('id', $second);
        self::assertArrayNotHasKey('current_version', $second);
        $draft = $this->draft($db, 'empty');
        self::assertSame([], $service->list($this->actor(), $draft['invoice_number'])['items']);
        foreach ([['unknown' => '1'], ['page' => '0'], ['page_size' => '101']] as $query) {
            $this->reject(fn () => $service->list($this->actor(), $invoice['invoice_number'], $query), 'VALIDATION_ERROR');
        }
    }

    public function testPaymentGrantDoesNotRequireInvoiceEditAndRevocationPrecedesReplay(): void
    {
        $db = new InvoiceMemoryConnection();
        $invoice = $this->submitted($db);
        $collector = new Actor('collector', 'collector', [], 7);
        $service = $this->service($db);
        $this->reject(fn () => $service->record($collector, $invoice['invoice_number'], $this->input()), 'FORBIDDEN', 403);
        $db->grants = ['7:collector:sales.payments:record'];
        $saved = $service->record($collector, $invoice['invoice_number'], $this->input());
        self::assertSame('collector', $saved['payment']['created_by']);
        $this->reject(fn () => $service->list($collector, $invoice['invoice_number']), 'FORBIDDEN', 403);
        $db->grants = ['7:collector:sales.payments:read'];
        self::assertCount(1, $service->list($collector, $invoice['invoice_number'])['items']);
        $this->reject(fn () => $service->record($collector, $invoice['invoice_number'], $this->input()), 'FORBIDDEN', 403);
        self::assertCount(1, $db->payments);
    }

    public function testIdentityCompanyOperationAndPayloadAreBoundToRetry(): void
    {
        $db = new InvoiceMemoryConnection();
        $invoice = $this->submitted($db);
        $service = $this->service($db);
        $service->record($this->actor(), $invoice['invoice_number'], $this->input());
        $this->reject(fn () => $service->record(new Actor('other', 'other', [], 7, true), $invoice['invoice_number'], $this->input()), 'DUPLICATE_ACTION_CONFLICT', 409);
        $this->reject(fn () => $service->record($this->actor(), $invoice['invoice_number'], $this->input('invoice-submit')), 'DUPLICATE_ACTION_CONFLICT', 409);
        $this->reject(fn () => $service->record($this->actor(8), $invoice['invoice_number'], $this->input('foreign')), 'NOT_FOUND', 404);
        $this->reject(fn () => $service->list($this->actor(8), $invoice['invoice_number']), 'NOT_FOUND', 404);
        $db->documents[100] = $db->document(100, 'SO-1', 'SALES_ORDER', 'SUBMITTED');
        $this->reject(fn () => $service->record($this->actor(), 'SO-1', $this->input('order')), 'NOT_FOUND', 404);
        $this->reject(fn () => $service->list($this->actor(), 'SO-1'), 'NOT_FOUND', 404);
    }

    public function testDraftAndReturnPaymentRacesAreRejectedAndPaidHintsAreCorrect(): void
    {
        $db = new InvoiceMemoryConnection();
        $invoice = $this->submitted($db);
        $service = $this->service($db);
        $invoices = new InvoiceService($db);
        self::assertContains('record_payment', $invoices->get($this->actor(), $invoice['invoice_number'])['available_actions']);
        $service->record($this->actor(), $invoice['invoice_number'], $this->input('full', '20.50'));
        $this->reject(fn () => $invoices->action($this->actor(), $invoice['invoice_number'], 'return_to_draft',
            ['action_reference' => 'return', 'expected_version' => 3]), 'INVALID_STATE', 409);
        self::assertSame([], $invoices->get($this->actor(), $invoice['invoice_number'])['available_actions']);
        $other = $this->draft($db, 'draft');
        $this->reject(fn () => $service->record($this->actor(), $other['invoice_number'], $this->input('draft-pay', '1', 1)),
            'INVALID_STATE', 409, 'Submit the Invoice before recording a payment.');
        $invoices->action($this->actor(), $other['invoice_number'], 'submit', ['action_reference' => 'other-submit', 'expected_version' => 1]);
        $invoices->action($this->actor(), $other['invoice_number'], 'return_to_draft', ['action_reference' => 'other-return', 'expected_version' => 2]);
        $this->reject(fn () => $service->record($this->actor(), $other['invoice_number'], $this->input('after-return', '1', 2)), 'INVALID_STATE', 409);
        self::assertCount(1, $db->payments);
    }

    #[DataProvider('invalidPayments')]
    public function testValidationMessagesAndNoPersistedEffects(array $changes, string $message): void
    {
        $db = new InvoiceMemoryConnection();
        $invoice = $this->submitted($db);
        $this->reject(fn () => $this->service($db)->record($this->actor(), $invoice['invoice_number'], [...$this->input(), ...$changes]),
            'VALIDATION_ERROR', 400, $message);
        self::assertSame([], $db->payments);
        self::assertSame(2, $db->documents[1]['VERSION']);
        self::assertSame('20.50', $db->customers[1]['BALANCE']);
        self::assertArrayNotHasKey('7:payment-1', $db->actions);
        self::assertSame(1, $db->nextPaymentNumber);
    }

    public static function invalidPayments(): array
    {
        return [[['payment_amount' => null], 'Enter Payment Amount.'], [['payment_amount' => ''], 'Enter Payment Amount.'],
            [['payment_amount' => 1], 'Enter a valid Payment Amount.'], [['payment_amount' => '1e2'], 'Enter a valid Payment Amount.'],
            [['payment_amount' => ' 1'], 'Enter a valid Payment Amount.'], [['payment_amount' => '-1'], 'Payment Amount must be greater than 0.'],
            [['payment_amount' => '0.00'], 'Payment Amount must be greater than 0.'], [['payment_amount' => '1.001'], 'Payment Amount can have at most 2 decimal places.'],
            [['payment_amount' => '10000000000000000'], 'Payment Amount exceeds the allowed numeric limit.'],
            [['payment_date' => null], 'Enter Payment Date.'], [['payment_date' => '2026-02-30'], 'Enter a valid Payment Date in YYYY-MM-DD format.'],
            [['payment_date' => '2026-11-23'], 'Payment Date must be on or after Invoice Date.'],
            [['payment_date' => '2026-11-26'], 'Payment Date cannot be in the future.'],
            [['payment_reference' => '  '], 'Enter Payment Reference.'], [['payment_reference' => str_repeat('x', 51)], 'Payment Reference must be 50 characters or fewer.'],
            [['payment_number' => 'PAY-OWN'], 'This request contains an unsupported or read-only field.'],
            [['company_id' => 8], 'This request contains an unsupported or read-only field.'],
            [['amount_due' => '1000'], 'This request contains an unsupported or read-only field.']];
    }

    #[DataProvider('faults')]
    public function testFailureAtAnyWriteRollsBackPaymentBalanceVersionAndAudit(string $fault): void
    {
        $db = new InvoiceMemoryConnection();
        $invoice = $this->submitted($db);
        $db->$fault = true;
        try {
            $this->service($db)->record($this->actor(), $invoice['invoice_number'], $this->input());
            self::fail('Injected failure must propagate.');
        } catch (DatabaseError|AppError) {}
        self::assertSame([], $db->payments);
        self::assertSame('20.50', $db->customers[1]['BALANCE']);
        self::assertSame(2, $db->documents[1]['VERSION']);
        self::assertCount(2, $db->audit);
        self::assertArrayNotHasKey('7:payment-1', $db->actions);
        $db->$fault = false;
        self::assertSame('13.25', $this->service($db)->record($this->actor(), $invoice['invoice_number'], $this->input())['invoice']['amount_due']);
    }

    public static function faults(): array
    {
        return [['failPayment'], ['failBalance'], ['failVersion'], ['failAudit']];
    }

    public function testInconsistentCustomerBalanceAndVersionCapacityCannotSavePayment(): void
    {
        $db = new InvoiceMemoryConnection();
        $invoice = $this->submitted($db);
        $db->customers[1]['BALANCE'] = '1.00';
        $this->reject(fn () => $this->service($db)->record($this->actor(), $invoice['invoice_number'], $this->input()), 'INVALID_INVOICE_DATA', 503);
        $db->customers[1]['BALANCE'] = '20.50';
        $db->documents[1]['VERSION'] = 9999999999;
        $this->reject(fn () => $this->service($db)->record($this->actor(), $invoice['invoice_number'], $this->input('capacity', '1', 9999999999)), 'INVALID_STATE', 409);
        self::assertSame([], $db->payments);
    }

    public function testLargeAmountsAreExactWithoutFloatConversion(): void
    {
        $db = new InvoiceMemoryConnection();
        $invoice = $this->draft($db, 'large', ['product_id' => '163', 'description' => 'A', 'quantity' => '1000000', 'unit_price' => '9999999999.99']);
        (new InvoiceService($db))->action($this->actor(), $invoice['invoice_number'], 'submit', ['action_reference' => 'submit', 'expected_version' => 1]);
        $saved = $this->service($db)->record($this->actor(), $invoice['invoice_number'], $this->input('full', '9999999999990000.00'));
        self::assertSame('9999999999990000.00', $saved['payment']['payment_amount']);
        self::assertSame('0.00', $saved['invoice']['amount_due']);
        self::assertSame('0.00', $db->customers[1]['BALANCE']);
    }

    public function testRoutesExposeRecordAndHistoryWithCorrectVersionsAndNoEditDelete(): void
    {
        $db = new InvoiceMemoryConnection();
        $invoice = $this->submitted($db);
        $api = new Api($db, Config::fromArray([]));
        $headers = ['authorization' => 'Bearer ' . str_repeat('a', 64), 'content-type' => 'application/json'];
        $url = '/api/v1/sales/invoices/' . $invoice['invoice_number'] . '/payments';
        self::assertSame(401, $api->handle('POST', $url, $this->input())['status']);
        $created = $api->handle('POST', $url, $this->input(), $headers);
        self::assertSame(201, $created['status']);
        $body = json_decode($created['body'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(3, $body['current_version']);
        self::assertSame(3, $body['result']['invoice']['current_version']);
        self::assertSame(3, $body['result']['current_version']);
        self::assertSame(['payment', 'invoice', 'current_version'], array_keys($body['result']));
        self::assertSame('payment-1', $body['operation_id']);
        self::assertSame($created['body'], $api->handle('POST', $url, $this->input(), $headers)['body']);
        $listed = $api->handle('GET', $url, [], $headers);
        self::assertSame(200, $listed['status']);
        $history = json_decode($listed['body'], true);
        self::assertNull($history['current_version']);
        self::assertNull($history['operation_id']);
        self::assertCount(1, $history['result']['items']);
        foreach (['PATCH', 'DELETE'] as $method) {
            self::assertSame(404, $api->handle($method, $url, [], $headers)['status']);
        }
    }
}
