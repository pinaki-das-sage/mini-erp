# API-first development

The shared framework implements bearer authentication, permission-checked route registration, JSON responses and input/pagination helpers. The note and user-administration APIs are working examples. Customer routes and the Quote backend are implemented; Quote Inventory validation is pending. Other domain endpoints remain team work.

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

Shipping Address and Payment Terms default from the active Customer and remain saved snapshots. Customer Code and Name in responses come from the current Customer master. Quantities are positive whole-number strings with up to 9 digits. Prices allow 10 whole digits and 2 decimals; amounts and totals allow 16 whole digits and 2 decimals. Every edit supplies all intended lines, retaining saved `line_id` values for edits. Array order controls line display positions. Line validation errors identify the affected line.

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
