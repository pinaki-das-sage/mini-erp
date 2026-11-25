<?php
declare(strict_types=1);

namespace MiniErp\Tests\unit\sales;

use MiniErp\inventory\QuoteInventoryValidator;
use MiniErp\inventory\ReservationService;
use MiniErp\sales\orders\InventoryOrderAdapter;
use MiniErp\sales\orders\OrderService;
use MiniErp\sales\orders\SalesTripAssignmentGuard;
use MiniErp\shared\AppError;
use MiniErp\shared\database\DatabaseError;
use MiniErp\shared\security\Actor;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/InventoryOrderAdapterTest.php';

final class OrderInvoiceServiceTest extends TestCase
{
    private function setupOrder(): array
    {
        $db = new ReservationMemoryConnection(); $guard = new SalesTripAssignmentGuard();
        $service = new OrderService($db, new InventoryOrderAdapter(
            new ReservationService($db, $guard->getPlannedQuantity(...)), new QuoteInventoryValidator()), $guard,
            static fn (): string => '2026-11-25');
        $actor = new Actor('admin', 'admin', [], 7, true);
        $order = $service->create($actor, ['source_quote_number' => 'SQ-000000000000010', 'notes' => 'Invoice snapshot']);
        return [$db, $service, $actor, $order];
    }

    public function testSubmitCopiesFinalOrderAndReturnResubmitReplacesDraftInvoice(): void
    {
        [$db, $service, $actor, $order] = $this->setupOrder();
        $number = $order['order_number']; $line = $order['lines'][0];
        $edited = $service->update($actor, $number, ['expected_version' => 1, 'lines' => [[
            'line_id' => $line['line_id'], 'product_id' => '163', 'description' => 'Final saved demand',
            'quantity' => '2', 'unit_price' => '7.25']]]);
        $request = ['expected_version' => 2, 'action_reference' => 'submit-with-invoice'];
        $submitted = $service->action($actor, $number, 'submit', $request);
        self::assertSame('SI-000000000000001', $submitted['invoice_number']);
        self::assertSame($submitted, $service->action($actor, $number, 'submit', $request));
        $invoice = $db->sales->documents[12];
        self::assertSame('DRAFT', $invoice['STATUS']); self::assertSame('2026-12-25', $invoice['DUE_DATE_TEXT']);
        foreach (['CUSTOMER_RECORD#', 'WAREHOUSE_ID', 'DATE_TEXT', 'SHIPPING_ADDRESS', 'PAYMENT_TERMS', 'NOTES'] as $field) {
            self::assertSame($db->sales->documents[11][$field], $invoice[$field]);
        }
        $invoiceLines = array_values(array_filter($db->sales->lines, fn ($r) => $r['DOCUMENT_RECORD#'] == 12));
        self::assertCount(1, $invoiceLines);
        self::assertSame('2', $invoiceLines[0]['QUANTITY_TEXT']); self::assertSame('7.25', $invoiceLines[0]['PRICE_TEXT']);
        self::assertSame($edited['lines'][0]['line_id'], $invoiceLines[0]['SOURCE_LINE_RECORD#']);
        self::assertSame(['ON_HAND' => 20, 'RESERVED' => 2], $db->balances[163]);
        $returned = $service->action($actor, $number, 'return_to_draft', ['expected_version' => 3, 'action_reference' => 'return-invoice']);
        self::assertSame('Draft', $returned['status']); self::assertArrayNotHasKey(12, $db->sales->documents);
        self::assertCount(1, $db->sales->links); self::assertSame(['ON_HAND' => 20, 'RESERVED' => 0], $db->balances[163]);
        $again = $service->action($actor, $number, 'submit', ['expected_version' => 4]);
        self::assertSame('SI-000000000000002', $again['invoice_number']); self::assertCount(3, $db->sales->documents);
    }

    public function testInvoiceLineFailureAndAuditFailureRollBackReservationAndOrder(): void
    {
        foreach (['line', 'audit'] as $failure) {
            [$db, $service, $actor, $order] = $this->setupOrder();
            if ($failure === 'line') { $db->sales->failOnLineWrite = $db->sales->lineWrites + 2; }
            else { $db->sales->failAudit = true; }
            try {
                $service->action($actor, $order['order_number'], 'submit', ['expected_version' => 1, 'action_reference' => 'failed-submit']);
                self::fail('Failure must roll back.');
            } catch (AppError|DatabaseError) {
                self::assertSame($order, $service->get($actor, $order['order_number']));
                self::assertCount(2, $db->sales->documents); self::assertCount(1, $db->sales->links);
                self::assertSame([], $db->reservations); self::assertSame(0, $db->balances[163]['RESERVED']);
                self::assertCount(1, $db->sales->actions);
            }
        }
    }

    public function testSubmittedPaidOrReferencedInvoiceBlocksReturnWithoutStockChanges(): void
    {
        foreach (['submitted', 'payment', 'downstream', 'line-reference'] as $failure) {
            [$db, $service, $actor, $order] = $this->setupOrder();
            $service->action($actor, $order['order_number'], 'submit', ['expected_version' => 1]);
            if ($failure === 'submitted') { $db->sales->documents[12]['STATUS'] = 'SUBMITTED'; }
            elseif ($failure === 'payment') { $db->sales->payments[] = ['CNY#' => 7, 'INVOICE_RECORD#' => 12]; }
            elseif ($failure === 'downstream') { $db->sales->links[] = ['CNY#' => 7, 'SOURCE_DOCUMENT_RECORD#' => 12, 'TARGET_DOCUMENT_RECORD#' => 99]; }
            else {
                $origin = array_values(array_filter($db->sales->lines, fn ($r) => $r['DOCUMENT_RECORD#'] == 12))[0];
                $db->sales->lines[999] = [...$origin, 'RECORD#' => 999, 'DOCUMENT_RECORD#' => 99, 'SOURCE_LINE_RECORD#' => $origin['RECORD#']];
            }
            self::assertSame([], $service->get($actor, $order['order_number'])['available_actions']);
            try { $service->action($actor, $order['order_number'], 'return_to_draft', ['expected_version' => 2]); self::fail('Return must fail.'); }
            catch (AppError $error) { self::assertSame('INVALID_STATE', $error->errorCode); }
            self::assertArrayHasKey(12, $db->sales->documents); self::assertSame(4, $db->balances[163]['RESERVED']);
            self::assertSame('Submitted', $service->get($actor, $order['order_number'])['status']);
        }
    }

    public function testReleaseFailureRestoresDeletedInvoiceAndSourceLines(): void
    {
        [$db, $service, $actor, $order] = $this->setupOrder();
        $service->action($actor, $order['order_number'], 'submit', ['expected_version' => 1]);
        $documents = $db->sales->documents; $lines = $db->sales->lines; $links = $db->sales->links;
        $db->failTripLookupAt = $db->tripLookupCount + 3;
        try { $service->action($actor, $order['order_number'], 'return_to_draft', ['expected_version' => 2]); self::fail('Release lookup must fail.'); }
        catch (AppError) {
            self::assertSame($documents, $db->sales->documents); self::assertSame($lines, $db->sales->lines);
            self::assertSame($links, $db->sales->links); self::assertSame(4, $db->balances[163]['RESERVED']);
        }
    }

    public function testReturnRequiresInvoiceDeletePermission(): void
    {
        [$db, $service, $actor, $order] = $this->setupOrder();
        $service->action($actor, $order['order_number'], 'submit', ['expected_version' => 1]);
        $db->sales->grants = ['sales.orders:return_to_draft', 'sales.orders:read'];
        $restricted = new Actor('admin', 'admin', [], 7);
        self::assertSame([], $service->get($restricted, $order['order_number'])['available_actions']);
        try { $service->action($restricted, $order['order_number'], 'return_to_draft', ['expected_version' => 2]); self::fail('Invoice delete permission is required.'); }
        catch (AppError $error) { self::assertSame('FORBIDDEN', $error->errorCode); }
        self::assertSame('DRAFT', $db->sales->documents[12]['STATUS']); self::assertSame(4, $db->balances[163]['RESERVED']);
    }
}
