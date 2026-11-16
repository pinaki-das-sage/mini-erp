# Shared contracts: who calls whom, and what to build first

Read with the [project plan](../README.md), [coding conventions](./coding-conventions.md) and your team brief.

**Status:** local/remote environment readiness is confirmed. The domain interfaces and test doubles below are still planned, not implemented by the foundation. Agree their exact PHP signatures and data fields on Day 1; approve/freeze them at the Day 2 design review.

## API-first work 

The shared [API framework](./api-development.md) now supplies login tokens, permission checks and note/user examples under `/api/v1`. For each story: agree the API contract and test examples -> approve design/test plan -> failing tests -> service/API -> verification -> Smarty UI. Guides enforce that order; module routes require registered permissions.

API calls authenticate the user; the internal PHP contracts below connect the modules using that same Actor and transaction. Do not replace those internal calls with HTTP or assume token login means the domain services/doubles are implemented. Apply foundation migration `004_api_tokens.php` before using the new API on a server. 

## The basic idea

- **Provider:** the team that owns the real data and implements the operation.
- **Caller:** another team that uses that operation instead of changing the provider's tables.
- **Contract:** the agreed method name, inputs, outputs and error behavior.
- **Test double:** a clearly labelled fake provider used to develop/test the caller before the real provider is ready.
- **Version:** a record's change number. A stale version means someone changed the record after you read it.

Example: Purchasing asks Inventory to receive 20 units. Purchasing owns the receipt; Inventory owns the stock change. They must agree how that call works before coding it.

## Build priorities

These are build/review priorities, not optional features. All listed contracts are required. Start code only after the relevant design is approved; do not wait for complete screens to publish a working service.

| Priority | What comes first | Target |
| --- | --- | --- |
| P0: agree now | Method signatures, IDs/types, company/role checks, versions, errors, transaction/lock rules and shared test examples for every contract | Draft Day 1; approval Day 2 |
| P1: first working links | Product lookup, receipt, availability, reserve and release | Build Days 3-4; real receipt/allocation on Day 5 |
| P2: planning and safe cancellation | Reservation detail, Sales dispatchable/line-lock services, Logistics claim query and Inventory consumption | Ready by Day 5; real planning/cancellation on Day 6; consumption used at Day 7 dispatch |
| P3: finish fulfillment | Sales dispatch/delivery facts and the real Logistics posting flow | Interfaces ready Day 5; real dispatch/delivery on Day 7 |

Keep UI work moving in parallel, but review these provider services before visual polish. Logistics can develop its dispatch/delivery flow with doubles for the Day 4 demo; P3 does not mean waiting until Day 7 to start.

## Contract matrix

Task IDs refer to the existing team work packages, not new Jira stories. PMs map them to Jira.

| Priority / contract | Provider task | Caller: what cannot be verified with real data until it is ready | Minimum input -> output |
| --- | --- | --- | --- |
| P1 Product lookup | Inventory INV-02 | Purchasing drafts, Sales drafts, Logistics product/weight checks | Product IDs -> ID, SKU, name, unit, weight in grams, active flag. Missing ID is an error. |
| P1 `receivePurchase` | Inventory INV-03 | Purchasing PUR-04: real receipt must also increase stock | Receipt-line source, product, positive quantity -> stock movement reference |
| P1 `getAvailability` | Inventory INV-04 | Sales allocation checks; Logistics stock/reservation checks | Product IDs -> on-hand, reserved, available. This is a snapshot, not a guarantee. |
| P1 `reserveStock` | Inventory INV-04 | Sales SAL-04: real whole-order allocation | Sales line, product, quantity -> full line reservation or zero, with its version |
| P1 `releaseReservation` | Inventory INV-04 | Sales SAL-05: real cancellation must release stock reservations; also needs the Logistics claim query | Original sales line, quantity -> eligible release result/version; reject quantities still claimed for a trip |
| P2 Reservation detail | Inventory INV-04/05 | Sales reservation summaries; Logistics LOG-02/04: plan/dispatch only matching reservations | Original sales-line IDs -> matching reservations, quantities and versions |
| P2 `getDispatchableLines` | Sales SAL-06 | Logistics LOG-02: plan from real fully allocated orders | Filters -> complete orders with original line/product IDs, weights, confirmed destination, quantities and versions |
| P2 Sales-line lock/validation | Sales SAL-06 | Sales/Logistics allocation, cancellation, planning and dispatch races | Sorted original sales-line IDs + shared transaction -> locked, rechecked order facts |
| P2 `getPlannedQuantity` | Logistics LOG-03 | Sales SAL-05 and Inventory release validation: cancellation/release must respect real draft-trip claims | Sales line -> active draft-trip claimed quantity, checked under the shared line lock |
| P2 `consumeReservation` | Inventory INV-05 | Logistics LOG-04: real dispatch must reduce matching stock/reservations | Shipment source, original sales line/reservation, quantity -> one consumption effect |
| P3 `recordDispatch` | Sales SAL-06 | Logistics LOG-04: real dispatch must update Sales in-transit quantities | Unique shipment source, sales line, quantity -> fulfillment result/version |
| P3 `recordDelivery` | Sales SAL-06 | Logistics LOG-05: real delivery must update Sales delivered quantities | Unique outcome/shipment source, sales line, full delivered quantity -> fulfillment result/version |

All calls carry the authenticated actor/company. Each write also carries the agreed shared transaction, action reference, business source IDs, expected version and payload. Record the exact field names/types and examples at design review; do not guess them from this summary.

## When is a caller actually blocked?

| Situation | What the caller can do | What is blocked |
| --- | --- | --- |
| Contract not agreed | Groom requirements, design its own data/screens, list questions | Coding the peer call or inventing its return fields |
| Contract agreed, double not available | Continue work that does not use that call; ask the organizer/foundation maintainer for the double | Tests/demo steps that need that provider |
| Contract agreed and double available | Implement the caller, test its decisions and its own Oracle writes | Claiming real peer stock, row-lock or cross-module rollback results |
| Real provider available | Wire the real adapter in the integration environment and run shared tests | Acceptance stays blocked until the real combined workflow passes |

If blocked, name the missing contract and the affected Jira story, not just "waiting for Inventory." Ask the provider for that small service before its whole module. Provider and caller guides agree an unblock date.

**The cancellation cycle:** Sales needs Logistics' claim query; Logistics needs Sales/Inventory data to plan. Break the development wait with explicit doubles, not direct table access. For real cancellation, remove all draft-trip claims, then release reservations and cancel the whole eligible order in one transaction.

## Rules every team follows

1. **One data owner.** Call the owner service; do not write its tables or store a second editable stock/reservation balance.
2. **One transaction for one business action.** Purchasing owns receipt; Sales owns allocation/cancellation; Logistics owns plan/edit/cancel/dispatch/delivery. All participants use the same connection and never commit separately. A failure on the last line, including a normal business rejection, rolls back earlier lines, audit and action results.
3. **Lock, then recheck.** A query snapshot can become old. Follow: vehicle/driver -> trip -> sorted sales lines -> sorted purchase lines -> sorted product balances -> reservations. Skip irrelevant steps, never reverse them. Guides agree any extra parent lock before code. Sales validates its own locked lines; callers do not update Sales tables directly.
4. **Safe retry.** An action reference identifies a request; a business source identifies its receipt/shipment/outcome. Authorize first. The same reference and payload returns its saved result before checking today's version; changed payload conflicts. A new reference must not repeat the same business effect. After an uncertain timeout retry unchanged input/reference; a new allocation attempt after replenishment uses a new reference.
5. **Consistent errors.** Use `success`, `operation_id`, `current_version`, `result`, `error`. Agree endpoint messages/status codes for `VALIDATION_ERROR`, `FORBIDDEN`, `INVALID_STATE`, `STALE_VERSION`, `INSUFFICIENT_STOCK`, `CAPACITY_EXCEEDED`, `QUANTITY_CONFLICT`, `DUPLICATE_ACTION_CONFLICT`; do not hide failure as success.
6. **Company isolation.** Use the foundation's authenticated Actor and `$actor->companyId`, never a posted actor/company or default company ID 1. Every business table has `COMPANY_ID`; reads/writes, joins, caches, action/source references and fixtures stay company-scoped. Compound keys/FKs reject cross-company links; SKU/reference uniqueness is per company.
7. **Permission checks on the server.** Register CRUD/actions in `ME_OBJECTS`/`ME_OBJECT_ACTIONS` and use the shared Authorization check for UI and REST. Roles/grants are both relevant; even an admin cannot self-approve or deliver another driver's trip. Foundation owns company/users/Settings; its security transactions stay separate from ERP posting. See [permissions and tenant boundaries](./permissions.md).
8. **No extra baseline scope.** Full receipt, whole-order allocation/claims/cancellation and full successful delivery. Returns, partial flows and split orders are not part of this release. Product weights are fixed once used.

## Independent development and migration boundaries

- Develop/migrate in your team's isolated schema, with the shared foundation installed. A company is not protection from another team's DDL.
- Own your tables, constraints and seeds. Every application-defined Oracle object name starts with `ME_`; your module uses `ME_INV_*`, `ME_PUR_*`, `ME_SAL_*` or `ME_LOG_*`.
- Agree shared ID types/columns on Day 2. Use stable peer IDs, not queries into another team's private schema.
- A foreign key links real tables. Put cross-module links in a separate reviewed integration migration so standalone installs do not need peer tables.
- In integration: install the real module tables first, validate existing rows, then add the links. Rehearse a clean install; required links must exist at final acceptance.
- Peer fixture tables, if needed for a test, belong only in test setup, not as fake production authorities.
- Integrated mode must reject missing real adapters or doubles. Never silently use a double when a real call fails.

## Test doubles

The organizer/foundation maintainer supplies the shared typed interfaces and scripted doubles; provider and caller teams review their examples. Track any missing pack item explicitly.

- Cover success, missing/inactive product, insufficient stock, claimed reservations, invalid/stale state, replay/conflict, repeated source and failure on a later line.
- A double returns a fixed agreed result or error; it is not another ERP. Reset it per test and clearly label simulated results.
- Example: a fake Inventory receipt can return a test movement reference without changing stock. This lets Purchasing test its receipt flow and local rollback, not prove Inventory stock changed.
- Only real shared tests prove peer quantities, Oracle row locks and rollback across modules. Doubles are never final integration evidence.

## Canonical fixtures

Use these shared test examples. Map codes to each environment's real IDs; do not assume generated IDs match between schemas. Include the company in that mapping.

`PO` means purchase order, `SO` sales order, `H` stock on hand, `R` reserved stock and `D` delivered quantity. A/B/C are test product codes.

| Fixture | Use |
| --- | --- |
| A 2,000 g; B 5,000 g; C 1,000 g; one inactive product | Product pickers, quantities, weights and validation |
| Two suppliers, three customers with addresses | Team-local masters and orders |
| Two drivers; vehicles 12,000 g and 30,000 g | Resources and capacity boundaries |
| Buyer A, approver B, receiver, inventory admin, sales clerk, dispatcher, drivers and supervisor | Role checks and no self-approval |
| PO 20 A; SO 10 A; stable line/receipt/shipment/action references | Baseline integration ends at H=10/R=0/D=10 |
| Two SOs of 8 A against H=10 | One reserves 8, the other 0 |
| Multi-product receipt/order/trip | Later-line failure and all-or-nothing posting |
| SO 6 A and SO 7 A | Exactly fits / exceeds the 12,000 g vehicle |
| Rejected/revised PO; planned/unplanned SO | Version and cancellation tests |

Inventory owns real products. Other teams use product fixtures only as test data. The shared demo starts with zero stock and uses real purchase receipts to add stock; Inventory's test-only workflow driver must never be a public arbitrary-stock endpoint.

## Acceptance checkpoints

- Day 4: each team can demo its own module with clearly labelled doubles.
- Day 5: real receipt and allocation; Day 6: real planning and claim-aware cancellation.
- Day 7: real all-or-nothing dispatch and full delivery.
- Day 8: two independent Oracle sessions, replay and forced rollback.
- Final acceptance: real quantities reconcile, required migration links exist, no simulated effects.

If a contract changes, provider and caller guides review the new inputs/outputs, schema impact and tests together. PMs update the affected stories; keep team briefs and doubles consistent.
