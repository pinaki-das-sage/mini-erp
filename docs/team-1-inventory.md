# Team 1: Inventory

**Team:** 2 developers + 1 QA. **Timebox:** 10 working days.  
**Read with:** [project rules and acceptance mapping](../README.md) and [shared contracts and fixtures](./shared-contracts.md).  
**People:** developers and QA assigned below; assign PM and primary/backup reviewer before kickoff.
**Project Manager:** Amar
**Project Guide:** Anoop
**Automation Guide:** Vasant

### Assigned team members

| Role | Name |
| --- | --- |
| Developer | Anish Shaw |
| Developer | DISHA N G |
| QA | Ajay S S |

Dev A/Dev B map to the work-package owners in Section 3.

Follow the shared [coding conventions](./coding-conventions.md), including lowercase folders.

Use `ME_`-prefixed Oracle objects and the shared [permission framework](./permissions.md). Register your CRUD areas and named actions; enforce permissions in services for both UI and REST. User/company Settings are foundation-owned.

## 1. Your outcome and scope

Deliver the authoritative product catalogue, physical stock, sales-line reservations and immutable stock ledger. Other modules use your services; they never update your tables.

Include product maintenance, availability, reservation detail, movement history, full purchase-receipt effects, all-or-none line reservation, eligible release and matching dispatch consumption. Exclude returns, partial allocation, valuation, arbitrary adjustments, multiple warehouses, batches, expiry and serials.

Own Product, StockBalance, Reservation and StockMovement entities. You design their actual schema and migrations. Shared users/audit/action infrastructure comes from the starter kit.

Rules:

- Product SKU is normalized and unique; weight is a positive integer in grams.
- Referenced products cannot be deleted. Product unit/weight freeze after the first transaction.
- Inactive products cannot appear on new document lines; existing commitments remain fulfillable.
- `available = H - total R`, `H >= 0`, `0 <= total R <= H`.
- Receipt adds H; reservation changes R only; dispatch decreases matching R and H equally.
- A reservation belongs to its original sales line; one line cannot consume another's stock.
- Physical movements reconcile to H; active reservations reconcile to total R.

## 2. How to work without waiting for other teams

The organizer supplies typed interfaces, canonical source IDs, roles, a test workflow driver and scripted peer doubles before Day 1. Build and test your actual module using **your own Oracle schema**.

| Need | Independent substitute | Real integration only |
| --- | --- | --- |
| Purchasing receipt caller | Test driver sends reviewed receipt-line references and quantities | Purchasing invokes your receipt service |
| Sales allocation/cancellation caller | Test driver supplies order/line references and authorized transaction context | Sales validates whole-order demand and calls your reserve/release services |
| Logistics consumption caller | Test driver supplies shipment and reservation references | Logistics validates claims/manifest and calls consumption |
| Draft-claim validation | Logistics contract double returns configured planned quantities | Real claim query participates under the agreed line lock |

Do not wait for PO/order/trip screens or foreign team schemas. Do not implement their business logic. The test driver is restricted to local/test mode, never a public arbitrary-stock endpoint. Doubles do not prove cross-module locks or atomicity.

### Interface deliverables

Implement Inventory's `getAvailability`, reservation-detail query, `receivePurchase`, `reserveStock`, `releaseReservation` and `consumeReservation`, plus the product lookup contract. See [the starter contract matrix](./shared-contracts.md#contract-matrix).

`reserveStock` reserves the full requested line quantity or none. Sales owns the transaction spanning **all order lines**; one successful line does not mean the order allocation can commit.

All write services use the caller's shared transaction context and never commit. Enforce actor/service authorization, source uniqueness and replay rules. Release must reject claimed quantities; the caller and claim-query adapter use the agreed sales-line lock. Revalidate stock after acquiring locks.

## 3. Work packages and ownership

| ID | Work / owner | Local prerequisite | Due / evidence |
| --- | --- | --- | --- |
| INV-01 | Both devs + QA: groom rules; ERD/data dictionary, transitions, service/lock design, wireframes and QA plan | Starter kit | Day 2: reviewed design and cases |
| INV-02 | Dev A: schema, product CRUD/validation and product lookup | INV-01 | Day 4: clean migrations, validation and UI tests |
| INV-03 | Dev A: receipt effect, ledger and history | INV-01; coordinate shared table migration with Dev B | Day 4: real local Oracle receipt/replay tests |
| INV-04 | Dev B: reservations, eligible release, availability/detail | INV-01 | Day 4: insufficient stock and competing requests |
| INV-05 | Dev B: matching consumption and reconciliation queries | INV-04 | Day 5: stock/reservation/ledger assertions |
| INV-06 | Both devs: real adapter integration and shared-data reconciliation | Local services; peer readiness only for integrated checks | Days 5-8: receipt/allocation/dispatch evidence |
| INV-07 | QA: cases, fixtures, automation, defects/retests and sign-off | Starts with INV-01, not after coding | Days 1-10 |

## 4. Design and review deliverables

By Day 2 submit:

1. ERD, keys/constraints/indexes and quantity/source-reference data dictionary.
2. Sequence diagrams for receipt, reserve/release and consume, including later-line failure.
3. Product/reservation lifecycle and product/availability/history wireframes.
4. Authorization, lock order, versioning, canonical replay payload and unique-effect design.
5. Reconciliation SQL design and QA expected-state tables.

Reviewer approves before domain migrations/implementation. Review PRs incrementally. Update the design when a decision changes.

## 5. Minimum test plan

Each case records fixture, role, steps, expected service/UI result, persisted state and evidence.

| Case | Exact expectation |
| --- | --- |
| INV-T01 SKU/weight validation | Duplicate normalized SKU, zero/negative/fractional weight rejected; no invalid rows |
| INV-T02 Referenced/inactive product | Deletion/unit-weight change rejected after transaction; inactive blocks new lines but not existing fulfillment |
| INV-T03 Receipt | Receive 20 A from H=0 -> H=20, R=0 and one +20 movement |
| INV-T04 Reservation | H=20/R=0, reserve 10 -> H=20/R=10/available=10 |
| INV-T05 Insufficient stock | H=6, request 10 -> reserve 0; balances unchanged |
| INV-T06 Allocation race | Two independent Oracle sessions request 8 against H=10 -> one 8, other 0; total R=8 |
| INV-T07 Claimed release | R=10/A=6, release 5 denied; no mutation; remove claims before eligible release |
| INV-T08 Wrong reservation | Another sales line cannot consume/release this line's reservation |
| INV-T09 Consumption | H=20/R=10, consume matching 10 -> H=10/R=0/available=10 |
| INV-T10 Replay/conflict | Same reference/payload returns original result; changed payload conflicts; new reference cannot repeat source effect |
| INV-T11 Stale/unauthorized action | Server rejects invalid actor/version; no stock or ledger effect |
| INV-T12 Rollback/reconciliation | Abort transaction after a write -> no committed effect; H equals net movement deltas and R equals active reservations |

INV-T06/12 require actual Oracle tests, not doubles. The seeded partial claim in INV-T07 is a defensive service test, not a split-planning UI feature. Add endpoint CSRF/output/error checks where applicable.

Source acceptance: retain INV-AC-01/02/04/07; adapt 03/05/08 as in the project README; defer 06. Joint ownership: INT-01/02 with Purchasing, INT-03/06/11/14 with Sales, INT-08/10 with Logistics.

## 6. Daily gates, demo and handover

- Days 1-2: approved stories/design/test plan; verify starter fixtures/interfaces.
- Days 3-4: implement principal path and start automation; standalone demo using test driver.
- Days 5-6: real receipt/allocation smoke; finish consumption and reconciliation.
- Days 7-8: dispatch integration, real races/rollback/replay and fixes.
- Day 9: regression, clean-schema rehearsal and release evidence.
- Day 10: demo, QA sign-off, PM acceptance and retrospective.

Standalone demo: create A, receive 20, reserve 10, release or consume using separate fixtures, show ledger/reservation reconciliation. Integrated demo ends at H=10/R=0 after full delivery of 10 A.

Deliver reviewed design/migrations/PRs, unit/Oracle/browser results, defect/retest log, exact module commands, fixture/reset instructions, known limits and one retrospective improvement. Your QA verifies independently; developers own unit tests. **Local sign-off is possible without peer code; final integrated acceptance is not.**
