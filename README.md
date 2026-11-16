# Mini ERP graduate project: two-week delivery plan

## 1. Purpose and agreed commitment

**Participants:** 11 graduates: 7 developers and 4 QAs.  
**Duration:** 10 working days, with a working starter kit available before Day 1.  
**Deliverable:** one connected wholesale-distribution application built with core PHP/OOP, JavaScript and Oracle, plus reviewed designs, tests, release evidence and handover.  
**Selected scope:** the source document's **10-day baseline**, confirmed for this plan. The full exception workflow needs the recommended 15-day schedule.

Source: *Mini ERP for Wholesale Distribution*, v1.0, 5 November 2026. Key sections: module requirements/acceptance (pp. 13-24), quantity rules and engineering controls (pp. 25-30), integrated acceptance (pp. 31-32), baseline schedule (p. 34), SDLC/done criteria (p. 35). Requirement IDs below refer to that document; this README explicitly identifies baseline adaptations rather than silently changing its full-scope criteria.

The learning objective is not the number of screens. Each team must demonstrate requirements analysis, system/database design, implementation, review, testing, defect resolution, deployment and retrospective using a real business workflow.

### Shared documentation

- [Team 1: Inventory](./docs/team-1-inventory.md)
- [Team 2: Purchasing](./docs/team-2-purchasing.md)
- [Team 3: Sales and Order Entry](./docs/team-3-sales.md)
- [Team 4: Logistics](./docs/team-4-logistics.md)
- [Shared contracts: priorities, callers and fixtures](./docs/shared-contracts.md)
- [Coding conventions](./docs/coding-conventions.md)
- [API-first framework and endpoint guide](./docs/api-development.md)
- [Company, users and permission registration](./docs/permissions.md)
- [Setup, local development and verification](./docs/SETUP.md)

**Implementation status:** the PHP 8.4 foundation is now available: shell/navigation, role-based login infrastructure, CSRF, INI-compatible OCI8 adapter, transaction context, checksum migrations and a neutral audited/versioned note example. Local unit/browser checks are provided. Remote Oracle is not configured or verified; ERP domain services and the peer adapter/fixture pack are still intentionally unimplemented.

The shared `/api/v1` framework provides expiring bearer login/logout, current identity/permissions, password change, note read/update and admin user CRUD/permission/reset operations. New module work is API-first: approved contract/design/test plan -> failing tests -> service/API -> verification -> Smarty UI. Browser cookies/CSRF remain supported separately; all channels share the same business services. Deploy the new `004_api_tokens.php` migration before API use. See the [API guide](./docs/api-development.md); this update's live Oracle/API acceptance is not claimed locally.

The shared UI includes manual company-aware login, a sidebar workspace and admin-only user/permission Settings confined to the signed-in company. One schema supports multiple companies; `SIA-MINI-ERP` remains the default training/demo company. Additional companies are created CLI-only with `php bin/addcompany.php "Company name"`, atomically creating their admin, all ten training users, roles/grants and welcome note. The printed login code is the uppercase name with whitespace replaced by hyphens. Tenant-owned data has mandatory `COMPANY_ID`; shared definitions/migration metadata remain global. Register CRUD objects and named actions through migrations; UI and REST use the same server-side grants. See the permission guide for tenant boundaries and upgrade behavior. User administration is foundation-owned, not assigned to a graduate team.

Regular bootstrap seeds SIA-MINI-ERP; creating another company also seeds the same ten training-role accounts with passwords matching their usernames. No separate lab seed configuration is needed; reruns preserve existing users/passwords/grants. Existing installations need a backup/maintenance window and `003_multi_tenancy.php`, followed by signing in again. Actual Oracle migration/isolation acceptance remains pending.

`npm run test:browser` runs on your local machine against the deployed URL configured by `BROWSER_TEST_BASE_URL`; it does not start local PHP. The original fixture checks remain available as `npm run test:browser:local`. See [browser setup and coverage](./docs/SETUP.md#9-install-npm-dependencies-and-run-browser-checks).

The view layer uses Smarty 5 templates under `views/`, organized by the same shared/module ownership as `src/`. Layout, header/sidebar/footer, security pages, Settings forms, permission controls and user listers are separate templates. PHP prepares presentation data and mounted URLs; templates do not query Oracle or authorize operations. HTML escaping is automatic through the shared helper, and rendered HTML caching is disabled. See [view conventions and a module example](./docs/coding-conventions.md#views-smarty-and-responsibility-boundaries).

The shared deployment example uses Apache with filesystem web root `~/public_html/mini-erp/public/` at https://www-p308.intacct.com/users/pinaki.das/mini-erp/public/. Any checkout name/location is supported: expose only its `public/` directory and retain the supplied checkout/public `.htaccess` files for private-file protection and automatically mounted front-controller routing. Apache must enable the documented overrides and `mod_rewrite`. URL-prefix detection is automatic by default; `APP_BASE_PATH` is an optional URL override, not a filesystem setting. See [Apache setup and routing](./docs/SETUP.md#apache-configuration-and-application-url).

**Dependency-minimized delivery:** the organizer prepares typed interfaces, deterministic peer doubles, canonical references and isolated test environments before kickoff. Teams implement and demonstrate their own modules without waiting for peer code/screens/tables. Day 2 reviews validate/freeze these contracts. Real receipt/allocation, planning and dispatch integrations remain scheduled for Days 5-7; doubles never count as proof of final cross-module integrity.

Keep module-local migrations independent; apply separately reviewed cross-module link migrations after the real referenced tables exist and before accepting the affected integration. The [shared contract guide](./docs/shared-contracts.md) defines this split and the final clean-schema integrity gate.

Team-shareable documents belong in `docs/`. Organizer-only preparation and implementation notes belong in the Git-ignored `local-docs/` folder and are not dependencies of the shared documentation. Git ignore prevents new files being added by default; it is not encryption or an access-control mechanism.

### Baseline business flow

1. Maintain products, suppliers and customers.
2. Create and submit a multi-line purchase order; a different authorized user approves it.
3. Receive **all outstanding quantities on every PO line in one atomic posting**.
4. Confirm a multi-line sales order. Confirmation does not reserve stock.
5. Explicitly allocate **the entire remaining order demand or nothing**, across all lines in one transaction.
6. Claim a **whole allocated order** on one draft trip. A trip may contain multiple whole orders if capacity permits.
7. Dispatch the complete trip atomically across Logistics, Inventory and Sales.
8. The assigned driver records complete successful delivery for each shipment line.
9. Close the trip after every shipment is delivered; complete a sales order only when all its demand is delivered.

If an entire order cannot fit an available vehicle, keep it pending and show an actionable capacity message. Do not silently split it. Insufficient stock leaves the entire allocation attempt unchanged; replenishment requires a new explicit allocation action.

### Scope boundaries

| Committed in 10 days | Deferred, not required for baseline sign-off |
| --- | --- |
| One company, warehouse, currency and business timezone; whole-unit quantities; fixed product weights | Multiple warehouses, unit conversion, batches, serials, expiry |
| Product/supplier/customer maintenance; shared foundation user administration; seeded vehicles and drivers | Vehicle/driver maintenance beyond required seeded-resource views |
| Approval with no self-approval; full receipt; all-or-none order allocation; whole-order trip claims; full successful delivery | Partial receipts/allocation, split dispatch, failed/partial delivery, physical returns and redelivery |
| Whole-document cancellation before receipt/dispatch, with permissions and reasons | Cancellation of selected quantities or partly fulfilled remainders |
| Draft trip edits/cancellation; removing whole-order claims before cancelling that order | Route optimization, GPS, signatures, external integrations |
| Stock ledger, reservations, demand quantities, histories, operational views | Financial invoices, payments, tax, valuation, discounts, manufacturing |
| Server authorization, CSRF, versions, concurrency, replay protection, audit and rollback | Production rollout, company-administration UI, customer imports, commercial support |

**Cancellation interpretation for this baseline:** cancel an eligible PO before any receipt; cancel an entire sales order before any dispatch. For an allocated sales order, first remove all draft-trip claims, then release all its reservations and cancel atomically. Submitted/approved contents remain immutable; cancellation is a separate audited transition. Do not use cancellation to undo a posted receipt or dispatch. Record these baseline decisions in grooming.

No stretch work starts before the complete baseline passes QA. Treat partial fulfillment and returns as a subsequent learning increment, not spare-time commitments.

## 2. Teams, ownership and governance

| Team | Staffing | Owns | Suggested developer split | Main QA responsibility |
| --- | --- | --- | --- | --- |
| **T1 Inventory** | 2 devs + 1 QA | Products, balances, reservations, movement ledger | Dev A: products, receipts, ledger and UI. Dev B: reserve/release/consume, locking and reconciliation. Joint review of all balance writes. | Stock conservation, allocation races, movement/source uniqueness |
| **T2 Purchasing** | 1 dev + 1 QA | Suppliers, POs, submission/approval versions, receipts | Single dev: narrow end-to-end workflow using common shell, one approval filter and one full-receipt action | Separation of duties, approval versions, over-receipt prevention, atomic receipts |
| **T3 Sales** | 2 devs + 1 QA | Customers, orders, cancelled/delivered/in-transit facts | Dev A: masters, snapshots, order lifecycle and UI. Dev B: allocation/cancellation orchestration, fulfillment and quantity read model | Demand conservation, whole-order allocation, fulfillment, stale actions |
| **T4 Logistics** | 2 devs + 1 QA | Resources, trips, claims, manifests, outcomes | Dev A: planner, claims, resources, capacity and UI. Dev B: dispatch orchestration, driver outcomes and closure | Resource/claim races, capacity boundaries, dispatch rollback and delivery permissions |

The smaller team belongs on Purchasing, as recommended by the source. Do not give it shared-platform ownership as an additional responsibility.

### Supporting roles: assign before kickoff

The source names Amar, Srinivasa, Supreeth and Nithya for PM coverage; Chirag, Anoop, Karthik and Bipin for design/code review; Brajesh and Vasant for QA automation guidance. **No team mapping is assumed here.** Confirm availability and record one PM and one primary reviewer against each team at kickoff, with a backup reviewer.

| Role | Responsibility and decision authority |
| --- | --- |
| Sponsor / project product owner | Own baseline scope, resolve business ambiguities, accept integrated outcome |
| Team PM | Groom priorities, track dependencies/risks, accept team stories, escalate blockers |
| Assigned team reviewer | Approve design, migrations and PRs; review transaction-sensitive code first |
| Foundation maintainer, from supporting technical staff | Deliver and support shared shell/Oracle/test infrastructure; not an extra trainee team |
| Module QA | Own test design, data, execution, defects, retests and evidence throughout the project |
| Automation mentors | Review scenarios by Day 2; guide implementation from Day 3, not just final-day review |
| Integrated release/demo coordinator | Coordinate merges, smoke runs and demo; nominate a supporting staff member so one trainee does not become a full-time integrator |

### Daily working agreements

- 15-minute joint stand-up: completed work, today's work, blockers, contract changes and integration readiness.
- Reserve a short daily reviewer slot; target feedback within one working day. Escalate a missed gate to the PM rather than bypassing it.
- One shared issue board, defect log and contract-change log. Every story names owner, requirement IDs, dependencies, acceptance and evidence.
- Small story branches and reviewed PRs into one protected shared integration branch. Keep it runnable; do not postpone merges until Day 9.
- PRs changing a shared contract require the owner's reviewer and affected module acknowledgment.
- Each merge runs module tests and the affected integration smoke. Design changes update the reviewed design and traceability.
- QA begins with grooming. Developers own unit tests and assist diagnosis; QA owns independent verification, not all testing.

## 3. Pre-kickoff starter kit: prepare infrastructure, not the solution

**Recommendation: yes, pre-start the application structure and Oracle connectivity.** Otherwise setup can consume much of a two-week exercise. Preserve domain modeling, SQL, business logic and test ownership as graduate work.

The table below remains the complete preparation specification. The foundation increment is now implemented; use [setup and verification](./docs/SETUP.md) for actual commands and verification boundaries. Repository provisioning, remote Oracle readiness and the peer adapter/fixture pack are not yet complete.

### Foundation work packages

| ID | Deliverable prepared by foundation maintainer | Readiness evidence |
| --- | --- | --- |
| FND-01 | Pin a team-supported PHP version, Oracle version, PHP Oracle driver/client combination, Composer dependencies and browser/JS tooling. Document installation and connectivity troubleshooting. | Every participant can run the same shell and connect to their assigned lab schema |
| FND-02 | Minimal PHP/OOP modular monolith: bootstrap/autoloading, routing, page layout, navigation placeholders and controller/service/repository conventions | A neutral sample page follows the full request flow without implementing ERP logic |
| FND-03 | Oracle bound-query helper and transaction context; explicit begin/commit/rollback; controlled error reporting; no auto-commit writes | A neutral two-write test proves commit and rollback using the actual selected driver |
| FND-04 | Session login with seeded lab users, role helper, server-side identity, CSRF token and output-escaping helpers | Login works; unauthorized direct request and invalid-CSRF write fail |
| FND-05 | Common validation/error envelope, correlation/action reference conventions, version-check pattern and audit interface | Example endpoint shows validation/conflict messages without raw SQL or credentials |
| FND-06 | Reviewed migration runner, applied-version tracking, shared identity schema and reference seed mechanism | Shared foundation installs on a clean disposable schema; altered applied migration is detected |
| FND-07 | PHPUnit/unit harness, Oracle integration harness, browser automation harness and CI checks | Neutral sample tests pass; CI reports a deliberately failed test correctly |
| FND-08 | Dedicated shared integration schema plus isolated team/test schemas, account access, configuration template and safe reset instructions | Teams cannot reset another team's or the shared integration data; no credentials committed |
| FND-09 | Agree starter command names for install, migrate, seed, serve, lint, unit, Oracle integration and browser smoke; write exact commands after the scaffold exists | A graduate follows the instructions unaided on a clean checkout |
| FND-10 | Prepare typed peer interfaces, canonical fixture references, explicit standalone adapters/doubles and restricted test workflow drivers | Each module can boot and run its own migrations/tests without peer implementations; integrated mode rejects doubles |

The availability check must not reveal credentials or connection strings. Keep credentials out of code/logs; give the runtime only the permissions it needs. Use synthetic data and explicitly disposable schemas. Do not install Oracle or reset data in a shared environment without an approved, isolated target.

Suggested structure to agree, not directories already created:

```text
public/                 front controller and browser assets
src/shared/             infrastructure, identity and common helpers
src/inventory/          controllers, services, repositories, views
src/purchasing/
src/sales/
src/logistics/
database/migrations/    ordered, immutable migration files
database/seeds/         deterministic lab fixtures
tests/unit/
tests/oracle/
tests/browser/
README.md               project plan and eventual run/handover guide
```

Use Composer autoloading and the existing team conventions. Vanilla JS is sufficient; do not introduce a framework, microservices, queues or a frontend build system merely for the exercise.

**Leave to graduates:** domain ERDs/table DDL, state diagrams, role/action matrix, contract implementation, transaction/lock design, calculation rules, screens, reconciliation SQL, domain tests and defect fixes. Supply small neutral examples, not completed stock/receipt/dispatch implementations.

**Day 0 gate:** all 11 participants successfully log in, connect, run a sample test and apply foundation migrations in their own designated lab environment. If this is not ready, move kickoff or replan; do not silently spend Days 1-2 troubleshooting and retain the same promise.

## 4. Shared design and integration contracts

### Architecture and data authority

Use **one PHP application and one Oracle database per environment** with in-process module interfaces. No module writes another module's tables. Reporting joins are permitted only through an explicitly reviewed read model; they must not introduce writable duplicates of authoritative quantities.

| Operation | Orchestrator / transaction owner | Participating module operations |
| --- | --- | --- |
| Full PO receipt | Purchasing | Purchasing receipt/totals + Inventory receipt movements |
| Whole-order allocation | Sales | Sales demand validation + Inventory reservations for every order line |
| Whole-order cancellation | Sales | Logistics claim validation + Inventory release + Sales cancellation |
| Trip plan/edit/cancel | Logistics | Resource/claim changes, Sales line and Inventory reservation validation |
| Full trip dispatch | Logistics | Claim/manifest changes + Inventory consumption + Sales in-transit facts |
| Successful delivery | Logistics | Outcome + Sales delivered fact |

Participating services accept the **same Oracle connection/transaction context** and never commit independently. Failure on the final line rolls back earlier lines, audit effects and persisted action results. Returning a normal business error must also roll back, not accidentally commit.

### Contracts to freeze by the end of Day 2

The organizer supplies proposed typed interfaces and fixtures before Day 1; teams validate and freeze them during design review. The starter pack also defines product lookup, reservation-detail queries and a Sales-owned line-lock/validation entry point so teams do not improvise private-table access. See [the contract matrix](./docs/shared-contracts.md#contract-matrix).

| Contract | Owner | Baseline semantics |
| --- | --- | --- |
| `getAvailability` | Inventory | Return on-hand, reserved and available for products; advisory only |
| `receivePurchase` | Inventory | Positive quantity with immutable receipt-line source; one stock movement |
| `reserveStock` | Inventory | Requested line quantity or zero; Sales orchestrates all-or-none across the entire order |
| `releaseReservation` | Inventory | Release only that line's unplanned reservation; Sales orchestrates whole-order cancellation |
| `consumeReservation` | Inventory | Consume matching reservation and on-hand once for a shipment line |
| `getDispatchableLines` | Sales | Return original line IDs, quantities, destination and versions; planner groups all lines of an order |
| `getPlannedQuantity` | Logistics | Return active draft claims under the common line lock |
| `recordDispatch` | Sales | Move matching reserved demand into in-transit once |
| `recordDelivery` | Sales | Move a fully delivered shipment line from in-transit to delivered once |

`receiveDeliveryReturn` and `recordReturn` are deferred. Do not add unused return implementations to the baseline. Agree order-level validation and lock coverage so line-level helpers cannot accidentally create partial allocation or split-order claims.

Each write request has an action reference, source document/line IDs, expected version and business payload. Derive actor identity on the server from the session; never trust a submitted actor ID.

Review examples for success, insufficient stock, exact/excess capacity, stale version, forbidden action, duplicate replay and changed-payload conflict. Use the source envelope: `success`, `operation_id`, `current_version`, `result`, `error`. Use consistent codes including `VALIDATION_ERROR`, `FORBIDDEN`, `INVALID_STATE`, `STALE_VERSION`, `INSUFFICIENT_STOCK`, `CAPACITY_EXCEEDED`, `QUANTITY_CONFLICT` and `DUPLICATE_ACTION_CONFLICT`; agree messages and HTTP mapping for browser-facing endpoints.

**Retry rule:** authorize first, then return a matching committed replay before checking the current version. The same reference with different canonical payload is `DUPLICATE_ACTION_CONFLICT`. Enforce unique business source effects as well, so using a different action reference cannot repeat a receipt, dispatch or delivery. New explicit allocation after replenishment uses a new reference; retry after an ambiguous timeout reuses the old one.

**Lock order from source:** vehicle/driver resources, trip, sales lines sorted by ID, purchase lines sorted by ID, product balances sorted by ID, reservations. Skip irrelevant categories; never reverse order. Agree any additional parent-document lock position before implementation and test with two Oracle sessions. All routes touching a shared invariant must follow the same convention.

### Quantity rules: review and test, not just display

For each confirmed sales line:

```text
Q = C + D + T + R + U
0 <= A <= R
available = H - total_reserved
0 <= total_reserved <= H
PO outstanding = ordered - received - cancelled
shipment quantity = delivered + unresolved   [no returns in baseline]
```

`Q` ordered, `C` cancelled, `D` delivered, `T` in transit, `R` that line's warehouse reservation, `U` derived unallocated demand, `A` draft-trip claim, `H` product on-hand. Inventory owns `H/R`; Sales owns `Q/C/D/T`; Logistics owns `A` and shipment reconciliation. Do not store an independently editable `U`.

Allocation changes `U/R`, not `H`. Planning changes `A`, not `H/R`. Dispatch reduces `A/R/H` and increases `T`. Delivery reduces `T` and increases `D`. Full cancellation moves eligible `U/R` to `C`. An order completes only at `D=Q`; dispatch is not completion.

Use Oracle keys, foreign keys, `NOT NULL`, check/unique constraints, positive integer quantities, integer grams and appropriate `NUMBER` precision for money. Reject prices with more than two decimals. Calculate totals server-side with exact decimal handling; avoid PHP/JS floating-point money calculations as the source of truth. Freeze product unit/weight after its first transaction.

## 5. Team work breakdown

Every work item below includes developer unit tests, reviewer approval and QA verification. Task IDs are suitable for the shared issue board. UI and service work can proceed in parallel after contracts/design approval.

### T1 Inventory

| ID / target | Work and accountable participant | Dependencies / required evidence |
| --- | --- | --- |
| INV-01 / Days 1-2 | Both devs + QA: groom INV requirements; design product/balance/reservation/movement ERD, stock transitions, service interfaces and reconciliation | Foundation; reviewed design, test plan and quantity examples |
| INV-02 / Days 3-4 | Dev A: migrations, product maintenance, normalized SKU uniqueness, immutable referenced products, availability and movement/history views | INV-01; clean-schema migration and validation tests |
| INV-03 / Days 3-4 | Dev A: receipt service with immutable source identity, bound SQL and ledger writes | INV-01; simulated receipt/replay/rollback tests; coordinate table migrations with Dev B |
| INV-04 / Days 3-4 | Dev B: all-or-none line reservation and eligible release services with lock/version handling | INV-01; insufficient-stock and competing-allocation tests |
| INV-05 / Days 4-7 | Dev B: matching-reservation consumption, dispatch source uniqueness and reservation-detail views | INV-04 + frozen shipment contract; positive/negative consumption tests |
| INV-06 / Days 5-8 | Both devs: real Purchasing/Sales integration, dispatch integration, stock/reservation reconciliation SQL | INV-03/04/05 + peer service readiness; receipt/allocation/dispatch smoke and two-session evidence |
| INV-07 / Days 1-10 | QA: cases/data from Day 1; API/service + DB checks, browser stock views, defects/retests and sign-off | Test plan reviewed Day 2; ledger totals checked after each committed operation |

**Independent Day 4 demo:** products plus receipt/reserve/release against reviewed test source IDs. All line helpers execute inside a caller-owned transaction, including in the demo.

### T2 Purchasing

| ID / target | Work and accountable participant | Dependencies / required evidence |
| --- | --- | --- |
| PUR-01 / Days 1-2 | Dev + QA: supplier/PO/approval/receipt design, role/state matrix, full-receipt/cancellation decisions and receipt transaction diagram | Frozen Inventory receipt contract; reviewed design and test plan |
| PUR-02 / Days 3-4 | Dev: migrations, supplier maintenance, multi-line drafts, required fields/prices and duplicate-product validation | PUR-01 + product schema contract; draft/master tests |
| PUR-03 / Days 3-4 | Dev: submit, separate-user approve/reject, reason/history, rejected-order revision, stale-version prevention | PUR-02; no-self-approval and old-submission tests |
| PUR-04 / Days 4-6 | Dev: full-receipt screen and orchestration; receipt/order/stock changes atomic; duplicate/source protection | PUR-03 + prepared Inventory double for local work; INV-03 only for real integration |
| PUR-05 / Days 4-6 | Dev: eligible whole-PO cancellation, filters/detail/history, received/outstanding read model | PUR-03; cancelled PO cannot be received; posted documents immutable |
| PUR-06 / Days 7-9 | Dev + QA: two-session receipt race, injected final-line failure, retries, permissions; fix and regression | PUR-04; complete before/after data and unique movement links |
| PUR-07 / Days 1-10 | QA: approval/receipt cases, deterministic fixtures, early automation, issue retests and handover sign-off | Reviewed scenarios Day 2; paired integration checks with Inventory QA |

**Independent Day 4 demo:** supplier -> PO -> approval -> full receipt using an Inventory test implementation. Keep the interface modest: no requisitions, elaborate dashboards or extra approval levels.

### T3 Sales and Order Entry

| ID / target | Work and accountable participant | Dependencies / required evidence |
| --- | --- | --- |
| SAL-01 / Days 1-2 | Both devs + QA: customer/order ERD, confirmed snapshots, derived quantities/states, whole-order orchestration and QA plan | Inventory/Logistics contract agreement; reviewed design |
| SAL-02 / Days 3-4 | Dev A: migrations, customer maintenance, multi-line drafts, server totals and validation | SAL-01 + product schema contract; inactive/missing/invalid input tests |
| SAL-03 / Days 3-4 | Dev A: confirm/freeze reference, prices and address; list/detail/history and permission/version checks | SAL-02; snapshot and stale-edit tests |
| SAL-04 / Days 3-6 | Dev B: explicit whole-order allocation, failure messages, manual retry and dispatchable query | SAL-01 + prepared Inventory double for local work; INV-04 only for real integration; later-line failure leaves earlier reservation unchanged |
| SAL-05 / Days 4-6 | Dev B: whole-order cancellation with claim checks, reservation release and audit reason | SAL-04 + prepared claim double for local work; real claim service only for integration; planned-order denial and release rollback |
| SAL-06 / Days 4-7 | Dev B: dispatch/delivery facts with unique sources; Dev A: fulfillment UI and derived completion | Shipment contracts + SAL-03; reservation -> transit -> delivered reconciliation |
| SAL-07 / Days 7-9 | Both devs + QA: races, stale/replay cases, integration fixes and regression | SAL-04/05/06; no double allocation/delivery; all demand buckets reconcile |
| SAL-08 / Days 1-10 | QA: reviewed case/data design, customer/order browser automation, cross-module verification and sign-off | Pair with Inventory for allocation and Logistics for fulfillment |

**Independent Day 4 demo:** confirm, allocate/cancel and display fulfillment using agreed Inventory/Logistics test implementations. Confirmation and allocation must remain visibly separate.

### T4 Logistics

| ID / target | Work and accountable participant | Dependencies / required evidence |
| --- | --- | --- |
| LOG-01 / Days 1-2 | Both devs + QA: resource/trip/claim/manifest/outcome ERD, capacity/state rules, permissions and dispatch transaction design | Sales/Inventory contracts; reviewed whole-order grouping rules and test plan |
| LOG-02 / Days 3-4 | Dev A: migrations, seeded resource views, planner, whole-order claims and integer-gram weight meter | LOG-01; exact-capacity/excess and resource uniqueness tests |
| LOG-03 / Days 3-6 | Dev A: edit/cancel draft trips, release claims/resources, prevent duplicate claims and incompatible assignments | LOG-02; two-trip claim race and cancellation tests |
| LOG-04 / Days 4-7 | Dev B: full dispatch orchestration, revalidation, source protection and immutable manifest | LOG-01 + own trip fixtures/prepared doubles for local work; INV-05/SAL-06/LOG-03 for real integration; final-line failure leaves all modules unchanged |
| LOG-05 / Days 4-7 | Dev B: assigned-driver queue, full successful outcomes, replay protection and closure | Prepared Sales delivery double + LOG-04 for local work; real Sales service only for integration; unauthorized driver denied; premature closure fails |
| LOG-06 / Days 7-9 | Both devs: real dispatch/delivery integration, resource/claim races and fixes | LOG-04/05; reconciliation and persisted shipment lineage |
| LOG-07 / Days 1-10 | QA: planner/driver browser cases, capacity/resource tests, dispatch evidence, automation and sign-off | Pair with Inventory/Sales QA for three-module posting |

**Independent Day 4 demo:** plan/cancel a trip and exercise dispatch/full-delivery services against test implementations. Never change physical stock merely by planning a trip.

## 6. Ten-day schedule and exit gates

Days are working-day numbers; agree calendar dates and staff review availability before kickoff.

| Day | Inventory | Purchasing | Sales | Logistics | Shared exit evidence |
| --- | --- | --- | --- | --- | --- |
| **1** | Stock/model grooming | Approval/receipt grooming | Demand/lifecycle grooming | Trip/capacity grooming | Scope, stories, questions and initial QA cases; joint business walkthrough |
| **2** | Service/ERD/lock review | Receipt/approval review | Allocation/cancel review | Claim/dispatch review | **G1:** designs, schema dependencies, contracts, QA plans and automation scenarios approved |
| **3** | Products + stock services | Suppliers + drafts/approval | Customers + drafts/confirm | Resources + planner | Reviewed migrations; unit tests and QA execution begin; CI stays green |
| **4** | Receipt/reserve demo | Approved PO/receipt demo | Allocate/cancel demo | Plan/dispatch/delivery demo | **G2:** four standalone principal paths using contract-compatible test implementations |
| **5** | Real receipt/reservation integration | Real Inventory receipt | Real Inventory allocation | Connect real dispatchable orders | **G3a:** real PO -> stock -> allocated order works, no manual data repairs |
| **6** | Consume/reconcile integration | Hardening/cancellation | Claims/cancel/fulfillment integration | Real claims/resources/manifest | **G3b:** whole orders planned on real trips; multi-line rollback checks underway |
| **7** | Dispatch stock checks | Receipt regression | Real transit/delivery/completion | Atomic dispatch + full delivery | **G4:** complete connected baseline works; reviewers approve posting paths |
| **8** | Allocation/source races | Receipt race/replay/failure | Cancel/plan race + stale actions | Resource/claim/dispatch races | **G5:** concurrency, replay, permission and rollback evidence; major defects fixed |
| **9** | Reconciliation regression | Receipt/approval regression | Lifecycle/browser regression | Planner/driver regression | **G6:** reviewed automation, clean deployment rehearsal, regression, candidate release and draft sign-offs |
| **10** | Module handover | Module handover | Module handover | Module handover | **G7:** final integrated demo, QA sign-offs, PM acceptance, tagged release and retrospective |

Start automation scaffolding/scenario implementation on Days 3-4 and add steps as real integrations become available. Day 9 is stabilization, not the first automation attempt.

If a gate fails, stop optional UI work, fix the dependency and escalate impact. Do not bypass review or integrity controls to claim on-time completion. Day 8 is intentionally defect-focused; do not plan new features into Days 9-10.

## 7. SDLC artifacts, reviews and traceability

Use this README as the single project landing page and the linked team briefs as independently usable assignments. Keep detailed designs/test evidence in the agreed issue tracker or reviewable repository artifacts linked from here as they are produced. Shared contracts, scope and integrated acceptance remain governed by this page and the shared contract guide; update affected briefs when those decisions change.

| SDLC stage | Required artifact / minimum content | Owner | Approval / timing |
| --- | --- | --- | --- |
| Requirements | Prioritized stories, requirement/AC IDs, business examples, exclusions, unresolved questions | Team + PM; QA challenges acceptance | PM/product owner, Days 1-2 |
| Design | Context/module diagram, sequence diagrams, state transitions, ERD/data dictionary, constraints/indexes, role matrix, wireframes, contracts, calculations, locks/retry/rollback and failure cases | Devs; QA reviews testability | Assigned reviewer, Day 2 before domain migrations/implementation |
| Test planning | Scope, risks, layers, fixtures, cases/expected results, environments, automation candidates, entry/exit rules | Module QA with dev input | Team reviewer/QA mentor, Day 2 |
| Database change | Ordered migrations, dependency order, stable seeds, clean install evidence, recovery notes | Module devs | Reviewer before merge/apply to shared integration |
| Implementation | Story PR, business-rule tests, meaningful errors, relevant design updates | Devs | Reviewer before merge; affected owner for contracts |
| Verification | Module/integration/browser results, two-session traces, reconciliation, defect/retest history | QA; devs execute unit/integration checks | QA owner; mentors review automation |
| Release/UAT | Candidate commit, regression evidence, role-based demo, product acceptance, limitations | Release coordinator + PM + QA | Day 9 rehearsal; Day 10 acceptance |
| Deployment/handover | Exact setup/run/test commands, migrations/seeds, configuration names without secrets, recovery/runbook, role instructions | Each team + foundation maintainer | Another participant reproduces setup from clean checkout |
| Maintenance/retrospective | Known issues with severity/owner; lessons, one improvement per team; deferred feature backlog | All participants | Final retrospective, Day 10 |

### Lightweight templates

**Story:** task ID; business goal; source FR/AC IDs; owner; dependency; given/when/then acceptance; scope adaptation; design/PR/test links; status.

**Design review checklist:** authoritative table owner, state/quantity invariants, server validation/roles, identity sources, bound SQL, money/weight handling, transaction owner, global lock order, stale versions, replay/source uniqueness, rollback on later-line failure, migration safety and user-facing errors.

**Test case:** case ID; FR/AC mapping; preconditions/role; fixture references; steps/request; exact expected UI/service result; expected persisted quantities/source effects; cleanup; actual result; evidence; defect ID.

**Defect:** failed case; reproducible steps; expected/actual; affected commit/environment; severity; evidence/correlation ID without secrets; owner; fix PR; retest result.

**Contract change:** old/new semantics; reason; affected callers; schema impact; example payloads; test updates; owner/reviewer approval. Freeze after the first integrated demo except for correctness fixes.

## 8. QA plan and acceptance mapping

### Test layers and ownership

- **Developer unit tests:** quantity calculations, state transitions, exact decimal validation, capacity boundaries, whole-order decisions and authorization policy. Do not mock away the rule under test.
- **Oracle integration tests:** actual constraints, versions, two independent connections, unique effects, cross-module transactions and rollback. Mock-only tests cannot prove Oracle lock behavior.
- **QA service/endpoint tests:** valid/invalid data, direct unauthorized requests, CSRF, inactive masters, stale pages, action replay and changed-payload conflict.
- **Browser automation:** meaningful business outcomes using role-specific accounts, not just successful clicks. Assert displayed quantities and verify persisted outcomes through supported service/test queries.
- **Joint integration tests:** Purchasing QA + Inventory QA own receipts; Sales QA + Inventory QA own allocation; Logistics QA leads dispatch/delivery with Sales/Inventory QA.
- **Release acceptance:** all applicable source criteria and added baseline tests pass, with evidence. Reviewers approve design/code; QA signs verification; PM/product owner accepts business behavior.

Each QA should derive approximately **12-15 meaningful module cases** covering its applicable criteria and edge cases; scenario quality matters more than a quota. Share cross-module fixtures/cases rather than duplicating four copies.

### Source module acceptance: explicit baseline treatment

**Retain** means keep the original criterion; **Adapt** means replace the partial-flow example with the stated baseline expectation; **Defer** means excluded from this release, not passed.

| Module | Retain | Adapt for baseline | Defer |
| --- | --- | --- | --- |
| Inventory | INV-AC-01, 02, 04, 07 | **03:** two 8-unit requests against 10 available: one reserves 8, the other 0. **05:** reserve/dispatch all 10: H 20 -> 10, R 10 -> 0, available remains 10. **08:** reconcile receipts and dispatches to H and active reservations to R; omit returns. | INV-AC-06 physical return |
| Purchasing | PUR-AC-01, 02, 05, 07, 08 | **03:** receive all 20 once; outstanding 0 and stock +20. **04:** two sessions each try the full outstanding 20; only one commits. **06:** cancel entire unreceived 20-unit PO; stock unchanged; receipt thereafter denied. | Original partial-receipt and remainder-cancellation examples |
| Sales | SAL-AC-01, 02, 08 | **03:** need 10/available 6 -> reserve 0/U 10; after sufficient receipt allocate all 10 explicitly. **04:** wholly planned order cannot be cancelled; remove claims, then whole-order cancellation releases stock. **05:** reserve 10, ship 10, deliver 10 -> D 10/T 0/R 0/U 0. | SAL-AC-06 redelivery; SAL-AC-07 partly delivered remainder/Closed Short |
| Logistics | LOG-AC-01, 03, 04, 05 | **02:** two trips compete for the same whole 6-unit order; only one claims it. **06:** ship and deliver all 6; no return. **07:** closure fails until all lines delivered, then frees resources. **08:** unauthorized/replayed full-delivery outcome has no second effect. | Original split claims, partial delivery and return portions of these criteria |

Retained INV-AC-04 can use a seeded service-level partial claim to verify the defensive release rule, even though the baseline planner only creates whole-order claims. Seeded test states must not become an unsupported production UI feature.

Full-scope FR-06/07 in Purchasing, FR-07 in Inventory, FR-07/08/09 exception portions in Sales, and FR-06/07/08 exception portions in Logistics are covered only to the baseline extent above. Record the omitted portions as deferred. `Closed Short` and return-state screens are not baseline deliverables.

### Integrated suite

| Case | Source mapping | Required result / evidence | Lead QA |
| --- | --- | --- | --- |
| INT-01 Atomic full receipt | SYS-AC-01 | PO received +20 and one matching Inventory +20 effect commit together | Purchasing |
| INT-02 Later-line receipt failure | SYS-AC-02 | Inject failure on second product; all receipt/totals/stock/audit/action-result changes roll back | Purchasing |
| INT-03 Allocation race | SYS-AC-03 adapted | Two orders request 8 against H 10: one gets 8, other 0; total R 8/U remainder valid | Inventory |
| INT-04 Cancel/plan race | SYS-AC-04 adapted | Whole-order cancellation races whole-order claim; valid winner only, no claim greater than R | Sales |
| INT-05 Explicit replenishment retry | SYS-AC-05 adapted | Failed allocation has no effect; full new PO receipt then new allocation reserves current whole demand only | Sales |
| INT-06 Timeout replay | SYS-AC-06 | Authorized same-reference retry returns committed result; changed payload conflicts; no extra effects | Inventory |
| INT-07 Whole-order claim race | SYS-AC-07 adapted | Same fully reserved order claimed by two trips; one wins, losing trip gets no partial claim/resource leak | Logistics |
| INT-08 Later-line dispatch failure | SYS-AC-08 | Trip stays Draft; all Inventory/Sales/Logistics effects roll back and original claims remain | Logistics |
| INT-09 Full delivery | SYS-AC-09 adapted | Ship 10 -> T 10; deliver all -> D 10/T 0; delivery does not change warehouse H | Logistics |
| INT-10 Reconciliation/protection | SYS-AC-12 adapted | Ledger, reservations, claims and order buckets agree after every step; unauthorized/stale actions change nothing | Sales |
| INT-11 Multi-line atomic allocation | Additional baseline criterion | First line has stock, second does not: no reservation on either; retry after replenishment succeeds on both | Sales |
| INT-12 Whole-order capacity | LOG-AC-03 + baseline grouping | Exact capacity succeeds; one extra unit fails; oversized order remains pending with no partial claims | Logistics |
| INT-13 Cross-role and stale writes | Source shared controls, p. 27 | No self-approval; wrong driver, invalid CSRF and stale mutations denied server-side with controlled messages | Purchasing |
| INT-14 Source uniqueness under new action ID | Source duplicate rules, p. 10 | Receipt/dispatch/delivery cannot repeat by changing only the action reference | Inventory |

SYS-AC-10 (physical returns) and SYS-AC-11 (redelivery) are **deferred**. Adapted SYS-AC-09 does not demonstrate the original partial-delivery case. Keep original IDs plus the baseline expectation in traceability so reviewers cannot confuse the two.

For races, use two independent Oracle sessions with a coordinated start; capture which action committed and the final persisted state. For rollback, use a controlled test-only fault seam, not a public debug endpoint. Verify all participating tables and source effects, not merely an error message.

### Automation commitment

Automate one shared role-based purchase-to-delivery happy path and at least one high-risk negative workflow, reviewed by the mentors. Also retain executable Oracle tests for concurrency, replay and forced rollback. A practical negative browser scenario is duplicate posting or forbidden/stale action; a browser double-click alone does not prove transaction concurrency.

## 9. Shared fixtures and final demonstration

The organizer publishes canonical fixture references and scripted peer scenarios before kickoff. Teams implement their own real seed logic after schema review; shared integration seeds use the same reference catalogue:

- Products A = 2,000 g, B = 5,000 g, C = 1,000 g; unique stable reference codes.
- Two suppliers, three customers with addresses, two drivers and role-specific accounts.
- Vehicles with capacity 12,000 g and 30,000 g.
- Start physical stock at zero; create stock through valid posted purchase receipts, not manual balance edits.
- One PO for 20 A and one sales order for 10 A.
- Separate fixtures for multi-product rollback, competing 8-unit orders against 10 available, rejected/revised approval, inactive masters and a whole-order capacity failure.
- Isolated fixtures for each case with known expected quantities; resets target only expressly disposable test schemas/data.

### Baseline demo: use the 30 kg vehicle

The PDF's 12 kg vehicle example requires split trips and returns and is **not** the 10-day demo. Use the 30 kg vehicle to carry the entire 20 kg customer order:

| Committed step | H | Total R | Available | Order U | Order T | Order D | Draft claim A |
| --- | --- | --- | --- | --- | --- | --- | --- |
| Receive PO of 20 A | 20 | 0 | 20 | - | - | - | 0 |
| Confirm SO for 10 A | 20 | 0 | 20 | 10 | 0 | 0 | 0 |
| Allocate all 10 | 20 | 10 | 10 | 0 | 0 | 0 | 0 |
| Plan whole order | 20 | 10 | 10 | 0 | 0 | 0 | 10 |
| Dispatch whole trip | 10 | 0 | 10 | 0 | 10 | 0 | 0 |
| Deliver all 10 | 10 | 0 | 10 | 0 | 0 | 10 | 0 |

Close the trip and show released resources, completed sales order, PO receipt lineage, immutable manifest and stock movement ledger. Then demonstrate a replay, rejected unauthorized/stale action and evidence from the concurrent/rollback tests. Run reconciliation after each step; final H=10 alone is not sufficient proof.

## 10. Completion, release and assessment

### Definition of done

- All committed module criteria, adapted integrated criteria and added baseline tests pass.
- No unresolved blocker or major data-integrity/security defect. Lesser defects have explicit severity, owner and product-owner acceptance.
- Designs, migrations, code and automation have the required reviews.
- Clean installation and migrations/seeds succeed in a designated empty lab schema, without manual table edits.
- Setup/run/test commands are exact and reproduced by someone other than their author; credentials remain outside the repository.
- Candidate release commit/tag, test evidence, role-based demo, known limitations and recovery instructions are recorded.
- Every module QA signs off; PM/product owner accepts the connected workflow.
- Final retrospective records lessons and prioritized follow-up work. Lab completion is not approval for customer/production deployment.

Rehearse release on Day 9 with the same process intended for Day 10. Review migration recovery before application deployment; do not "roll back" by deleting posted business data. Restore only an approved disposable lab snapshot or use the reviewed forward-recovery procedure.

### Suggested learning assessment

Evaluate individuals as well as team delivery. Ask each developer to explain one transaction, one schema decision and one business-rule test; ask each QA to explain one boundary, one defect and its persisted-state verification.

| Dimension | Suggested weight |
| --- | --- |
| Business correctness and integration | 30% |
| Design, OOP, SQL and integrity decisions | 20% |
| Test design, automation, defect diagnosis and evidence | 25% |
| SDLC discipline, reviews and collaboration | 15% |
| Usability, reproducible handover and explanation | 10% |

Pairing is encouraged; ensure both developers can explain the shared posting design. QA should participate in SQL/data verification and automation, not be restricted to manual clicking.

## 11. Recommendations and kickoff checklist

### Improvements to the original plan

1. **Keep the reduced scope deliberate.** The source's full exception system is good follow-on material, but too ambitious for a first two-week implementation. Protect integrity controls before extra screens.
2. **Prepare Day 0 rigorously.** A working connector alone is insufficient: transactions, roles, tests, migrations and reproducible environments also need to work.
3. **Make whole-order atomicity explicit.** All-or-none line helpers do not guarantee all-or-none multi-line orders. Review this extra orchestration invariant.
4. **Reduce dependency waiting.** Supply typed interfaces, deterministic peer doubles and isolated module environments before kickoff; freeze reviewed contracts by Day 2. Integrate receipts/allocation by Day 5 and dispatch by Day 7.
5. **Keep reviewer capacity scheduled.** The estimate assumes timely design/PR turnaround. Give transaction work priority and nominate backups.
6. **Use evidence-driven QA from Day 1.** Include roles, retries, failures, exact capacity and real Oracle races; start automation early.
7. **Avoid scaffolding away the learning.** Staff own infrastructure; graduates own domain design, migrations, services and business tests.
8. **Do not evaluate screen count or polish alone.** Reward sound reasoning, reconciliation, collaboration and reproducible delivery.
9. **Keep commercial exploration separate.** Customer interviews, Oracle deployment economics, imports, backup/restore, stock correction and support readiness are later work, as the PDF recommends.

### Decisions to record before starting

- [x] Choose 10-working-day baseline.
- [ ] Confirm calendar dates and reviewer/mentor availability.
- [ ] Assign graduate names to teams and developer/QA responsibilities.
- [ ] Assign one PM, primary/backup reviewer and QA mentor coverage per team.
- [ ] Nominate foundation maintainer and integrated release/demo coordinator.
- [ ] Select supported runtime/Oracle driver versions and isolated environment strategy.
- [ ] Prepare independent module composition, peer doubles and canonical fixtures; ensure integrated mode rejects doubles.
- [ ] Agree contract/error/version/action-reference conventions and common lock order.
- [ ] Approve baseline whole-order allocation, planning and cancellation interpretations.
- [ ] Agree migration ordering, seed references, role matrix, currency and business timezone.
- [ ] Confirm issue/review/evidence locations and review turnaround.
- [ ] Pass the all-participant Day 0 readiness gate.

### Delivery links: populate as work is produced

| Item | Location / accountable owner |
| --- | --- |
| Shared issue board, requirement traceability and defect log | To be recorded by project PM |
| T1 design / PRs / QA evidence / sign-off | To be recorded by Inventory team |
| T2 design / PRs / QA evidence / sign-off | To be recorded by Purchasing team |
| T3 design / PRs / QA evidence / sign-off | To be recorded by Sales team |
| T4 design / PRs / QA evidence / sign-off | To be recorded by Logistics team |
| Contracts, migrations and contract-change log | To be recorded by foundation maintainer and module owners |
| Exact application setup, run and test commands | To be added by foundation maintainer after scaffold implementation |
| Integrated test report, release/tag and demo evidence | To be recorded by release coordinator |
| Product acceptance, known limitations and retrospective | To be recorded by project PM |
