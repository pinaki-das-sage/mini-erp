<?php
declare(strict_types=1);

namespace MiniErp\Tests\unit\sales;

use MiniErp\sales\invoices\InvoiceService;
use MiniErp\sales\payments\PaymentPages;
use MiniErp\shared\AppError;
use MiniErp\shared\WebPath;
use MiniErp\shared\security\Actor;
use MiniErp\Tests\unit\sales\fixtures\InvoiceMemoryConnection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PaymentPagesTest extends TestCase
{
    private function actor(int $company = 7): Actor
    {
        return new Actor('admin', 'admin', [], $company, true);
    }

    private function fixture(bool $submitted = true): array
    {
        $db = new InvoiceMemoryConnection();
        $clock = static fn (): string => '2026-11-26';

        $db->documents[100] = $db->document(
            100, 'SO-UI-1', 'SALES_ORDER', 'SUBMITTED',
        );
        $db->lines[101] = $db->line(
            101, 100, 1, 163, 'Payment UI item', '2', '10.25',
        );

        $service = new InvoiceService($db, $clock);
        $invoice = $service->create($this->actor(), [
            'action_reference' => 'invoice-create',
            'source_order_number' => 'SO-UI-1',
        ]);

        if ($submitted) {
            $service->action($this->actor(), $invoice['invoice_number'], 'submit', [
                'action_reference' => 'invoice-submit',
                'expected_version' => 1,
            ]);
        }

        $pages = new PaymentPages(
            $db,
            new WebPath('/training'),
            'csrf',
            $clock,
        );

        return [$db, $pages, $invoice['invoice_number']];
    }

    private function input(
        string $reference = 'payment-1',
        string $amount = '7.25',
        string $version = '2',
    ): array {
        return [
            'csrf_token' => 'csrf',
            'action_reference' => $reference,
            'expected_version' => $version,
            'payment_amount' => $amount,
            'payment_date' => '2026-11-26',
            'payment_reference' => 'BANK-1',
        ];
    }

    private function denied(callable $operation): void
    {
        try {
            $operation();
            self::fail('Expected permission or CSRF rejection.');
        } catch (AppError $error) {
            self::assertSame(403, $error->status);
        }
    }

    public function testEntryFormUsesMountedUrlsAndValidPatterns(): void
    {
        [, $pages, $number] = $this->fixture();

        $response = $pages->handle(
            'GET',
            '/modules/sales/payments/new',
            $this->actor(),
            [],
            ['invoice_number' => $number],
        );

        self::assertSame(200, $response['status']);
        self::assertStringContainsString(
            '/training/modules/sales/payments/new?invoice_number=',
            $response['body'],
        );
        self::assertStringContainsString('name="payment_amount"', $response['body']);
        self::assertStringContainsString('[0-9]{1,16}', $response['body']);
        self::assertStringContainsString('max="2026-11-26"', $response['body']);
    }

    public function testPaymentsAndOriginalReceiptReplayAfterFullyPaid(): void
    {
        [$db, $pages, $number] = $this->fixture();
        $query = ['invoice_number' => $number];
        $input = $this->input();
        $input['payment_reference'] = '<script>bank</script>';

        $first = $pages->handle(
            'POST', '/modules/sales/payments/new', $this->actor(), $input, $query,
        );

        self::assertSame(201, $first['status']);
        self::assertSame('13.25', $db->customers[1]['BALANCE']);
        self::assertCount(1, $db->payments);
        self::assertStringContainsString('&lt;script&gt;bank&lt;/script&gt;', $first['body']);
        self::assertStringNotContainsString('<script>bank</script>', $first['body']);

        $last = $pages->handle(
            'POST',
            '/modules/sales/payments/new',
            $this->actor(),
            $this->input('payment-2', '13.25', '3'),
            $query,
        );

        self::assertSame(201, $last['status']);
        self::assertSame('0.00', $db->customers[1]['BALANCE']);
        self::assertCount(2, $db->payments);

        $retry = $pages->handle(
            'POST', '/modules/sales/payments/new', $this->actor(), $input, $query,
        );

        self::assertSame($first, $retry);
        self::assertCount(2, $db->payments);
        self::assertSame('0.00', $db->customers[1]['BALANCE']);
    }

    #[DataProvider('invalidPayments')]
    public function testInvalidPaymentsDoNotChangeBalances(
        array $changes,
        int $expectedStatus,
    ): void {
        [$db, $pages, $number] = $this->fixture();

        $response = $pages->handle(
            'POST',
            '/modules/sales/payments/new',
            $this->actor(),
            array_replace($this->input(), $changes),
            ['invoice_number' => $number],
        );

        self::assertSame($expectedStatus, $response['status']);
        self::assertCount(0, $db->payments);
        self::assertSame('20.50', $db->customers[1]['BALANCE']);
    }

    public static function invalidPayments(): array
    {
        return [
            'overpayment' => [['payment_amount' => '20.51'], 400],
            'zero' => [['payment_amount' => '0.00'], 400],
            'precision' => [['payment_amount' => '1.001'], 400],
            'future date' => [['payment_date' => '2026-11-27'], 400],
            'early date' => [['payment_date' => '2026-11-23'], 400],
            'stale version' => [['expected_version' => '1'], 409],
            'invalid version' => [['expected_version' => 'abc'], 400],
        ];
    }

    public function testDraftAndPaidInvoicesCannotAcceptNewPayments(): void
    {
        [, $pages, $number] = $this->fixture(false);
        $response = $pages->handle(
            'GET', '/modules/sales/payments/new', $this->actor(), [],
            ['invoice_number' => $number],
        );

        self::assertStringContainsString('Submit the Invoice', $response['body']);
        self::assertStringNotContainsString('name="payment_amount"', $response['body']);

        [$db, $pages, $number] = $this->fixture();
        $query = ['invoice_number' => $number];

        $pages->handle(
            'POST', '/modules/sales/payments/new', $this->actor(),
            $this->input('full-payment', '20.50'), $query,
        );

        $response = $pages->handle(
            'GET', '/modules/sales/payments/new', $this->actor(), [], $query,
        );

        self::assertStringContainsString('fully paid', $response['body']);
        self::assertStringNotContainsString('name="payment_amount"', $response['body']);

        $response = $pages->handle(
            'POST', '/modules/sales/payments/new', $this->actor(),
            $this->input('extra-payment', '0.01', '3'), $query,
        );

        self::assertSame(409, $response['status']);
        self::assertCount(1, $db->payments);
    }

    public function testHistoryPaginationAndCompanyIsolation(): void
    {
        [$db, $pages, $number] = $this->fixture();
        $query = ['invoice_number' => $number];

        $pages->handle(
            'POST', '/modules/sales/payments/new', $this->actor(),
            $this->input(), $query,
        );
        $pages->handle(
            'POST', '/modules/sales/payments/new', $this->actor(),
            $this->input('payment-2', '1.00', '3'), $query,
        );

        $response = $pages->handle(
            'GET', '/modules/sales/payments/history', $this->actor(), [],
            [...$query, 'page_size' => '1'],
        );

        self::assertSame(200, $response['status']);
        self::assertStringContainsString('PAY-000000000000001', $response['body']);
        self::assertStringNotContainsString('PAY-000000000000002', $response['body']);
        self::assertStringContainsString('page=2', $response['body']);

        $foreign = $pages->handle(
            'GET', '/modules/sales/payments/history', $this->actor(8), [], $query,
        );

        self::assertSame(404, $foreign['status']);
        self::assertStringNotContainsString('PAY-000000000000001', $foreign['body']);
        self::assertCount(2, $db->payments);
    }

    public function testCsrfAndReadOnlyPermissionsAreEnforced(): void
    {
        [$db, $pages, $number] = $this->fixture();
        $query = ['invoice_number' => $number];

        $this->denied(fn () => $pages->handle(
            'POST',
            '/modules/sales/payments/new',
            $this->actor(),
            [...$this->input(), 'csrf_token' => 'wrong'],
            $query,
        ));

        self::assertCount(0, $db->payments);

        $reader = new Actor('reader', 'reader', [], 7, false);
        $db->grants = ['7:reader:sales.payments:read'];

        $history = $pages->handle(
            'GET', '/modules/sales/payments/history', $reader, [], $query,
        );

        self::assertSame(200, $history['status']);
        self::assertStringNotContainsString('formaction=', $history['body']);

        $this->denied(fn () => $pages->handle(
            'GET', '/modules/sales/payments/new', $reader, [], $query,
        ));

        self::assertCount(0, $db->payments);
    }

    public function testPaymentReferenceIsOptionalAndHistoryHidesRecorderWhileShowingHistoricalBalances(): void
    {
        [$db, $pages, $number] = $this->fixture();
        $query = ['invoice_number' => $number];
        $form = $pages->handle('GET', '/modules/sales/payments/new', $this->actor(), [], $query)['body'];
        self::assertStringContainsString('Payment reference (optional)', $form);
        self::assertDoesNotMatchRegularExpression('/name="payment_reference"[^>]*\brequired\b/s', $form);
        $input = $this->input(); unset($input['payment_reference']);
        self::assertSame(201, $pages->handle('POST', '/modules/sales/payments/new', $this->actor(), $input, $query)['status']);
        $pages->handle('POST', '/modules/sales/payments/new', $this->actor(), $this->input('final', '13.25', '3'), $query);
        $history = $pages->handle('GET', '/modules/sales/payments/history', $this->actor(), [], [...$query, 'page_size' => '1'])['body'];
        self::assertStringContainsString('Amount paid', $history);
        self::assertStringContainsString('Amount due', $history);
        self::assertStringContainsString('13.25', $history);
        self::assertStringContainsString('Partially Paid', $history);
        self::assertStringNotContainsString('Recorded by', $history);
        self::assertStringNotContainsString('Recorded by', $form);
        self::assertSame('admin', $db->actions['7:payment-1']['ACTOR_ID']);
    }

    public function testSubmittedInvoiceBrowserFiltersAndActionsMatchPaymentStateAndPermissions(): void
    {
        [$db, $pages, $number] = $this->fixture();
        $html = $pages->handle('GET', '/modules/sales/payments', $this->actor(), [], [])['body'];
        self::assertStringContainsString('Submitted Invoices', $html);
        self::assertStringContainsString($number, $html);
        self::assertStringContainsString('Record payment</a>', $html);
        self::assertStringNotContainsString('View history</a>', $html);
        self::assertStringNotContainsString('name="page_size"', $html);
        $query = ['invoice_number' => $number];
        $pages->handle('POST', '/modules/sales/payments/new', $this->actor(), $this->input(), $query);
        $html = $pages->handle('GET', '/modules/sales/payments', $this->actor(), [], ['payment_status' => 'Partially Paid'])['body'];
        self::assertStringContainsString('Record payment</a>', $html);
        self::assertStringContainsString('View history</a>', $html);
        self::assertStringContainsString('13.25', $html);
        $pages->handle('POST', '/modules/sales/payments/new', $this->actor(), $this->input('paid', '13.25', '3'), $query);
        $html = $pages->handle('GET', '/modules/sales/payments', $this->actor(), [], ['invoice_number' => $number, 'payment_status' => 'Paid'])['body'];
        self::assertStringNotContainsString('Record payment</a>', $html);
        self::assertStringContainsString('View history</a>', $html);
        $none = $pages->handle('GET', '/modules/sales/payments', $this->actor(), [], ['customer_code' => 'OTHER'])['body'];
        self::assertStringNotContainsString('href="/training/modules/sales/invoices/' . $number . '"', $none);
        $foreign = $pages->handle('GET', '/modules/sales/payments', $this->actor(8), [], [])['body'];
        self::assertStringNotContainsString('href="/training/modules/sales/invoices/' . $number . '"', $foreign);
        [, $draftPages, $draftNumber] = $this->fixture(false);
        self::assertStringNotContainsString('href="/training/modules/sales/invoices/' . $draftNumber . '"', $draftPages->handle('GET', '/modules/sales/payments', $this->actor(), [], [])['body']);
        $reader = new Actor('reader', 'reader', [], 7, false);
        $db->grants = ['7:reader:sales.payments:read'];
        $html = $pages->handle('GET', '/modules/sales/payments', $reader, [], [])['body'];
        self::assertStringNotContainsString('Submitted Invoices', $html);
        self::assertStringNotContainsString('href="/training/modules/sales/invoices/' . $number . '"', $html);
        $db->grants[] = '7:reader:sales.invoices:read';
        $html = $pages->handle('GET', '/modules/sales/payments', $reader, [], [])['body'];
        self::assertStringContainsString($number, $html);
        self::assertStringNotContainsString('Record payment</a>', $html);
        self::assertStringContainsString('View history</a>', $html);
        self::assertSame(400, $pages->handle('GET', '/modules/sales/payments', $this->actor(), [], ['payment_status' => 'invalid'])['status']);
    }

    public function testInvoiceBrowserPaginatesTwentySixSubmittedInvoicesWithoutPayments(): void
    {
        [$db, $pages] = $this->fixture();
        $service = new InvoiceService($db, static fn (): string => '2026-11-26');
        for ($index = 1; $index <= 25; $index++) {
            $id = 10000 + $index;
            $db->documents[$id] = $db->document($id, 'SO-BROWSE-' . $index, 'SALES_ORDER', 'SUBMITTED');
            $db->lines[$id] = $db->line($id, $id, 1, 163, 'Item', '1', '10.00');
            $invoice = $service->create($this->actor(), ['source_order_number' => 'SO-BROWSE-' . $index]);
            $service->action($this->actor(), $invoice['invoice_number'], 'submit', ['expected_version' => 1]);
        }
        $first = $pages->handle('GET', '/modules/sales/payments', $this->actor(), [], ['customer_code' => 'CUST-0001'])['body'];
        self::assertSame(25, substr_count($first, 'Record payment</a>'));
        self::assertStringContainsString('Next &gt;</a>', $first);
        self::assertStringContainsString('customer_code=CUST-0001&amp;page=2', $first);
        $second = $pages->handle('GET', '/modules/sales/payments', $this->actor(), [], ['customer_code' => 'CUST-0001', 'page' => '2'])['body'];
        self::assertSame(1, substr_count($second, 'Record payment</a>'));
        self::assertStringContainsString('Previous</a>', $second);
        self::assertStringNotContainsString('Next &gt;</a>', $second);
    }
}
