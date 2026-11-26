# API-first development

The shared framework implements bearer authentication, permission-checked route registration, JSON responses and input/pagination helpers. The note and user-administration APIs are working examples. Customer routes and the Quote backend are implemented; Quote Inventory validation is pending. Other domain endpoints remain team work.

## Agreed Order increment: linear source flow

The current Order contract in `public/openapi.yaml` narrows the broader future workflow described below:
Submitted Quote -> Draft Order -> Submitted Order -> In Progress -> Closed. A Draft Invoice is created separately from a Submitted Order.
Standalone Orders/Invoices are outside this increment. Order sources cannot
be cleared or replaced. Customer, Warehouse, address and terms remain locked; the copied lines become
editable Draft Order lines. The Quote is the starting point and remains unchanged when the Order is edited.

Order Submit reserves final saved demand and marks the Order Submitted in one transaction.
Any submission failure rolls back stock, Order status/version, audit and replay records. Exact retries
return the original result. Invoice creation is a separate action using the existing Invoice service,
exposed by the Create Invoice button on a Submitted Order. It copies the saved Order and creates one
linked Draft Invoice. Invoice Date uses Order Date; Due Date follows payment terms. Order Submit does
not return an Invoice number. Draft Invoice creation has no Customer debt or stock effect.

One whole Sales Order uses one current Trip, including every line for its full saved quantity.
Every callback supplies every saved Order line, and getAssignments returns one Trip with one shared
status. Draft assignment leaves the Order Submitted. Dispatch consumes all line reservations and
starts In Progress. Delivery of the whole Trip closes the Order. Draft cancellation clears all Trip
references and leaves reservations active until reassignment or Order return to Draft. Dispatched
assignments cannot be cancelled or reassigned. Invoice creation remains a separate action and delivery
never creates or modifies an Invoice. See [the Sales?Logistics handoff](sales-logistics-handoff.md).

An unassigned Submitted Order can return to Draft when it has no Invoice, or its linked Invoice is Draft without payments
or downstream references. Delete that Invoice/link, release reservations and return the Order atomically.
Invoice creation requires sales.invoices:create; deleting an existing linked Invoice requires sales.invoices:delete.
Keep the original Quote link. In Progress/Closed cannot return.

Kavya's Invoice API/backend branch remains unmerged. This internal integration uses existing Sales tables
and numbering; no public Invoice CRUD/payment endpoints are added. Future Invoice writers must use a
consistent Order-before-Invoice lock order, including Invoice Submit/update/delete, before integration.
Live Oracle transaction/concurrency acceptance remains pending.

## Order backend and dependency status

`src/sales/orders/OrderService.php` implements Quote-backed create/list/get/update/delete and an eligible
Quote lookup. All Order routes are registered in `src/sales/api.php`; standalone Order creation is rejected.
Order listing filters to same-company Quote-backed Orders before pagination. Legacy standalone Orders and
records missing a valid Quote source do not break the page or consume its slots. They are left untouched;
direct detail/actions still reject missing or wrong-type sources. Do not guess links or delete old records
to work around the error; any historical data correction requires verified source evidence.
Create requires `sales.orders:create` and `sales.quotes:read`. Eligible lookup uses those same permissions.
Reads require `sales.orders:read`; edits require Update, deletion Delete, and lifecycle actions use the named
`submit` / `return_to_draft` grants. Submit also requires current Quote Read, including on retries.

Create requires only `source_quote_number`; the server copies the entire authoritative Quote line set,
Customer, Warehouse, shipping address and payment terms. Do not send those inherited fields or `lines`
when creating an Order. Optional Order-specific fields are Order Date, requested delivery date, Customer
PO Number and notes, plus the optional retry reference.

Draft updates allow editing Products, descriptions, quantities and prices, adding/removing lines and
reordering them. `expected_version` is required; `lines` is optional. Omit `lines` for notes/date-only edits.
If supplied, send the entire intended Order line set: retain `line_id` to edit/reorder an existing line,
omit `line_id` to add a new line, and omit a saved row from the collection to remove it. An empty collection
is invalid. Every submitted line requires numeric `product_id`, description, positive whole-number quantity
and nonnegative unit price with at most two decimals. Unknown/duplicate IDs and read-only fields are rejected.
Totals are calculated exactly and header/lines/version/audit/retry result are saved atomically. No stock
check or reservation happens during Draft edits; Inventory validates and reserves the final saved demand
on Submit. Product existence/company is enforced by the existing database FK. Submitted/In Progress/Closed
Orders cannot be edited, nor can referenced or Trip-assigned Drafts.

The server preserves original `source_line_id` provenance on quantity/price/description changes. Added
lines have null provenance; replacing a Product clears its original Quote-line reference. Clients may
omit this field or echo the current saved value but cannot forge/reassign it. The Order's Quote header link
always remains, even if all original lines are replaced. Customer, Warehouse, shipping address and terms
come from the source, preserving its snapshots. Order Date defaults to the
business date and must be on/after Quote Date. On create, copy the Quote's requested delivery date only if
it is on/after Order Date; an earlier default is left unset (null), preserving the Quote unchanged. Explicit
non-null delivery dates must be on/after Order Date. The mutable
Order header fields are dates, Customer PO Number and notes. Use the returned `current_version` as
`expected_version` for each saved change. Optional `action_reference` supports exact retries.

```json
{
  "source_quote_number": "SQ-000000000000044",
  "action_reference": "order-from-quote-044"
}
```

Example Draft update (use current Order line IDs/version from GET; omitted saved rows are removed):

```json
{
  "expected_version": 1,
  "notes": "Updated order notes",
  "lines": [
    {"line_id": "122", "product_id": "163", "description": "Sagar Testing order update", "quantity": "12", "unit_price": "9818.00"}
  ]
}
```

A notes-only update can be `{"expected_version": 1, "notes": "Deliver before noon"}`. No line array is needed.

Replace the example Quote number with an eligible Submitted Quote. Order numbers use `SO-` plus 15 digits
from the existing migration 009 sequence; gaps are expected and numbers are not reused. No new migration
is needed. Verify sales migrations 005-011 and the Inventory Product FK prerequisite before use; do not
edit applied migrations or reset numbering. Draft deletion releases its Quote link under the Quote parent
lock, enabling the Quote to be used again.

Order Submit is now connected to the supplied `src/inventory/ReservationService.php`, preserved byte-for-byte
from the Inventory handoff. `InventoryOrderAdapter` implements `OrderInventory` and is injected by
`src/sales/api.php`. It locks Sales lines by numeric record ID, rechecks authoritative demand, validates
active same-company Products/W01 through the existing Quote validator and calls Inventory `reserveStock`
using numeric internal Order/line/Product IDs (not the public SO number). Calls sort by Product ID then
line ID; repeated Product quantities consume availability cumulatively under Inventory balance locks.
Every saved line must be fully reserved before Submitted commits. Exact stock is sufficient; later-line
shortages, provider errors and Sales audit failures roll back all Inventory/Sales effects together. On-hand
stock does not decrease during reservation. No internal HTTP, Inventory-table access from Sales or runtime
fake is used. Submit requires `inventory.reservations:reserve` in addition to Sales Submit and Quote Read.

The adapter also calls Inventory `getReservationDetail` and `releaseReservation`, requiring Inventory
reservation Read and Release grants. The simplified return flow uses `SalesTripAssignmentGuard` as its
assignment authority: any saved `TRIP_ID`, `LOGISTICS_TRIP_LINE_ID`, or active Trip status blocks return.
`trip_status` stores Draft/Dispatched/Delivered, not a boolean. Cancelled status is unassigned only after
both references are cleared. The guard locks the same-company Order and its lines and supplies Inventory's
planned-quantity callback from those saved fields. Successful verification yields zero for unassigned
lines; Draft/unclear assignments conservatively report the saved quantity. This is not a constant-zero
fallback. Missing Orders/lines and query failures are errors. All failures preserve state/reservations;
later-line release failures roll back earlier releases as well. Eligible Submitted Orders now advertise
`move_to_draft`; source Invoice references and versions are still checked before release.

This simpler contract requires Logistics to save assignments/claims and mirror Sales Trip fields through
the same transaction/callbacks and parent/line locks. A hidden Logistics-only claim is unsupported. No
Logistics HTTP/provider lookup is needed on return. Keep the original Quote unchanged. Release retains
Inventory history and resubmission creates a fresh reservation cycle without duplicating active records.
For Logistics' dispatch/event verification, use its own authoritative claim callback as proposed in the
handoff; do not reuse an intermediate pre-refresh Sales-field snapshot for dispatch detail checks.

Deploy `src/inventory/ReservationService.php`, `src/sales/orders/InventoryOrderAdapter.php`,
`src/sales/orders/SalesTripAssignmentGuard.php`, `src/sales/invoices/OrderInvoiceService.php`, the matching `OrderService.php` and `src/sales/api.php`
together, plus the updated OpenAPI document. Regenerate Composer autoload files if using
an authoritative class map. This change introduces no Sales migration. Inventory's owning migrations must
already provide `ME_INV_PRODUCTS`, `ME_INV_STOCK_BALANCE`, `ME_INV_RESERVATION`, `ME_INV_RESERVATION_SEQ` and
the registered `inventory.reservations` Read/Reserve/Release permissions. Missing objects or ungranted
operations are real deployment errors; do not replace them with invented balances or automatic grants.

Lock order for this increment is source Quote -> Order parent -> Customer -> sorted Sales lines ->
Inventory calls ordered by Product ID/line ID, each locking balance before reservation.
Source identity is immutable and rechecked after locking. Lists release each record's locks before the next
record. Every future Logistics assignment/cancellation and Invoice source claim must coordinate on the
same Order parent; review the complete Trip/balance lock order with both provider teams before integration.
Draft deletion relies on the invariant that Logistics cannot assign Draft Orders. Existing Sales Trip fields
are presentation facts only; they are not an authoritative replacement for a Logistics return check.

The proposed `SalesFulfillment` event methods and provider/Invoice interfaces are ready for team review:
see [the Sales–Logistics handoff](sales-logistics-handoff.md). They are not wired into production; real
Logistics event facts remain integration work; Invoice creation is a separate Submitted Order action. The current
return-to-Draft path uses the saved-field protocol above. No UI or live
Oracle/concurrency acceptance is claimed. Adapter tests execute the real
supplied reservation service with transactional database doubles; other Sales tests also use provider
doubles. They cannot prove actual Oracle locks or persisted stock effects. Run:

```text
php vendor/bin/phpunit --filter OrderServiceTest
php vendor/bin/phpunit --filter InventoryOrderAdapterTest
php vendor/bin/phpunit --filter SalesFulfillmentTest
php vendor/bin/phpunit --filter SalesTripAssignmentGuardTest
php vendor/bin/phpunit --testsuite unit
php bin/lint.php
node --test tests/unit/sales/sales-openapi.test.cjs
```

Local verification for this increment: 311 focused PHPUnit tests passed (OrderService, QuoteService,
CustomerService, SalesRules, InventoryQuoteValidator, InventoryOrderAdapter, OrderInvoiceService, LogisticsOrderAdapter,
SalesFulfillment and SalesTripAssignmentGuard), with 1,701 assertions
on PHP 8.4.27 WebAssembly. This includes the supplied ReservationService executing against a transactional
database double for exact stock, cumulative shortages, permission checks, audit rollback and release/resubmit cycles.
PHP parser checks passed for 85 application/test/migration PHP files. Native PHP execution is blocked on
this Windows host; the full subprocess-dependent unit suite was not completed. OpenAPI checks have 12
passes and three pre-existing failures in Customer metadata and shared Product
definitions. Order-specific checks pass. No Oracle, provider concurrency or real stock effects were tested.

## Sales backend folders

Customer-only classes live in `src/sales/customers/` (`MiniErp\sales\customers`), and Quote-only classes live in `src/sales/quotes/` (`MiniErp\sales\quotes`). Shared `Validation.php`, `DocumentRules.php`, `SalesPermissions.php`, and the module route entry point `api.php` remain directly in `src/sales/`. Migrations and fixtures remain under `database/`; API URLs and JSON fields are unchanged.

Deploy both new directories and the matching `src/sales/api.php` together when upgrading the old flat layout. Retire the superseded root-level Customer/Quote class files after deployment. Composer's existing PSR-4 mapping supports the new folders; regenerate autoload files with `composer dump-autoload` if the server uses an optimized or authoritative class map. There are no database changes for this reorganization.

## Customer Code generation

Customer creation generates an immutable code such as `CUST-001`, `CUST-002`, and `CUST-1000`. Omit `customer_code` from the create body; supplying it is rejected. A single Oracle sequence serves all companies, and rollbacks or cached sequence values can leave gaps. Numeric Customer URL IDs remain separate. Existing codes remain unchanged.

Pause Customer creation while deploying the matching backend and applying `php bin/console.php migrate --module=sales` with the configured schema confirmation when required. Migration `011_oe_customer_code_sequence.php` starts above the largest existing uppercase `CUST-` code with a 1 to 15 digit suffix across all companies (or at 1 if none exist). Resume creation after both steps finish. Do not reset or cycle the sequence. Missing or exhausted numbering returns `NUMBERING_UNAVAILABLE` (503).

`action_reference` remains optional: the same explicit reference and input replay the original result without consuming another Customer Code. Repeated requests without a reference create separate Customers with separate codes.

## Sales Quote implementation and Inventory handoff

The supplied Sales and Order Entry requirements and five-table OE design govern Quotes. Older starter briefs describe a different Sales workflow; do not copy their allocation states or exclude Quotes from this implementation.

Quote routes under `/api/v1/sales/quotes` support create/list/get, POST `/{quote_number}/update`, and POST actions `submit`, `move-to-draft`, `delete`. Create uses Create authorization; edits, submit and return use Update; reads use Read; deletion uses Delete. Legacy named submit/return grants do not independently authorize Quote workflow. Every saved-document change requires `expected_version`. `action_reference` is optional, matching Customers: omit it for an independent request; preserve an explicit reference and payload for retries. All update endpoints use POST with an `/update` suffix because the hosting environment restricts HTTP methods.

```json
{
  "customer_code": "CUST-001",
  "warehouse_id": "W01",
  "lines": [
    {"product_id": "163", "description": "Sample product", "quantity": "5", "unit_price": "250.00"}
  ]
}
```

Shipping Address and Payment Terms default from the active Customer and remain saved snapshots. Customer Code and Name in responses come from the current Customer master. Quantities are positive whole-number strings with up to 9 digits. Prices allow 10 whole digits and 2 decimals; amounts and totals allow 16 whole digits and 2 decimals. Draft Quote updates require the latest `expected_version`. Omit `lines` for date/notes/header-only edits: saved lines are revalidated and preserved without line writes. If supplied, `lines` is the complete intended collection, retaining saved `line_id` values for edits; `null` and an empty array are rejected. Array order controls line display positions. Line validation errors identify the affected line. Dates are checked against the resulting saved header, including any omitted date fields retained from the Quote.

Sales migrations 005/006 install headers/lines. `007_oe_source_links.php` and `008_oe_payments.php` restore the supplied table migrations, already applied on the target database. `009_oe_document_numbering.php` creates separate Quote, Order, Invoice and Payment sequences together. The separate Quote numbering migration was consolidated into 009 at the requester's direction. If an earlier version of 009 or either former Quote numbering filename is already recorded as applied, reconcile migration history against existing sequences before the next run; do not recreate or reset sequences. The runner uses complete filenames and checksums as migration identities.

Before running migrations on an existing installation, restore byte-for-byte originals of already-applied files from the server or your teammate if their recorded checksums differ from these pasted-source copies. The runner hashes entire files, including formatting and line endings. Do not change ledger checksums or rerun table creation to bypass a mismatch. Check `SELECT VERSION, CHECKSUM, STATUS FROM ME_MIGRATIONS WHERE COMPONENT = 'sales' ORDER BY VERSION` against the originals. If tables were created manually without ledger entries, reconcile their installation history before using the runner.

After deploying matching files and verifying applied history, apply pending migrations with `php bin/console.php migrate --module=sales` against the intended Oracle schema. Already-applied table migrations are skipped. The Quote backend formats `SQ-` plus a 15-digit sequence. Future Order/Invoice/Payment services will format `SO-`, `SI-` and `PAY-` plus 15 digits; the new migration only creates their sequences. These numbers are never reused and fit within 20 characters. Check imported numbering namespaces before deployment.

Migration `010_oe_inventory_product_reference.php` requires Inventory's `ME_INV_PRODUCTS` table and its `(COMPANY_ID, PRODUCT_ID)` primary key in the same schema. With application writes stopped, it adds numeric `PRODUCT_ID NUMBER(10)` and applies the approved mapping for company 1, document RECORD# 1, line RECORD# 1: `ITEM-001` becomes Product 163 (SKU RF-001, Bolt). It preserves line IDs, descriptions, quantities and prices; the affected unreferenced Draft Quote receives Warehouse W01 and one version increment. The migration verifies that Product 163 is active in company 1/W01 and rejects any other existing lines before schema changes, since they have no approved mapping. An empty line table is also supported. It makes PRODUCT_ID required, adds the company-scoped Product foreign key, and only then drops ITEM_CODE. Do not delete records or guess mappings to bypass preflight checks. Oracle DDL is not transactional, so inspect the ledger/schema and follow recovery if a run fails; do not blindly retry a partially applied migration. Deploy the matching Quote backend together with this migration. Quote requests and responses now use `product_id` (replacing `item_code`), carrying the Inventory Product ID as a positive decimal string of up to 10 digits, not a SKU. Update clients together with the backend; the old field is rejected. Quote Warehouse is W01. No direct stock-table access or stock columns are added to Sales.

The source-link table is authoritative for downstream usage, including a Draft Order. Future Order services must lock the source Quote parent before claiming or releasing its link, validate source/target types and company, and save the link and complete copied lines atomically. The unique source and target constraints enforce one current target per source and one source per target. Returning a Quote to Draft or deleting it rechecks usage under that same parent lock.

Inventory integration follows [the shared contract boundary](./shared-contracts.md#contract-matrix): call the owning PHP service using the trusted Actor/company and shared transaction. Do not query Inventory's private tables in runtime services, perform internal HTTP calls or store another stock balance in OE. Product references use Inventory's numeric PRODUCT_ID; its single Warehouse is W01. Exact provider signatures still need agreement. The foreign key enforces Product existence/company; the provider validates active status and Warehouse.

| Workflow | Inventory involvement |
| --- | --- |
| Quote create/edit | Validate local fields, active Customer, dates, complete lines and totals. Save Draft without calling Inventory; Item/Warehouse reference validation is deferred to Submit. |
| Quote submit | Validate active, same-company Item and Warehouse references. No availability check, reservation or stock change. |
| Future Order submission | Use `getAvailability` for the company/Warehouse and combined Item quantities; the result is a snapshot. Inventory must confirm all reservations through `reserveStock` before the Order becomes Submitted. |
| Eligible Order return/deletion | Coordinate `releaseReservation` and confirm release before the action completes. |

`QuoteInventory::validate` is the integration interface. The `QuoteService` in `src/sales/api.php` receives Inventory's supplied implementation directly: `new MiniErp\inventory\QuoteInventoryValidator()`. Direct service construction also defaults to this real validator. Drafts can be created and edited without Inventory service validation; Submit always validates against Inventory through the existing transaction. There is no runtime test adapter, fixture loader or mode switch.

### Real Inventory validation

Deploy `src/inventory/QuoteInventoryValidator.php`, `src/sales/quotes/QuoteService.php` and the matching `src/sales/api.php` together. No Inventory mode configuration, additional transaction, endpoint or database migration is needed. Keep application settings and credentials unchanged. Inventory's `ME_INV_PRODUCTS` table must be accessible through Sales' existing database connection. Inventory's implementation selects `PRODUCT_ID`, `WAREHOUSE_ID` and `IS_ACTIVE` using the trusted `COMPANY_ID`, checks numeric Product ID strings and deduplicates/chunks lookups. It does not query stock, write data, open a connection, commit or roll back.

Provider limitations: Warehouse validation currently accepts the fixed `W01` identifier. Inventory has no Warehouse master or Warehouse active flag, so separate Warehouse existence/active checks are not implemented. Products must match the Warehouse and have `IS_ACTIVE = 'Y'`. The provider SELECT does not lock Product rows; teams must still agree behavior for concurrent Product deactivation. Merely sharing a transaction is not evidence that such races have been tested.

Inventory returns `VALIDATION_ERROR` (400) for malformed/missing/wrong-company/wrong-Warehouse Product references, `INVALID_STATE` (409) for inactive Products, and `INVENTORY_UNAVAILABLE` (503) for query failures. These pass through Sales' existing handling; submission rolls back and the saved Draft/version remain unchanged. After successful Submit, use the returned version for Move to Draft. The provider and POST lifecycle are covered locally with database doubles; live Oracle Submit/rollback verification must be run after deployment.

Before the Inventory handoff can be completed, agree active Item/Warehouse lookup, identifier mapping, exact signatures, company checks, error codes and lock order. For Order stock integration, also agree reservation source identity, replay/version behavior and all-or-nothing rollback on a later-line failure. Real concurrent-order and rollback acceptance tests require Inventory's implementation on the shared connection. This section specifies the integration requirement; it does not claim availability or reservations are connected.

## 1. Required story order

1. Groom acceptance examples with the PM and graduate QA.
2. Define the API method/path, JSON fields/types, permissions, success/error examples and peer calls.
3. Obtain design/test-plan approval, including transactions, locks, versions and migration safety.
4. Write a failing API/service test.
5. Implement the reviewed migration and service, then its thin API handler.
6. Verify the API before adding the Smarty UI.

The framework forces authentication and explicit permission metadata for module routes. Guides enforce the API-first order during reviews; software cannot prove which file a developer wrote first.

Keep business rules in services. UI and API call those same services. Cross-module calls remain **in-process PHP calls sharing one Oracle transaction**, never HTTP calls between teams.

## 2. Login and token use

All paths below are relative to the deployed application directory, for example `<application>/public/api/v1/auth/login`. Use HTTPS with a trusted server certificate.

```http
POST /api/v1/auth/login
Content-Type: application/json
```

```json
{
  "company": "SIA-MINI-ERP",
  "username": "<username>",
  "password": "<password>"
}
```

Do not send an Authorization header when logging in. Successful login returns HTTP 200:

```json
{
  "success": true,
  "operation_id": null,
  "current_version": null,
  "result": {
    "access_token": "<64-character-random-token>",
    "token_type": "Bearer",
    "expires_in": 3600,
    "expires_at": "<UTC timestamp>",
    "must_change_password": false
  },
  "error": null
}
```

Subsequent requests include:

```http
Authorization: Bearer <access_token>
```

- These are random opaque tokens, not JWTs or PHP session IDs. Oracle stores only their SHA-256 hashes.
- Default lifetime is one hour from issue, regardless of activity. Configure `API_TOKEN_TTL_SECONDS=3600`; allowed range is 60-86400 seconds. Tokens do not renew automatically; there are no refresh tokens.
- Each request checks expiry/revocation, active company/user, security version, current roles and required grants. Never authorize using a permission list cached at login.
- User edits, permission changes, password changes/resets and disabling invalidate existing tokens through the user's security version. Logout revokes only the presented token. Already-running transactions are not cancelled retroactively.
- Temporary-password accounts can use identity, password change and logout only. Password change invalidates their tokens; log in again afterward.
- Login throttling allows five attempts per company/username and 100 per source IP in each fixed one-minute bucket, including successful attempts. Failed attempts persist. HTTP 429 includes `Retry-After: 60`.
- IP comes from the server's `REMOTE_ADDR`, not a client-provided forwarded header. A reverse proxy may make clients share an IP quota; hosting administrators must review this for their deployment.

## 3. Available endpoints

### Bruno login sample

In Bruno, choose **Open Collection** and select [examples/bruno](../examples/bruno). Select your configured remote environment, set `base_url`/`company`/`username`, and enter `password` as a secret environment variable. Then open **Login** and send it. Do not replace the secret placeholder with a password in the tracked request file.

The [login request](../examples/bruno/login.bru) checks HTTP 200, the response envelope, token format and configured expiry without printing the raw token in assertion errors. Set `expected_ttl_seconds` to match the server; the expiry check allows 30 seconds for network/clock skew. The included base URL targets the training server; change its deployment directory for your own sandbox. This is an interactive sample, not a live test already run.

Deploy the new code/migration first. Trust the approved CA in Bruno; disabling TLS checks is not security acceptance. Never share saved responses containing tokens. If you need private environment files or CLI automation, copy the collection into ignored `local-docs/` and keep credentials out of the shared example.

### Bruno user-management requests

The same collection includes **List users**, **Create test user**, **Update test user**, **Set test user permissions** and **Reset test user password**. Run Login as an admin first; success stores `access_token` in runtime memory only, and a new login attempt clears the old token.

Send requests individually on an approved sandbox. Create prompts for a unique username/display name and temporary password, then selects the returned `target_user_id`. For an existing test user, clear any runtime `target_user_id` in Bruno's Variables view and explicitly set that ID in your environment. Confirm the target before every write.

List users before each mutation and enter the latest `current_version` when prompted (an unquoted positive integer). Update defaults to non-admin/active; deliberately set `active: false` to disable rather than delete. Permissions **replace all grants** with the sample note grants. Reset prompts for a new password and requires the user's next-login password change. Do not target real employee accounts or run the whole collection blindly.

Prompt variables are not saved and are skipped by Bruno's automated runner; these write examples are interactive. Do not save passwords/tokens in request files or environment variables other than declared secrets. The renamed/private environment file is user-owned.

Collection script/contract checks: `node --test tests/unit/bruno-collection.test.cjs`. These local checks do not execute remote CRUD operations.

| Method / path under `/api/v1` | Input / behavior |
| --- | --- |
| `POST /auth/login` | `company`, `username`, `password` strings; issues token |
| `GET /auth/me` | Current identity/company, roles, admin/password-change flags and supported granted permissions |
| `POST /auth/logout` | `{}`; revokes current token |
| `POST /auth/change-password` | `current_password`, `new_password`; invalidates sessions/tokens |
| `GET /note` | Read the company's welcome note; requires `shared.note:read` |
| `POST /note` | `text`, `action_reference`, positive integer `expected_version`; requires `shared.note:update` |
| `GET /users?page=1&page_size=25` | Admin-only paged user list; requires `shared.user:read` |
| `POST /users` | Admin-only create: `username`, `display_name`, `password`, boolean `is_admin`; HTTP 201 |
| `POST /users/{id}/update` | Admin-only edit: `display_name`, booleans `is_admin`/`active`, positive integer `expected_version` |
| `POST /users/{id}/permissions` | Admin-only: `grants` array of `object:operation` strings, `expected_version` |
| `POST /users/{id}/reset-password` | Admin-only: `password`, `expected_version`; next login requires password change |

User mutations require `shared.user:create`, `update`, `permissions` or `reset_password`, as appropriate. Granting these to a non-admin does **not** make that user an administrator; the service retains its admin-only restriction. Users cannot be deleted.

The user-list result contains `items`, `page`, `page_size`. Each item has `id`, `username`, `display_name`, boolean `is_admin`/`active`, and integer `current_version`. Passwords/hashes are never returned. It is a paged list, not a complete-export endpoint.

All writes require `Content-Type: application/json` and a JSON object, even logout (`{}`). Integers and booleans must be real JSON values, not `"1"` or `"false"`. The HTTP boundary limits JSON bodies to 8 KiB.

Note update example:

```json
{
  "text": "Reviewed API example",
  "action_reference": "note-demo-001",
  "expected_version": 1
}
```

Read the current version first. Retry unchanged input/reference after an uncertain result; do not invent a fresh reference to repeat a posting. The note service preserves its transaction, audit, replay, stale-version and changed-payload checks.

Existing browser routes (`/login`, `/logout`, `/api/note`, `/api/settings/users`) retain HttpOnly cookies and CSRF. They are not bearer endpoints. `/api/v1` neither starts nor authenticates from a browser session; an invalid bearer never falls back to cookies. The Smarty UI remains cookie-based and shares the same services.

## 4. Add a module API

Create your module's `src/<module>/api.php`. The framework loads these four module files when present; each must return a registration closure. Missing module files mean unimplemented routes, not simulated functionality.

This example is a pattern, **not an existing Inventory implementation**. Your team implements `ProductService`, its contracts/tests and its object registration migration:

```php
<?php
declare(strict_types=1);

use MiniErp\inventory\ProductService;
use MiniErp\shared\Api;
use MiniErp\shared\database\Connection;
use MiniErp\shared\security\Actor;

return static function (Api $api, Connection $connection): void {
    $products = new ProductService($connection);
    $api->register(
        'GET',
        '/inventory/products',
        'inventory.products',
        'read',
        static function (Actor $actor, array $input, array $params, array $query) use ($products): array {
            $page = Api::pagination($query, ['sku', 'active']);
            return $products->listProducts($actor, $page);
        },
    );
};
```

- Prefix `/api/v1` is added by routing; register paths without it.
- Register a method, path, object, operation and handler. Missing permission metadata, invalid registrations and duplicate routes are errors. `/auth` is reserved.
- `/inventory/products/{id}` supplies `$params['id']`. Path IDs use letters, digits, underscores/hyphens; validate their domain meaning in the service.
- Handler receives authenticated Actor, JSON input, path parameters and query parameters. It returns an array service result; the framework wraps it. Optional final registration argument `201` supports resource creation; default is `200`.
- Throw `AppError` for business rejection so the transaction owner rolls back. Do not return a failure envelope or `false` from a transaction and accidentally commit; handlers return results, not complete HTTP/envelope responses.
- `Api::string()`, `integer()` and `boolean()` reject missing/wrongly typed fields. Add business length/range/state validation in the service.
- `Api::pagination($query, $allowedFilters)` returns page, page size, offset and filter strings. Defaults: page 1, size 25; maximum size 100/page 1000000. Unknown fields and nested values fail. Validate filter meanings and use stable ordering.
- Bind SQL values, including offset/limit. Map allowed filters/sort fields to fixed SQL identifiers; never interpolate arbitrary query fields.
- Register objects/actions through reviewed migrations. The API guard checks permission **before** invoking a handler; services must also check authorization for browser/internal callers.
- Derive user/company from Actor, not JSON, query fields or path claims. Scope every data read/write and peer reference to that company.

Use [Api.php](../src/shared/Api.php), [TokenService.php](../src/shared/security/TokenService.php), [API tests](../tests/unit/ApiFrameworkTest.php) and [Oracle acceptance tests](../tests/oracle/OracleFoundationTest.php) as reference code.

## 5. Responses, failures and verification

Every versioned response uses `success`, `operation_id`, `current_version`, `result`, `error`. Successful writes can report their committed record version; list versions belong on individual items.

| Status | Meaning |
| --- | --- |
| 200 / 201 | Successful operation / resource created |
| 400 | Invalid fields/JSON |
| 401 | Missing, expired, revoked or invalid authentication; includes a Bearer challenge |
| 403 | Permission, role or password-change restriction |
| 404 | Missing API operation or resource |
| 409 | Stale version, replay conflict or business-state conflict |
| 415 | API write is not JSON |
| 429 | Login throttled |
| 503 | Database/configuration unavailable; never simulated success |

Business errors retain their service error codes. Unexpected failures use the HTTP boundary's safe correlation ID, not raw exceptions. Responses are not cached.

For each module: test authentication, company isolation, permissions, positive/negative inputs, versions and relevant replay/rollback rules before UI work. Use real Oracle tests for persistence/locks; doubles prove only their declared local behavior.

```bash
php84 vendor/bin/phpunit --testsuite unit
php84 bin/lint.php
```

On an explicitly approved disposable `_TEST` schema, configured with matching Oracle credentials and migrations:

```bash
LAB_ORACLE_TEST=1 ORACLE_TEST_SCHEMA=YOUR_TEAM_TEST php vendor/bin/phpunit --testsuite oracle
```

This mutates isolated fixtures. A skipped Oracle suite is not acceptance.

## 6. Deployment and operational safety

After pulling this update, preserve private configuration, install locked Composer dependencies and apply **`004_api_tokens.php`** with the existing migration command in each approved team/integration schema. Back up and stop traffic during migration. `/ready` now requires both token/throttle tables and current checksums. See [setup and migration recovery](./SETUP.md).

No new Composer/npm dependency is needed. Existing browsers do not need to switch to bearer storage.

- Require HTTPS at the hosting edge; ensure Apache/PHP receives Authorization correctly. Do not trust arbitrary forwarded headers or bypass certificate validation for authentication acceptance.
- Keep tokens/passwords out of URLs, shell history, access/debug logs, screenshots, saved test reports and browser `localStorage`. Treat a bearer token like a password.
- Cross-origin browser access is not enabled by this framework. Do not add wildcard CORS or token-in-cookie fallback.
- Token times are stored as UTC timezone-aware timestamps; synchronize application-server clocks. Throttle `WINDOW_ID` is a numeric minute-bucket identifier, not a business timestamp.
- An authorized maintainer should periodically purge expired/revoked tokens and old throttle buckets through reviewed maintenance. Automatic cleanup/refresh and narrower independent token scopes are not implemented in this baseline.
