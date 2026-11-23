# API 01 permissions and common rules

Permission policy updated on 20 November 2026: non-admin Sales access comes only from explicit per-user grants. Roles and job titles provide no access. Admins retain access to supported, active registered operations within their own company. API-01 is a common policy, not a separate endpoint.

## Implementation

- SalesPermissions accepts shared Authorization and maps service actions to registered object/operation checks. Unknown actions remain rejected.
- CustomerService checks permission before business reads, validation, writes or replay. CustomerActions also checks the actual create/update event before claiming or replaying an action reference.
- The shared API router checks the same registered permission before invoking a service. Both Customer update routes use sales.customers:update.
- Shared Authorization enforces temporary-password restrictions, active registration, supported operations and company/user grants. No role-based fallback is used.
- DocumentRules and Validation retain company, lifecycle, revision, source, field and value checks. Permission does not bypass any business rule.

| Service action | Shared permission |
| --- | --- |
| Customer create/read | sales.customers:create / sales.customers:read |
| Customer edit/deactivate/reactivate | sales.customers:update |
| Document create/read/edit/delete | sales.<document plural>:create/read/update/delete |
| Document submit/return_to_draft | sales.<document plural>:submit/return_to_draft |
| Payment create or Invoice record_payment | sales.payments:record |
| Payment read | sales.payments:read |

Customer deletion and payment editing/deletion remain unsupported. Customer activation changes use the existing update grant. Read is a separate grant; create/update does not implicitly grant list/open access.

## Registration and grants

001_oe_permissions.php defines the original objects and payment record action. The entire file is preserved to keep previously recorded migration checksums valid; its historical admin-only comments are superseded by this policy. New migration 003_oe_document_actions.php registers submit and return_to_draft for Quotes, Orders, Shippers and Invoices. Neither migration assigns user grants. Do not rewrite previously applied SQL migrations.

Apply pending migrations in the reviewed target schema using:

~~~powershell
php bin/console.php migrate --module=sales --confirm-schema=YOUR_TEST_SCHEMA
~~~

Then an admin uses Settings ? Users ? permissions to assign each user's required operations. Newly created non-admin users start without business grants. Registration makes operations available in that existing editor; it does not generate endpoints.

## Integration requirements

Construct the helper with new SalesPermissions(new Authorization($connection)) and use a trusted authenticated Actor. Authorize before business access and before returning any replayed result. Scope every business query/write to Actor::companyId; grants do not authorize another company's rows. Keep changes, audit and replay results in one transaction.

Customer APIs are implemented. Quote, Order, Shipper, Invoice and Payment services remain planned; their future handlers must use the same checks. Shipper submission additionally needs the Logistics-owned dispatch authorization described in API-05, for both standalone and linked Shippers. This helper does not implement that provider.

## Verification

SalesRulesTest covers permission mapping for every supported action, roles without grants, admins, temporary-password restrictions, unsupported actions and existing business guards. CustomerServiceTest covers create-only grants, independent read/update access, company/user scoping, revocation before replay and both update routes. ConsoleMigrationTest checks registration order and migration replay.

Run the Sales unit tests, project lint and broader unit suite with PHP 8.4 and the locked dependencies. Real Oracle migration execution, locking and rollback acceptance still require the intended test schema. No database migration was applied as part of this permission change.

Verification on 20 November 2026 (PHP 8.4.27): Sales unit suite passed with 224 tests and 808 assertions; project PHP syntax checks passed. The full unit suite ran 361 tests and 1,459 assertions with one failure in the previously documented ApiFrameworkTest::testVersionedHttpRequestsDoNotStartBrowserSessions subprocess check (exit code 255). No Oracle migration or live acceptance was run.
