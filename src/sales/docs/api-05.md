# API-05 Shipper contract

## Status and policy

Swagger-only draft following the approved API-05 plan and v6.1 Shipper requirements. No backend, migrations, deployment or database actions are included. All eight Shipper operations are marked planned. Existing authentication, Customer, Quote and Order behavior is preserved; shared source-line descriptions now identify the immediate predecessor rather than assuming a Quote.

This plan restores the separate Logistics authorization requirement for Shipper submission, in addition to Sales admin access. It supersedes the earlier admin-only interpretation for this specific action. The current SalesPermissions helper still implements only the admin gate; the future Shipper service must call the agreed Logistics provider. The provider interface and permission mapping remain integration work, not an implemented check.

## Operations

All paths are under `/api/v1` and require bearer authentication and same-company admin access.

| Method | Path | Purpose |
| --- | --- | --- |
| POST | `/sales/shippers` | Create standalone or Order-linked Draft |
| GET | `/sales/shippers` | List/filter Shipper summaries |
| GET | `/sales/shippers/eligible-orders` | Find unused Submitted Order sources |
| GET | `/sales/shippers/{shipper_number}` | Read complete Shipper and source lineage |
| PATCH | `/sales/shippers/{shipper_number}` | Update Draft and/or source |
| POST | `/sales/shippers/{shipper_number}/submit` | Confirm dispatch by submitting |
| POST | `/sales/shippers/{shipper_number}/move-to-draft` | Return eligible Submitted Shipper |
| POST | `/sales/shippers/{shipper_number}/delete` | Delete eligible unreferenced Draft |

The static eligible-orders GET must be registered before the parameterized Shipper GET. Source lookup returns existing Order summaries; use Order GET to preview complete lines. It does not reserve an Order. The current retained Order is displayed from its saved Shipper, and its own claim is accepted during that Shipper's save/submission.

Shipper Number is generated, maximum 20 characters, unique per company/type and never reused. No second public header identifier is introduced. Action POST bodies reuse action_reference/expected_version. Deletion returns a confirmation with null current_version; future UI deletion belongs only in All Documents.

## Fields and validation

| Field | Rule |
| --- | --- |
| source_order_number | Optional; maximum 20; omit/null for standalone creation |
| customer_id | Required saved value; active and company-visible; standalone input or copied source |
| warehouse_id | Required saved value; active Inventory reference, maximum 20; inherited by lines |
| shipment_date | Defaults to business date; not future; on/after source Order Date when linked |
| shipping_address | Required saved value; maximum 500; Customer default standalone or source copy |
| carrier | Maximum 100; optional/empty on Draft, required at submission |
| tracking_number | Optional; maximum 50 |
| notes | Optional; maximum 500 |
| lines | Complete intended collection with at least one valid line |

Trim text before required/length checks. Null clears the source; empty strings clear optional text. Carrier and Tracking Number are target-specific, editable Draft fields even when source-linked. Shipment Date and Notes are also target-specific. Submitted fields remain read-only.

Use exact messages: `Enter Carrier before submitting the Shipper.`, `Shipment Date cannot be in the future.`, and `Shipment Date must be on or after Order Date.` Common field, line, source, stale-version and state messages are reused. Future-date and sequence validation depend on the configured business clock and saved source; JSON Schema date format alone does not prove these rules.

No Payment Terms, Quote Date, Order Date, Customer PO Number or Requested Delivery Date is introduced as editable Shipper input. The approved API-06 plan resolves Invoice terms by using Customer defaults when the source supplies none; Invoice Draft terms are editable. No Shipper terms field or automatic terms propagation is introduced.

## Complete lines and sources

Reuse API-04's complete-line/source rules with the Order as immediate predecessor. Standalone create requires Customer/Warehouse. Linked create may omit inherited headers because they are copied, but every intended source line is supplied for validation against authoritative source values.

Copy Customer, Warehouse, Shipping Address and every Order line exactly once in source order. Keep inherited values locked while the source remains. New Shipper lines receive their own line_id; source_line_id refers to the Order line, not the original Quote line. Return null source_line_id for standalone lines. Use shared description, quantity/price precision, half-up per-line rounding and amount-capacity rules. Repeated Items on distinct lines remain allowed. Warehouse is derived from the header, and caller-supplied line/total amounts are rejected.

Only readable same-company Submitted Orders unused by another Shipper are eligible. Draft save claims the Order. On PATCH, omitted source retains it; null clears it; another Order Number replaces it. UI confirmation is required before replacement/clearing. Source changes, copied-line validation, release/claim, revision, audit and retry result commit atomically, or preserve the previous document and claim. Omitted source during PATCH requires the service to read saved facts to determine whether linked rules still apply. On source replacement, supply new Order source-line IDs and omit old Shipper line IDs.

Deleting the Draft releases its Order. Return to Draft retains its Order claim and inherited-field locks. Any linked Invoice, including a Draft Invoice, blocks return to Draft. Delete only an eligible unreferenced Draft. Submission leaves its source Order Submitted. No Inventory stock, allocation, Customer balance or separate Shipped status is introduced.

## Inventory and Logistics integration

Inventory owns active Item/Warehouse reads and company-visible reference validation. Agree provider fields and PHP interfaces with Inventory; do not read/maintain peer tables as a second authority or invent provider endpoint paths. Missing required reference validation must reject the save/submission rather than assume success.

Only submission additionally requires Logistics dispatch authorization, whether standalone or Order-linked. Supply trusted actor/company and the agreed Shipper context to a Logistics-owned, read-only PHP authorization provider. There is no public OE authorization endpoint, caller boolean or assumed Logistics permission code in this contract. No new OE authorization table is proposed.

Authorize before replay or effects; an authorized identical committed retry returns the original result before current revision/state checks. The authorization provider must not perform dispatch/stock posting as a side effect of checking permission. Then lock/recheck saved facts and apply the normal valid-Draft, Carrier, Shipment Date, full-line and source checks. Commit status, revision, audit and retry result together. Carrier can be absent on Draft save; submission validates the saved Carrier, not an unsaved value in the action body.

Logistics denial returns HTTP 403 with FORBIDDEN. Missing/unavailable required dispatch authorization returns HTTP 503 with DEPENDENCY_UNAVAILABLE. The 503 contract also permits database/Inventory unavailability. No failure may submit the Shipper or alter its source, lines or totals. Local test doubles can support later isolated development but do not prove real authorization/integration.

## Responses and verification follow-up

Responses include Shipper Number, source Order Number or null, saved header, ordered lines with target/source identities, calculated amounts, status, available_actions and revision. Submitted responses require a nonempty Carrier. Available actions are hints; actual execution must recheck permission and business conditions. List filters combine Customer, Warehouse, status, Shipment Date range and pagination, ordered by shipment_date then shipper_number. Eligible-source date filters apply to Order Date.

Later backend acceptance must cover standalone/linked Drafts without Carrier, allowed submission with Carrier, missing Carrier rejection, future/early Shipment Dates, copied-line locks, source replacement/release and races, denial/unavailability from Logistics, no stock or Order-status changes, return blocked by a Draft Invoice, versions, retries and later-line rollback. The existing 8 KiB JSON boundary and realistic document sizes also need implementation review. No HTTP or Oracle acceptance is claimed by the Swagger-only work.

Local contract checks passed: YAML with unique keys, 341 resolved references, 12 existing/request/error examples, eight protected planned Shipper operations, unique operation IDs, fully resolved shared field limits, standalone/linked/source-change request shapes, submitted-response Carrier requirements, denied unsupported inputs and read/list/delete response shapes. Submission's Logistics 403/503 contracts and Draft-Invoice return restriction are documented and checked structurally. Existing paths/responses and schema constraints were compared with their pre-edit definitions and preserved; only two shared source-line descriptions were generalized. Git whitespace checks passed. Business-clock comparisons, saved-source equality and real authorization/locking remain backend acceptance work.
