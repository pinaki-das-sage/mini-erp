# Sales and Order Entry development

Keep Sales and Order Entry implementation notes, changes, test evidence and outstanding work in this folder. Shared framework documentation remains under the repository's top-level `docs` folder.

## Development records

| Story | Record | Current status |
| --- | --- | --- |
| API-01 | [Permissions and common rules](api-01.md) | Shared per-user grant helper and common rules implemented; no separate API-01 endpoint; endpoint wiring and persistence verification pending |
| API-02 | [Customer metadata](api-02.md) | Customer service and five routes implemented, including POST update alongside PATCH; numeric id, customer_code, is_active and atomic audit/retries; no Customer version token; full Oracle verification and financial balance integration pending |
| API-03 | [Sales Quote contract](api-03.md) | Swagger draft for Draft/Submitted lifecycle and complete lines; backend, deployment and database verification pending |
| API-04 | [Sales Order contract](api-04.md) | Swagger draft for standalone/Quote-linked Orders and source lifecycle; backend, deployment and database verification pending |
| API-05 | [Shipper contract](api-05.md) | Swagger draft with Order sources, Carrier/date rules and Logistics submission authorization; backend and integration verification pending |
| API-06 | [Sales Invoice contract](api-06.md) | Swagger draft with Shipper sources, Due Dates, payment summaries and balance lifecycle; backend and payment integration pending |
| API-07 | [Customer payment contract](api-07.md) | Swagger draft for payment recording/history, current-due protection and safe retries; backend and Oracle verification pending |

The [OE design](../../../docs/oe-design.md) describes the business workflow. Physical schema and Swagger contracts are separate artifacts; helper classes do not establish approved contracts or a completed API.

The [API contract summary](../../../docs/sales-api-contract-summary.md) lists all Swagger methods, required and optional inputs, outputs, errors, and proposed migration mappings.

The [simplified database design](../../../docs/sales-and-order-entry-db-design.md) contains five table definitions for approval, with calculated values and relationship joins described separately. It is a proposal to reconcile with the earlier ERD and migration draft after review.

The 20 November review revision uses `(CNY#, RECORD#)` on every Sales table, company-scoped foreign keys and index coverage, `CHAR(1)` flags, and a stored, server-maintained Customer balance. Customer runtime/migration alignment with this physical naming is a separate follow-up.

The [Sales flow and database walkthrough](../../../docs/sales-linked-flow-db-walkthrough.md) follows one linked example from Customer through payment, showing stored rows, copied line identities, Inventory reservation requests and the Logistics handoff at each step.

## Sales tests

Sales-owned tests live in a `sales/` subfolder inside each existing test type:

- `tests/unit/sales/`: CustomerServiceTest.php and SalesRulesTest.php.
- `tests/oracle/sales/`: OracleCustomerTest.php, gated behind the existing disposable Oracle test-schema opt-in.
- `tests/browser/sales/`: reserved for Sales Playwright specs; no Sales browser scenarios exist yet.
- `tests/apache/sales/`: reserved for Sales Apache integration tests; no Sales Apache scenarios exist yet.

The existing PHPUnit `unit`, `oracle` and `apache` suites discover their Sales subfolders recursively. Run Sales unit tests alone with `php vendor/bin/phpunit tests/unit/sales`. Local Playwright discovers Sales browser specs through `playwright.local.config.js`.

Sales migration CLI tests are in `tests/unit/sales/ConsoleMigrationTest.php`. Run `php bin/console.php migrate --module=sales --confirm-schema=YOUR_TEST_SCHEMA` against the reviewed test schema to apply pending Shared migrations, then Sales. The default command remains Shared-only; this implementation work has not applied either component to Oracle.

Verification on 20 November 2026: the ConsoleMigrationTest, shared ConsoleTest and MigrationTest checks passed (32 tests, 211 assertions). Coverage includes Shared-before-Sales ordering, the Shared-only default, completed migration skipping, invalid options, target verification, and Shared failures preventing Sales execution. PHP syntax checks passed for the CLI and test files. These checks use a connection double, not a live Oracle schema.

## Recording future changes

Add one development record per story and link it from the table above. Update the matching record whenever its implementation changes. Include:

- What changed and why, with links to the affected source, migration and test files.
- Permission mappings, service behavior and dependencies that callers need to understand.
- Migration status, including whether files are drafts or have been applied to a named environment.
- Verification commands and results, with remaining failures or integration gaps.
- Pending work and decisions needed before the story can be considered complete.

Keep implemented behavior separate from planned work. Record later verification results as new dated entries so earlier evidence remains understandable. Do not put credentials, tokens, private configuration or Customer/payment payloads in these records.
