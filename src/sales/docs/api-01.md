# API 01 permissions and common rules

Status recorded on 18 November 2026: the reusable OE foundation is implemented. API-01 applies common checks across Customer, Quote, Order, Shipper, Invoice and Payment operations; it is not a separate endpoint or business table. The helpers still need to be called by the services and endpoints developed in API-02 through API-07.

## Files and changes

| File | Implemented behavior |
| --- | --- |
| [SalesPermissions.php](../SalesPermissions.php) | Requires authenticated admin status for all Sales operations; rejects unsupported actions. No database grants or Logistics permission callback are used by this helper. |
| [DocumentRules.php](../DocumentRules.php) | Checks company identity, expected revision, document action eligibility and changes to inherited fields. These checks use facts supplied by the calling service. |
| [Validation.php](../Validation.php) | Trims text and checks required values/lengths; validates decimal strings, calendar dates, date order, future dates and allowed request fields. |
| [001_oe_permissions.php](../../../database/migrations/sales/001_oe_permissions.php) | Draft shared-router capability registration only. It grants no access; individual grants cannot override the service admin check. |
| [SalesRulesTest.php](../../../tests/unit/sales/SalesRulesTest.php) | Tests admin/non-admin and password-change access for every supported action, unsupported operations, lifecycle restrictions, company boundaries, revisions, inherited fields and validation. |

The shared framework code and shared migration files were not changed for this foundation.

## Admin-only access decision - 18 November 2026

The user replaced the granular permission model with a simple rule: authenticated admins can access Sales; non-admins cannot. This applies to reads, lookups and mutations. API-01 is a common policy, with no separate endpoint or request/response contract. Swagger for API-02 through API-07 must document bearer authentication, HTTP 401 and HTTP 403.

SalesPermissions checks the trusted Actor::isAdmin flag and rejects users who must change their password. It no longer accepts an Authorization dependency or a Logistics permission callback. Admin status satisfies the Sales access gate for Shipper submission. The subsequently approved API-05 contract additionally requires Logistics dispatch authorization; the future Shipper service must call that provider because this helper does not implement it. Carrier and all document rules still apply. Individual grants never authorize non-admin Sales access. Customer deletion and payment editing/deletion remain unsupported even for admins.

The shared router still requires registered object/operation metadata. Retain the draft capability migration for that compatibility requirement. Future Sales handlers and services must call SalesPermissions before data access or replay, because the shared route guard alone can accept individual grants. Shared authorization behavior for other modules is unchanged.

## Document and validation behavior

All four document types use `Draft` and `Submitted`. The action guard permits creation only for an unsaved document, reading in either saved state, and editing/submitting only a Draft. Returning a Submitted document or deleting a Draft requires no downstream usage and no recorded payments. Services must count a downstream Draft as usage. Payment recording is limited to Submitted Invoices; current Amount Due and payment amount validation belong to the payment service.

Company mismatches return a generic `NOT_FOUND` error with HTTP 404. Stale revisions return `STALE_VERSION` with HTTP 409. Administrator status does not bypass these checks.

Source-linked documents reject changes to inherited fields. The caller supplies the applicable inherited-field list and the fields whose values actually changed, including changes inside lines. Returning to Draft does not remove that protection while the source link remains.

Text is trimmed before required and character-length checks. Decimal validation accepts strings, checks precision/capacity without float conversion, and can require a positive value or allow zero. It does not calculate line amounts or totals. Date validation checks real calendar dates in `YYYY-MM-DD` format. The application must supply its configured business date for future-date checks. Write services must provide explicit allowed fields so clients cannot edit company, actor, generated numbers, status or calculated amounts.

## Integration requirements

Use SalesPermissions with the authenticated [Actor](../../shared/security/Actor.php) and existing [TransactionManager](../../shared/database/TransactionManager.php). The pure guards do not query persistence, acquire locks, save records or create transactions.

Each service must:

1. Authorize the action before reading business data or returning a replayed result.
2. Scope every query, join and write to the authenticated `Actor::companyId`. Company equality checking supplements this SQL restriction.
3. Resolve an identical committed retry before applying the current revision check.
4. Lock and reread authoritative document/source/payment facts in the agreed lock order. Never accept status, downstream usage or payment-existence flags from client JSON.
5. Apply the relevant guards and complete business validation.
6. Save business changes, source claims, revisions, audit and replay results in the same transaction; let errors roll back the complete operation.

The existing [ActionExecutor](../../shared/ActionExecutor.php) still requires the training `trainee` role and emits a note-specific audit event. Review and adapt its behavior before reusing it for OE.

## Migration status

`database/migrations/sales/001_oe_permissions.php` is prepared but has not been applied by this work. It inserts rows into the existing `ME_OBJECTS` and `ME_OBJECT_ACTIONS` tables. These rows describe shared-route capabilities only; Sales access depends on authenticated admin status, regardless of any user grants.

The default CLI command applies Shared migrations only. Use `php bin/console.php migrate --module=sales --confirm-schema=YOUR_TEST_SCHEMA` to apply pending Shared migrations before Sales, after target schema and permission-code review. Keep Sales migrations separate from applied foundation migrations.

## Verification record

Earlier evidence recorded on 18 November 2026 before the admin-only change:

| Check | Result |
| --- | --- |
| Latest targeted `SalesRulesTest.php` run | Passed: 75 tests, 209 assertions |
| Project PHP syntax checks, followed by syntax checks on the final changed PHP files | Passed |
| Broader unit run before the last 24 permission-mapping cases were added | 188 tests, 755 assertions; one failure in the unchanged `ApiFrameworkTest::testVersionedHttpRequestsDoNotStartBrowserSessions` subprocess test |
| Database migration application and real Oracle concurrency/rollback verification | Pending |

The broader suite failure returned subprocess exit code 255 in the Windows environment. It prevents reporting the entire unit suite as passing. The latest targeted run includes the final changes; the broader run above does not include the last 24 added test cases.

With PHP 8.4 and the locked Composer dependencies installed, run these commands from the repository root:

```powershell
php vendor/bin/phpunit tests/unit/sales/SalesRulesTest.php
php bin/lint.php
php vendor/bin/phpunit --testsuite unit
```

Use the appropriate PHP 8.4 executable if `php` is not on PATH. The implementation session used a local CLI configuration to avoid missing Herd extensions and an invalid preload path; no shared PHP configuration was changed.

## Remaining work

- Implement Customer/document/payment persistence and wire these helpers into real API and service operations.
- Define and review request/response contracts and table structures; verify the Sales migration invocation against the intended test schema.
- Implement generated numbers, exact calculations, active-reference validation, complete line validation and source exclusivity.
- Integrate Inventory Item/Warehouse reads and the separate Logistics dispatch authorization now required by the approved API-05 contract. The current helper implements the admin gate only.
- Implement safe retries, audit, revision updates and transactional source/payment operations; verify locking and rollback with real Oracle sessions.
- Add endpoint tests for authentication, company isolation, permissions and business failures.
- Implement UI behavior, including Delete placement only in All Documents, and repeat the API business cases through the UI.

## Admin-only verification - 18 November 2026

Targeted SalesRulesTest.php passed on PHP 8.4.27: 146 tests, 263 assertions. Coverage includes all supported Customer, document and payment actions for admins, denial for non-admins even with an admin role label, password-change restrictions, unsupported actions and retained business guards. Syntax checks passed for the changed PHP files. The broader unit suite ran 283 tests and 914 assertions with one failure in the previously recorded, unchanged ApiFrameworkTest::testVersionedHttpRequestsDoNotStartBrowserSessions subprocess check (exit code 255). The full suite is therefore not passing. No database migration was applied; route wiring and real Oracle verification remain pending.

The business baseline is the [OE design](../../../docs/oe-design.md). Older Sales allocation/fulfillment assumptions do not govern this v6.1 Draft/Submitted workflow.
