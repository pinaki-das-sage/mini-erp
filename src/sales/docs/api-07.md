# API-07 Customer payment contract

## Status

Swagger-only draft following the approved API-07 plan and v6.1 payment requirements/errors. No backend, migration, deployment, payment recording or Oracle verification is performed by this work. Existing contracts are preserved. Both new operations are planned and require the appropriate Sales permission within the authenticated company through the bearer token.

## Two operations

Paths are under `/api/v1`.

| Method | Path | Purpose |
| --- | --- | --- |
| POST | `/sales/invoices/{invoice_number}/payments` | Record a partial/full received payment; HTTP 201 |
| GET | `/sales/invoices/{invoice_number}/payments` | Read immutable payment history; HTTP 200 |

There is no Payment update/delete or extra public Payment ID. Payment Number is generated, read-only, at most 20 characters and company-unique. Payment records are append-only and have no separately editable revision; the Invoice revision protects concurrent writes.

Future route registration uses `sales.payments:record` for POST and `sales.payments:read` for GET, matching the draft shared registry migration. The generic Create flag is disabled for the payment object. Services must invoke SalesPermissions to enforce the same registered per-user grants as the routes.

## Request

| Field | Required | Rule |
| --- | --- | --- |
| action_reference | Yes | Shared retry reference; unique per company; preserve original input on retry |
| expected_version | Yes | Positive current Invoice revision |
| payment_amount | Yes | Decimal string; positive; maximum 16 whole digits and 2 decimals; at most current due |
| payment_date | Yes | Real YYYY-MM-DD date; on/after Invoice Date and not after business date |
| payment_reference | Yes | Trimmed nonempty business text, maximum 50 characters |

Invoice Number comes from the URL. Reject caller Invoice identity, Payment Number, Company/actor, Total Paid, Amount Due, Customer balance, payment status or any unsupported field in the body. Payment Reference describes the business receipt/transfer; it is not an assumed unique bank transaction ID and is distinct from the action_reference retry key.

Use exact decimals; accept up to two decimals without silently truncating/rounding excess precision. Return saved amounts with exactly two decimals. The server must distinguish the supplied exact missing/nonnumeric/zero/negative/precision/capacity messages instead of returning one generic decimal-helper error. Date comparisons need the configured business clock and saved Invoice Date; JSON Schema date format alone is insufficient. No date/reference omission or amount-only exception is introduced.

## Eligibility and transaction order

Record money already received outside the application; no gateway or bank transfer is performed. Apply identical rules to standalone and source-linked Invoices. An inactive Customer does not block a payment on an existing Submitted Invoice; active authenticated user/company and admin checks still apply. There is no Inventory or Logistics call and no source-eligibility revalidation for payment recording.

1. Authenticate and require the appropriate Sales permission within the authenticated company. Resolve the target as an Invoice document in that company; do not accept another shared-table document type merely because its number matches.
2. Resolve an identical committed request before current state/revision/due checks. Bind the fingerprint to actor/company, Invoice identity, operation and complete original body. A changed body under the committed reference conflicts. Preserve original expected_version on retry; do not substitute the latest version.
3. For a new request, lock/reread authoritative Invoice facts. Require Submitted state, then check expected_version and the current payment/due state. Draft returns the supplied submit-first error. A stale Submitted Invoice revision returns the current-due message; with a current revision a fully Paid Invoice returns the fully-paid message.
4. Check amount > 0 and <= current due, Payment Date range, reference and capacity. Never trust browser Amount Due. Serialize concurrent payments and Invoice return-to-Draft on the same authoritative Invoice facts so they cannot overpay or return after a payment wins.
5. Save the immutable payment, increment Invoice revision, calculate updated payment summary, write audit and save the retry result in one transaction. Any failure rolls back all effects. Customer outstanding remains derived from Submitted Invoice dues and decreases by the recorded amount exactly once.

The Invoice remains Submitted. Partial payment sets Partially Paid; zero remaining due sets Paid. Any payment blocks return to Draft. A new payment on Paid fails; an identical retry of the final committed payment still succeeds by returning its original result. A deliberately new reference represents a new payment, still bounded by current due. The existing note-specific ActionExecutor needs the previously recorded audit/role review before reuse; Swagger does not implement safe replay.

## Responses and history

POST returns the standard success envelope with operation_id equal to action_reference and current_version equal to the updated Invoice revision. Result includes the saved payment and an Invoice snapshot (Invoice Number, Submitted status, current revision, Invoice Total, Total Paid, Amount Due, payment status). Reuse InvoicePaymentSummary. A successful recording can only produce Partially Paid or Paid, never Unpaid or a Draft/null stamp. Envelope and snapshot versions must agree in implementation.

Payment history returns Payment Number, Invoice Number, recorded amount/date/reference, authenticated recording user ID (created_by) and server UTC timestamp (created_at). These are read-only. GET supports page/page_size, defaults 1/25 and maximum size 100, ordered by created_at then payment_number. Reject unknown query fields. List envelope operation_id/current_version are null; individual payments have no versions. An existing Draft/unpaid Invoice with no payments returns an empty list; missing/cross-company Invoice returns generic 404.

An identical POST retry returns its original 201 result and snapshot, which may precede later payments. Use existing GET Invoice for the latest totals; do not silently substitute a newer result into the saved retry response. No editable Customer balance or extra mutable payment summary is introduced.

## Exact errors

Swagger lists the supplied exact messages once in PaymentValidationError and PaymentConflict: missing/invalid/nonpositive/excess-precision amount, capacity, amount above current due, missing/long reference, invalid/early/future Payment Date, Draft/Paid Invoice, changed due and changed retry payload. Validation is HTTP 400; state/version/retry conflicts are 409. Shared 401/403/404/415/500/503 responses cover authentication, access, identity, JSON and server/database failures.

## Examples and future acceptance

The Swagger example assumes a Submitted Invoice INV-1000 with total 1000.00 and revision 2. Record 300.00 to produce revision 3, paid 300.00, due 700.00 and Partially Paid. It is an illustrative fixture, not an automatically provisioned Invoice or a live transaction.

| Next action | Expected |
| --- | --- |
| Attempt 800.00 at current revision 3 | Reject over-current-due; no new payment or revision |
| Record 700.00 with a new reference at revision 3 | Revision 4, paid 1000.00, due 0.00, Paid |
| Retry final payment with its original reference/body/revision 3 | Original success, no duplicate |
| New payment against current Paid Invoice | Reject fully paid |
| Concurrent requests from same starting revision | Serialize; loser refreshes current due, never overpay |

Repeat standalone/source-linked flows and verify inactive Customer payments, permissions/company isolation, negative/zero/precision/date/reference errors, changed-payload retries, payment/return races, rollback and reconciliation to immutable recordings. Customer balance must include all its Submitted Invoices, not only this one. Exact arithmetic/equality, clock checks, authorization, real numbering and Oracle persistence/locking require backend implementation. No HTTP or Oracle acceptance is claimed by contract checks. Cross-story All Documents filtering and agreement on Inventory reference interfaces remain separate from these payment operations.

Local checks passed: YAML with unique keys, 474 resolved references, 18 existing/request/response/model examples, two protected planned payment operations, unique operation IDs, required amount/date/reference/version fields, decimal boundaries, rejected caller totals/identity fields, partial/paid Invoice snapshot shapes, immutable history shapes and exact error messages. The illustrative payment result's totals and Invoice revision agree. Existing paths, schemas and responses were compared with their pre-edit definitions and preserved. Git whitespace checks passed. Current-due/date comparisons, actual replay behavior, aggregate calculations, tenant authorization and races remain backend acceptance work.
