# Team 2: Purchasing

**Team:** 1 developer + 1 QA. **Timebox:** 10 working days.  
**Read with:** [project rules and acceptance mapping](../README.md) and [shared contracts and fixtures](./shared-contracts.md).  
**People:** developer and QA assigned below; assign PM and primary/backup reviewer before kickoff.

**Project Manager:** Nithya

**Project Guide:** Karthik

**Automation Guide:** Brajesh

### Assigned team members

| Role | Name |
| --- | --- |
| Developer | Prabhath Reddy |
| QA | Neha Sai Priya T |

The developer owns the Dev work packages in Section 3.

The developer's name is clipped in the supplied image; confirm the full spelling before kickoff.

Follow the shared [coding conventions](./coding-conventions.md), including lowercase folders.

Use `ME_`-prefixed Oracle objects and the shared [permission framework](./permissions.md). Register your CRUD areas and named actions; enforce permissions in services for both UI and REST. User/company Settings are foundation-owned.

## 1. Your outcome and scope

Deliver supplier maintenance and purchase order -> independent approval -> full receipt. Keep the interface intentionally narrow: one approval filter, one receipt action and clear detail/history pages.

Own Supplier, PurchaseOrder, PurchaseLine, ApprovalVersion, GoodsReceipt and GoodsReceiptLine. Do not own product/stock tables. Exclude partial receipts, supplier returns, requisitions, invoices, payment, tax and complex approval chains.

Rules:

- Supplier code is unique; inactive supplier blocks new orders, not preserved history.
- Draft requires active supplier, required date and at least one active product line.
- Quantities are positive integers, product lines unique per order, prices nonnegative with at most two decimals.
- Draft -> Submitted -> Approved -> Closed; rejection requires a reason and supports revision/resubmission with history.
- Approver differs from submitter. Submitted/approved lines and prices are frozen.
- A receipt posts **all outstanding quantities across all lines** in one transaction.
- Receipt/totals and Inventory effects commit together. Posted receipts cannot be edited/deleted.
- Cancel an eligible entire unreceived PO with a reason; never reverse posted stock.
- `outstanding = ordered - received - cancelled`; full receipt leaves zero outstanding.

## 2. How to work without waiting for Inventory

| Need | Independent substitute from organizer | Real integration only |
| --- | --- | --- |
| Active product picker and product details | Product lookup double with A/B/C, inactive and invalid examples | Inventory product lookup adapter |
| Receipt stock effects | Inventory receipt double with success/replay/conflict and selected-line failure | Real Inventory receipt service |
| Oracle tables and user roles | Your isolated schema plus shared identity/test harness | Shared integration schema and migrations |

Build all screens, approval rules, orchestration and your own Oracle persistence without waiting for Inventory's code or tables. Peer product IDs are contract references, not a reason to query another team's private schema.

The receipt double may record expected calls or raise a failure. Use it to verify orchestration and local receipt rollback; **do not claim that this proves real Inventory stock rollback**. That evidence is collected at the scheduled integration checkpoint.

### Interfaces and transaction boundary

Consume product lookup and Inventory `receivePurchase`. Include immutable receipt-line identity, product, positive quantity, action/source references, expected version and server-derived actor through the reviewed context.

Purchasing owns the receipt transaction. Lock/revalidate the PO lines, write all receipt effects, call Inventory for every line, persist audit/action result and commit once. On any failure, including a normal business rejection, roll back everything. Neither domain service commits independently.

Check authorized replay before current-version validation; changed payload conflicts. Prevent duplicate receipt effects under a new action reference. Keep errors actionable and free of SQL/credentials.

## 3. Work packages and ownership

| ID | Work / owner | Local prerequisite | Due / evidence |
| --- | --- | --- | --- |
| PUR-01 | Dev + QA: groom scope; ERD, workflow/approval/receipt diagrams, wireframes and test plan | Starter kit | Day 2: reviewed design |
| PUR-02 | Dev: migrations, suppliers and multi-line drafts | PUR-01 | Day 4: validation and local Oracle tests |
| PUR-03 | Dev: submit/approve/reject/revise, role/version checks and history | PUR-02 | Day 4: no-self-approval and stale submission tests |
| PUR-04 | Dev: full-receipt UI/orchestration, unique effects and rollback | PUR-03 + supplied Inventory double | Day 4 principal path; Day 5 real adapter smoke |
| PUR-05 | Dev: whole unreceived-PO cancellation, lists/detail/outstanding | PUR-03 | Day 6: transition and immutability tests |
| PUR-06 | Dev + QA: real receipt integration, races, replay/failure and fixes | Local implementation; Inventory only for shared checks | Days 5-8 |
| PUR-07 | QA: cases, data, early automation, defects/retests and sign-off | Starts Day 1 | Days 1-10 |

QA prepares fixtures/tests while the developer implements. Do not add shared-platform tasks or optional dashboards to this smaller team.

## 4. Design and review deliverables

By Day 2 submit:

1. ERD/data dictionary, order/receipt/source constraints and migration order.
2. State transitions, approval-version history and full-receipt/cancellation rules.
3. Role/action matrix, including separate approver and receiving clerk.
4. Receipt sequence diagram showing all lines and rollback after the last stock call.
5. Supplier/order/approval/receipt wireframes; stale/replay/error UX.
6. QA plan with exact received/outstanding and expected stock-effect assertions.

Reviewer approves design before domain migrations/implementation; review migrations and small PRs before shared merge.

## 5. Minimum test plan

| Case | Exact expectation |
| --- | --- |
| PUR-T01 Supplier | Duplicate code rejected; inactive supplier blocks a new PO, existing history remains |
| PUR-T02 Draft validation | Missing supplier/date/lines, zero/fractional quantity, duplicate product and inactive product rejected |
| PUR-T03 Price validation | Negative or >2-decimal price rejected; accepted totals computed server-side exactly |
| PUR-T04 Self-approval | Submitter cannot approve; authorized different user can approve current submission |
| PUR-T05 Rejection/version | Reason required; revision preserves decisions; stale approval of old submission rejected |
| PUR-T06 Full receipt | Approved PO 20 A -> received 20/outstanding 0/Closed, one receipt-line effect |
| PUR-T07 Partial/over receipt | Receive 12 of 20 or 21 of 20 rejected in baseline; no effects |
| PUR-T08 Receipt race | Two Oracle sessions attempt all remaining 20; one commits, total received stays 20 |
| PUR-T09 Later-line failure | On second product failure, all receipt/totals/action/audit changes roll back; real integration also proves stock unchanged |
| PUR-T10 Replay/conflict | Same-reference replay returns original; changed payload and new-reference repeated source cannot duplicate stock |
| PUR-T11 Cancellation | Entire unreceived PO cancels with reason, stock unchanged; cancelled PO cannot be received |
| PUR-T12 Immutability/protection | Posted receipt/approved lines cannot be changed; wrong role, stale version or invalid CSRF causes no mutation |

Use actual Oracle sessions for PUR-T08 and real integrated rollback for the stock portion of PUR-T09. Browser automation should cover role switches and expected received/outstanding values, not just clicks.

Source acceptance: retain PUR-AC-01/02/05/07/08; adapt 03/04/06; original partial/remainder examples are deferred. Lead INT-01/02 and purchase-role portions of INT-13; pair with Inventory on replay/source effects.

## 6. Daily gates, demo and handover

- Days 1-2: reviewed requirements/design/test plan and product/receipt contracts.
- Days 3-4: supplier -> approved order -> full receipt with doubles; start automation.
- Days 5-6: real receipt smoke, cancellation/history and hardening.
- Days 7-8: races, stale actions, retries and rollback; fix defects.
- Day 9: regression and clean install; Day 10: demo/sign-off/retrospective.

Standalone demo needs only the starter kit: create a supplier, draft 20 A, submit, change user to approve, post full receipt and show history/replay. Clearly label simulated stock effects. Integrated demo proves real H increases by exactly 20.

Deliver reviewed design/migrations/PRs, tests and execution evidence, defect/retest log, exact run/seed/reset instructions, known limits and retrospective. **Independent module acceptance does not replace the real receipt/Inventory acceptance gate.**
