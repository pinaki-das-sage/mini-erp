# Team 4: Logistics

**Team:** 2 developers + 1 QA. **Timebox:** 10 working days.  
**Read with:** [project rules and acceptance mapping](../README.md) and [shared contracts and fixtures](./shared-contracts.md).  
**People:** developers and QA assigned below; assign PM and primary/backup reviewer before kickoff.
**Project Manager:** Avinav
**Project Guide:** Bipin
**Automation Guide:** Vasant

### Assigned team members

| Role | Name |
| --- | --- |
| Developer | V M Rutvik Swami |
| Developer | Varun A |
| QA | Varun Yarmalli |

V M Rutvik Swami is Dev A and Varun A is Dev B for the work packages in Section 3.

Follow the shared [coding conventions](./coding-conventions.md), including lowercase folders.

Use `ME_`-prefixed Oracle objects and the shared [permission framework](./permissions.md). Register your CRUD areas and named actions; enforce permissions in services for both UI and REST. User/company Settings are foundation-owned.

## 1. Your outcome and scope

Deliver trip planning, capacity/resource control, immutable dispatch manifests, assigned-driver successful delivery and reconciled trip closure.

Own Vehicle, DriverAssignment, Trip, TripLine and DeliveryOutcome entities. Exclude split-order planning, partial/failed delivery, returns/redelivery, GPS/routing, signatures and fees. Vehicles/drivers are seeded; elaborate maintenance is not required.

Rules:

- Trip: Draft -> Dispatched -> Closed; Draft may be Cancelled.
- Each vehicle and driver belongs to at most one Draft/Dispatched trip.
- Claim all lines of a fully allocated order together, on only one trip.
- A trip may contain multiple whole orders. Do not split an oversized order; leave it pending.
- Load is `sum(quantity * unit_weight_grams)`; exact capacity succeeds, excess fails.
- Planning changes claims only, not warehouse H/R. Draft cancellation frees claims/resources.
- Dispatch consumes matching H/R, updates Sales T and locks the manifest in one transaction.
- Only the assigned driver posts full successful outcomes; D increases/T decreases once.
- Close only when every shipment line has a final successful outcome and T is reconciled to zero; release resources.

## 2. How to work without waiting for Sales or Inventory

| Need | Independent substitute from organizer | Real integration only |
| --- | --- | --- |
| Fully reserved customer orders | Sales query/line-lock doubles with canonical whole orders, destinations and versions | Sales dispatchable/lock services |
| Weight and reservation detail | Product/Inventory doubles with stable product weights and reservations | Inventory product/reservation queries |
| Stock consumption | Inventory consume double with success/replay/failure | Real matching reservation consumption |
| Dispatch/delivery facts | Sales write doubles with success/replay/conflict and chosen-line failure | Real Sales facts |

Your resource/trip/manifest/outcome persistence and local race tests use **your own Oracle schema**. No peer screens or private foreign tables are needed. Test-only peer scenarios are not real stocked orders and must be visibly labeled.

### Interfaces and transaction boundary

Consume product/reservation queries, Sales dispatchable and line-lock services, Inventory `consumeReservation`, Sales `recordDispatch`/`recordDelivery`. Supply Logistics `getPlannedQuantity`.

Logistics owns planning, dispatch and delivery transactions. Dispatch covers every manifest line on the shared connection/context. Revalidate resources, capacity, sales versions, reservations and claims under locks before mutation; freeze quantity/weight snapshots at dispatch. A last-line failure leaves the trip Draft and all original claims/reservations intact.

All participants follow resource -> trip -> sorted sales lines -> relevant later lock categories from the common plan. A query snapshot alone never guarantees dispatch. Never silently fall back to simulated stock or fulfillment when a real adapter fails.

## 3. Work packages and ownership

| ID | Work / owner | Local prerequisite | Due / evidence |
| --- | --- | --- | --- |
| LOG-01 | Both devs + QA: ERD, states, whole-order grouping, capacity/locks, wireframes and test plan | Starter kit | Day 2: reviewed design |
| LOG-02 | Dev A: migrations, seeded resources, planner/weight meter and claims | LOG-01 + supplied query doubles | Day 4: exact/excess capacity and resource checks |
| LOG-03 | Dev A: draft edit/cancel, resource/claim release and planned-quantity query | LOG-02 | Day 5: local race/cancellation tests |
| LOG-04 | Dev B: full dispatch orchestration and immutable manifest | LOG-01; own trip fixtures + supplied write doubles | Day 4 orchestration demo; Day 7 real dispatch |
| LOG-05 | Dev B: driver queue, successful outcomes, unique effects and closure | LOG-04 | Day 4: scripted outcome/closure demo; Day 7: real integration |
| LOG-06 | Both devs: real adapters, shared races/rollback/reconciliation and fixes | Local implementation; peers only for shared checks | Days 5-8 |
| LOG-07 | QA: cases/data, browser automation, defects/retests and sign-off | Starts Day 1 | Days 1-10 |

## 4. Design and review deliverables

By Day 2 submit:

1. Resource/trip/claim/manifest/outcome ERD, unique assignments/source effects and migration order.
2. Trip/shipment states, weight calculations and whole-order eligibility rules.
3. Plan/cancel, dispatch and outcome/closure sequence diagrams with rollback paths.
4. Dispatcher/assigned-driver/supervisor role matrix and server-side checks.
5. Planner, manifest, driver queue/history wireframes with useful capacity/conflict messages.
6. QA plan distinguishing local resource tests from real three-module atomicity tests.

Reviewer approves before domain migrations/implementation. Prioritize dispatch and resource/claim code reviews over visual polish.

## 5. Minimum test plan

| Case | Exact expectation |
| --- | --- |
| LOG-T01 Resource race | Two Oracle sessions choose same driver/vehicle; one succeeds, loser leaks no other resource |
| LOG-T02 Whole-order claim | All order lines claimed together; no second active claim of same order |
| LOG-T03 Peer claim race | Two real integrated sessions claim same reserved 6-unit order; one wins, no partial claim |
| LOG-T04 Exact capacity | 6 A * 2,000 g = 12,000 g fits 12,000 g vehicle |
| LOG-T05 Capacity excess | 7 A exceeds 12,000 g; whole order remains pending, no stock/claim/resource mutation |
| LOG-T06 Multiple whole orders | Combined load allowed only within capacity; remove order releases all its claims |
| LOG-T07 Draft cancel | Claims/resources released; physical stock/reservations unchanged |
| LOG-T08 Dispatch | Whole manifest locks; H/R decrease and Sales T increases by matching quantities |
| LOG-T09 Later-line failure | Real three-module dispatch rolls back after a write; trip Draft, stock/reservations/claims/facts unchanged |
| LOG-T10 Full outcome | Assigned driver delivers all shipped quantity; T -> D once; partial/over/fractional outcome rejected |
| LOG-T11 Closure | Cannot close before every line delivered; successful closure frees both resources |
| LOG-T12 Replay/protection | Duplicate/new-reference repeated source cannot repeat effects; wrong driver, stale version, invalid CSRF and manifest edit rejected |

Local resource uniqueness tests use real Oracle. Doubles suffice for early orchestration decisions, not LOG-T03/08/09 integrated proof. Verify stock remains unchanged at planning and delivery, not merely final displayed status.

Source acceptance: retain LOG-AC-01/03/04/05; adapt 02/06/07/08. Return/split portions are deferred. Lead INT-07/08/09/12; pair on INT-04/10/13/14.

## 6. Daily gates, demo and handover

- Days 1-2: reviewed design/tests/contracts.
- Days 3-4: standalone planning/cancel/dispatch/outcome orchestration; start automation.
- Days 5-6: real dispatchable orders, claim query and reservation checks.
- Day 7: real atomic dispatch/full delivery; Day 8: races/rollback/replay and fixes.
- Day 9: regression/clean install; Day 10: demo/sign-off/retrospective.

Standalone demo: seeded 10 A order weighs 20,000 g; 12,000 g vehicle rejected; 30,000 g vehicle accepted; dispatch/full delivery/close using labeled doubles. Integrated demo uses actual Sales/Inventory services and proves H=10/R=0/D=10.

Deliver reviewed design/migrations/PRs, executable tests/evidence, defects/retests, exact module commands, known limits and retrospective. **The independent module demo can proceed without peers; final three-module dispatch acceptance cannot.**
