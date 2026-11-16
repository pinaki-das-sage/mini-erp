# Mini ERP coding conventions

Applies to all four teams and the shared foundation. These are the initial review standards; update them together when a reviewed contract needs to change.

## 1. Naming and folder layout

| Element | Convention | Example |
| --- | --- | --- |
| Project-owned folders | Lowercase; use `snake_case` for multiple words | `src/shared/database`, `tests/browser` |
| PHP class/interface and matching file | `PascalCase` | `TransactionManager`, `TransactionManager.php` |
| PHP methods, functions, properties, local variables | `camelCase` | `receivePurchase()`, `$actionReference` |
| JavaScript functions, variables and properties | `camelCase` | `calculateTripWeight`, `expectedVersion` |
| Constants and environment variables | `UPPER_SNAKE_CASE` | `MAX_QUANTITY`, `ORACLE_CONFIG_FILE` |
| Oracle schema objects | Unquoted `UPPER_SNAKE_CASE`, mandatory `ME_` prefix | `ME_USERS`, `ME_INV_PRODUCT_SKU_UK` |
| Oracle columns | Unquoted `UPPER_SNAKE_CASE`; no `ME_` prefix required | `ACTION_REFERENCE`, `USER_ID` |
| Shared JSON/service fields | Agreed `snake_case` contract | `action_reference`, `expected_version` |
| URLs | Lowercase | `/modules/inventory`, `/api/note` |
| CSS classes and HTML IDs | `kebab-case` | `note-form`, `note-status` |

File names are not forced lowercase. Use descriptive business names, not abbreviations such as `$q1`, except documented quantity-model symbols.

**Namespaces:** Composer maps `MiniErp\` to `src/`. Namespace path segments after this root are lowercase to match the folders exactly; class names stay PascalCase:

```php
namespace MiniErp\shared\database;

final class TransactionManager
{
    // src/shared/database/TransactionManager.php
}
```

Likewise, `MiniErp\Tests\` maps to `tests/`, and test namespaces use `MiniErp\Tests\unit` or `MiniErp\Tests\oracle`. This deliberate namespace convention avoids case-sensitive Linux autoload failures. Do not rely on macOS case-insensitive paths.

Generated dependencies (`vendor/`, `node_modules/`) retain upstream paths. Do not rename their folders or edit their code. The project lint command checks project-owned folder casing.

## 2. General engineering

- Keep controllers thin: request validation -> authorized service -> repository/database -> response/view.
- One authoritative module for each business fact. No module writes another module's tables.
- Favor small cohesive methods and explicit dependencies over globals, singleton business services or unnecessary abstraction.
- Reuse shared validation, transaction, authorization and error helpers. Do not maintain four incompatible versions.
- Prefer early guards and clear errors. Do not swallow exceptions, hide failures or substitute simulated success.
- Comment only non-obvious business or transaction decisions; explain why, not what the next line does.
- Use UTF-8, LF line endings and a final newline. Avoid unrelated reformatting.

## 3. PHP

- Target PHP 8.4. Start every PHP code file with `declare(strict_types=1);`.
- Use four-space indentation in PHP logic, braces consistently, typed arguments/returns/properties and `readonly` where appropriate.
- Add PHPDoc for array shapes, collections and resources that native PHP types cannot express.
- Use `===`/`!==`, explicit `null` handling and validation of input types. Avoid loose comparisons for versions, IDs or quantities.
- Validate on the server even when the browser already validated. Quantities are positive integers; weights are integer grams.
- Use exact decimal arithmetic for money; reject prices beyond two decimals. Browser/PHP float totals are not authoritative.
- Controllers derive actor identity from the authenticated browser session or API bearer token, never posted `actor_id` or role names.
- Company identity is also server-derived. Use the authenticated `$actor->companyId`; `Actor` construction and `AuthService::actor($userId, $companyId, $securityVersion)` require explicit tenant context. Never default a business request to company ID `1`.
- Check permissions and state on every write. Hiding a button is not authorization.
- Register each business area in `ME_OBJECTS` and its named actions in `ME_OBJECT_ACTIONS` through a reviewed migration. Use the shared `Authorization::requirePermission()` in both UI and REST service paths; no unregistered/unsupported operation is allowed.
- Settings are admin-only. Admins have all registered business permissions, but must not bypass state, ownership, no-self-approval or assigned-driver restrictions.
- New non-admin users have no business grants by default. Never accept client-submitted admin flags or actor IDs as authorization evidence.
- Use `password_hash()`/`password_verify()` and regenerate the session ID after login/logout.
- Protect all session-based writes with CSRF, including login and logout. Use secure/HttpOnly/SameSite cookies and HTTPS remotely.
- New module development is [API-first](./api-development.md): reviewed API contract/design/test plan, failing tests, service/API verification, then Smarty UI. Register module endpoints through `src/<module>/api.php`; every route needs explicit object/operation permission metadata.
- `/api/v1` is bearer-only and never falls back to cookies. Tokens expire, are stored only as hashes, and are invalidated by security-version changes; do not cache login-time permissions or log/store raw tokens in URLs/browser localStorage.
- Smarty automatically escapes printed values through the shared `Security::escape()` helper. Do not pre-escape view data, disable escaping with `nofilter`, or confuse HTML escaping with SQL binding.
- Generate URLs in PHP with `WebPath::url()` and pass them to templates; the shared renderer supplies `$urls` and module links. Browser URLs use `appUrl('/route')`. Do not hardcode hostname-root links, form actions, redirects or API calls; deployments may have an `APP_BASE_PATH`.
- Resolve filesystem paths from `__DIR__`/`dirname(__DIR__)`, not a fixed username, checkout name or working directory. Keep URL generation separate from filesystem paths.
- Catch known business/database failures explicitly. Catch-all handling is limited to resource cleanup, transaction rollback/rethrow and the process/HTTP boundary; never return success from it.
- Do not expose raw SQL, connection strings, passwords or traces to users. Log safe correlation IDs and error codes.
- Long-running processes must release transaction resources; normal PHP request state is not a cross-request cache.

### Views: Smarty and responsibility boundaries

- Keep PHP code in `src/`; `.tpl` presentation files belong in `views/`. The hierarchy mirrors domain ownership: `views/shared/`, `views/inventory/`, `views/purchasing/`, `views/sales/` and `views/logistics/`.
- `src/shared/view/Renderer.php` renders the shared layout with a controller-selected content template. Use explicit relative template names, never request-supplied filenames.
- `views/shared/layout.tpl` composes `partials/header.tpl`, `partials/sidebar.tpl`, the page template and `partials/footer.tpl`. Security, Settings, user forms/permission controls, listers and the neutral example have separate templates.
- Templates may loop over prepared rows and conditionally show markup. SQL, authorization, transactions, session changes, grant matching, URL encoding and data normalization stay in PHP. Hiding a control is not authorization.
- Pass page-specific data under `$data`; the renderer also supplies `$title`, `$csrf`, `$actor`, `$urls` and `$basePath`. Do not pass repositories, database connections or service objects to templates.
- HTML escaping is automatic via the renderer's default `escape_html` modifier. It preserves the shared helper's quote escaping and invalid UTF-8 substitution. Avoid raw HTML/inline JavaScript data interpolation; use normal JSON API responses for browser data.
- Literal braces in a template can use Smarty's `{ldelim}` and `{rdelim}` tags, as demonstrated by the username input pattern.
- Smarty compiles templates to ignored `var/smarty/compile/`, outside `public/`. Rendered HTML caching is disabled so actor/company/CSRF data cannot be reused across requests. Do not edit or commit generated PHP.

For example, a module controller can render prepared product data using its existing `$paths` (`WebPath`) and `$csrf`:

```php
use MiniErp\shared\view\Renderer;

$body = (new Renderer($paths))->render(
    'inventory/products/list.tpl',
    'Products',
    $csrf,
    ['products' => $products],
);
```

The corresponding page template contains presentation only; the shared renderer wraps it in the common layout:

```smarty
<ul>
  {foreach $data.products as $product}
    <li>{$product.NAME}</li>
  {/foreach}
</ul>
```

## 4. JavaScript and browser behavior

- Use `const` by default, `let` when reassignment is necessary; avoid `var`.
- Use strict mode, strict equality, `async`/`await` and checked HTTP/business response status.
- Use two-space indentation in JS. Keep DOM/event code separate from business calculations when either grows.
- Use `textContent` for untrusted messages, not `innerHTML`.
- Keep form labels, keyboard access and accessible error/status messages. Errors must remain visible.
- Send CSRF tokens on writes. Do not embed credentials or trust client-calculated prices/roles/versions.
- Disable duplicate submission while a request is pending, but enforce duplicate protection on the server too.
- After an ambiguous timeout, retry unchanged payload with the original action reference. Do not silently invent a new reference.
- Show stale-version/conflict messages and require refresh/correction. Do not blindly retry failed writes.
- Use integer grams/whole-unit quantities. Treat displayed money as formatted data from the server.
- Client validation improves UX; server validation and Oracle constraints remain authoritative.
- Avoid a new framework or dependency unless it removes a demonstrated need and is reviewed.

## 5. Oracle and SQL

- Bind every value. Do not interpolate request values into SQL. Dynamic identifiers require a fixed allowlist, not parameter binding.
- Use uppercase SQL keywords and unquoted schema identifiers. Avoid quoted case-sensitive object names.
- **Every application-defined Oracle schema object must start with `ME_`: tables, views/materialized views, triggers, sequences, indexes, named constraints, synonyms, procedures, functions, packages and types.** Include a domain component where useful: `ME_INV_PRODUCTS`, `ME_SAL_ORDER_V`, `ME_LOG_TRIP_SEQ`, `ME_USERS_BIU_TRG`.
- Column names do not require this prefix. Oracle-owned/system-generated objects are not renamed. Do not rewrite applied migrations to rename older objects; use a new reviewed migration if needed.
- Select explicit columns, not `SELECT *` in production services. Do not silently truncate correctness-critical result sets.
- Use primary/foreign keys, `NOT NULL`, checks and uniqueness constraints alongside service validation.
- Every tenant-owned/business table needs mandatory `COMPANY_ID` referencing `ME_COMPANY`. Scope every read/write/join, business uniqueness rule, replay namespace and cache key to the authenticated company. Compound foreign keys must prevent mismatched company/user/document/source relationships.
- Shared definitions/migration ledger (`ME_ROLES`, `ME_OBJECTS`, `ME_OBJECT_ACTIONS`, `ME_MIGRATIONS`) and operational login-throttle counters (`ME_API_LOGIN_LIMITS`) are global. API tokens belong to a company/user with a compound foreign key. Admin permissions do not bypass tenant isolation.
- Choose `NUMBER` precision deliberately for integer quantities, versions and two-decimal amounts; use character lengths/LOBs intentionally.
- Use timestamp-with-time-zone storage consistently; display the configured business timezone.
- Every business write uses `OCI_NO_AUTO_COMMIT`. Only the outer application orchestrator commits or rolls back.
- Participating domain services receive the same transaction context and never start nested commits.
- Acquire locks in the agreed global order; sort line/product IDs. Revalidate authoritative quantities after locking.
- Availability reads are advisory. Use real Oracle two-session tests for races, not mocks.
- Pair record-version guards with affected-row checks. Zero updated rows is a conflict, not success.
- Enforce both action-reference replay and unique business source effects. Authorize before replay; replay before current-version checks.
- Oracle DDL implicitly commits. Keep DDL separate from business transactions and preserve a failed migration marker for explicit recovery.
- Reviewed anonymous `DECLARE` schema blocks also run outside business transactions. Never use broad constraint cascades to silently remove module dependencies.
- Object/action registration rows may be inserted by migrations. The runner commits each DML step separately from DDL; a failure retains `RUNNING` and requires explicit recovery, not automatic replay of earlier statements.
- Never edit/remove an applied migration. Add a reviewed new migration; do not destroy posted data to simulate rollback.
- Keep module migrations independently installable. Apply reviewed cross-module links before accepting the real integration.
- No arbitrary stock corrections or direct balance edits. Reconcile movements, reservations, claims and demand after each posting.

## 6. Configuration, tests and reviews

- Keep completed `config.ini`, `.env` and credentials out of source control and outside the public web root.
- Templates contain names/placeholders only. Configure isolated schemas and explicitly confirm targets before migration/seed commands.
- Do not automatically fall back from real Oracle/services to test doubles.
- Write a failing behavior test before new implementation; then green/refactor.
- Unit tests prove rules/orchestration; actual Oracle tests prove constraints, locks and rollback; browser tests prove user-visible outcomes.
- Tests should assert business results, not just mocked calls or clicks. Do not mock away the rule being tested.
- A skipped remote test is not a pass. Report unconfigured/blocked readiness explicitly.
- Small PRs include requirement/task IDs, reviewed design changes, migration impact, tests and evidence.
- Review casing, types, permissions, validation, source ownership, transactions, errors and migration safety before UI polish.

Run `composer lint` and `composer test` with PHP 8.4 before requesting review. The starter setup guide documents commands for environments where `php` points to an older version.
