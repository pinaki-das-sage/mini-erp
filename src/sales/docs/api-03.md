# API-03 Sales Quote contract

## Status

Swagger-only draft based on Order Entry v6.1: common document states/fields (pages 2 and 5), complete line collection and validation (pages 6-7), no-source/optional downstream source rules (pages 8-9), and error/filter/handoff requirements (pages 12-13). The session's admin-only rule replaces granular Sales permissions. No Quote backend, migration, deployment or Oracle verification is included.

## Operations

All paths are under `/api/v1`; authentication and company ownership come from the bearer token. All seven operations are marked planned.

| Method | Path | Purpose |
| --- | --- | --- |
| POST | `/sales/quotes` | Save a complete valid numbered Draft |
| GET | `/sales/quotes` | List/filter paged Quote summaries |
| GET | `/sales/quotes/{quote_number}` | Read header and all ordered lines |
| PATCH | `/sales/quotes/{quote_number}` | Edit a Draft with its complete intended lines |
| POST | `/sales/quotes/{quote_number}/submit` | Validate the saved Draft and submit |
| POST | `/sales/quotes/{quote_number}/move-to-draft` | Return an eligible Submitted Quote |
| POST | `/sales/quotes/{quote_number}/delete` | Delete an eligible unreferenced Draft |

Quote Number is generated, at most 20 characters, unique per company/type and never reused. Use it in URLs without another public header ID. Line IDs are generated stable identifiers explicitly required by the line-entry contract. Proposed number characters match the current router (letters, digits, underscores and hyphens); the final numbering prefix/namespace needs review before implementation. Quotes have no Source field.

Deletion uses POST so expected_version and action_reference stay in a JSON body, matching the other actions and the framework's response envelope. It returns HTTP 200 with a deletion confirmation and null current_version. The future UI may offer document deletion only from All Documents; an API contract cannot enforce button placement.

## Fields and defaults

Customer ID and Warehouse ID are required references, maximum 20 characters. Item Code is maximum 30 characters. Customer must be active and all references must be available to the signed-in company. Inventory owns Item/Warehouse selection and validation; no Inventory maintenance or stock changes are introduced. The physical provider identifiers and adapters remain integration work.

Quote Date defaults to the configured business date on creation. Valid Until and Requested Delivery Date are optional and must be on/after Quote Date; null clears either optional date during update. The requirements do not prohibit future Quote Dates. Shipping Address is required in the saved record, maximum 500 characters, defaulting from Customer billing address. Payment Terms defaults from Customer (Due on receipt, Net 15 or Net 30). Both remain editable. Notes is optional, maximum 500 characters; empty text clears it. Default fields may be omitted on creation but must be populated and validated before persistence. Master-data changes do not silently rewrite saved addresses, descriptions, prices or terms.

The public status values are `Draft` and `Submitted`, matching the current Sales guard. The proposed ERD uses uppercase storage values; the eventual service must agree/map that representation consistently. Callers cannot write status, Quote Number or calculated totals.

## Lines and calculations

- Every create/update supplies the complete intended `lines` array; at least one valid line must remain. There is no separate line endpoint.
- Every line requires Item Code, Description (maximum 200), positive Quantity (9 whole digits + 3 decimals), and nonnegative Unit Price (10 whole digits + 2 decimals). Decimal values are strings. Repeated Items on different lines are allowed.
- On create/new lines, omit line_id. On update, retain IDs for edits, include lines without IDs for additions, and omit saved lines to remove them. Reject unknown/foreign/duplicate saved IDs. Array order determines saved display line numbers without replacing stable IDs.
- Derive each line's Warehouse from the header. Return line_id, line_number, inherited Warehouse, line_amount and document_total. Reject caller-supplied amounts or independently edited line Warehouse.
- Calculate using exact decimals: round each Quantity x Unit Price half up to 2 decimals, then sum rounded lines. Maximum Line Amount/Total is 16 whole digits plus 2 decimals. Reject precision/capacity overflow; never truncate. A zero-price/zero-total Quote is allowed; positive-total submission is an Invoice rule.
- Header PATCH fields are partial; omitted header fields remain unchanged, but lines is always required. Revalidate the complete resulting document on save and submission. No line edit creates a payment or changes Customer balance or stock.

## Lifecycle, concurrency and replay

Create always saves a Draft before submission. Only Drafts can be edited/submitted. A Submitted Quote can return to Draft only if no Order references it; an Order in Draft counts as usage. A saved downstream Draft claims its source, so source claim/return must lock and recheck the same authoritative facts. An unreferenced Draft alone can be deleted. Return preserves Quote Number and lines. Submission and return are actions, not editable status fields.

All writes require action_reference; changes to saved records require expected_version. Authorize before replay, resolve an identical committed result before checking the current revision, then validate/lock/recheck current facts. Bind the retry fingerprint to document type, operation, Quote identity and payload, not just the body. A failed later line or action rolls back header, lines, totals, state, audit and retry changes. Increment the document version after each accepted change. Lines use the parent revision.

Return available_actions based on current state and downstream usage, and recheck them at execution. They are hints, not client authority. The list combines Customer, Warehouse, status, Quote Date range and pagination (page 1 / size 25, maximum 100), ordered by quote_date then quote_number. Invalid ranges return the supplied exact message. The future combined All Documents list and API-04 eligible Quote-source lookup are separate contracts; list summaries do not include all lines.

## Verification and implementation follow-up

Contract checks must cover required fields, complete lines, precision, server-only fields, unique operation IDs, bearer metadata, all references/examples and preservation of existing contracts. The shared exact line/date/state messages are recorded once in Swagger; code must later return those messages, not merely generic allowlist errors.

Local checks passed: YAML with unique keys, 148 resolved references, six existing/request examples, seven protected planned Quote operations, representative create/update/action/read/list/delete payloads, decimal boundaries and rejected invalid inputs. Existing authentication/Customer paths, schemas and responses were compared with their pre-edit definitions and preserved. Git whitespace checks passed. These checks validate the contract; no HTTP or Oracle acceptance was performed.

Required future business cases: two lines total 263.50; editing first Quantity produces 389.00; removing second line produces 376.50; removing the last line fails. Verify half-up rounding, later-line rollback, invalid references, duplicate/foreign line IDs, stale updates, retries, blocked Submitted edits, eligible return and a return blocked by a Draft Order.

The current HTTP boundary is limited to 8 KiB. Agree a supported document size and adjust/review that boundary if needed before implementation; this contract invents no unapproved line-count limit. Actual reference validation, safe decimal arithmetic, persistence, audit/retry adaptation and real Oracle race/rollback tests remain pending. Local specification checks do not prove endpoint behavior.
