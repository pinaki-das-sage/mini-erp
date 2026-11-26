# Sales–Logistics: whole lines across multiple Trips

Sales callbacks support different whole lines of one Order on different Trips. A single line's quantity
cannot be split, and a line may have at most one active assignment. Existing whole-Order Trips still work.
No Sales database migration or method signature change is required. Logistics must check its own
database constraints and update Trip planning, validation, and dispatch writers before using this flow.

## Files to share

- `src/sales/orders/SalesFulfillment.php`: Sales callbacks and authoritative Order/line reconciliation.
- `src/sales/orders/LogisticsOrderProvider.php`: the shared provider contract.
- `src/logistics/SalesOrderProvider.php`: revised Logistics read/event provider; review and use this version.
- This document: callback timing, payloads, and expected behavior.

`src/sales/orders/LogisticsOrderAdapter.php` retains its existing signatures and forwarding behavior.

## Transaction and payload

Logistics owns the outer database transaction and Trip lock. Call `lockOrderForEvent()` BEFORE changing
assignments or consuming Inventory. It still returns the complete Order and all lines. Select this Trip's
whole lines from that result, and use the locked Order's `current_version` for the callback. Events on
other Trips increment the same Order version, so do not reuse a version cached during Trip creation.
Keep the existing agreed Quote -> Order -> Customer -> sorted Sales-line -> Inventory lock protocol.
An error must roll back the entire outer transaction, including Logistics and Inventory changes.

Example for Trip 7 carrying only Sales line 123:

```php
$input = [
    'expected_version' => $locked['current_version'],
    'event_reference' => 'logtrip_assignment_7_event_1',
    'trip_id' => '7',
    'lines' => [['line_id' => '123', 'trip_line_id' => '501']],
];
```

IDs are decimal strings. `line_id` is the saved Sales Order line ID, not a Quote source line or a display
position. Include every Order line on the affected Trip, not every line of the Order. Do not add quantity
overrides: Sales verifies the Logistics and Inventory quantities against each complete saved Sales line.
Use a stable event reference for each event, with `logtrip_assignment_`, `logtrip_dispatch_`,
`logtrip_cancellation_`, or `logtrip_delivery_` as appropriate. Retry successful callbacks with their original
payload/version/reference. Logistics must deduplicate its outer event before repeating owner-side writes;
do not reset Trip facts to an earlier status merely to replay a Sales callback.

## Assignment, dispatch, cancellation, and delivery

1. Draft planning: choose unassigned whole lines on a Submitted or In Progress Order. Persist Trip/line
   claims and assignment actor/time, then call `recordTripAssignment()`. No stock is consumed.
2. Dispatch: under the same locks, consume only this Trip's lines using their individual reservation IDs,
   Sales line IDs, Trip ID, and TripLine IDs. Persist Dispatched status and evidence, then call
   `recordTripAssignment()` with a dispatch event reference. Sales verifies the matching consumed
   reservations before accepting progress. Other Order lines need not be assigned yet.
3. Draft cancellation: persist cancellation evidence and remove this Trip from current claims, keeping
   its Trip/line records or history available to `assertEvent()`. Call `recordTripCancellation()` for all
   of this Trip's lines. Sales clears only those Draft references. Stock stays reserved for reassignment
   or a later Order return to Draft. Dispatched or delivered assignments cannot be cancelled/reassigned.
4. Delivery: persist Delivered status and assigned-driver evidence, then call `recordDelivery()` for this
   Trip's lines. Only previously dispatched lines can be delivered. No Inventory or Invoice mutation
   occurs in Sales delivery callbacks.

For changing a line's Trip, cancel its old Draft Trip first, then plan its now-unassigned whole lines again.
Do not overwrite an active assignment. Multiple lines may share a Trip. A callback cannot omit other
lines on that same Trip while advancing its header status.

`getAssignments()` must return all non-cancelled assignments across every Trip for this Order, including
Draft, Dispatched, and Delivered lines. Mixed statuses across different Trips are valid. Duplicate Sales
line claims, duplicate TripLine IDs, or mixed header statuses within one Trip are invalid. Sales reconciles
untouched lines against this complete snapshot; an event for Trip 7 cannot change Trip 8's facts.

## Order status and surrounding flow

| Saved Order line state | Order status |
| --- | --- |
| No line dispatched or delivered | Submitted |
| At least one dispatched or delivered, but not all delivered | In Progress |
| Every line delivered | Closed |

Cancelling another Draft Trip does not reset an In Progress Order. Delivery of one Trip does not close
an Order with other undelivered lines. Return to Draft remains blocked by any current assignment or
dispatched/delivered line. When eligible, Order return releases all remaining reservations atomically;
any linked Invoice must satisfy the existing Draft/deletion guards.

Quote -> Draft Order -> Submitted Order stays unchanged. Order submission reserves all lines; Invoice
creation remains a separate action on the Submitted Order. Create the Invoice before the first dispatch
if it is needed: the current Invoice create service does not accept In Progress or Closed Orders. Trip
events neither create additional Invoices nor alter existing Invoices.

## Logistics-owned work

- Trip create/edit/validation: select whole unassigned lines, including remaining lines on In Progress
  Orders; reject an active claim on the same line in another Trip.
- Repository/claims: support multiple active Trip IDs per Order while preserving one active claim per
  Sales line. Cancel only the affected Trip's claims. Check schema constraints before deployment.
- Dispatch: consume selected lines' reservations exactly once, not the complete Order's reservations.
- Callbacks: send the affected Trip's complete line mapping with current Order version and persistent
  event/driver evidence, in the same transaction. Preserve full cross-Trip snapshots for Sales checks.

Validate together: two and three Trips; two lines sharing one Trip; planning after another dispatch;
Draft cancellation while another Trip is Draft/dispatched/delivered; stale versions; duplicate claims;
callback retries; wrong quantities; and failure rollback after Inventory consumption. Confirm no change
to the existing Invoice, and close only after every Order line is delivered.
