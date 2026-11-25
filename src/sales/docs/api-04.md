# API-04 Sales Order contract

## Status

Swagger-only draft based on Order Entry v6.1 common document/line rules, optional source rules and Sales Order acceptance. The session's admin-only rule applies. Existing authentication, Customer and Quote definitions are preserved. No Order services, migrations, deployment or database actions are included.

## Operations

All paths are under `/api/v1`; all eight operations are planned, bearer-authenticated and admin-only within the signed-in company.

| Method | Path | Purpose |
| --- | --- | --- |
| POST | `/sales/orders` | Create a standalone or Quote-linked Draft |
| GET | `/sales/orders` | List/filter Order summaries |
| GET | `/sales/orders/eligible-quotes` | Find unused Submitted Quote sources |
| GET | `/sales/orders/{order_number}` | Read header, source and all ordered lines |
| PATCH | `/sales/orders/{order_number}` | Update a Draft or change/clear its source |
| POST | `/sales/orders/{order_number}/submit` | Submit the saved valid Draft |
| POST | `/sales/orders/{order_number}/move-to-draft` | Return an eligible Submitted Order |
| POST | `/sales/orders/{order_number}/delete` | Delete an eligible unreferenced Draft |

Register the static eligible-quotes GET before the parameterized Order GET in the future PHP route registration so the generic route does not capture it as an Order Number. Source lookup returns Quote summaries using the existing QuoteListResponse; use the existing Quote GET to preview all lines. Lookup never reserves a source. It lists unused Quotes only; a retained source is displayed from the saved Order. The save/submit check must accept that Order's own claim as distinct from competing usage.

Order Number is generated, unique per company/type, maximum 20 characters and never reused. No extra public header ID is introduced. Action POSTs use the shared version/retry body. Deletion returns a confirmation with null current_version; the future UI offers deletion in All Documents only.

## Fields

Order fields are optional source_quote_number, Customer ID, Warehouse ID, Order Date, optional Requested Delivery Date, optional Customer PO Number (maximum 30), required saved Shipping Address (maximum 500), required saved Payment Terms and optional Notes (maximum 500). Order has no Quote Date or Valid Until field. Order Date defaults to the configured business date at creation and must be on/after source Quote Date when linked. Requested Delivery Date must be on/after Order Date; null clears it on update. The supplied requirements do not impose a future Order Date or expired-Quote restriction.

Standalone create requires Customer and Warehouse; Address/Payment Terms default from Customer. Linked create may omit inherited header values because the service copies them. If inherited values are supplied, reject mismatches. Customer/Warehouse references are maximum 20 characters and company-scoped. Apply required active-reference validation on save/submission; any proposed exception for references later made inactive needs agreement. Saved document snapshots are not silently refreshed from master changes.

## Source and complete lines

- source_quote_number omitted/null on create means standalone. Omitted on PATCH retains the source; null clears it; a number selects/replaces it. Empty string is not a valid source number. Changing/clearing requires UI confirmation, not an invented confirmation flag in JSON.
- Only a readable, same-company Submitted Quote not claimed by another Order is eligible. One Quote supplies one full Order. Recheck under lock on saving and submission. Saving a downstream Draft claims the Quote, blocks competing Orders and blocks returning the Quote to Draft.
- Copy Customer, Warehouse, Shipping Address, Payment Terms and all source lines. While linked, these inherited values remain locked. Order Date, Requested Delivery Date, Customer PO Number and Notes remain target-specific editable Draft fields.
- Every create/update explicitly supplies the complete intended lines collection; at least one valid line must remain. Use the shared description, quantity, price, rounding and amount-capacity rules. Linked input is checked against authoritative source data; the service copies the authoritative values. Include every source line exactly once in source order, even when Item Codes repeat.
- source_line_id identifies the Quote's saved line. A linked create/new source copy supplies these source IDs and omits target line_id; the server generates new Order line IDs. A subsequent edit retaining the source echoes saved Order line_id and source_line_id with unchanged copied values. Reject copied-line additions, changes and removals.
- Standalone lines have no source_line_id input and return source_line_id: null. They can be added/edited/removed using the Order's own line IDs. Clearing a source retains the intended saved Order line IDs where supplied and clears their active source-line linkage; audit preserves prior lineage. Replacing a source supplies all new source lines and omits the previous Order line IDs.
- When PATCH omits source_quote_number, schema validation alone cannot know whether the saved Order is linked. The service must read the authoritative retained source and enforce the appropriate source-line identity and immutability checks. The schema covers explicitly selecting or clearing a source.
- Source replacement/release, complete validation, line saving, revision, audit and retry result commit atomically. A failed new claim preserves the previous Order and source claim. Deleting a linked Draft releases its source. Returning an Order to Draft retains its Quote link and inherited-field locks.

The shared SalesSourceNumber and SalesSourceLine models are intended for later optional-source document contracts too. Common field/line/date messages remain in SalesDocumentValidationError; source-specific mismatch and copied-line messages are defined with the source schemas. SalesSourceConflict includes the exact source-unavailable message plus existing state/version/retry messages.

## Lifecycle, responses and later verification

Create a valid Draft first; only Drafts can be edited/submitted. Submission sets Submitted and makes the Order eligible as a Shipper source. There is no allocation, Inventory posting, Customer-balance change or Pending/Shipped status. An Order can return to Draft only if no Shipper references it; a Draft Shipper also blocks return. Delete only an unreferenced Draft, preserving audit and number non-reuse.

Responses return Order Number, source Quote Number (null standalone), all saved header values, current revision, calculated totals, available actions and ordered lines with both target and source line identity. List summaries omit lines. List filters combine Customer, Warehouse, status, Order Date range and pagination (page 1 / size 25, maximum 100). Eligible-source filters apply to Quote Date instead. Unknown fields/query parameters and invalid date ranges are rejected consistently with the shared framework.

Backend work must authorize before data access/replay, scope every lookup/write to the signed-in company and bind retry fingerprints to document type, action, identity and payload. Resolve identical committed retries before checking today's revision. Lock/recheck source status/ownership/claim and Order/Shipper dependencies in an agreed order. Returning available_actions never replaces authorization and state checks on execution.

Future acceptance must cover standalone and linked create, copied-value locks, complete lines, source clearing/replacement, claim races, source-return races, stale edits, identical/conflicting retries, later-line rollback, Order return blocked by a Draft Shipper and source release after Draft deletion. Inventory references, business-date defaults, 8 KiB request-size suitability and Oracle persistence/locks remain implementation work. No real API or Oracle acceptance is claimed for Swagger checks.

Local contract checks passed: YAML with unique keys, 246 resolved references, eight existing/request examples, eight protected planned Order operations, unique operation IDs, standalone/linked creation, source retain/clear/replace request shapes, rejected unsupported/invalid inputs and read/list/delete response shapes. Existing paths, schemas and responses were compared with their pre-edit definitions and preserved. Git whitespace checks passed. State, source ownership, copied-value equality and races depend on authoritative saved facts and remain backend acceptance work.
