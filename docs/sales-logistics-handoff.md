# Sales?Logistics: one whole Order per Trip

One Sales Order uses one current Trip, including every saved Order line for its full quantity.
Splitting an Order or one line across Trips is unsupported. Cancelled Trip history may remain;
a replacement Trip is allowed only after the whole previous Draft assignment is cancelled.
No Sales database migration or method signature change is required.

## Files to share

- src/sales/orders/SalesFulfillment.php: callbacks and complete Order/line reconciliation.
- src/sales/orders/LogisticsOrderProvider.php: provider contract.
- src/logistics/SalesOrderProvider.php: Logistics read/event provider.
- This document: callback timing, payloads, and expected behavior.

LogisticsOrderAdapter retains its existing signatures and forwarding behavior. The provider retains
numeric ME_USERS.ID lookup for audit/driver authorization and the agreed event-reference prefixes.

## Transaction and payload

Logistics owns the outer database transaction and Trip lock. Call lockOrderForEvent() before changing
assignments or consuming Inventory. It returns the complete Order and all lines. Use its current_version
for the callback. Keep the agreed Quote -> Order -> Customer -> sorted Sales-line -> Inventory lock
protocol. An error must roll back the entire transaction, including Logistics and Inventory changes.

Example for a two-line Order on Trip 7:

```php
$input = [
    'expected_version' => $locked['current_version'],
    'event_reference' => 'logtrip_assignment_7_event_1',
    'trip_id' => '7',
    'lines' => [
        ['line_id' => '123', 'trip_line_id' => '501'],
        ['line_id' => '124', 'trip_line_id' => '502'],
    ],
];
```

IDs are decimal strings. line_id is the saved Sales Order line ID, not a Quote source line or display
position. Include every saved Order line exactly once in assignment, dispatch, cancellation and delivery.
TripLine IDs must be distinct. Do not add quantity overrides: saved demand must match Logistics and
Inventory. Use stable event references prefixed logtrip_assignment_, logtrip_dispatch_,
logtrip_cancellation_ or logtrip_delivery_. Exact retries use the original payload/version/reference.
Logistics must deduplicate the outer event before repeating its own writes; never rewind Trip facts
merely to replay a Sales callback.

## Events and status

| Logistics event | Sales callback | Order status |
| --- | --- | --- |
| Create Draft Trip with all Order lines | recordTripAssignment() | Submitted |
| Dispatch whole Trip | recordTripAssignment() | In Progress |
| Cancel whole Draft Trip | recordTripCancellation() | Submitted |
| Deliver whole dispatched Trip | recordDelivery() | Closed |

Draft creation does not consume stock. At dispatch, Logistics consumes each Order line's reservation
exactly once, passing its reservation ID, Sales line ID, Trip ID and TripLine ID in the same transaction.
Persist dispatch evidence before the callback; Sales checks matching consumed reservations for all lines.

Cancellation must remove all current claims while retaining Trip/line records or history until the
callback succeeds. Sales clears all Draft references; stock stays reserved for reassignment or Order
return to Draft. Dispatched or Delivered assignments cannot be cancelled, reassigned or moved backward.

Delivery requires persisted delivery evidence and assigned-driver authorization. Every line must already
be dispatched on that same Trip. Delivery neither consumes stock again nor creates or modifies an Invoice.

getAssignments() returns all non-cancelled assignments for the Order on one Trip with one shared status.
Mixed Trip IDs/statuses, duplicate Sales lines/TripLine IDs, foreign lines or wrong quantities are rejected.
Sales rejects partial callback mappings and reconciles every saved line before writing.

## Surrounding Sales flow

Submitted Quote -> Draft Order -> Submitted Order -> In Progress -> Closed stays unchanged. Submit
reserves every line. Create Invoice remains a separate action on a Submitted Order. Create it before
dispatch if needed; the current Invoice create service does not accept In Progress or Closed Orders.

Return to Draft remains blocked by an active Trip or dispatched/delivered line. Once unassigned, the
existing return guards apply: no linked Invoice, or an eligible Draft Invoice without payments/downstream
references. Return releases all reservations and removes an eligible linked Draft Invoice atomically.

Logistics planning/validation must reject a second active Trip for an Order and always create all its
lines together. Dispatch and delivery must advance the whole Trip. Verify two- and three-line Orders,
partial mapping rejection, second-Trip rejection, Draft cancellation/reassignment, stale versions,
retries, wrong demand, dispatch rollback and unchanged Invoices together before deployment.
