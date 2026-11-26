# Invoice backend — 24 November 2026

## Current Order-number creation contract

`POST /api/v1/sales/invoices` accepts only `source_order_number` and an optional
`action_reference`. The source must be a same-company Submitted Sales Order without
an existing Invoice. Standalone creation and caller-supplied header/line values are
rejected. The backend copies the complete saved Order, including its date, Customer,
Warehouse, shipping address, terms, notes and ordered lines, and derives Due Date
from that copied date and terms. Document and line source links are preserved.
Existing linked Invoices cannot clear or replace their Order source.

Order Submit already creates a Draft Invoice in the same transaction as Inventory
reservation and returns `invoice_number`. Use that number to read the Invoice;
calling create again for that Order is rejected. The public create operation covers
previously submitted Orders without an Invoice. Explicit action references replay
the original result; without one, the unique source claim prevents duplicates.
Creation adds no Customer debt; Invoice Submit adds debt once.

```json
{ "source_order_number": "SO-000000000000001" }
```

Draft updates require only `expected_version`; `invoice_date`, `due_date`, `notes`
and retry `action_reference` are optional. Lines and all inherited business fields
are rejected in update requests. The server validates the saved lines and preserves
their IDs and source links without rewriting them. A missing retry reference is
generated internally; repeating an update with the old version is rejected as stale.

```json
{
  "expected_version": 1,
  "invoice_date": "2026-11-25",
  "due_date": "2026-11-25",
  "notes": "Payment due on receipt."
}
```

The earlier implementation notes below describe the imported backend; the creation
contract above supersedes their standalone/manual-line examples.

Invoice submit, move-to-Draft and delete also require only `expected_version`.
`action_reference` remains optional for explicit replay; when omitted, an internal
reference is generated and stale/state checks prevent repeated debt changes.
Payment recording also supports an optional retry reference. Use the latest version for
each Invoice action; a retry without an explicit reference does not replay success.

`GET /api/v1/sales/invoices/eligible-orders` is implemented as a read-only paginated
lookup requiring Invoice create and Order read permissions. It returns only the
current company's Submitted Sales Orders without a source claim. Customer Code,
Warehouse and inclusive Order-date filters are supported. Draft/In Progress/Closed
Orders and Orders already linked to an Invoice are excluded before pagination.
Creation still rechecks status and claim under lock. Since Order Submit already
creates an Invoice, normally submitted Orders will not appear here.

Seven Sales Invoice operations have local PHP implementations. Deployment and real
Oracle/Swagger acceptance remain pending for the complete lifecycle. Payment
recording/history are now registered by the follow-up [API-07 implementation](api-07.md).
The requester verified remote Invoice creation/listing and reported a successful
Draft update. Remote payment transactions and complete lifecycle acceptance remain
pending. No database migrations were changed for the Invoice/Payment backend work.

| Method | Path under `/api/v1` | Behavior |
| --- | --- | --- |
| GET | `/sales/invoices` | Company-scoped summaries, filters, exact totals and pagination |
| POST | `/sales/invoices` | Copy an unused Submitted Order using its number only, HTTP 201 |
| GET | `/sales/invoices/{invoice_number}` | Saved header, ordered lines and payment summary |
| POST | `/sales/invoices/{invoice_number}/update` | Draft dates/notes only, expected version; optional retry reference |
| POST | `/sales/invoices/{invoice_number}/submit` | Positive-total Draft to Submitted; add Customer debt |
| POST | `/sales/invoices/{invoice_number}/move-to-draft` | Submitted with no payment/downstream references; remove debt |
| POST | `/sales/invoices/{invoice_number}/delete` | Eligible Draft only; release source and delete lines/header |

## Files and dependencies

Invoice classes live in `src/sales/invoices/`; PaymentService lives in
`src/sales/payments/`. Shared helpers remain directly under `src/sales/`.
Public API URLs are unchanged. Existing SKU-based Invoice requests must use
`product_id` instead; this change does not require a database migration.

- `InvoiceService.php`: public service and existing list query.
- `InvoiceWorkflow.php`: orchestrates header locks, writes, balance changes and action results.
- `InvoiceDraft.php`: validates complete contents, Customer/Inventory references and source claims.
- `InvoiceStore.php`: scoped document, line, payment and source queries; full response construction.
- `InvoiceMoney.php`: exact integer cents and explicit capacity checks before multiplication/summing.
- `InvoiceActions.php`: permission checks, company/actor/operation-bound retries and sanitized audit.
- `api.php`: route registrations; no changes to Customer/Quote route behavior.

Requires the finalized Customer/document/line/link/payment tables and document numbering
from migration `009_oe_document_numbering.php`. Invoice numbers use
`ME_OE_INVOICE_NO_SEQ` and `SI-` plus 15 digits. Inventory migration `010` replaces
line ITEM_CODE with PRODUCT_ID. The API accepts `product_id` as a positive numeric string (for example `"163"`)
in the requested company and Warehouse. Products must have `IS_ACTIVE = 'Y'`.
Products are locked in sorted Product ID order while validating; no stock/reservation or
Logistics mutation occurs. Warehouse validity is checked through that Product's
Inventory-owned WAREHOUSE_ID; there is no separate Warehouse provider in this checkout.

The business date defaults to Asia/Kolkata and supports `APP_BUSINESS_TIMEZONE` in
the PHP environment/`.env`. Tests inject a deterministic business-date callback.
Explicit Invoice Dates may be future dates; Invoice Date must not precede source
Order Date. Terms derive Due Date by 0/15/30 calendar days. Updates preserve Due Date
unless date/terms actually change or a new Due Date is supplied.

## Transactions and ownership

Every write requires action_reference. Update/lifecycle actions also require positive
integer expected_version. Identical authorized retries return the original result
before current state/version/reference checks, including after deletion. Changed
payload, actor or operation under a committed reference conflicts. Sequence gaps are
normal and do not mean missing business records.

Invoice, complete lines, source claim, Customer balance, audit and action result share
one transaction. Each mutation locks the authoritative Invoice header. Submit/return
also lock the Customer before balance writes; all other Invoice/payment writers must
follow this same Invoice-lock protocol. GET detail locks the header while assembling
its multi-query snapshot. Real simultaneous-session race verification is still required.

Drafts contribute no debt. Submit adds the total once. Returning an eligible unpaid
Invoice removes it once and retains its source claim. Payments of any amount block
return. Submitted Invoices cannot be edited/deleted. Exact decimal capacity is checked
before saving, summing and changing balances. Zero-total Drafts are allowed; submit
requires a positive total.

Linked Invoices require Order read access, an eligible Submitted/In Progress/Closed
Order, and every source line exactly once in source order. Customer, Warehouse,
terms, line Item/description/quantity/price are inherited and validated against source.
Date/Due Date/Notes are Invoice-specific. Source replacement and release are atomic;
source documents are locked in stable record-ID order. Real Order return/edit code
must participate in the source-lock/usage-check protocol as well.

Stable existing line IDs survive edits/reordering. IDs from other Invoices and duplicate
IDs are rejected. Removed IDs are deleted and new lines receive generated identities.
Positive temporary positions avoid unique-position collisions while swapping lines.
Downstream header/line references and recorded payments block unsafe Draft changes.

Full GET/write responses include conservative permission/state-based action hints.
Submitted Invoices with positive due now advertise record_payment when authorized;
Paid Invoices have no mutation hints. List summaries currently leave available_actions
empty; action endpoints remain the authoritative eligibility checks.

## Deploy and test

Deploy all six Invoice classes, updated `src/sales/api.php`, and `public/openapi.yaml`
together. Merge route additions into the current remote file; preserve teammates'
Quote registrations. Do not upload applied migration files or rerun migrations for
this PHP change. Keep `x-implementation-status: integration-pending` until acceptance.

Run locally:

```text
php vendor/bin/phpunit tests/unit/sales/
```

`tests/oracle/sales/OracleInvoiceTest.php` is opt-in only, requires an explicitly
enabled disposable schema ending in `_TEST`, a seeded admin and active Inventory
Product. It creates and cleans only its own fixtures. It has not run against Oracle.
It is not a concurrency test; real coordinated races remain a separate acceptance gate.

Swagger sequence (use actual same-company Customer Code, Warehouse and Product ID):

1. Login/Authorize. POST Invoice with one line (2 at 10.25), a new action_reference,
   and no source for the first test. Expect Draft/version 1/total 20.50, no debt.
2. GET its returned Invoice Number; list with Customer/date/status filters.
3. POST update using returned line_id values and current expected_version. Verify
   line IDs, totals, date/default behavior and one version increment.
4. Submit current version. Expect Submitted/Unpaid and exactly one balance increase.
5. Retry the identical submit body/reference/original version. Expect original success,
   no extra balance/version/audit. Changed body under that reference must conflict.
6. Move to Draft with a new reference/current version. Verify balance restored once.
7. Delete the Draft with a new reference/current version. GET returns 404; identical
   delete retry still succeeds. Number is never reused.
8. Repeat with an eligible source Order and exact source_line_id values. Verify claim
   uniqueness, retained claim on return, released claim on deletion and inherited locks.
9. Verify bad precision/zero quantity/foreign Customer or Item, stale writes, missing
   permissions, duplicate lines, paid-return protection and forced rollback.

The Swagger creation example uses illustrative CUST-0001/W01/RF-001 values, not seeded
fixtures. Replace them with saved references. The existing 8 KiB HTTP request limit
still applies. Successful local doubles do not prove Oracle SQL/locking or remote API
acceptance. Payment summary reads work with saved payment rows; payment posting is separate.

## Local verification

Remote read-only diagnosis confirmed ORA-01745 for the `:number` bind name and success
for `:document_number`. Header lookup/insert and Oracle-test cleanup now use the latter.
The behavioral double rejects the reserved bind name to prevent regression. Sales
checks after this correction: 270 tests, 1,156 assertions passed; re-deployment and
end-to-end Invoice creation verification are still required.

All six Invoice classes, route file, workflow tests and Oracle-test file pass PHP syntax
checks. Sales plus MigrationTest: 277 tests, 1,190 assertions passed. YAML parsed with
unique keys, all 322 local references resolved, and operation IDs were unique.
The gated OracleInvoiceTest was skipped (no disposable-schema opt-in); no Oracle
connection or database mutation was performed for this implementation.

The broader unit run was not green: 407 tests exposed an unchanged shared readiness
dependency on missing MigrationRunner::directories(), and the Windows command-line
subprocess failure in ApiFrameworkTest::testVersionedHttpRequestsDoNotStartBrowserSessions.
Those shared files were not changed for Invoice work. Actual Oracle SQL acceptance,
coordinated concurrent-session races and remote Swagger acceptance remain pending.
