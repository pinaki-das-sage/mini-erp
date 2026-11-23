# API-02 Customer metadata

## Customer schema update — 23 November 2026

This entry supersedes the database-column and balance-provider descriptions below. CustomerService now requires Sales migration `004_oe_customer_schema_update.php`: public `id` maps to `RECORD#` (up to 15 digits), and authenticated company scope maps to `CNY#`. Shared security, action and audit tables retain their existing column names. Public request/response names and Customer URLs are unchanged.

Reads return `AMOUNT_CUSTOMER_OWES_US` from the saved row, formatted with two decimals using string operations. There is no injected balance provider or provisional zero fallback. Missing or invalid balances fail with `BALANCE_UNAVAILABLE`; Customer create/update requests cannot set the balance. Invoice/Payment balance maintenance remains future work.

Creation uses the database defaults for balance and timestamps. Metadata updates set `WHENMODIFIED = CURRENT_TIMESTAMP`, preserve `WHENCREATED` and the balance, and reread the saved row before persisting the action result. Authorization, company isolation, row locking, audit and safe retry remain in force. Identical retries return their original result without changing timestamps again.

Deploy the migrated schema and this backend together with application traffic stopped. The updated backend does not support the pre-004 Customer schema. Oracle acceptance tests require all pending Sales migrations in an explicitly enabled disposable test schema; local unit checks do not prove real Oracle persistence or concurrency.

## Status

Implementation entry, updated 20 November 2026: [CustomerService](../CustomerService.php), [CustomerActions](../CustomerActions.php) and five [routes](../api.php) are implemented, including the POST update alternative. This entry supersedes older field names and sequence/revision descriptions below. The actual contract uses numeric `id`, immutable business `customer_code`, `is_active` (`T`/`F`), `search`, `page` and `page_size`. Local implementation work has not applied migrations; the requester reported successful Shared/Sales migration application on the remote schema CRE26R48_OWNER_01.

### Write protection and deployment prerequisites

- `POST /api/v1/sales/customers/{id}/update` is available alongside PATCH while the hosting restriction on PATCH is investigated. Both routes call the same update service, require the appropriate permission, and accept the same JSON fields and `action_reference`. With an explicit reference, identical retries across either route return the original result without another write or audit entry; changed payloads with the same reference conflict. No migration is needed for this route addition.
- Apply the shared foundation migrations and Sales migrations `001_oe_permissions.php`, `002_oe_customers.php` in the intended test schema before running these endpoints. Use `php bin/console.php migrate --module=sales --confirm-schema=YOUR_TEST_SCHEMA`; this validates the target and runs pending Shared migrations before Sales. The default command remains Shared-only.
- The existing Customer migration uses Oracle identity generation, `CUSTOMER_CODE`, and `IS_ACTIVE`. Customers intentionally have no VERSION column or revision token. The unapplied version migration has been removed. Applied migration checksums must never be silently changed.
- All service methods enforce the appropriate permission and password-change restrictions before data access. Every Customer lookup/write uses authenticated company identity; foreign-company IDs return generic 404.
- Customer create, PATCH and POST update accept an optional `action_reference`. Omit it for ordinary requests; the server generates an internal random reference. Without a supplied reference, each request executes independently and the response envelope has `operation_id: null`. Clients needing safe retries should generate a UUID per new operation and reuse it only with the same input. PATCH requires at least one editable field and no version input. Customer records contain no `current_version`; the shared response envelope keeps `current_version: null`. PATCH locks the Customer and changes only supplied fields; the last committed change to a field wins.
- CustomerActions adapts the shared note executor into a Customer-specific transaction coordinator. It has no trainee-role requirement. Fingerprints include the operation and target, authorize before replay, return an identical committed result without repeating the write, and reject changed input or another actor with 409.
- Customer changes, retry claims/results and audit commit together. Failure rolls back all three. Audit details contain the numeric Customer ID, excluding contact/address payloads. Replay result storage necessarily contains the original API result.
- PATCH preserves omitted fields, clears optional text with an empty string, rejects null, and changes activation through `is_active`. Codes remain case-sensitive and unique per company. No Customer delete is registered.
- Search is a case-insensitive literal substring of Customer Code or Name, with SQL wildcard characters escaped. It combines with `is_active`; omitted activation includes both values. Ordering is Customer Code then numeric ID.

### Balance limitation

The optional injected balance provider is the integration boundary for API-06/API-07. Without a provider, this metadata-only implementation returns `0.00`; this is provisional and is not financial acceptance. Do not use it as an authoritative balance once invoices/payments are enabled. The provider must return a nonnegative exact decimal string with 16 whole digits and 2 decimals; invalid values reject the operation. No balance column is stored or writable.

### Verification for this implementation

[CustomerServiceTest](../../../tests/unit/sales/CustomerServiceTest.php) covers persistence behavior through a transactional double, partial updates, defaults, exact text validation, company isolation, admin enforcement, duplicate/retry conflicts, successive updates without version tokens, audit-failure rollback, literal search bindings and the four bearer routes/envelopes. These tests do not prove Oracle SQL or real concurrent locking.

[OracleCustomerTest](../../../tests/oracle/sales/OracleCustomerTest.php) prepares a gated acceptance scenario for real identity/default/unique constraints, commit visibility, retries, successive field updates, filtered reads, isolation and rollback. It requires explicitly enabled `LAB_ORACLE_TEST=1`, an `ORACLE_TEST_SCHEMA` ending in `_TEST`, matching credentials, all migrations and an active non-temporary admin. It cleans up only its generated fixtures. It has not been executed against Oracle by this work; simultaneous-session contention remains to be verified.

Verification recorded on 19 November 2026:

- Customer, Sales rules, action and migration unit checks: **190 tests, 519 assertions passed**, including 29 Customer cases. Command: `php vendor/bin/phpunit --filter 'CustomerServiceTest|SalesRulesTest|MigrationTest|ActionTest'`.
- `php bin/lint.php`: passed for all project PHP files.
- Swagger: parsed with unique-key validation; all 374 local references resolved.
- The broader unit run before the last two Customer cases passed 309 of 310 tests. The existing `ApiFrameworkTest::testVersionedHttpRequestsDoNotStartBrowserSessions` fails when its Windows `php -r` subprocess loses quoting and exits 255 before loading the application. This test was not changed.
- The local Herd INI references unavailable extensions and a missing auto-prepend loader. Checks used a temporary workspace PHP INI with mbstring; no installed PHP configuration was modified.
- Oracle Customer test: skipped because the explicit test-schema opt-in was absent. No database connection or migration application was performed.

### Historical contract notes

The remaining notes record the earlier draft and its validation history. For executable field names, revision behavior and migration structure, use the implementation entry above and current Swagger.

## Agreed public API

Paths are relative to `/api/v1`. Every operation requires bearer authentication and the appropriate permission in the authenticated company, as defined in API-01.

| Method | Path | Purpose |
| --- | --- | --- |
| POST | `/sales/customers` | Create Customer metadata; HTTP 201 |
| GET | `/sales/customers` | List, search by Customer ID/name and filter status |
| GET | `/sales/customers/{id}` | Read an Active or Inactive Customer by generated numeric ID |
| PATCH | `/sales/customers/{id}` | Update details, deactivate or reactivate by generated numeric ID |
| POST | `/sales/customers/{id}/update` | Same partial update as PATCH, for hosts restricting PATCH |

`id` is a server-generated positive integer, returned on Customer reads/creates/updates and used in URLs such as `/sales/customers/1`. Never send it on create or update. Generation starts at 1 and advances automatically; gaps are possible. Resolve every ID within the authenticated company; an ID from another company returns 404.

`customer_id` remains the user-entered business identifier from the requirements: at most 20 characters, letters/numbers/hyphens, unique within the company and immutable after first save. Responses include both identifiers; there is no `customer_code` field. Existing document requests and filters continue to use the business `customer_id`. No Customer DELETE or separate status endpoint is provided.

## Field rules

| Field | Required on creation | Limit or values |
| --- | --- | --- |
| id | Generated; never input | Positive integer, maximum 9999999999; read-only URL identifier |
| customer_id | Yes | 20 characters; company-unique and immutable |
| customer_name | Yes | 100 characters |
| status | Populated; default ACTIVE | ACTIVE / INACTIVE |
| contact_name | No | 100 characters |
| email_address | No | 254 characters; validate if nonempty |
| phone_number | No | 25 characters; digits, spaces, +, -, parentheses |
| billing_address_line_1 | Yes | 150 characters |
| billing_address_line_2 | No | 150 characters |
| city | Yes | 80 characters |
| state_province | No | 80 characters |
| postal_code | No | 20-character string; preserve leading zeros |
| country | Yes | Free-text country name, 80 characters |
| payment_terms | Populated; default NET_30 | DUE_ON_RECEIPT / NET_15 / NET_30 |
| notes | No | 500 characters |
| amount_customer_owes_us | Calculated; never input | Decimal string; 16 whole digits plus 2 decimals; default 0.00 |

Trim free text before required/length validation. If Status or Payment Terms is omitted at creation, apply the stated default; explicitly blank or invalid values are rejected. Use exact error messages from the requirements, as recorded in Swagger validation/conflict examples.

## Partial updates and reads

- PATCH accepts editable metadata and `status`. Omitted fields stay unchanged. Empty strings clear optional text; null is rejected. At least one editable field must be present. A supplied Customer ID change is rejected with the exact immutable-ID message; a supplied balance is rejected with its calculated-field message.
- Customer writes accept optional `action_reference` for safe retries; without it requests execute independently. Customer PATCH has no revision token. Authorize before replay, then return an identical committed result without repeating the update. Different input with the same reference conflicts. Accepted updates commit changes, audit and retry result together.
- To deactivate, PATCH with `status: INACTIVE`; to reactivate, PATCH with `status: ACTIVE`. Preserve historical references and allow payments on already Submitted Invoices even after deactivation.
- The list supports `q`, `status`, `page` and `page_size`. Search is a case-insensitive literal substring of Customer ID or Customer Name; filters combine. Omitted status includes both statuses. New-selection dropdowns must request `status=ACTIVE`. Sort by Customer ID. Defaults are page 1 and size 25; maximum size 100.
- Single-record responses include saved Customer metadata and calculated balance. List response result contains items/page/page_size. Customer records have no version field; the shared envelope uses `current_version: null`. Optional text is returned as an empty string when unpopulated.

## Balance acceptance

Derive the Customer balance across all Submitted Invoices less their actual recorded payments; Draft Invoices do not contribute. Never accept an editable balance, opening balances, credits or refunds.

| Action | Amount Customer Owes Us |
| --- | --- |
| Submit Invoice 1000.00 | 1000.00 |
| Record payment 300.00 | 700.00 |
| Record payment 700.00 | 0.00 |

Full verification of this calculation requires Invoice and Payment integration in API-06/API-07. A permanent hard-coded zero would not satisfy the requirement.

## Implementation follow-up

The specification was shortened from 1174 to 508 lines. Customer field definitions and retry-reference validation are shared, only two small Customer write examples remain, and exact validation messages are listed once. Operation descriptions focus on the business action; the Customers tag records shared access and inactive-Customer behavior. Transaction, audit, retry and detailed search behavior are documented here instead of repeated in Swagger. Authentication operations and schemas are preserved. Create/update use shared fields with OpenAPI 3.1 unevaluatedProperties to reject unsupported input without repeating every field.

Earlier local checks covered all Customer text limits and representative rejected inputs. After the final cleanup, checks confirmed YAML with unique keys, 57 resolved references, unchanged expanded schema constraints, endpoints, status codes, required fields, exact business error messages and bearer security. Authentication operations and non-Customer schemas were compared with the pre-edit versions and preserved. Git whitespace checks passed. These are documentation checks, not HTTP or Oracle acceptance.

The 19 November revision replaces the earlier business-key primary key with generated `ID NUMBER(10)` as primary key. `ME_OE_CUSTOMER_SEQ` starts at 1 and supplies the column default. A unique constraint on `(COMPANY_ID, CUSTOMER_ID)` keeps business identifiers unique within each company and remains the target for proposed document Customer foreign keys. Company ownership comes from authentication; the same business Customer ID can exist in different companies. Exact-case uniqueness follows the current draft; case-insensitive uniqueness remains a decision to settle before persistence implementation.

The migration remains unapplied. It prepares the Customer table and numeric ID sequence; the service must enforce business Customer ID immutability, tenant-scoped numeric lookups. No deployed table or data was changed by this draft edit.

The revised [Customer migration](../../../database/migrations/sales/002_oe_customers.php) passed PHP syntax and read-only statement-loading/identity checks. Oracle constraint and persistence verification remain pending until the migration is applied in the intended test database.

Implement and verify admin enforcement, tenant-scoped lookup, uniqueness/immutability, validation, source history preservation, retry/rollback behavior and status filtering after the contract review. Swagger examples document expected behavior; they do not make the Customer operations executable.

## Simplification approved on 20 November 2026

Customer versioning was removed at the requester's direction. No expected_version is accepted and no Customer current_version is emitted. Updates remain partial and transactional, with audit, row locking and safe retries. Historical verification counts above refer to the 19 November implementation. No database migration was applied or reversed.

The updated Customer, Sales rules, action and migration unit checks passed on 20 November: 190 tests, 519 assertions. Customer tests verify successive field updates without a revision token, original-result retries, unsupported version input, rollback and the shared envelope's null version. Swagger parsed with all 374 references resolved; document revision requirements remain intact.

## POST update alternative, 20 November 2026

Added POST `/sales/customers/{id}/update` alongside the existing PATCH route after the requester observed hosting-layer HTML 403 responses to PATCH and successful GET requests. Remote support for POST update still needs verification after deploying `src/sales/api.php` and `public/openapi.yaml`. No Customer service or database changes are needed. The Customer test file passed 30 tests with 219 assertions, including POST update, PATCH compatibility, replay across methods, changed-payload conflicts, validation, missing records and admin/bearer enforcement. Syntax and Swagger checks passed; these are local checks, not a claim that the remote hosting restriction is resolved.

### Optional Customer action references - 20 November 2026

Swagger examples now omit action_reference for Customer create, PATCH and POST update, and the Customer schemas no longer require it. CustomerService generates a fresh random internal reference when the field is absent; explicitly supplied references keep existing validation and retry behavior. Unkeyed requests execute independently, so they do not provide replay protection. No database migration is required. Sales unit suite passed: 226 tests, 836 assertions. Changed PHP syntax checks and OpenAPI YAML parsing passed.
