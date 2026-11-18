# API-first development

The shared framework implements bearer authentication, permission-checked route registration, JSON responses and input/pagination helpers. The note and user-administration APIs are working examples. The [Leads blueprint](./entity-blueprint.md) adds a complete independent Sales entity; the baseline ERP workflow endpoints remain team work.

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
| `PATCH /users/{id}` | Admin-only edit: `display_name`, booleans `is_admin`/`active`, positive integer `expected_version` |
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

For an implemented end-to-end example, start with the [Leads blueprint](./entity-blueprint.md).
It includes six `/api/v1/sales/leads` routes (list/detail/create/update/archive/restore),
permission registration, Oracle persistence, Smarty UI and [Bruno requests](../examples/bruno/leads).

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
