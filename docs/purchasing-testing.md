# Purchasing API and database verification

Document entry `product_id` is now a positive JSON integer, from 1 through
9999999999 (for example, `"product_id": 1001`). Apply the forward purchasing
migration with `php bin/console.php migrate purchasing` before deploying this
API change. Migration `005_numeric_product_id.php` converts the column to
`NUMBER(10)` and rejects existing nonnumeric, zero, negative, or oversized values
before changing the table. Stop purchasing writes and back up the table first;
Oracle DDL commits each step, so restore the backup if conversion is interrupted.
Existing applied migration files remain unchanged.

The successful migration output verifies that the migration runner applied the
purchasing statements. It does not verify API behavior or business rules.

Purchasing routes use a small MVC boundary: src/purchasing/api.php registers the
routes, controllers validate requests, and models read/write Oracle through the
shared Connection. Vendor list/detail/create/update are implemented. The vendor
list reads ME_PUR_VENDOR for the authenticated company and supports pagination,
search and status filters. Document list/detail/create/update use ME_PUR_DOC as
the shared header table for order, receiver and invoice records, distinguished by
RECORDTYPE. Lines are stored in ME_PUR_DOC_ENTRY. Entry list/detail reads are
available; entry create/update operations are performed through the document
endpoints. The existing migration set registers only vendor read permission. Vendor
create/update and document/entry routes also require their operation metadata in
ME_OBJECTS before they can pass the API authorization guard. The schema must
enforce uniqueness for (CNYID, VENDOR_ID), (CNYID, DOCNO), and
(CNYID, DOC_ID, LINENO); the current table definitions do not. Resolve duplicate
values and arrange those database changes in the approved test schema before
testing writes. No migration files were changed for this API wiring.

This first database check preserves the current VARCHAR2 IDs and Oracle timestamp
strings. Full OpenAPI response parity (integer IDs and timestamp formatting) is
still pending. The all-endpoint Python smoke check will continue to fail for
document and entry endpoints until they are implemented.

## Database inspection

From SQLcl or SQL*Plus, connected as the application schema owner, run:

~~~sql
@tests/oracle/purchasing-inspect.sql
~~~

The script reads metadata and checks for duplicate keys and orphaned rows.
Expect purchasing versions to be APPLIED, three tables, three enabled composite
primary keys and two enabled composite foreign keys. Empty tables passing the
data queries do not prove constraints reject invalid writes.

| Area | OpenAPI expectation | Current purchasing DDL |
| --- | --- | --- |
| Internal IDs | Integer IDs | VARCHAR2(40), with no generated default |
| Vendor email | Up to 254 characters | VARCHAR2(100) |
| Vendor postal code | Up to 20 characters | VARCHAR(10) |
| Vendor business ID | Unique per company | No unique constraint on (CNYID, VENDOR_ID) |
| Entry line number | Unique per document | No unique constraint on (CNYID, DOC_ID, LINENO) |
| Required vendor fields | Name, address, city and country required | These columns are nullable |

Also verify company ownership, status values, positive quantities, precision,
generated audit fields and defaults in the eventual services. Company columns
have no FK to ME_COMPANY; the product FK is deferred.

Resolve agreed schema changes with new forward migrations. Preserve applied
files and checksums. Shared 006_drop_purchasing.php drops all purchasing tables:
never use it to reset test data or edit migration history to rerun it.

## Live read-only HTTP smoke checks

Open <deployed-public-directory>/swagger.html.

1. Execute POST /auth/login with your company, username and password.
2. Copy result.access_token into Authorize (the token alone).
3. Execute GET /auth/me and confirm your identity and permissions.
4. Execute the three purchasing list requests. Expect HTTP 200, success true,
   an array result and error null. A 404 identifies missing routes/deployment;
   403 identifies insufficient permission or a required password change.

Swagger sends real HTTP requests; references to dummy IDs in the YAML do not seed
records or enable a mock backend. Use IDs actually returned by POST.

For repeatable GET checks, run locally with Python 3:

~~~bash
python3 bin/test-purchasing-api.py https://your-host/path/to/mini-erp/public
~~~

Paste the token at the hidden prompt, or supply PURCHASING_TEST_TOKEN through
your local secret environment. The script checks identity, authenticated lists
and rejection of unauthenticated lists. It exits nonzero on failure, verifies
HTTPS certificates, refuses redirects and prints no tokens or response bodies.
It does not create records or verify writes.

## CRUD acceptance in an isolated test company/schema

Use Swagger examples as request bodies after agreeing schema differences.
Use a unique vendor business ID per run. After each write, verify through GET and
SELECT from the same company; Swagger example responses are not test evidence.

| Case | Expected result and database check |
| --- | --- |
| Create valid vendor | 201; generated identity/company/audit values; GET matches persisted inputs |
| Duplicate vendor business ID in same company | 409; exactly one persisted vendor |
| Invalid email/phone, blank required fields, null or oversized input | 400 identifying the field; no row inserted |
| Update permitted vendor fields | 200; GET and database show new values; business ID unchanged |
| Create document with two entries | 201; one header and two linked entries; correct rounded amounts and total |
| Invalid second entry | 400; no header or entries persisted; existing records unchanged |
| PATCH existing entry description/notes and add another entry | 200; correct existing ID retained and new line created atomically |
| Invalid nested PATCH | 400; header and every entry unchanged |
| Read document and entry by returned IDs | 200; correct company and parent |
| Valid token lacking purchasing permission | 403; no data changes |
| Company B reads or updates company A's IDs | No disclosure or mutation of A's data |
| Missing/expired token | 401 |
| Write without application/json | 415; no changes |

In a dedicated disposable Oracle test schema, additionally attempt duplicate
primary keys, orphan document/entry references and references across companies;
expect constraint rejection. Roll back test DML. Concurrent duplicate-vendor
creates and duplicate-line inserts also need tests once uniqueness enforcement
exists. Do not run DDL resets on the migrated training schema.

## Existing foundation checks

On supported PHP 8.4, with locked dependencies installed:

~~~bash
composer install --no-interaction --prefer-dist
php bin/lint.php
php vendor/bin/phpunit --testsuite unit
~~~

These are foundation checks, not purchasing CRUD coverage. The existing Oracle
suite requires its explicitly configured disposable _TEST schema; do not point
it at CRE26R48_OWNER_01 to bypass that guard.
