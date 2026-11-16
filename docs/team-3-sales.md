# Team 3: Sales and Order Entry

**Team:** 2 developers + 1 QA. **Timebox:** 10 working days.  
**Read with:** [project rules and acceptance mapping](../README.md) and [shared contracts and fixtures](./shared-contracts.md).  
**People:** developers and QA assigned below; assign PM and primary/backup reviewer before kickoff.
**Project Manager:** Supreeth
**Project Guide:** Chirag
**Automation Guide:** Brajesh

### Assigned team members

| Role | Name |
| --- | --- |
| Developer | Kavya M |
| Developer | Sagar Khadka |
| QA | T C MOHAN BABU |

Dev A/Dev B map to the work-package owners in Section 3.

Follow the shared [coding conventions](./coding-conventions.md), including lowercase folders.

Use `ME_`-prefixed Oracle objects and the shared [permission framework](./permissions.md). Register your CRUD areas and named actions; enforce permissions in services for both UI and REST. User/company Settings are foundation-owned.

## 1. Your outcome and scope

Deliver customer/order management and an accurate view of unallocated, reserved, in-transit, delivered and cancelled demand.

Own Customer, SalesOrder, SalesLine and FulfillmentEvent. Inventory owns reservations/physical balances; Logistics owns trip claims and shipments. Exclude quotations, partial allocation, split fulfillment, returns/redelivery, partial cancellation, Closed Short, invoices, payments, taxes and discounts.

Rules:

- Unique customer code; active customer/address and positive whole-unit order lines required.
- Product lines are unique per order; prices have at most two decimals and totals are server-calculated.
- Confirmation freezes reference, product, quantity, price and address; all demand starts unallocated.
- Allocation is explicit: **all remaining demand on every order line, or nothing**.
- Insufficient stock on the final line rolls back reservations on earlier lines.
- Cancel the whole eligible undispatched order with a reason. Remove all draft-trip claims first, then release reservations and cancel atomically.
- Dispatch is not delivery; only Logistics' authorized workflows submit fulfillment facts.
- For each line: `Q = C + D + T + R + U`. U is derived; R comes from Inventory.
- Completed means all Q delivered; wholly cancelled undelivered order is Cancelled. No completion while U/R/T remains.

## 2. How to work without waiting for Inventory or Logistics

| Need | Independent substitute from organizer | Real integration only |
| --- | --- | --- |
| Products/availability/reservations | Product lookup and Inventory doubles with full success, insufficient stock, failure/replay and reservation-detail scenarios | Real Inventory services |
| Draft trip claims | Logistics double returning zero or whole-order claims | Real Logistics query under shared line locks |
| Dispatch/delivery caller | Test workflow driver with original order/line/shipment references | Logistics orchestrator |

Use your own Oracle schema for real customer/order/fulfillment persistence. No waiting for stock/trip screens or querying private foreign schemas. Doubles are development/test-only; live mode must fail explicitly if a real adapter is missing.

### Interfaces and transaction boundary

Consume product lookup; Inventory availability, reservation detail, reserve and release; Logistics `getPlannedQuantity`. Supply `getDispatchableLines`, an agreed sales-line lock/validation entry point, `recordDispatch` and `recordDelivery`.

Dispatchable data includes whole-order grouping, original line IDs, product/weight, confirmed destination, quantities and versions. Do not expose partly reserved orders as baseline-ready.

Sales owns whole-order allocation/cancellation transactions. Logistics owns dispatch/delivery transactions; your fulfillment services join the caller's connection/context and never commit. Re-read quantities after locks; include version and source checks. Own Q/C/D/T, not a second writable reservation balance.

## 3. Work packages and ownership

| ID | Work / owner | Local prerequisite | Due / evidence |
| --- | --- | --- | --- |
| SAL-01 | Both devs + QA: design ERD, quantity/state model, wireframes, interfaces and QA plan | Starter kit | Day 2: reviewed design |
| SAL-02 | Dev A: customer/order migrations, masters/drafts, validation and exact totals | SAL-01 | Day 4: local persistence/UI tests |
| SAL-03 | Dev A: confirmation/snapshots/references, detail/history, roles/versions | SAL-02 | Day 4: immutable confirmed document |
| SAL-04 | Dev B: whole-order allocation and explicit retry using Inventory double | SAL-01; own confirmed-order fixtures | Day 4: success and later-line failure tests |
| SAL-05 | Dev B: whole-order claim-aware cancellation | SAL-04 + supplied claim double | Day 6: release/cancellation atomicity |
| SAL-06 | Dev B: dispatchable/lock contracts and fulfillment facts; Dev A: quantity/history UI | SAL-03; contract fixtures | Day 5 interfaces; Day 7 integrated fulfillment |
| SAL-07 | Both devs: real integrations/races/reconciliation/fixes | Local implementation; peers only for shared checks | Days 5-8 |
| SAL-08 | QA: cases/data, early automation, defects/retests and sign-off | Starts Day 1 | Days 1-10 |

## 4. Design and review deliverables

By Day 2 submit:

1. Customer/order/fulfillment ERD, confirmed snapshots, source uniqueness and constraints.
2. State diagram and worked Q/C/D/T/R/U tables for confirm/allocate/dispatch/deliver/cancel.
3. Whole-order allocation/cancellation sequence diagrams, including failure on the last line.
4. Dispatchable/line-lock contract, role matrix and shared lock order.
5. Wireframes with remaining quantities, explicit allocation and useful failure messages.
6. QA plan covering state conservation, stale versions, replay and multi-line failure.

Assigned reviewer approves before domain migrations/implementation. Peer-facing interface PRs should be small and ready independently of complete screens.

## 5. Minimum test plan

| Case | Exact expectation |
| --- | --- |
| SAL-T01 Customer | Unique code; inactive customer or missing address blocks new order |
| SAL-T02 Draft | Missing/duplicate/inactive product, invalid quantity or invalid price rejected; totals ignore tampered client totals |
| SAL-T03 Confirmation | SO 10 A -> U=10/R=0/T=0/D=0; reference/address/price/quantity snapshots frozen |
| SAL-T04 Whole-order allocation | Sufficient stock for every line -> full reservations; Q invariant holds |
| SAL-T05 Insufficient stock | Need 10/available 6 -> no reservation; U=10 |
| SAL-T06 Later-line failure | First product has stock, second does not -> entire order unallocated; actual Oracle integration proves no earlier reservation persists |
| SAL-T07 Explicit retry/replay | Replenish then new action allocates; old committed action replay returns its old result, not extra reservation |
| SAL-T08 Cancellation | No draft claims -> whole eligible order cancels/releases all reservations with reason |
| SAL-T09 Cancel/plan race | Shared Oracle integration permits valid winner only; claimed order cannot cancel; no A greater than R |
| SAL-T10 Fulfillment | Reserve 10, dispatch 10 -> T=10/D=0; deliver 10 -> T=0/D=10/Completed |
| SAL-T11 Duplicate source | Same dispatch/delivery source cannot repeat, even under another action reference |
| SAL-T12 Stale/protection | Stale confirmed edit, wrong role or invalid CSRF rejected; dispatched/delivered demand cannot cancel |

Use doubles to test decisions and local rollback; actual Inventory/Logistics adapters are required for SAL-T06/09 and shared quantity/lock proof. Do not mark these integrated cases passed using doubles.

Source acceptance: retain SAL-AC-01/02/08; adapt 03/04/05; defer original 06/07 exception scenarios. Lead INT-04/05/10/11; pair on INT-03/06/08/09/14.

## 6. Daily gates, demo and handover

- Days 1-2: approved rules/design/tests, fixtures and typed interfaces.
- Days 3-4: confirm/allocate/cancel standalone; fulfillment driver and early automation.
- Day 5: real Inventory allocation; Day 6: real claims/cancellation.
- Day 7: real dispatch/delivery; Day 8: races/replay/rollback and fixes.
- Day 9: regression/clean install; Day 10: demo, sign-off and retrospective.

Standalone demo: customer -> confirm 10 A -> show allocation failure at 6 available -> change test scenario to sufficient stock -> allocate -> driver-simulated dispatch/delivery -> Completed. Use a separate order to demonstrate cancellation.

Integrated demo uses the original order and real reservations/shipments, with D=10 only after successful delivery. Deliver reviewed design/migrations/PRs, tests/evidence, defects/retests, exact commands and known limits. **Local module work does not need peer code; final integrated quantity reconciliation does.**
