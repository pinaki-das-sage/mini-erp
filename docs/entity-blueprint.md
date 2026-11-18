# Adding an entity: the Leads blueprint

Leads is a working teaching example under Sales. Copy its **sequence and
responsibility boundaries**, not its business rules blindly.

The approved scope is a company-wide contact register: create, list, view,
edit, archive and restore. Leads do not depend on Customers, Orders or stock.
Qualification does not convert a lead to a customer. There is no permanent
delete, assignment workflow, import/export or lead-specific role.

## 1. Deploy and try it

Use the approved lab schema and PHP 8.4. Back up the schema and stop traffic
before migration. Preserve private configuration and install existing locked
dependencies if needed; Leads introduces no package dependency.

From the checkout:

```bash
php bin/console.php migrate
```

The command discovers ordered module folders automatically: `001-shared`
runs first, then [002-sales/001_leads.php](../database/migrations/002-sales/001_leads.php).
The ledger still uses component names `shared` and `sales`, without the order
prefix. Previously applied migrations are not replayed after the directory move.
An optional `migrate sales` targets only Sales when its prerequisites are already
installed. See [migration ordering and upgrades](./SETUP.md#5-apply-migrations-to-the-approved-lab-schema).

Oracle DDL commits implicitly. If a migration fails or remains `RUNNING`,
stop and follow [migration recovery](./SETUP.md#migration-failure). Never edit
an applied migration or mark it applied by hand. Re-running unchanged,
successfully applied migrations performs no new module writes.

Sign in and open **Sales -> Leads**, or `<application>/public/sales/leads`.
Admins have all registered Leads operations automatically. For a non-admin:

1. As an admin, open **Settings -> Users & permissions**.
2. Select the user; under Sales / Leads select the required grants.
3. Save, then sign in again as that user (permission edits invalidate existing
   sessions and API tokens).
4. Open Leads and exercise the allowed operations. No grants are seeded for
   ordinary/training users.

`/ready` checks foundation readiness, not Sales migration acceptance. Verify
the Leads API and pages after deployment. No remote migration or live Oracle
verification is implied by local fixture tests.

## 2. Agree the data before writing code

| API field | Rules | Example |
| --- | --- | --- |
| `contact_name` | Required, 1-150 characters after trimming; input limit 150 | Alex Smith |
| `business_name` | Optional, at most 150 characters | Example Distribution |
| `email` | Optional, valid email, at most 254 characters | alex@example.test |
| `phone` | Optional text, at most 50 characters; preserves `+` and leading zeros | +44 00123 |
| `source` | `website`, `referral`, `event`, `other`; default `other` on create | website |
| `status` | `new`, `contacted`, `qualified`, `lost`; default `new` on create | new |
| `notes` | Optional plain text, at most 1000 characters | Requested a callback |

At least one of email or phone is required. Both may be present. Neither is
unique: people may share contact details. All statuses can be edited directly.
Use synthetic contacts in training, not real personal data.

Inputs must be strings, including optional fields; omit optional fields on
create or supply `""`. `null`, unknown fields and client-supplied company/user,
reference, archive flag or version fields are rejected. Use the named actions
for archiving/restoring. PATCH preserves omitted fields; `""` clears an optional
field, but cannot leave the lead without email and phone.

Server-managed fields:

- `id`: random 32-character lowercase hexadecimal ID.
- `lead_reference`: `LD-` plus the uppercase ID, unique within the company.
- `archived`: boolean, initially false; independent of business status.
- `current_version`: positive integer, initially 1.
- `created_at`, `updated_at`: UTC timestamps.
- `created_by`, `updated_by`: authenticated user IDs.
- `COMPANY_ID`: derived only from Actor; not accepted as request input.

## 3. Design and migrate the table

[The migration](../database/migrations/002-sales/001_leads.php) creates
`ME_SAL_LEADS`, its indexes and permission definitions.

- Primary key: `(COMPANY_ID, ID)`.
- Unique lead reference: `(COMPANY_ID, LEAD_REFERENCE)`.
- Company FK plus compound creator/updater FKs prevent cross-company links.
- Checks constrain contact presence, source, status, archive flag and version.
- Character-size columns support Unicode independently of Oracle's default
  byte/character setting.
- List index starts with company/archive scope; creator/updater indexes support
  the compound user references.
- No Customer FK: this blueprint deliberately has no peer-module dependency.

Keep schema changes in new numbered files. Migration registration is
metadata, not code generation: the service, endpoints and screens still need
implementation and tests.

For a new module, add a unique `NNN-component` directory under
`database/migrations/` and place its numbered PHP migrations directly inside.
For example, `003-inventory/001_products.php` is picked up by the same `migrate`
command with no CLI code change. The sequence orders folders; the suffix is the
stable ledger identity. Agree ordering with the maintainer, especially for
cross-module integration migrations.

## 4. Register and enforce permissions

The object code is `sales.leads`.

| Operation | Allows |
| --- | --- |
| `read` | List/search and view active or archived leads in the signed-in company |
| `create` | Create a lead |
| `update` | Edit active leads |
| `archive` | Archive an active lead |
| `restore` | Restore an archived lead, preserving business status |

There is no `delete` grant or endpoint. Update does not imply archive or
restore. Administrators do not bypass company scope, validation or versions.

Every public [LeadService](../src/sales/LeadService.php) operation authorizes
before data access or replay. The API also checks route metadata before calling
the service. UI visibility uses the same registered grants, but is not the
security boundary.

Browser detail/edit/archive/restore screens need `read` to display the lead,
plus the relevant write grant. Create-only users can open **Sales -> Create
lead** and receive a confirmation without being redirected to a forbidden
detail screen. REST mutations require their write grant, not an additional
read grant. All granted users operate company-wide; creator IDs are audit
information, not ownership restrictions.

## 5. Implement and verify the service/API first

The application service owns each standalone Leads transaction.
[LeadService](../src/sales/LeadService.php) uses bound SQL, explicit company
predicates and the shared helpers:

1. Authorize the requested operation.
2. Validate input, ID and action-reference/version types.
3. Use [ActionExecutor](../src/shared/ActionExecutor.php) to claim the action.
4. For existing leads, lock the company-scoped row with `FOR UPDATE`, recheck
   its version/state, validate the merged PATCH and increment its version.
5. Persist the business write, action result and audit event in one transaction.
6. Roll back on any business/database failure, including audit failure.

Each write requires `action_reference`: 1-100 letters, digits, underscores or
hyphens. Existing-record writes also require positive JSON integer
`expected_version`. Supply a new reference for a new action. After an uncertain
result, retry the same reference, body and version unchanged.

Successful replay returns the original result before rechecking today's row
version. Permission is checked again first. A changed payload, actor or operation
with the same company-scoped reference conflicts. References share the company
action namespace with other modules, so use descriptive unique values.

The generic executor no longer requires the note example's `trainee` role;
that check remains in NeutralNote. Callers must authorize before invoking the
executor and supply their event name. Leads writes audit `LEAD_CREATE`,
`LEAD_UPDATE`, `LEAD_ARCHIVE` or `LEAD_RESTORE`. The shared audit/action storage
retains request data/results; treat it as contact data, restrict database access
and apply an approved retention policy rather than logging contacts to consoles.

[sales/api.php](../src/sales/api.php) registers these routes under `/api/v1`:

| Method | Path | Permission |
| --- | --- | --- |
| GET | `/sales/leads` | read |
| GET | `/sales/leads/{id}` | read |
| POST | `/sales/leads` | create |
| PATCH | `/sales/leads/{id}` | update |
| POST | `/sales/leads/{id}/archive` | archive |
| POST | `/sales/leads/{id}/restore` | restore |

List query parameters: `page` (default 1), `page_size` (default 25, max 100),
`search` (up to 150 characters), `source`, `status`, and `archived`
(`false` default, `true`, `all`). Search treats `%` and `_` literally across
reference, name, business, email and phone. Ordering is newest first with ID
as a stable tie-breaker. Result: `{items, page, page_size}`. No total count is
returned; a full page permits a next-page request which may be empty.

Create:

```json
{
  "contact_name": "Alex Smith",
  "email": "alex@example.test",
  "source": "website",
  "action_reference": "leads-training-create-001"
}
```

HTTP 201 uses the standard envelope; result contains `id`, `lead_reference`
and `current_version: 1`. Detail returns the fields in section 2. PATCH:

```json
{
  "status": "contacted",
  "notes": "Synthetic follow-up",
  "expected_version": 1,
  "action_reference": "leads-training-edit-001"
}
```

Archive/restore accept only `expected_version` and `action_reference`.
Their result and PATCH result contain `id` and `current_version`. All writes
return the action reference as `operation_id`. Errors use the existing envelope:
400 validation, 401 authentication, 403 permission, 404 missing company-scoped
lead, 409 stale version/state/replay conflict, 415 non-JSON write, 503 database.
The framework's 8 KiB JSON body limit still applies.

The [Bruno Leads folder](../examples/bruno/leads) includes all six requests.
Run Login first; use synthetic data in an approved sandbox and send individually.
Create selects `target_lead_id` only on success. A failure leaves any previous
selection unchanged: confirm the selected ID before each mutation. To select an
existing lead, clear the runtime selection and set its ID explicitly in the
environment. Read detail for the latest version. Prompts make these interactive
samples, not an automated write suite. Never run the whole collection blindly.

## 6. Add the UI without duplicating business rules

| Location | Responsibility |
| --- | --- |
| [Kernel](../src/shared/Kernel.php) Leads handlers | Browser authentication, CSRF, routing and presentation data |
| [Renderer](../src/shared/view/Renderer.php) | Mounted application URLs and escaped Smarty layout |
| [Sales entry](../views/sales/index.tpl) | Permission-aware links to list/create |
| [Lister](../views/sales/leads/list.tpl) | Server-side filters, pagination and detail links |
| [Form](../views/sales/leads/form.tpl) | Create/edit fields, required attributes and visible errors |
| [Detail](../views/sales/leads/detail.tpl) | Lead facts, edit and versioned archive/restore controls |
| [Browser JavaScript](../public/assets/app.js) | JSON submission, CSRF, duplicate-submit guard and retry feedback |

Page routes are `/sales/leads`, `/sales/leads/new`, `/sales/leads/{id}` and
`/sales/leads/{id}/edit`. Browser writes use the cookie-authenticated,
CSRF-protected `POST /api/sales/leads` adapter with an explicit operation.
It calls the same service as bearer REST; it does not call REST over HTTP.
JavaScript is required for writes; forms use POST rather than leaking contact
data into URLs if JavaScript is unavailable. Lead filter terms do appear in
GET URLs: avoid putting sensitive contacts in saved/shared links.

Keep credentials/tokens out of JavaScript storage. Render contact/notes as
escaped text. An uncertain network/server response retains the original
request; changed input cannot be silently retried as that same pending action.
The complete example contains no Customer/Order conversion or stock effects.

## 7. Review and test before copying the pattern

Write a failing test before implementing each new behavior. Relevant tests:

- [Service tests](../tests/unit/LeadServiceTest.php): validation, Unicode limits,
  tenant boundaries, PATCH merging, versions, archive/restore, replay and rollback.
- [API tests](../tests/unit/ApiFrameworkTest.php): all six route permissions,
  bearer requirement, input rejection, creation status and envelope.
- [UI handler tests](../tests/unit/LeadUiTest.php): mounted links, escaping,
  permission-aware controls, session/CSRF and create-only access.
- [Browser tests](../tests/browser/leads.spec.js): actual Smarty/JavaScript
  behavior with mocked network responses, not live Oracle acceptance.
- [Oracle tests](../tests/oracle/OracleFoundationTest.php): opt-in persistence,
  replay, audit, stale versions, Unicode and company isolation.

Focused local commands (use your PHP 8.4 binary):

```bash
php84 vendor/bin/phpunit tests/unit/LeadServiceTest.php tests/unit/LeadUiTest.php tests/unit/ApiFrameworkTest.php tests/unit/ActionTest.php tests/unit/ConsoleTest.php
php84 bin/lint.php
npm run check:js
node --test --test-name-pattern='lead samples' tests/unit/bruno-collection.test.cjs
PHP_BINARY=php84 npx playwright test --config playwright.local.config.js tests/browser/leads.spec.js
```

On an explicitly approved disposable `_TEST` schema, apply shared and Sales
migrations and required foundation test seeds first, then:

```bash
LAB_ORACLE_TEST=1 ORACLE_TEST_SCHEMA=YOUR_TEAM_TEST php vendor/bin/phpunit --testsuite oracle
```

The new Leads Oracle test creates isolated company fixtures and removes its
rows afterward; the wider suite also requires the existing foundation fixtures.
A skipped Oracle test is not acceptance. There is no legacy XML API in this
project; REST and browser/service paths are the applicable channels.

Before handover, verify at least: valid create; missing contact method; invalid
email; maximum/over-limit Unicode input; filtered/paged list; partial update;
stale update; archive; archived-edit rejection; restore; unchanged retry;
changed-payload retry conflict; denied grants; forged company/actor input;
cross-company access; rollback on failure; escaped UI values; CSRF rejection.
Test real Oracle locks/rollback and repeatable migrations on the disposable
schema before accepting a remote deployment.
