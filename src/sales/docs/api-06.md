# API-06 Sales Invoice contract

## Status

Swagger-only draft following the approved Invoice plan and v6.1 common document/source/line requirements. Existing authentication, Customer, Quote, Order and Shipper contracts are preserved. All eight Invoice operations are planned. No backend, migration, deployment, payment recording or Oracle verification is included.

## Operations

All paths are relative to `/api/v1`; bearer authentication, the appropriate Sales permission and the signed-in company apply.

| Method | Path | Purpose |
| --- | --- | --- |
| POST | `/sales/invoices` | Create standalone or Shipper-linked Draft |
| GET | `/sales/invoices` | List/filter Invoice summaries |
| GET | `/sales/invoices/eligible-shippers` | Find unused Submitted Shipper sources |
| GET | `/sales/invoices/{invoice_number}` | Read full Invoice and payment summary |
| PATCH | `/sales/invoices/{invoice_number}` | Edit Draft or change/clear Source |
| POST | `/sales/invoices/{invoice_number}/submit` | Submit saved positive-total Draft |
| POST | `/sales/invoices/{invoice_number}/move-to-draft` | Return eligible Invoice without any payment |
| POST | `/sales/invoices/{invoice_number}/delete` | Delete eligible Draft |

Register the static eligible-shippers GET before the parameterized Invoice GET. Lookup returns existing Shipper summaries; use Shipper GET to preview lines. It lists unused sources and claims nothing. A retained source is shown from the saved Invoice, and that Invoice's own claim is accepted at save/submission. Eligible-source date filters apply to Shipment Date. No Logistics dispatch authorization is repeated.

Invoice Number is generated, company/type-unique, maximum 20 characters and never reused. There is no second public header identifier. Shared version/retry action bodies and POST deletion follow the earlier document contracts. Future document deletion is offered only in All Documents. Deletion returns a confirmation with null current_version.

## Fields and approved defaults

| Field | Rule |
| --- | --- |
| source_shipper_number | Optional; maximum 20; omit/null for standalone creation |
| customer_id | Required saved value; active company-visible Customer, entered or copied |
| warehouse_id | Required saved value; active Inventory reference, maximum 20, entered or copied |
| invoice_date | Defaults to business date; on/after source Shipment Date |
| payment_terms | Required saved value; DUE_ON_RECEIPT / NET_15 / NET_30 |
| due_date | Required saved value; defaults from terms; on/after Invoice Date |
| notes | Optional; maximum 500 |
| lines | Complete intended collection with at least one valid line |
| document_total | Read-only Invoice Total, using shared exact amounts/rounding |
| total_paid | Read-only sum of recorded payments |
| amount_due | Read-only Invoice Total minus Total Paid |
| payment_status | Read-only null / Unpaid / Partially Paid / Paid |

Do not add Shipping Address, Carrier, Tracking Number, Customer PO Number, tax, discounts or editable payment fields to Invoice input. Invoice Date is not subject to Shipment/Payment Date's future-date prohibition. Trim/length/precision rules remain shared. Empty Notes clears it; null is used to clear Source, not required dates/terms.

The approved source-terms fallback is explicit: use source terms if supplied, otherwise Customer. The current Shipper contract has no Payment Terms, so default Invoice terms from Customer. Terms are Invoice-specific editable Draft values, even when linked; no Shipper terms field or hidden propagation was added. A later master-data change does not silently update saved Invoice terms.

Due on receipt adds 0 calendar days to Invoice Date, Net 15 adds 15, Net 30 adds 30. Creation may omit the date/terms/default Due Date, but all must be populated and validated before saving. An explicit Due Date is accepted if on/after Invoice Date. On PATCH, if Invoice Date or Payment Terms actually changes and due_date is omitted, rederive Due Date. An explicitly supplied valid due_date wins. Other omitted fields remain unchanged. Date comparisons and derivation require service logic; JSON Schema date format alone does not enforce them.

## Sources and lines

Standalone creation requires Customer/Warehouse. A linked Invoice may omit inherited headers, which are copied from an eligible readable same-company Submitted Shipper unused by another Invoice. Copy and lock Customer, Warehouse and every line once in source order; Terms/Invoice Date/Due Date/Notes remain Invoice-specific. Supplied inherited values must match the authoritative source.

Every create/update supplies complete lines. New Invoice lines get their own line_id; source_line_id refers to the Shipper line, not an earlier Order/Quote line. Standalone lines have no source ID input and return null source IDs. Reuse description, quantity, price, line Warehouse, calculated-field and amount-capacity rules. Calculate each line half up to two decimals, then sum; repeated Items on separate lines are allowed. A zero-total Draft is valid, but submission is not.

Omitted source on PATCH retains it; null clears it; another number replaces it after UI confirmation. A linked Draft claims its Shipper. Save/replacement/release, complete validation, revision, audit and retry result commit atomically. A failed replacement preserves the previous Invoice and claim. The service must read saved facts when an omitted Source could retain a link. Deleting a linked Draft releases its claim; returning to Draft retains it and continues to block returning the Shipper to Draft.

## Lifecycle, payment summary and Customer balance

| State/action | Payment summary and balance behavior |
| --- | --- |
| Draft | payment_status null; total_paid 0.00; amount_due equals Invoice Total; no payment entry or Customer-balance contribution |
| Submit | Require positive total; status Submitted; payment_status Unpaid; amount_due contributes once |
| Partial payment (API-07) | Status stays Submitted; payment_status Partially Paid; current due contributes |
| Fully paid (API-07) | Status stays Submitted; payment_status Paid; due 0.00; no further payment action |
| Eligible return to Draft | Require no downstream usage and no payment records; clear payment_status and remove contribution atomically |
| Resubmit | Restore contribution once; do not create a duplicate Invoice or balance effect |

The exact nonpositive submission message is `Invoice Total must be greater than 0.` A payment of any amount blocks return with `Cannot move to Draft: a payment has been recorded for this Invoice.` Check real payment records under the Invoice lock; never trust caller flags or only a displayed status. No downstream reference and no payments are also required for eligible Draft deletion.

The API uses Paid, matching the supplied requirements; UI wording may display Fully Paid. Payment status never replaces Draft/Submitted document status. Submitted Invoices must have a positive total and a populated stamp. Total Paid and Amount Due remain decimal strings; payments must never make due negative. Schema conditions express simple stamp/zero/nonzero consistency, but the actual sum/equality to immutable recorded payments must be calculated by the backend.

Customer balance is derived across Submitted Invoice dues; do not accept or independently overwrite a Customer balance. Draft line changes do not affect it. Serialize submit/return/payment races on authoritative Invoice facts so a committed payment and a return cannot both win. Authorize before replay, bind the fingerprint to type/action/identity/payload, and return an identical committed result before today's revision/state checks. Persist effects, audit and retry result atomically. Payment recording/history endpoints are API-07; no payment row is created by an API-06 operation.

Available actions include record_payment only for Submitted positive-due Invoices. A payment removes return-to-Draft eligibility. Fully paid Invoices have no mutation action in this scope. Hints do not replace execution-time checks. All APIs require the appropriate Sales permission; Invoice operations read/validate Inventory references but do not post stock or repeat Shipper Logistics authorization. An inactive Customer may still pay an existing Submitted Invoice through API-07.

## Lists and verification follow-up

List filters combine Customer, Warehouse, document status, Invoice Date range, payment_status and shared pagination. Payment-status filtering returns Submitted Invoices only; combining it with status=Draft yields no matches. Stable order is invoice_date then invoice_number. List summaries omit lines and include the calculated payment fields.

Future business acceptance must cover standalone/linked creation, defaults and explicit Due Dates, terms/date updates, zero-total Draft and rejected zero-total submission, full-source locks, source races/replacement, stale edits/retries, exactly-once submit/return/resubmit balance contributions, payment-blocked return and later-line rollback. Invoice 1000.00 -> payment 300.00 -> due 700.00 -> payment 700.00 -> due 0.00 needs API-07 integration. Business clocks, Inventory references, 8 KiB request-size suitability and real Oracle locking remain implementation work; specification checks are not HTTP or Oracle acceptance.

Local checks passed: YAML with unique keys, 439 resolved references, 16 existing/request/error/model examples, eight protected planned Invoice operations, unique operation IDs, standalone/linked/source-change shapes, shared field limits, rejected caller payment fields, zero-total Draft and positive-total Submitted response constraints, unpaid/partially paid/paid summaries and read/list/delete shapes. Existing paths, schemas and responses were compared against their pre-edit definitions and preserved. Git whitespace checks passed. These checks do not prove source equality, Due Date computation, payment aggregation, Customer-balance effects or transaction races; those remain backend acceptance work.
