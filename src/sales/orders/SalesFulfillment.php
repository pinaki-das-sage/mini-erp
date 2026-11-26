<?php
declare(strict_types=1);

namespace MiniErp\sales\orders;

use MiniErp\inventory\ReservationService;
use MiniErp\sales\DocumentRules;
use MiniErp\sales\SalesPermissions;
use MiniErp\sales\Validation;
use MiniErp\shared\ActionExecutor;
use MiniErp\shared\Api;
use MiniErp\shared\AppError;
use MiniErp\shared\database\Connection;
use MiniErp\shared\database\DatabaseError;
use MiniErp\shared\database\TransactionContext;
use MiniErp\shared\security\Actor;
use MiniErp\shared\security\Authorization;

/** Reviewable implementation for the proposed Logistics handoff; no public HTTP endpoints.
 * Logistics owns the outer transaction and Trip locks. Propagate all errors to its TransactionManager.
 * Whole-Order assignment/cancellation/delivery only. No stock mutation occurs in these Sales methods.
 */
final readonly class SalesFulfillment
{
    private const ACTIONS = ['assignment' => 'record_trip_assignment', 'cancellation' => 'record_trip_cancellation', 'delivery' => 'record_delivery'];
    private const STATUSES = ['SUBMITTED' => 'Submitted', 'IN_PROGRESS' => 'In Progress', 'CLOSED' => 'Closed'];

    public function __construct(private Connection $connection, private LogisticsOrderProvider $logistics,
        private ReservationService $reservations) {}

    /** Call after Trip locks but BEFORE Logistics/Inventory writes, especially before dispatch consumption.
     * For a multi-Order Trip, prelock all source Quotes/Order parents/Customers/lines in globally agreed
     * sorted order before any Inventory mutation; the complete batch protocol needs team confirmation.
     * Returns original numeric record identities, not public business numbers.
     */
    public function lockOrderForEvent(TransactionContext $tx, Actor $actor, string $orderNumber, string $event): array
    {
        if (!isset(self::ACTIONS[$event])) { throw new AppError('VALIDATION_ERROR', 'Select a valid fulfillment event.'); }
        (new Authorization($this->connection))->requirePermission($actor, SalesPermissions::DOCUMENTS['order'], self::ACTIONS[$event]);
        $order = $this->lockOrder($tx, $actor, $orderNumber);
        $customers = $tx->query('SELECT RECORD# FROM ME_OE_CUSTOMERS WHERE CNY# = :company_id AND RECORD# = :id FOR UPDATE',
            ['company_id' => $actor->companyId, 'id' => $order['CUSTOMER_RECORD#']]);
        if ($customers === []) { throw new AppError('INVALID_STATE', 'Order Customer is unavailable.', 409); }
        $lines = $tx->query("SELECT L.*, TO_CHAR(QUANTITY, 'FM999999999') AS QUANTITY_TEXT FROM ME_OE_DOCUMENT_LINES L
            WHERE CNY# = :company_id AND DOCUMENT_RECORD# = :id ORDER BY RECORD# FOR UPDATE",
            ['company_id' => $actor->companyId, 'id' => $order['RECORD#']]);
        return ['order_number' => $orderNumber, 'order_id' => (string) $order['RECORD#'],
            'status' => $order['STATUS'] === 'DRAFT' ? 'Draft' : (self::STATUSES[$order['STATUS']] ?? $order['STATUS']),
            'current_version' => (int) $order['VERSION'], 'lines' => array_map(static fn (array $line): array => [
                'line_id' => (string) $line['RECORD#'], 'product_id' => (string) $line['PRODUCT_ID'],
                'quantity' => (string) $line['QUANTITY_TEXT']], $lines)];
    }

    /** Also call after dispatch to refresh the same assignment to Dispatched, with a new stable event reference. */
    public function recordTripAssignment(TransactionContext $tx, Actor $actor, string $orderNumber, array $input): array
    {
        return $this->event($tx, $actor, 'assignment', $orderNumber, $input);
    }

    /** Before dispatch only. Cancellation leaves reservations ACTIVE; a later return-to-Draft releases them. */
    public function recordTripCancellation(TransactionContext $tx, Actor $actor, string $orderNumber, array $input): array
    {
        return $this->event($tx, $actor, 'cancellation', $orderNumber, $input);
    }

    /** Call when every Order line has successful full delivery. Invoice creation belongs to Order Submit. */
    public function recordDelivery(TransactionContext $tx, Actor $actor, string $orderNumber, array $input): array
    {
        return $this->event($tx, $actor, 'delivery', $orderNumber, $input);
    }

    private function event(TransactionContext $tx, Actor $actor, string $event, string $number, array $input): array
    {
        (new Authorization($this->connection))->requirePermission($actor, SalesPermissions::DOCUMENTS['order'], self::ACTIONS[$event]);
        Validation::allowedFields($input, ['expected_version', 'event_reference', 'trip_id', 'lines']);
        Api::integer($input, 'expected_version');
        $reference = $input['event_reference'] ?? null;
        if (!is_string($reference) || !preg_match('/\A[A-Za-z0-9_-]{1,100}\z/D', $reference)) {
            throw new AppError('VALIDATION_ERROR', 'Provide a stable Logistics event_reference of 1-100 letters, digits, underscores or hyphens.');
        }
        if (!preg_match('/\A[A-Za-z0-9_-]{1,20}\z/D', $number)) { throw new AppError('NOT_FOUND', 'Order not found.', 404); }
        $tripId = $this->id($input['trip_id'] ?? null, 'Trip ID');
        $input['lines'] = $this->mapping($input['lines'] ?? null);
        // Derived child identity cannot collide with Logistics' outer ME_ACTIONS reference. A changed actor,
        // version or payload on the same event conflicts; exact replays resolve before today's version/state.
        $key = 'oe-fulfill-' . hash('sha256', json_encode([$event, $number, $reference], JSON_THROW_ON_ERROR));
        $hash = ActionExecutor::fingerprint(['event' => $event, 'order_number' => $number, 'input' => $input]);
        $identity = ['company_id' => $actor->companyId, 'reference' => $key];
        try {
            $tx->execute('INSERT INTO ME_ACTIONS (COMPANY_ID, ACTION_REFERENCE, ACTOR_ID, PAYLOAD_HASH) VALUES (:company_id, :reference, :actor, :hash)',
                [...$identity, 'actor' => $actor->id, 'hash' => $hash]);
        } catch (DatabaseError $error) {
            if ($error->oracleCode !== 1) { throw $error; }
            $rows = $tx->query('SELECT ACTOR_ID, PAYLOAD_HASH, RESULT_JSON FROM ME_ACTIONS WHERE COMPANY_ID = :company_id AND ACTION_REFERENCE = :reference', $identity);
            if ($rows === [] || $rows[0]['ACTOR_ID'] !== $actor->id || $rows[0]['PAYLOAD_HASH'] !== $hash || $rows[0]['RESULT_JSON'] === null) {
                throw new AppError('DUPLICATE_ACTION_CONFLICT', 'This Logistics event reference belongs to a different request.', 409);
            }
            return json_decode($rows[0]['RESULT_JSON'], true, 512, JSON_THROW_ON_ERROR);
        }
        $order = $this->lockOrder($tx, $actor, $number);
        DocumentRules::requireVersion((int) $order['VERSION'], $input['expected_version']);
        if (!in_array($order['STATUS'], ['SUBMITTED', 'IN_PROGRESS'], true)) {
            throw new AppError('INVALID_STATE', 'Only Submitted or In Progress Orders accept new Logistics events.', 409);
        }
        $tx->query('SELECT RECORD# FROM ME_OE_CUSTOMERS WHERE CNY# = :company_id AND RECORD# = :id FOR UPDATE',
            ['company_id' => $actor->companyId, 'id' => $order['CUSTOMER_RECORD#']]);
        $lines = $tx->query("SELECT L.*, TO_CHAR(QUANTITY, 'FM999999999') AS QUANTITY_TEXT FROM ME_OE_DOCUMENT_LINES L
            WHERE CNY# = :company_id AND DOCUMENT_RECORD# = :id ORDER BY RECORD# FOR UPDATE",
            ['company_id' => $actor->companyId, 'id' => $order['RECORD#']]);
        if ($lines === [] || count($lines) !== count($input['lines'])) {
            throw new AppError('VALIDATION_ERROR', 'Provide every saved Order line exactly once; partial/split fulfillment is outside this handoff.');
        }
        $mapping = array_column($input['lines'], 'trip_line_id', 'line_id');
        foreach ($lines as $line) { if (!isset($mapping[(string) $line['RECORD#']])) { throw new AppError('VALIDATION_ERROR', 'A line does not belong to this Order.'); } }
        $orderId = (string) $order['RECORD#'];
        try {
            $this->logistics->assertEvent($tx, $actor, $event, $orderId, $tripId, $input['lines'], $reference);
            $facts = $this->facts($this->logistics->getAssignments($tx, $actor, $orderId));
        } catch (DatabaseError) { throw new AppError('LOGISTICS_UNAVAILABLE', 'Logistics event facts could not be verified.', 503); }
        if ($event === 'cancellation' && $facts !== []) {
            throw new AppError('INVALID_STATE', 'Logistics still reports valid Trip assignments for this Order.', 409);
        }
        if ($event !== 'cancellation' && count($facts) !== count($lines)) {
            throw new AppError('INVALID_STATE', 'Logistics must confirm the complete whole-Order assignment.', 409);
        }
        if ($event !== 'cancellation' && count(array_unique(array_column($facts, 'trip_status'))) !== 1) {
            throw new AppError('INVALID_STATE', 'Whole-Order Trip progress must be consistent across every line.', 409);
        }
        $changed = false;
        foreach ($lines as $line) {
            $lineId = (string) $line['RECORD#'];
            $previous = $line['TRIP_STATUS'];
            if ($event === 'cancellation' || $event === 'delivery' || $order['STATUS'] === 'IN_PROGRESS') {
                if ((string) ($line['TRIP_ID'] ?? '') !== $tripId || (string) ($line['LOGISTICS_TRIP_LINE_ID'] ?? '') !== $mapping[$lineId]) {
                    throw new AppError('INVALID_STATE', 'Trip and Trip line must match the saved Order assignment.', 409);
                }
            } elseif ($line['TRIP_ID'] !== null || in_array($previous, ['Draft', 'Dispatched', 'Delivered'], true)) {
                throw new AppError('INVALID_STATE', 'The Order already has an assignment.', 409);
            }
            $status = 'Cancelled';
            if ($event !== 'cancellation') {
                $fact = $facts[$lineId] ?? null;
                if ($fact === null || $fact['trip_id'] !== $tripId || $fact['trip_line_id'] !== $mapping[$lineId] ||
                    $fact['product_id'] !== (string) $line['PRODUCT_ID'] || $fact['quantity'] !== (string) $line['QUANTITY_TEXT']) {
                    throw new AppError('INVALID_STATE', 'Logistics assignment does not match the saved Order demand.', 409);
                }
                $status = $fact['trip_status'];
                if ($event === 'delivery' && $status !== 'Delivered' || $event === 'assignment' && !in_array($status, ['Draft', 'Dispatched'], true)) {
                    throw new AppError('INVALID_STATE', 'Logistics status does not match this event.', 409);
                }
                if ($order['STATUS'] === 'SUBMITTED' && $status !== 'Draft' || $previous === 'Dispatched' && $status === 'Draft' || $previous === 'Delivered') {
                    throw new AppError('INVALID_STATE', 'Trip progress cannot skip initial assignment or move backwards.', 409);
                }
            } elseif ($previous !== 'Draft') {
                throw new AppError('INVALID_STATE', 'Only undispatched Draft-trip assignments can be cancelled.', 409);
            }
            try { $reservation = $this->reservations->getReservationDetail($actor, $tx, (int) $orderId, (int) $lineId); }
            catch (DatabaseError) { throw new AppError('INVENTORY_UNAVAILABLE', 'Inventory fulfillment facts could not be verified.', 503); }
            $expectedReservation = in_array($status, ['Dispatched', 'Delivered'], true) ? 'DISPATCHED' : 'ACTIVE';
            if ($reservation['status'] !== $expectedReservation || $reservation['product_id'] !== (int) $line['PRODUCT_ID'] ||
                $reservation['reserved_quantity'] !== (int) $line['QUANTITY_TEXT']) {
                throw new AppError('INVALID_STATE', 'Inventory must confirm the matching active/consumed reservation for this Trip event.', 409);
            }
            if ($expectedReservation === 'DISPATCHED' && ((string) $reservation['trip_id'] !== $tripId || (string) $reservation['trip_line_id'] !== $mapping[$lineId])) {
                throw new AppError('INVALID_STATE', 'Inventory consumption belongs to a different Trip assignment.', 409);
            }
            $nextTrip = $event === 'cancellation' ? null : $tripId;
            $nextTripLine = $event === 'cancellation' ? null : $mapping[$lineId];
            if ($previous !== $status || (string) ($line['TRIP_ID'] ?? '') !== (string) ($nextTrip ?? '') ||
                (string) ($line['LOGISTICS_TRIP_LINE_ID'] ?? '') !== (string) ($nextTripLine ?? '')) {
                $written = $tx->execute('UPDATE ME_OE_DOCUMENT_LINES SET TRIP_ID = :trip_id, LOGISTICS_TRIP_LINE_ID = :trip_line_id,
                    TRIP_STATUS = :trip_status, WHENMODIFIED = CURRENT_TIMESTAMP
                    WHERE CNY# = :company_id AND DOCUMENT_RECORD# = :id AND RECORD# = :line_id',
                    ['company_id' => $actor->companyId, 'id' => $order['RECORD#'], 'line_id' => $line['RECORD#'],
                        'trip_id' => $nextTrip, 'trip_line_id' => $nextTripLine, 'trip_status' => $status]);
                if ($written !== 1) { throw new AppError('INVALID_STATE', 'Order line could not be updated.', 409); }
                $changed = true;
            }
        }
        $status = match ($event) { 'cancellation' => 'SUBMITTED', 'delivery' => 'CLOSED', default => 'IN_PROGRESS' };
        $changed = $changed || $order['STATUS'] !== $status;
        if ($changed) {
            if ((int) $order['VERSION'] >= 9999999999) { throw new AppError('INVALID_STATE', 'Order version capacity exhausted.', 409); }
            $written = $tx->execute("UPDATE ME_OE_DOCUMENTS SET STATUS = :status, VERSION = VERSION + 1, WHENMODIFIED = CURRENT_TIMESTAMP
                WHERE CNY# = :company_id AND RECORD# = :id AND DOCUMENT_TYPE = 'SALES_ORDER'",
                ['company_id' => $actor->companyId, 'id' => $order['RECORD#'], 'status' => $status]);
            if ($written !== 1) { throw new AppError('INVALID_STATE', 'Order status could not be updated.', 409); }
        }
        $result = ['order_number' => $number, 'order_id' => $orderId, 'status' => self::STATUSES[$status],
            'current_version' => (int) $order['VERSION'] + ($changed ? 1 : 0), 'trip_id' => $tripId,
            'event_reference' => $reference];
        $tx->execute('UPDATE ME_ACTIONS SET RESULT_JSON = :result WHERE COMPANY_ID = :company_id AND ACTION_REFERENCE = :reference',
            [...$identity, 'result' => json_encode($result, JSON_THROW_ON_ERROR)]);
        $tx->execute('INSERT INTO ME_AUDIT (ID, COMPANY_ID, ACTION_REFERENCE, ACTOR_ID, EVENT_TYPE, DETAILS_JSON)
            VALUES (:id, :company_id, :reference, :actor, :event, :details)', [...$identity, 'id' => bin2hex(random_bytes(16)),
                'actor' => $actor->id, 'event' => 'OE_LOGISTICS_' . strtoupper($event),
                'details' => json_encode(['order_number' => $number, 'trip_id' => $tripId, 'event_reference' => $reference], JSON_THROW_ON_ERROR)]);
        return $result;
    }

    private function id(mixed $value, string $label): string
    {
        if (!is_string($value) || !preg_match('/\A[1-9][0-9]{0,14}\z/D', $value)) { throw new AppError('VALIDATION_ERROR', $label . ' must be a positive decimal string of up to 15 digits.'); }
        return $value;
    }

    private function mapping(mixed $lines): array
    {
        if (!is_array($lines) || !array_is_list($lines) || $lines === []) { throw new AppError('VALIDATION_ERROR', 'Provide the complete Order-to-Trip line mapping.'); }
        $result = []; $seen = []; $tripLines = [];
        foreach ($lines as $line) {
            if ($line instanceof \stdClass) { $line = get_object_vars($line); }
            if (!is_array($line) || array_is_list($line)) { throw new AppError('VALIDATION_ERROR', 'Each mapping must be an object.'); }
            Validation::allowedFields($line, ['line_id', 'trip_line_id']);
            $id = $this->id($line['line_id'] ?? null, 'Sales line ID'); $tripLine = $this->id($line['trip_line_id'] ?? null, 'Trip line ID');
            if (isset($seen[$id]) || isset($tripLines[$tripLine])) { throw new AppError('VALIDATION_ERROR', 'Duplicate Sales or Trip line mapping.'); }
            $seen[$id] = true; $tripLines[$tripLine] = true; $result[] = ['line_id' => $id, 'trip_line_id' => $tripLine];
        }
        usort($result, static fn (array $a, array $b): int => (int) $a['line_id'] <=> (int) $b['line_id']);
        return $result;
    }

    private function facts(array $facts): array
    {
        if (!array_is_list($facts)) { throw new AppError('LOGISTICS_UNAVAILABLE', 'Logistics returned invalid assignment facts.', 503); }
        $result = []; $tripLines = [];
        foreach ($facts as $fact) {
            if (!is_array($fact)) { throw new AppError('LOGISTICS_UNAVAILABLE', 'Logistics returned invalid assignment facts.', 503); }
            foreach (['line_id', 'trip_id', 'trip_line_id', 'product_id', 'quantity'] as $key) {
                if (!is_string($fact[$key] ?? null) || !preg_match('/\A[1-9][0-9]{0,14}\z/D', $fact[$key])) {
                    throw new AppError('LOGISTICS_UNAVAILABLE', 'Logistics returned invalid assignment facts.', 503);
                }
            }
            if (!in_array($fact['trip_status'] ?? null, ['Draft', 'Dispatched', 'Delivered'], true) || isset($result[$fact['line_id']]) || isset($tripLines[$fact['trip_line_id']])) {
                throw new AppError('LOGISTICS_UNAVAILABLE', 'Logistics returned duplicate or invalid assignment facts.', 503);
            }
            $result[$fact['line_id']] = $fact; $tripLines[$fact['trip_line_id']] = true;
        }
        return $result;
    }

    /** Same Quote -> Order parent synchronization protocol as OrderService; Logistics already holds its Trip. */
    private function lockOrder(TransactionContext $tx, Actor $actor, string $number): array
    {
        $rows = $tx->query('SELECT RECORD# FROM ME_OE_DOCUMENTS WHERE CNY# = :company_id AND DOCUMENT_TYPE = :document_type AND DOCUMENT_NUMBER = :document_number',
            ['company_id' => $actor->companyId, 'document_type' => 'SALES_ORDER', 'document_number' => $number]);
        if ($rows === []) { throw new AppError('NOT_FOUND', 'Order not found.', 404); }
        $links = $tx->query('SELECT SOURCE_DOCUMENT_RECORD#, TARGET_DOCUMENT_RECORD# FROM ME_OE_SOURCE_LINKS WHERE CNY# = :company_id AND TARGET_DOCUMENT_RECORD# = :target_id',
            ['company_id' => $actor->companyId, 'target_id' => $rows[0]['RECORD#']]);
        if (count($links) !== 1) { throw new AppError('INVALID_STATE', 'Order must retain its Quote source.', 409); }
        $sources = $tx->query('SELECT DOCUMENT_NUMBER FROM ME_OE_DOCUMENTS WHERE CNY# = :company_id AND RECORD# = :id AND DOCUMENT_TYPE = :document_type',
            ['company_id' => $actor->companyId, 'id' => $links[0]['SOURCE_DOCUMENT_RECORD#'], 'document_type' => 'SALES_QUOTE']);
        if ($sources === []) { throw new AppError('INVALID_STATE', 'Order Quote source is invalid.', 409); }
        $tx->query('SELECT RECORD# FROM ME_OE_DOCUMENTS WHERE CNY# = :company_id AND DOCUMENT_TYPE = :document_type AND DOCUMENT_NUMBER = :document_number FOR UPDATE',
            ['company_id' => $actor->companyId, 'document_type' => 'SALES_QUOTE', 'document_number' => $sources[0]['DOCUMENT_NUMBER']]);
        $orders = $tx->query('SELECT D.* FROM ME_OE_DOCUMENTS D WHERE CNY# = :company_id AND DOCUMENT_TYPE = :document_type AND DOCUMENT_NUMBER = :document_number FOR UPDATE',
            ['company_id' => $actor->companyId, 'document_type' => 'SALES_ORDER', 'document_number' => $number]);
        if ($orders === []) { throw new AppError('NOT_FOUND', 'Order not found.', 404); }
        $current = $tx->query('SELECT SOURCE_DOCUMENT_RECORD#, TARGET_DOCUMENT_RECORD# FROM ME_OE_SOURCE_LINKS WHERE CNY# = :company_id AND TARGET_DOCUMENT_RECORD# = :target_id',
            ['company_id' => $actor->companyId, 'target_id' => $orders[0]['RECORD#']]);
        if ($current === [] || (string) $current[0]['SOURCE_DOCUMENT_RECORD#'] !== (string) $links[0]['SOURCE_DOCUMENT_RECORD#']) {
            throw new AppError('INVALID_STATE', 'Order source changed during fulfillment.', 409);
        }
        return $orders[0];
    }
}
