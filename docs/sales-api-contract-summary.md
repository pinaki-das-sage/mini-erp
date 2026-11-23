# Mini ERP API contract summary

Contract snapshot: 19 November 2026. Source: [public/openapi.yaml](../public/openapi.yaml). This reference covers every current operation, its input, output and declared errors, followed by database implications.

**44 operations across 33 paths: 20 POST, 19 GET and 5 PATCH.** Five Customer routes are implemented locally; the other Sales contracts await backend implementation. Authentication has existing endpoints. Swagger contracts do not establish working Sales endpoints or applied migrations.

Use the operation tables to choose a request, the field tables to construct it, and the response and error sections to interpret the result. API-01 defines permissions/common rules and has no separate endpoint.

[Operations](#operations) · [Input fields](#input-fields) · [Outputs](#outputs) · [Errors](#errors) · [Migration mapping](#migration-mapping)

## Common request rules

- Base URL: `./api/v1`, relative to `public/`. Every path parameter in braces is mandatory. All current query parameters are optional. GET operations have no request body.
- Send `Authorization: Bearer <access_token>` except on login. POST/PATCH require a JSON body and `Content-Type: application/json`.
- Sales uses the signed-in company and trusted admin identity. Do not send company ID, actor ID, permission flags or an admin flag in Sales request bodies. Shipper submission also requires Logistics dispatch authorization.
- **M** = mandatory input; **O** = optional input; **C** = conditionally mandatory; **D** = optional input with a creation default. A field optional in a request may still be mandatory on a valid saved record.
- Trim text before validation. Optional text can be cleared with `""`; nullable optional dates and source references can be cleared with `null`. Omission in PATCH preserves an existing value, except documented dependent defaults such as Invoice Due Date.
- Calculated values, generated document/payment numbers and document status are output fields. Document status changes through actions. Customer status changes through PATCH. There is no HTTP DELETE operation.

Sales write schemas reject unsupported fields. Quotes, Orders and Shippers do not post stock or change Customer balance. Invoice submission affects balance through Submitted due, without stock posting; recorded payments reduce that due.

## Operations

Each request names all mandatory top-level JSON fields and all other accepted top-level fields. Field types and conditional rules follow below. Error numbers are the HTTP statuses declared for that operation; the error catalog explains their conditions and messages.

| Area | GET | POST | PATCH | Total |
| --- | --- | --- | --- | --- |
| Authentication | 1 | 2 | 0 | 3 |
| API 02 Customers | 2 | 2 | 1 | 5 |
| API 03 Quotes | 2 | 4 | 1 | 7 |
| API 04 Orders | 3 | 4 | 1 | 8 |
| API 05 Shippers | 3 | 4 | 1 | 8 |
| API 06 Invoices | 3 | 4 | 1 | 8 |
| API 07 Payments | 1 | 1 | 0 | 2 |

### Authentication

| Method and path | Mandatory JSON | Optional JSON or query | Success | Declared errors |
| --- | --- | --- | --- | --- |
| `POST /auth/login` | `company`, `username`, `password` | None | 200 `success`, `result.access_token` | 401; 500 |
| `GET /auth/me` | None | None | 200 `IdentityResponse` | 401; 500; 503 |
| `POST /auth/logout` | None | None | 200 `LogoutResponse` | 400; 401; 415; 500; 503 |

### API 02 Customers

| Method and path | Mandatory JSON | Optional JSON or query | Success | Declared errors |
| --- | --- | --- | --- | --- |
| `POST /sales/customers` | `customer_id`, `customer_name`, `billing_address_line_1`, `city`, `country` | `action_reference` (optional retry key), `contact_name`, `email_address`, `phone_number`, `billing_address_line_2`, `state_province`, `postal_code`, `payment_terms`, `notes`, `status` | 201 `CustomerResponse` | 400; 401; 403; 409; 415; 500; 503 |
| `GET /sales/customers` | None | Query: `q`, `status`, `page`, `page_size` | 200 `CustomerListResponse` | 400; 401; 403; 500; 503 |
| `GET /sales/customers/{id}` | None | None | 200 `CustomerResponse` | 400; 401; 403; 404; 500; 503 |
| `PATCH /sales/customers/{id}` | at least one editable Customer field | `action_reference` (optional retry key), `customer_name`, `contact_name`, `email_address`, `phone_number`, `billing_address_line_1`, `billing_address_line_2`, `city`, `state_province`, `postal_code`, `country`, `payment_terms`, `notes`, `status` | 200 `CustomerResponse` | 400; 401; 403; 404; 409; 415; 500; 503 |
| `POST /sales/customers/{id}/update` | at least one editable Customer field | `action_reference` (optional retry key), `customer_name`, `contact_name`, `email_address`, `phone_number`, `billing_address_line_1`, `billing_address_line_2`, `city`, `state_province`, `postal_code`, `country`, `payment_terms`, `notes`, `status` | 200 `CustomerResponse` | 400; 401; 403; 404; 409; 415; 500; 503 |

### API 03 Quotes

| Method and path | Mandatory JSON | Optional JSON or query | Success | Declared errors |
| --- | --- | --- | --- | --- |
| `POST /sales/quotes` | `action_reference`, `customer_id`, `warehouse_id`, `lines` | `quote_date`, `valid_until`, `requested_delivery_date`, `shipping_address`, `payment_terms`, `notes` | 201 `QuoteResponse` | 400; 401; 403; 409; 415; 500; 503 |
| `GET /sales/quotes` | None | Query: `customer_id`, `warehouse_id`, `status`, `from_date`, `to_date`, `page`, `page_size` | 200 `QuoteListResponse` | 400; 401; 403; 500; 503 |
| `GET /sales/quotes/{quote_number}` | None | None | 200 `QuoteResponse` | 400; 401; 403; 404; 500; 503 |
| `PATCH /sales/quotes/{quote_number}` | `action_reference`, `expected_version`, `lines` | `customer_id`, `warehouse_id`, `quote_date`, `valid_until`, `requested_delivery_date`, `shipping_address`, `payment_terms`, `notes` | 200 `QuoteResponse` | 400; 401; 403; 404; 409; 415; 500; 503 |
| `POST /sales/quotes/{quote_number}/submit` | `action_reference`, `expected_version` | None | 200 `QuoteResponse` | 400; 401; 403; 404; 409; 415; 500; 503 |
| `POST /sales/quotes/{quote_number}/move-to-draft` | `action_reference`, `expected_version` | None | 200 `QuoteResponse` | 400; 401; 403; 404; 409; 415; 500; 503 |
| `POST /sales/quotes/{quote_number}/delete` | `action_reference`, `expected_version` | None | 200 `QuoteDeleteResponse` | 400; 401; 403; 404; 409; 415; 500; 503 |

### API 04 Orders

| Method and path | Mandatory JSON | Optional JSON or query | Success | Declared errors |
| --- | --- | --- | --- | --- |
| `POST /sales/orders` | `action_reference`, `lines`; standalone also requires `customer_id`, `warehouse_id` | `source_quote_number`, `customer_id`, `warehouse_id`, `order_date`, `requested_delivery_date`, `customer_po_number`, `shipping_address`, `payment_terms`, `notes` | 201 `OrderResponse` | 400; 401; 403; 409; 415; 500; 503 |
| `GET /sales/orders` | None | Query: `customer_id`, `warehouse_id`, `status`, `from_date`, `to_date`, `page`, `page_size` | 200 `OrderListResponse` | 400; 401; 403; 500; 503 |
| `GET /sales/orders/eligible-quotes` | None | Query: `customer_id`, `warehouse_id`, `from_date`, `to_date`, `page`, `page_size` | 200 `QuoteListResponse` | 400; 401; 403; 500; 503 |
| `GET /sales/orders/{order_number}` | None | None | 200 `OrderResponse` | 400; 401; 403; 404; 500; 503 |
| `PATCH /sales/orders/{order_number}` | `action_reference`, `expected_version`, `lines` | `source_quote_number`, `customer_id`, `warehouse_id`, `order_date`, `requested_delivery_date`, `customer_po_number`, `shipping_address`, `payment_terms`, `notes` | 200 `OrderResponse` | 400; 401; 403; 404; 409; 415; 500; 503 |
| `POST /sales/orders/{order_number}/submit` | `action_reference`, `expected_version` | None | 200 `OrderResponse` | 400; 401; 403; 404; 409; 415; 500; 503 |
| `POST /sales/orders/{order_number}/move-to-draft` | `action_reference`, `expected_version` | None | 200 `OrderResponse` | 400; 401; 403; 404; 409; 415; 500; 503 |
| `POST /sales/orders/{order_number}/delete` | `action_reference`, `expected_version` | None | 200 `OrderDeleteResponse` | 400; 401; 403; 404; 409; 415; 500; 503 |

### API 05 Shippers

| Method and path | Mandatory JSON | Optional JSON or query | Success | Declared errors |
| --- | --- | --- | --- | --- |
| `POST /sales/shippers` | `action_reference`, `lines`; standalone also requires `customer_id`, `warehouse_id` | `source_order_number`, `customer_id`, `warehouse_id`, `shipment_date`, `shipping_address`, `carrier`, `tracking_number`, `notes` | 201 `ShipperResponse` | 400; 401; 403; 409; 415; 500; 503 |
| `GET /sales/shippers` | None | Query: `customer_id`, `warehouse_id`, `status`, `from_date`, `to_date`, `page`, `page_size` | 200 `ShipperListResponse` | 400; 401; 403; 500; 503 |
| `GET /sales/shippers/eligible-orders` | None | Query: `customer_id`, `warehouse_id`, `from_date`, `to_date`, `page`, `page_size` | 200 `OrderListResponse` | 400; 401; 403; 500; 503 |
| `GET /sales/shippers/{shipper_number}` | None | None | 200 `ShipperResponse` | 400; 401; 403; 404; 500; 503 |
| `PATCH /sales/shippers/{shipper_number}` | `action_reference`, `expected_version`, `lines` | `source_order_number`, `customer_id`, `warehouse_id`, `shipment_date`, `shipping_address`, `carrier`, `tracking_number`, `notes` | 200 `ShipperResponse` | 400; 401; 403; 404; 409; 415; 500; 503 |
| `POST /sales/shippers/{shipper_number}/submit` | `action_reference`, `expected_version` | None | 200 `ShipperResponse` | 400; 401; 403; 404; 409; 415; 500; 503 |
| `POST /sales/shippers/{shipper_number}/move-to-draft` | `action_reference`, `expected_version` | None | 200 `ShipperResponse` | 400; 401; 403; 404; 409; 415; 500; 503 |
| `POST /sales/shippers/{shipper_number}/delete` | `action_reference`, `expected_version` | None | 200 `ShipperDeleteResponse` | 400; 401; 403; 404; 409; 415; 500; 503 |

### API 06 Invoices

| Method and path | Mandatory JSON | Optional JSON or query | Success | Declared errors |
| --- | --- | --- | --- | --- |
| `POST /sales/invoices` | `action_reference`, `lines`; standalone also requires `customer_id`, `warehouse_id` | `source_shipper_number`, `customer_id`, `warehouse_id`, `invoice_date`, `payment_terms`, `due_date`, `notes` | 201 `InvoiceResponse` | 400; 401; 403; 409; 415; 500; 503 |
| `GET /sales/invoices` | None | Query: `customer_id`, `warehouse_id`, `status`, `from_date`, `to_date`, `page`, `page_size`, `payment_status` | 200 `InvoiceListResponse` | 400; 401; 403; 500; 503 |
| `GET /sales/invoices/eligible-shippers` | None | Query: `customer_id`, `warehouse_id`, `from_date`, `to_date`, `page`, `page_size` | 200 `ShipperListResponse` | 400; 401; 403; 500; 503 |
| `GET /sales/invoices/{invoice_number}` | None | None | 200 `InvoiceResponse` | 400; 401; 403; 404; 500; 503 |
| `PATCH /sales/invoices/{invoice_number}` | `action_reference`, `expected_version`, `lines` | `source_shipper_number`, `customer_id`, `warehouse_id`, `invoice_date`, `payment_terms`, `due_date`, `notes` | 200 `InvoiceResponse` | 400; 401; 403; 404; 409; 415; 500; 503 |
| `POST /sales/invoices/{invoice_number}/submit` | `action_reference`, `expected_version` | None | 200 `InvoiceResponse` | 400; 401; 403; 404; 409; 415; 500; 503 |
| `POST /sales/invoices/{invoice_number}/move-to-draft` | `action_reference`, `expected_version` | None | 200 `InvoiceResponse` | 400; 401; 403; 404; 409; 415; 500; 503 |
| `POST /sales/invoices/{invoice_number}/delete` | `action_reference`, `expected_version` | None | 200 `InvoiceDeleteResponse` | 400; 401; 403; 404; 409; 415; 500; 503 |

### API 07 Payments

| Method and path | Mandatory JSON | Optional JSON or query | Success | Declared errors |
| --- | --- | --- | --- | --- |
| `POST /sales/invoices/{invoice_number}/payments` | `action_reference`, `expected_version`, `payment_amount`, `payment_date`, `payment_reference` | None | 201 `PaymentCreateResponse` | 400; 401; 403; 404; 409; 415; 500; 503 |
| `GET /sales/invoices/{invoice_number}/payments` | None | Query: `page`, `page_size` | 200 `PaymentHistoryResponse` | 400; 401; 403; 404; 500; 503 |

## Input fields

### Query and path parameters

The same query name can have different descriptions on document lists and source lookups, so the table retains its endpoint context. Query values do not belong in JSON.

| Operation | Parameter | Location | Required | Type and limits | Rule |
| --- | --- | --- | --- | --- | --- |
| `GET /sales/customers` | `q` | query | O | string; length 0..100 | Customer ID/name search. |
| `GET /sales/customers` | `status` | query | O | string; `ACTIVE`, `INACTIVE` | ACTIVE for new selections; omit for both statuses. |
| `GET /sales/customers`, `GET /sales/quotes`, `GET /sales/orders`, `GET /sales/orders/eligible-quotes`, `GET /sales/shippers`, `GET /sales/shippers/eligible-orders`, `GET /sales/invoices`, `GET /sales/invoices/eligible-shippers`, `GET /sales/invoices/{invoice_number}/payments` | `page` | query | O | integer; range 1..1000000; default `1` |  |
| `GET /sales/customers`, `GET /sales/quotes`, `GET /sales/orders`, `GET /sales/orders/eligible-quotes`, `GET /sales/shippers`, `GET /sales/shippers/eligible-orders`, `GET /sales/invoices`, `GET /sales/invoices/eligible-shippers`, `GET /sales/invoices/{invoice_number}/payments` | `page_size` | query | O | integer; range 1..100; default `25` |  |
| `GET /sales/customers/{id}`, `PATCH /sales/customers/{id}`, `POST /sales/customers/{id}/update` | `id` | path | M | integer; range 1..9999999999 | Generated numeric Customer record ID; lookup is restricted to the signed-in company. |
| `GET /sales/quotes`, `GET /sales/orders`, `GET /sales/orders/eligible-quotes`, `GET /sales/shippers`, `GET /sales/shippers/eligible-orders`, `GET /sales/invoices`, `GET /sales/invoices/eligible-shippers` | `customer_id` | query | O | string; length 1..20; pattern `^[A-Za-z0-9-]+$` |  |
| `GET /sales/quotes`, `GET /sales/orders`, `GET /sales/orders/eligible-quotes`, `GET /sales/shippers`, `GET /sales/shippers/eligible-orders`, `GET /sales/invoices`, `GET /sales/invoices/eligible-shippers` | `warehouse_id` | query | O | string; length 1..20 |  |
| `GET /sales/quotes`, `GET /sales/orders`, `GET /sales/shippers`, `GET /sales/invoices` | `status` | query | O | string; `Draft`, `Submitted` |  |
| `GET /sales/quotes`, `GET /sales/orders`, `GET /sales/orders/eligible-quotes`, `GET /sales/shippers`, `GET /sales/shippers/eligible-orders`, `GET /sales/invoices`, `GET /sales/invoices/eligible-shippers` | `from_date` | query | O | string (date) |  |
| `GET /sales/quotes`, `GET /sales/orders`, `GET /sales/orders/eligible-quotes`, `GET /sales/shippers`, `GET /sales/shippers/eligible-orders`, `GET /sales/invoices`, `GET /sales/invoices/eligible-shippers` | `to_date` | query | O | string (date) |  |
| `GET /sales/quotes/{quote_number}`, `PATCH /sales/quotes/{quote_number}`, `POST /sales/quotes/{quote_number}/submit`, `POST /sales/quotes/{quote_number}/move-to-draft`, `POST /sales/quotes/{quote_number}/delete` | `quote_number` | path | M | string; length 1..20; pattern `^[A-Za-z0-9_-]+$` |  |
| `GET /sales/orders/{order_number}`, `PATCH /sales/orders/{order_number}`, `POST /sales/orders/{order_number}/submit`, `POST /sales/orders/{order_number}/move-to-draft`, `POST /sales/orders/{order_number}/delete` | `order_number` | path | M | string; length 1..20; pattern `^[A-Za-z0-9_-]+$` |  |
| `GET /sales/shippers/{shipper_number}`, `PATCH /sales/shippers/{shipper_number}`, `POST /sales/shippers/{shipper_number}/submit`, `POST /sales/shippers/{shipper_number}/move-to-draft`, `POST /sales/shippers/{shipper_number}/delete` | `shipper_number` | path | M | string; length 1..20; pattern `^[A-Za-z0-9_-]+$` |  |
| `GET /sales/invoices` | `payment_status` | query | O | string; `Unpaid`, `Partially Paid`, `Paid` | Submitted Invoices only. |
| `GET /sales/invoices/{invoice_number}`, `PATCH /sales/invoices/{invoice_number}`, `POST /sales/invoices/{invoice_number}/submit`, `POST /sales/invoices/{invoice_number}/move-to-draft`, `POST /sales/invoices/{invoice_number}/delete` | `invoice_number` | path | M | string; length 1..20; pattern `^[A-Za-z0-9_-]+$` |  |
| `POST /sales/invoices/{invoice_number}/payments`, `GET /sales/invoices/{invoice_number}/payments` | `invoice_number` | path | M | string; length 1..20; pattern `^[A-Za-z0-9_-]+$` |  |

### Request controls

`action_reference` is the safe-retry key, unique within the company; it is not the YAML `operationId` or the business Payment Reference. An identical authorized retry returns the committed result. Reusing a reference for different input conflicts.

`expected_version` is the current saved revision for document mutations. Customers have no version column or revision token; their shared response envelope has `current_version: null`. Payment recording uses the **Invoice revision**, not a Payment revision. New creates do not accept it.

| Field | Type and limits | Rule |
| --- | --- | --- |
| `action_reference` | string; length 1..100; pattern `^[A-Za-z0-9_-]+$` | M on every Sales write. Preserve the original value and full input on retry. |
| `expected_version` | integer; range 1..9999999999 | M on document PATCH, lifecycle actions and Payment POST; not accepted for Customers. Read the latest record first. |

### Authentication input

| Field | Required | Type |
| --- | --- | --- |
| `company` | M | string |
| `username` | M | string |
| `password` | M | string (password) |

GET `/auth/me` needs only the bearer token. POST `/auth/logout` needs the bearer token, Content-Type application/json and an empty JSON body (`{}`). Logout revokes only the supplied token; it does not end the separate browser session. An already revoked token returns 401.

### Customer fields

Create requirements are shown below. On Customer PATCH or POST update, provide `action_reference` plus at least one editable field; all editable Customer fields are individually optional. The server generates numeric `id` for URLs such as `/sales/customers/1`; it is required in Customer outputs and forbidden in create/update input. The entered business `customer_id` remains unique within the company and immutable. Document requests/filters continue to use business `customer_id`; no `customer_code` is introduced.

| Field | Create | PATCH | Type and limits | Rule |
| --- | --- | --- | --- | --- |
| `customer_name` | M | O | string; length 1..100 |  |
| `contact_name` | O | O | string; length 0..100 |  |
| `email_address` | O | O | string; length 0..254 | Validate if nonempty. |
| `phone_number` | O | O | string; length 0..25; pattern `^[0-9 +()\-]*$` | Digits, spaces, +, -, parentheses only. |
| `billing_address_line_1` | M | O | string; length 1..150 |  |
| `billing_address_line_2` | O | O | string; length 0..150 |  |
| `city` | M | O | string; length 1..80 |  |
| `state_province` | O | O | string; length 0..80 |  |
| `postal_code` | O | O | string; length 0..20 | Text; preserve leading zeros. |
| `country` | M | O | string; length 1..80 | Free-text country name. |
| `payment_terms` | D | O | string; `DUE_ON_RECEIPT`, `NET_15`, `NET_30`; default `"NET_30"` |  |
| `notes` | O | O | string; length 0..500 |  |
| `status` | D | O | string; `ACTIVE`, `INACTIVE`; default `"ACTIVE"` |  |
| `customer_id` | M | Forbidden | string; length 1..20; pattern `^[A-Za-z0-9-]+$` | Unique within company; immutable after save. |

`status` defaults to `ACTIVE`; PATCH can deactivate/reactivate. Inactive Customers disappear from new selections; historical references and payments against existing Submitted Invoices remain valid. `amount_customer_owes_us` is calculated and rejected in input.

### Quote header fields

| Field | Create | Type and limits | Rule |
| --- | --- | --- | --- |
| `customer_id` | M | string; length 1..20; pattern `^[A-Za-z0-9-]+$` | Active Customer; editable on a Draft Quote. |
| `warehouse_id` | M | string; length 1..20 | Active Inventory Warehouse in the signed-in company; applies to all lines. |
| `quote_date` | O / default or copy as described | string (date) | Quote Date; defaults to the configured current business date on creation. |
| `valid_until` | O / default or copy as described | string / null (date) | Optional; on/after Quote Date. Null clears it on update. |
| `requested_delivery_date` | O / default or copy as described | string / null (date) | Optional; on/after Quote Date. Null clears it on update. |
| `shipping_address` | O / default or copy as described | string; length 1..500 | Defaults from Customer billing address on creation; editable and saved as a snapshot. |
| `payment_terms` | O / default or copy as described | string; `DUE_ON_RECEIPT`, `NET_15`, `NET_30` | Defaults from Customer on creation; editable and saved as a snapshot. |
| `notes` | O / default or copy as described | string; length 0..500 |  |

PATCH uses these header fields as optional changes and **requires the complete intended `lines` collection**, `action_reference` and `expected_version`. The resulting saved document must remain valid.

### Order header fields

| Field | Create | Type and limits | Rule |
| --- | --- | --- | --- |
| `source_quote_number` | O / default or copy as described | string; length 1..20; pattern `^[A-Za-z0-9_-]+$` OR null; default `null` | Optional source number. Create: omit/null for standalone. PATCH: omit to retain, null to clear, number to select/replace. A source must be readable, same-company, Submitted and unused by another target. Inherited-value mismatch: {Field label} must match the source document. |
| `customer_id` | C: standalone | string; length 1..20; pattern `^[A-Za-z0-9-]+$` | Active Customer; copied and locked while a Quote source is retained. |
| `warehouse_id` | C: standalone | string; length 1..20 | Active Inventory Warehouse; copied and locked when linked. |
| `order_date` | O / default or copy as described | string (date) | Defaults to business date on creation; on/after source Quote Date when linked. |
| `requested_delivery_date` | O / default or copy as described | string / null (date) | Optional; on/after Order Date. Null clears it on update. |
| `customer_po_number` | O / default or copy as described | string; length 0..30 |  |
| `shipping_address` | O / default or copy as described | string; length 1..500 | Required saved value. Customer default standalone; copied and locked when linked. |
| `payment_terms` | O / default or copy as described | string; `DUE_ON_RECEIPT`, `NET_15`, `NET_30` | Customer default standalone; copied and locked when linked. |
| `notes` | O / default or copy as described | string; length 0..500 |  |

PATCH uses these header fields as optional changes and **requires the complete intended `lines` collection**, `action_reference` and `expected_version`. The resulting saved document must remain valid.

### Shipper header fields

| Field | Create | Type and limits | Rule |
| --- | --- | --- | --- |
| `source_order_number` | O / default or copy as described | string; length 1..20; pattern `^[A-Za-z0-9_-]+$` OR null; default `null` | Optional source number. Create: omit/null for standalone. PATCH: omit to retain, null to clear, number to select/replace. A source must be readable, same-company, Submitted and unused by another target. Inherited-value mismatch: {Field label} must match the source document. |
| `customer_id` | C: standalone | string; length 1..20; pattern `^[A-Za-z0-9-]+$` | Active Customer; copied and locked while an Order source is retained. |
| `warehouse_id` | C: standalone | string; length 1..20 | Active Inventory Warehouse; copied and locked when linked. |
| `shipment_date` | O / default or copy as described | string (date) | Defaults to business date. Not future; on/after source Order Date when linked. Errors: Shipment Date cannot be in the future. / Shipment Date must be on or after Order Date. |
| `shipping_address` | O / default or copy as described | string; length 1..500 | Required saved value. Customer default standalone; copied and locked when linked. |
| `carrier` | O / default or copy as described | string; length 0..100 | Optional/empty on Draft; required on submit. Error: Enter Carrier before submitting the Shipper. |
| `tracking_number` | O / default or copy as described | string; length 0..50 |  |
| `notes` | O / default or copy as described | string; length 0..500 |  |

PATCH uses these header fields as optional changes and **requires the complete intended `lines` collection**, `action_reference` and `expected_version`. The resulting saved document must remain valid.

### Invoice header fields

| Field | Create | Type and limits | Rule |
| --- | --- | --- | --- |
| `source_shipper_number` | O / default or copy as described | string; length 1..20; pattern `^[A-Za-z0-9_-]+$` OR null; default `null` | Optional source number. Create: omit/null for standalone. PATCH: omit to retain, null to clear, number to select/replace. A source must be readable, same-company, Submitted and unused by another target. Inherited-value mismatch: {Field label} must match the source document. |
| `customer_id` | C: standalone | string; length 1..20; pattern `^[A-Za-z0-9-]+$` | Active Customer; copied and locked when Shipper-linked. |
| `warehouse_id` | C: standalone | string; length 1..20 | Active Inventory Warehouse; copied and locked when linked. |
| `invoice_date` | O / default or copy as described | string (date) | Defaults to business date at creation; on/after source Shipment Date. Future Invoice Dates are not prohibited. |
| `payment_terms` | O / default or copy as described | string; `DUE_ON_RECEIPT`, `NET_15`, `NET_30` | Required saved value; default from source if supplied, otherwise Customer. Current Shipper supplies no terms: use Customer. Editable on Draft, including linked Invoices. |
| `due_date` | O / default or copy as described | string (date) | On/after Invoice Date. Default: Invoice Date + 0/15/30 calendar days for Due on receipt/Net 15/Net 30. If Invoice Date or terms changes, recompute unless due_date is explicitly supplied. |
| `notes` | O / default or copy as described | string; length 0..500 |  |

PATCH uses these header fields as optional changes and **requires the complete intended `lines` collection**, `action_reference` and `expected_version`. The resulting saved document must remain valid.

### Header defaults and source conditions

- Creation date defaults to the configured business date. Quote/Order/Invoice dates may be future dates; Shipment Date cannot be in the future. Valid Until and Requested Delivery Date cannot precede their document date. Linked Order Date is on/after Quote Date; Shipment Date is on/after Order Date; Invoice Date is on/after Shipment Date.
- For standalone creation, address/terms default from Customer where those fields exist. Saved address/terms remain required where applicable. Invoice has no shipping-address input; Shipper has no payment-terms field.
- Quote has no source. Order may copy a full Submitted Quote; Shipper a full Submitted Order; Invoice a full Submitted Shipper. Linked creation can omit inherited headers; supplied inherited values must match. Always submit every copied line. Inherited Customer, Warehouse and lines are locked; address/terms are locked where inherited by the target contract.
- Source omission on PATCH retains the link, `null` clears it, and a number selects/replaces it. Changing source requires complete validation and atomic release/claim. Replacing source uses the new source line IDs and omits old target line IDs. Clearing source removes `source_line_id` inputs.
- A source is claimed on Draft save, with one target per source. Eligible-source lookup does not reserve it. Deleting the target Draft releases it; returning the target to Draft retains its claim. A downstream Draft already blocks returning its source to Draft.
- Shipper Carrier may be empty in a Draft but is mandatory for submission. Shipper submission confirms dispatch and checks Logistics authorization for both standalone and linked documents.
- Invoice Payment Terms default from Customer when omitted, including when linked because Shipper has no terms. Invoice Due Date defaults to Invoice Date plus 0/15/30 calendar days. When Invoice Date or terms actually change on PATCH and Due Date is omitted, recompute it; an explicit valid Due Date wins. Due Date cannot precede Invoice Date.

### Document lines

All four document creates and PATCH operations require at least one line and the complete intended collection in display order. Retain saved line IDs when editing; omit IDs for new lines; omit saved lines to remove them. Create rejects `line_id`. The same Item can appear on multiple distinct lines.

| Field | Required input | Type and limits | Rule |
| --- | --- | --- | --- |
| `line_id` | O on PATCH; forbidden on create | string; length 1..40 | Retain for an existing line; omit for a new line. |
| `item_code` | M | string; length 1..30 | Active Item available to the signed-in company; Inventory-owned. |
| `description` | M | string; length 1..200 |  |
| `quantity` | M | string; pattern `^(?=[0-9.]*[1-9])[0-9]{1,9}(?:\.[0-9]{1,3})?$` | Greater than zero; at most 9 whole digits and 3 decimals. |
| `unit_price` | M | string; pattern `^[0-9]{1,10}(?:\.[0-9]{1,2})?$` | Zero or greater; at most 10 whole digits and 2 decimals. |
| `source_line_id` | C: linked document | string; length 1..40 | Saved line ID from the immediate source document; required when linked. The target has its own line_id. |

`source_line_id` belongs to the immediate predecessor. It is required on copied lines, forbidden for standalone inputs and absent from Quote line inputs. A retained linked document must echo its unchanged complete lines, including its own line IDs and source line IDs. The service must check existing source state when source is omitted on PATCH.

Server-generated line fields are `line_number`, `warehouse_id` and `line_amount`. Warehouse comes from the header; no per-line Warehouse selection. Round each quantity × unit price half up to 2 decimals, then sum rounded line amounts into Document Total. Reject capacity overflow.

### Payment input

All five JSON fields are mandatory; there are no optional Payment input fields. Invoice Number is mandatory in the path.

| Field | Type and limits | Rule |
| --- | --- | --- |
| `action_reference` | string; length 1..100; pattern `^[A-Za-z0-9_-]+$` | Request identifier for safe retries. |
| `expected_version` | integer; range 1..9999999999 | Current Invoice revision, not a Payment revision. Read the Invoice first. |
| `payment_amount` | string; pattern `^(?=[0-9.]*[1-9])[0-9]{1,16}(?:\.[0-9]{1,2})?$` | Greater than zero; at most 16 whole digits and 2 decimals. Cannot exceed the locked current Amount Due. Send a decimal string; do not round/truncate invalid input. |
| `payment_date` | string (date) | Required; on/after Invoice Date and not after configured current business date. |
| `payment_reference` | string; length 1..50; pattern `\S` | Required business receipt/transfer reference; trim before validation. Not a unique bank transaction ID or API retry key. |

Payment Amount is a positive decimal string with up to 16 whole digits and 2 decimal places, no more than the current locked Invoice due. Payment Date must be on/after Invoice Date and no later than the business date. Payment Reference is trimmed, nonblank business text; it is not required to be unique.

Only Submitted Invoices with a positive due accept new payments. Lock/recheck the Invoice, insert the immutable payment and increment the Invoice revision atomically. Authorize before replay; resolve identical committed retries before current revision/state/due checks, including after the final payment. A new stale request against a Submitted Invoice receives the current-due conflict message.

### Lifecycle action input

| Action | Required body | Condition and effect |
| --- | --- | --- |
| `submit` | `action_reference`, `expected_version` | Saved valid Draft → Submitted. Invoice total must be greater than zero; Shipper adds Carrier/date/Logistics checks. |
| `move-to-draft` | `action_reference`, `expected_version` | Submitted → Draft only when no downstream use. Any recorded payment blocks Invoice return. |
| `delete` | `action_reference`, `expected_version` | POST action, Draft only, no downstream documents. Releases source claim. |

These actions operate on the saved record and accept no unsaved header/line edits. Payment has no edit/delete lifecycle and Customer has no delete endpoint.

## Outputs

### Envelopes

Except for the intentionally minimal login schema, success responses require `success`, `operation_id`, `current_version`, `result` and `error`. `success` is true and `error` is null. `operation_id` echoes the Sales action reference on writes; list and identity responses use null. `current_version` identifies the saved document revision; Customer, list, identity and delete results use null. Payment POST returns the updated Invoice revision.

Lists return `result: {items: [...], page, page_size}`. The current contract does not promise a total count or next-page token. Detail/create/update/submit/return responses return the full relevant entity in `result`. Delete returns its document number and `deleted: true`.

Login currently documents only `success` and `result.access_token`, without required-property lists. The backend response shown earlier contains more envelope/token fields; those are not yet specified by the login contract.

`LogoutResponse` uses the success envelope with null operation_id/current_version and required `result.revoked: true`. Logout declares 400 for malformed/oversized JSON, 401 for missing/invalid/revoked token, 415 for wrong Content-Type, 500 for unexpected failure and 503 for database/configuration unavailability.

### Entity fields

The following are fields inside the entity, not the outer envelope. **Required in output** means the key must be present; it does not mean the caller must supply it. Optional text normally returns an empty string; nullable dates and source references return null when absent.

#### Identity

| Field | Required in output | Type and limits | Meaning |
| --- | --- | --- | --- |
| `id` | Yes | string |  |
| `username` | Yes | string |  |
| `company_id` | Yes | integer |  |
| `company_name` | Yes | string |  |
| `roles` | Yes | array of string |  |
| `is_admin` | Yes | boolean | Current trusted admin flag used by Sales services for access decisions. |
| `must_change_password` | Yes | boolean |  |
| `permissions` | Yes | array of string | Current effective shared permissions; Sales routes and services enforce these same registered object/operation permissions. |

#### Customer

| Field | Required in output | Type and limits | Meaning |
| --- | --- | --- | --- |
| `customer_name` | Yes | string; length 1..100 |  |
| `contact_name` | Yes | string; length 0..100 |  |
| `email_address` | Yes | string; length 0..254 | Validate if nonempty. |
| `phone_number` | Yes | string; length 0..25; pattern `^[0-9 +()\-]*$` | Digits, spaces, +, -, parentheses only. |
| `billing_address_line_1` | Yes | string; length 1..150 |  |
| `billing_address_line_2` | Yes | string; length 0..150 |  |
| `city` | Yes | string; length 1..80 |  |
| `state_province` | Yes | string; length 0..80 |  |
| `postal_code` | Yes | string; length 0..20 | Text; preserve leading zeros. |
| `country` | Yes | string; length 1..80 | Free-text country name. |
| `payment_terms` | Yes | string; `DUE_ON_RECEIPT`, `NET_15`, `NET_30` |  |
| `notes` | Yes | string; length 0..500 |  |
| `status` | Yes | string; `ACTIVE`, `INACTIVE` |  |
| `id` | Yes | integer; range 1..9999999999 | Server-generated numeric record ID used in Customer URLs; never supplied on create or update. |
| `customer_id` | Yes | string; length 1..20; pattern `^[A-Za-z0-9-]+$` | Company-unique and immutable after first save. |
| `amount_customer_owes_us` | Yes | string; pattern `^[0-9]{1,16}\.[0-9]{2}$`; default `"0.00"` | Outstanding Submitted Invoices less recorded payments. No opening balances, credits or refunds. |

#### Quote

| Field | Required in output | Type and limits | Meaning |
| --- | --- | --- | --- |
| `customer_id` | Yes | string; length 1..20; pattern `^[A-Za-z0-9-]+$` | Active Customer; editable on a Draft Quote. |
| `warehouse_id` | Yes | string; length 1..20 | Active Inventory Warehouse in the signed-in company; applies to all lines. |
| `quote_date` | Yes | string (date) | Quote Date; defaults to the configured current business date on creation. |
| `valid_until` | Yes | string / null (date) | Optional; on/after Quote Date. Null clears it on update. |
| `requested_delivery_date` | Yes | string / null (date) | Optional; on/after Quote Date. Null clears it on update. |
| `shipping_address` | Yes | string; length 1..500 | Defaults from Customer billing address on creation; editable and saved as a snapshot. |
| `payment_terms` | Yes | string; `DUE_ON_RECEIPT`, `NET_15`, `NET_30` | Defaults from Customer on creation; editable and saved as a snapshot. |
| `notes` | Yes | string; length 0..500 |  |
| `quote_number` | Yes | string; length 1..20; pattern `^[A-Za-z0-9_-]+$` | Generated document number; read-only, company/type-unique and never reused, even after deletion. |
| `status` | Yes | string; `Draft`, `Submitted` |  |
| `document_total` | Yes | string; pattern `^[0-9]{1,16}\.[0-9]{2}$` | Calculated using exact decimals; maximum 16 whole digits and 2 decimals. |
| `current_version` | Yes | integer; range 1..9999999999 |  |
| `available_actions` | Yes | array of string; `edit`, `submit`, `move_to_draft`, `delete` | Current eligible actions; authorization and business conditions are rechecked when executed. |
| `lines` | Yes | array of SalesLine; at least 1 item | Full lines, including generated IDs, display order, inherited Warehouse and calculated amount. |

#### Order

| Field | Required in output | Type and limits | Meaning |
| --- | --- | --- | --- |
| `source_quote_number` | Yes | string; length 1..20; pattern `^[A-Za-z0-9_-]+$` OR null; default `null` | Optional source number. Create: omit/null for standalone. PATCH: omit to retain, null to clear, number to select/replace. A source must be readable, same-company, Submitted and unused by another target. Inherited-value mismatch: {Field label} must match the source document. |
| `customer_id` | Yes | string; length 1..20; pattern `^[A-Za-z0-9-]+$` | Active Customer; copied and locked while a Quote source is retained. |
| `warehouse_id` | Yes | string; length 1..20 | Active Inventory Warehouse; copied and locked when linked. |
| `order_date` | Yes | string (date) | Defaults to business date on creation; on/after source Quote Date when linked. |
| `requested_delivery_date` | Yes | string / null (date) | Optional; on/after Order Date. Null clears it on update. |
| `customer_po_number` | Yes | string; length 0..30 |  |
| `shipping_address` | Yes | string; length 1..500 | Required saved value. Customer default standalone; copied and locked when linked. |
| `payment_terms` | Yes | string; `DUE_ON_RECEIPT`, `NET_15`, `NET_30` | Customer default standalone; copied and locked when linked. |
| `notes` | Yes | string; length 0..500 |  |
| `order_number` | Yes | string; length 1..20; pattern `^[A-Za-z0-9_-]+$` | Generated document number; read-only, company/type-unique and never reused, even after deletion. |
| `status` | Yes | string; `Draft`, `Submitted` |  |
| `document_total` | Yes | string; pattern `^[0-9]{1,16}\.[0-9]{2}$` | Calculated using exact decimals; maximum 16 whole digits and 2 decimals. |
| `current_version` | Yes | integer; range 1..9999999999 |  |
| `available_actions` | Yes | array of string; `edit`, `submit`, `move_to_draft`, `delete` |  |
| `lines` | Yes | array of SalesSourceLine; at least 1 item | Full lines, including generated IDs, display order, inherited Warehouse and calculated amount. |

#### Shipper

| Field | Required in output | Type and limits | Meaning |
| --- | --- | --- | --- |
| `source_order_number` | Yes | string; length 1..20; pattern `^[A-Za-z0-9_-]+$` OR null; default `null` | Optional source number. Create: omit/null for standalone. PATCH: omit to retain, null to clear, number to select/replace. A source must be readable, same-company, Submitted and unused by another target. Inherited-value mismatch: {Field label} must match the source document. |
| `customer_id` | Yes | string; length 1..20; pattern `^[A-Za-z0-9-]+$` | Active Customer; copied and locked while an Order source is retained. |
| `warehouse_id` | Yes | string; length 1..20 | Active Inventory Warehouse; copied and locked when linked. |
| `shipment_date` | Yes | string (date) | Defaults to business date. Not future; on/after source Order Date when linked. Errors: Shipment Date cannot be in the future. / Shipment Date must be on or after Order Date. |
| `shipping_address` | Yes | string; length 1..500 | Required saved value. Customer default standalone; copied and locked when linked. |
| `carrier` | Yes | string; length 0..100 | Optional/empty on Draft; required on submit. Error: Enter Carrier before submitting the Shipper. |
| `tracking_number` | Yes | string; length 0..50 |  |
| `notes` | Yes | string; length 0..500 |  |
| `shipper_number` | Yes | string; length 1..20; pattern `^[A-Za-z0-9_-]+$` | Generated document number; read-only, company/type-unique and never reused, even after deletion. |
| `status` | Yes | string; `Draft`, `Submitted` |  |
| `document_total` | Yes | string; pattern `^[0-9]{1,16}\.[0-9]{2}$` | Calculated using exact decimals; maximum 16 whole digits and 2 decimals. |
| `current_version` | Yes | integer; range 1..9999999999 |  |
| `available_actions` | Yes | array of string; `edit`, `submit`, `move_to_draft`, `delete` |  |
| `lines` | Yes | array of SalesSourceLine; at least 1 item | Full lines, including generated IDs, display order, inherited Warehouse and calculated amount. |

#### Invoice

| Field | Required in output | Type and limits | Meaning |
| --- | --- | --- | --- |
| `source_shipper_number` | Yes | string; length 1..20; pattern `^[A-Za-z0-9_-]+$` OR null; default `null` | Optional source number. Create: omit/null for standalone. PATCH: omit to retain, null to clear, number to select/replace. A source must be readable, same-company, Submitted and unused by another target. Inherited-value mismatch: {Field label} must match the source document. |
| `customer_id` | Yes | string; length 1..20; pattern `^[A-Za-z0-9-]+$` | Active Customer; copied and locked when Shipper-linked. |
| `warehouse_id` | Yes | string; length 1..20 | Active Inventory Warehouse; copied and locked when linked. |
| `invoice_date` | Yes | string (date) | Defaults to business date at creation; on/after source Shipment Date. Future Invoice Dates are not prohibited. |
| `payment_terms` | Yes | string; `DUE_ON_RECEIPT`, `NET_15`, `NET_30` | Required saved value; default from source if supplied, otherwise Customer. Current Shipper supplies no terms: use Customer. Editable on Draft, including linked Invoices. |
| `due_date` | Yes | string (date) | On/after Invoice Date. Default: Invoice Date + 0/15/30 calendar days for Due on receipt/Net 15/Net 30. If Invoice Date or terms changes, recompute unless due_date is explicitly supplied. |
| `notes` | Yes | string; length 0..500 |  |
| `invoice_number` | Yes | string; length 1..20; pattern `^[A-Za-z0-9_-]+$` | Generated document number; read-only, company/type-unique and never reused, even after deletion. |
| `status` | Yes | string; `Draft`, `Submitted` |  |
| `current_version` | Yes | integer; range 1..9999999999 |  |
| `available_actions` | Yes | array of string; `edit`, `submit`, `move_to_draft`, `delete`, `record_payment` |  |
| `document_total` | Yes | string; pattern `^[0-9]{1,16}\.[0-9]{2}$` | Calculated using exact decimals; maximum 16 whole digits and 2 decimals. |
| `total_paid` | Yes | string; pattern `^[0-9]{1,16}\.[0-9]{2}$` | Sum of recorded payments; 0.00 on Draft. |
| `amount_due` | Yes | string; pattern `^[0-9]{1,16}\.[0-9]{2}$` | Invoice Total minus Total Paid, including on Draft. Only Submitted Invoice dues contribute to Customer balance. |
| `payment_status` | Yes | string / null; `Unpaid`, `Partially Paid`, `Paid`, `null` | Null on Draft. Paid is a payment status; document status remains Submitted. |
| `lines` | Yes | array of SalesSourceLine; at least 1 item | Full lines, including generated IDs, display order, inherited Warehouse and calculated amount. |

#### SalesSourceLine

| Field | Required in output | Type and limits | Meaning |
| --- | --- | --- | --- |
| `line_id` | Yes | string; length 1..40 | Retain for an existing line; omit for a new line. |
| `item_code` | Yes | string; length 1..30 | Active Item available to the signed-in company; Inventory-owned. |
| `description` | Yes | string; length 1..200 |  |
| `quantity` | Yes | string; pattern `^(?=[0-9.]*[1-9])[0-9]{1,9}(?:\.[0-9]{1,3})?$` | Greater than zero; at most 9 whole digits and 3 decimals. |
| `unit_price` | Yes | string; pattern `^[0-9]{1,10}(?:\.[0-9]{1,2})?$` | Zero or greater; at most 10 whole digits and 2 decimals. |
| `line_number` | Yes | integer; range 1..unbounded |  |
| `warehouse_id` | Yes | string; length 1..20 | Inherited from the header; never editable per line. |
| `line_amount` | Yes | string; pattern `^[0-9]{1,16}\.[0-9]{2}$` | Calculated using exact decimals; maximum 16 whole digits and 2 decimals. |
| `source_line_id` | Yes | string / null; length 1..40 | Immediate source document line ID, or null on standalone lines. |

#### PaymentRecord

| Field | Required in output | Type and limits | Meaning |
| --- | --- | --- | --- |
| `payment_number` | Yes | string; length 1..20 | Generated immutable Payment Number; unique within the company. |
| `invoice_number` | Yes | string; length 1..20; pattern `^[A-Za-z0-9_-]+$` | Generated document number; read-only, company/type-unique and never reused, even after deletion. |
| `payment_amount` | Yes | string; pattern `^(?=[0-9.]*[1-9])[0-9]{1,16}\.[0-9]{2}$` | Recorded immutable amount, normalized to two decimals. |
| `payment_date` | Yes | string (date) | Required; on/after Invoice Date and not after configured current business date. |
| `payment_reference` | Yes | string; length 1..50; pattern `\S` | Required business receipt/transfer reference; trim before validation. Not a unique bank transaction ID or API retry key. |
| `created_by` | Yes | string; length 1..40 | Authenticated recording user ID. |
| `created_at` | Yes | string (date-time) | Server recording timestamp in UTC. |

#### PaymentInvoiceSnapshot

| Field | Required in output | Type and limits | Meaning |
| --- | --- | --- | --- |
| `document_total` | Yes | string; pattern `^(?=[0-9.]*[1-9])[0-9]{1,16}\.[0-9]{2}$` | Calculated using exact decimals; maximum 16 whole digits and 2 decimals. |
| `total_paid` | Yes | string; pattern `^[0-9]{1,16}\.[0-9]{2}$` | Positive total recorded payments after this payment. |
| `amount_due` | Yes | string; pattern `^[0-9]{1,16}\.[0-9]{2}$` | Invoice Total minus Total Paid, including on Draft. Only Submitted Invoice dues contribute to Customer balance. |
| `payment_status` | Yes | string / null; `Partially Paid`, `Paid` | After this positive payment: Partially Paid or Paid; never null. |
| `invoice_number` | Yes | string; length 1..20; pattern `^[A-Za-z0-9_-]+$` | Generated document number; read-only, company/type-unique and never reused, even after deletion. |
| `status` | Yes | string; constant `"Submitted"` |  |
| `current_version` | Yes | integer; range 1..9999999999 |  |

### List and delete output models

| Models | Result |
| --- | --- |
| `CustomerListResponse` | `items` are full Customer objects; `page`, `page_size`. |
| `QuoteListResponse`, `OrderListResponse`, `ShipperListResponse`, `InvoiceListResponse` | `items` are the corresponding Summary objects: saved header, number, status, totals, version and available actions; full `lines` are excluded. Invoice summaries include payment totals/status. |
| Eligible Quote / Order / Shipper GETs | The corresponding source ListResponse. Dates/filter rules refer to the source type; only eligible Submitted sources are returned. |
| `QuoteDeleteResponse`, `OrderDeleteResponse`, `ShipperDeleteResponse`, `InvoiceDeleteResponse` | `result` contains the corresponding `*_number` and `deleted: true`; envelope `current_version` is null. |
| `PaymentCreateResponse` | `result: {payment: PaymentRecord, invoice: PaymentInvoiceSnapshot}`; snapshot revision matches envelope revision. |
| `PaymentHistoryResponse` | `result: {items: PaymentRecord[], page, page_size}`; empty items are valid for an existing Invoice with no payments, including a Draft. Sort by created_at then payment_number. Missing/cross-company Invoice is 404. |

### Calculations and output actions

- Quantity is a positive decimal string (9 whole, 3 fractional digits); Unit Price is nonnegative (10 whole, 2 fractional digits). Line Amount/Document Total and balance totals use decimal strings up to 16 whole digits with 2 output decimals. Payment Amount input allows one or two fractional digits or none; output has exactly 2.
- Invoice `total_paid` = sum of recorded payments, `amount_due` = document_total − total_paid. Draft `payment_status` is null; Submitted status is `Unpaid`, `Partially Paid` or `Paid`. A zero-total Draft is allowed; submission requires a positive total.
- Customer `amount_customer_owes_us` = sum of all that Customer’s Submitted Invoice dues. Draft invoices contribute nothing, even though Draft amount_due reflects their total. No opening balances, credits or refunds.
- `available_actions` can contain `edit`, `submit`, `move_to_draft`, `delete`; Invoice also has `record_payment`. These reflect current permissions/state/dependencies. Fully paid Invoices have no further mutations. The action value `move_to_draft` corresponds to the route suffix `move-to-draft`.
- Example: Submitted Invoice 1,000.00 → record 300.00 → due 700.00; record another 700.00 → due 0.00. Payment creation returns that transaction’s Invoice snapshot. A replay can return an older snapshot; GET the Invoice for its latest state.

## Errors

The operation tables list each route’s exact declared HTTP error statuses. Components below supply the messages and conditions. Customer operations use Customer validation/conflict/not-found components. Quotes use SalesDocument variants; Orders and Shippers use SalesSourceConflict; Invoices use InvoiceConflict. Shipper submit overrides 403/503 with ShipperDispatchForbidden/ShipperSubmitUnavailable; Invoice submit overrides 400 with InvoiceSubmitValidationError. Payment POST uses PaymentValidationError/PaymentConflict; Payment GET uses common document validation/not-found errors. Login 401/500 currently have descriptions only; they do not declare an error body schema.

Other declared error responses use `ErrorResponse`: required `success: false`, `operation_id: null`, `current_version: null`, `result: null`, and `error`. Inside `error`, `code` and `message` are required strings and `request_id` is optional. A stale error does not promise the current version in the envelope.

| HTTP status | Purpose |
| --- | --- |
| 400 | Invalid fields, dates, calculated input, or business validation. |
| 401 | Missing/invalid authentication; protected routes declare WWW-Authenticate: Bearer. |
| 403 | Sales admin/password-change restriction; Shipper submit additionally checks dispatch permission. |
| 404 | Resource absent or outside the signed-in company. |
| 409 | Duplicate ID, stale version, action-reference reuse, state/source conflict. |
| 415 | Write is not application/json. |
| 500 | Unexpected server failure. |
| 503 | Database/configuration unavailable; Shipper submission can also fail on dispatch dependency. |

### InternalError

Unexpected server failure.

### Unavailable

Database or application configuration is unavailable.

### CustomerValidationError

Invalid input; save no changes. Exact messages:

- Missing required field: Enter {Field label}.
- Overlength: {Field label} must be {limit} characters or fewer.
- Customer ID cannot be changed after creation.
- Customer ID may contain only letters, numbers and hyphens.
- Enter a valid Email Address.
- Enter a valid Phone Number.
- Enter Country.
- Country must be 80 characters or fewer.
- Select a valid Status.
- Select valid Payment Terms.
- Amount Customer Owes Us is calculated and cannot be edited.

### CustomerUnauthenticated

Missing, malformed, expired or revoked bearer token.

### CustomerForbidden

The matching Sales object/operation permission is required; complete any required password change first.

### CustomerNotFound

Customer not found in the signed-in company.

### CustomerConflict

No changes committed.

- DUPLICATE_CUSTOMER_ID: Customer ID already exists. Enter a unique ID.
- DUPLICATE_ACTION_CONFLICT: This action reference belongs to a different request.

### CustomerUnsupportedMediaType

Writes require Content-Type application/json.

### SalesDocumentValidationError

Invalid input; preserve the whole saved document. Exact messages:

- Enter {Field label}. / {Field label} must be {limit} characters or fewer.
- Add at least one item line.
- Select a valid {Field label} available to your company.
- Selected {Customer / Item / Warehouse} is inactive. Select an active record.
- Enter a valid {Date label} in YYYY-MM-DD format.
- {Date label} must be on or after {Earlier date label}.
- From Date must be on or before To Date.
- Line {n}: Select a valid line belonging to this document.
- Line {n}: Duplicate line identifier.
- Line {n}: Select an active Item available to your company.
- Line {n}: Enter Description. / Line {n}: Description must be 200 characters or fewer.
- Line {n}: Enter Quantity greater than 0, with up to 9 whole digits and 3 decimal places.
- Line {n}: Enter Unit Price of 0 or more, with up to 10 whole digits and 2 decimal places.
- Line {n}: Warehouse must match the document Warehouse.
- Line {n}: Line Amount is calculated and cannot be edited.
- Document Total is calculated and cannot be edited.
- {Line Amount / Document Total} exceeds the allowed numeric limit.
- Use the available action to change Document Status.

### SalesDocumentConflict

No business changes committed. Exact messages:

- INVALID_STATE: Only Draft documents can be edited.
- INVALID_STATE: Only a saved Draft can be submitted.
- INVALID_STATE: Only Submitted documents can move to Draft.
- INVALID_STATE: Cannot move to Draft: this document is used as a source by {Document number}.
- INVALID_STATE: Only Draft documents with no downstream documents can be deleted.
- STALE_VERSION: This document has changed. Refresh and try again.
- DUPLICATE_ACTION_CONFLICT: This action reference belongs to a different request.

### SalesDocumentNotFound

Document not found in the signed-in company.

### SalesSourceConflict

No business changes committed. Exact messages:

- INVALID_STATE: Only Draft documents can be edited.
- INVALID_STATE: Only a saved Draft can be submitted.
- INVALID_STATE: Only Submitted documents can move to Draft.
- INVALID_STATE: Cannot move to Draft: this document is used as a source by {Document number}.
- INVALID_STATE: Only Draft documents with no downstream documents can be deleted.
- STALE_VERSION: This document has changed. Refresh and try again.
- DUPLICATE_ACTION_CONFLICT: This action reference belongs to a different request.
- SOURCE_UNAVAILABLE: Selected source is no longer available. Refresh and select another source, or leave Source blank.

### ShipperDispatchForbidden

the appropriate Sales permission and separate Logistics dispatch authorization are required, for standalone and linked Shippers. The actor/company come from the token; no caller authorization flag is accepted. Required password changes also block access.

Example error: `FORBIDDEN` — You do not have permission to perform this action.

### ShipperSubmitUnavailable

Database/Inventory validation unavailable, or required Logistics dispatch authorization is unconfigured/unavailable. Preserve Draft state and all saved data; never assume authorization.

Example error: `DEPENDENCY_UNAVAILABLE` — Logistics dispatch authorization is not available.

### InvoiceConflict

No business changes committed. Exact messages:

- INVALID_STATE: Only Draft documents can be edited.
- INVALID_STATE: Only a saved Draft can be submitted.
- INVALID_STATE: Only Submitted documents can move to Draft.
- INVALID_STATE: Cannot move to Draft: this document is used as a source by {Document number}.
- INVALID_STATE: Only Draft documents with no downstream documents can be deleted.
- STALE_VERSION: This document has changed. Refresh and try again.
- DUPLICATE_ACTION_CONFLICT: This action reference belongs to a different request.
- SOURCE_UNAVAILABLE: Selected source is no longer available. Refresh and select another source, or leave Source blank.
- INVALID_STATE: Cannot move to Draft: a payment has been recorded for this Invoice.

### InvoiceSubmitValidationError

Shared document, source, line and date validation applies. Additional exact message: Invoice Total must be greater than 0. Reject without status, balance or source changes.

Example error: `VALIDATION_ERROR` — Invoice Total must be greater than 0.

### PaymentValidationError

Invalid fields/date/amount; preserve all payment, Invoice and balance data. Exact messages:

- Enter Payment Amount. / Enter a valid Payment Amount.
- Payment Amount must be greater than 0.
- Payment Amount can have at most 2 decimal places.
- Payment Amount exceeds the allowed numeric limit.
- Payment Amount cannot exceed Amount Due ({current amount due}).
- Enter Payment Reference.
- Payment Reference must be 50 characters or fewer.
- Enter Payment Date.
- Enter a valid Payment Date in YYYY-MM-DD format.
- Payment Date must be on or after Invoice Date.
- Payment Date cannot be in the future.

Unsupported/read-only fields and invalid shared request controls are also rejected.

### PaymentConflict

No new effects. Exact messages:

- INVALID_STATE: Submit the Invoice before recording a payment.
- INVALID_STATE: This Invoice is fully paid. No further payment can be recorded.
- STALE_VERSION: Amount Due has changed to {current amount due}. Review the amount and try again.
- DUPLICATE_ACTION_CONFLICT: This action reference belongs to a different request.

Authorize and resolve identical committed retries before revision/state/due checks, including after final payment. On a new request against a Submitted Invoice, a stale Invoice revision returns the current-due message; with a current revision a Paid Invoice returns the fully-paid message.

### Additional validation conditions

Inherited header mismatches use `{Field label} must match the source document.` Linked-line edits use `Source-linked lines cannot be added, changed or removed.` Shipper rules include `Enter Carrier before submitting the Shipper.`, `Shipment Date cannot be in the future.` and `Shipment Date must be on or after Order Date.` Source eligibility and dependencies must be rechecked when saving/submitting, not trusted from an earlier lookup.

## Migration mapping

**Proposed storage mapping, not an applied migration.** API field names describe the public contract; physical names and shared-table choices need not match it. Use request requirements together with saved-record invariants, not request `required` lists alone, to decide database constraints.

| Contract requirement | Proposed storage or constraint |
| --- | --- |
| Company isolation | COMPANY_ID on every Sales entity; company-scoped keys, foreign keys and queries. Derive company from actor. |
| Customer public identity | ME_OE_CUSTOMERS primary key ID NUMBER(10), default ME_OE_CUSTOMER_SEQ.NEXTVAL starting at 1. Expose it as read-only numeric id for Customer URLs; scope lookups by company. Keep unique (COMPANY_ID, CUSTOMER_ID), VARCHAR2(20 CHAR), character check and business ID immutability. Existing draft follows this mapping; document business-key references remain valid. Sequence gaps are possible. |
| Customer metadata | Customer text lengths follow the field table. Saved name/address line 1/city/country required. Status ACTIVE/INACTIVE and payment-term checks/defaults. No writable balance column. |
| Quote / Order / Shipper / Invoice headers | Proposed ME_OE_DOCUMENTS with type, company, generated document number, Customer/Warehouse, applicable dates, optional text, terms and revision. Type-specific checks enforce required saved fields. Separate tables remain a physical alternative. |
| Generated public numbers | Numbers are strings up to 20 characters, unique per company/type as applicable and never reused after deletion. Configure prefixes/sequence scope and durable issued-number tracking before DDL; Oracle sequences can have gaps. Do not accept caller numbers. |
| Saved optional fields | Fields absent from a request may receive defaults/copies before insert. Shared header table must allow fields not applicable to some types. Oracle empty text becomes NULL; normalize optional text to empty strings in responses. |
| Document lifecycle | Persist Draft/Submitted state with an explicit mapping to any uppercase storage values. Use service checks for downstream use, revision and payments. Customer status is a separate enum. |
| Line ownership and order | Proposed ME_OE_DOCUMENT_LINES with document FK, generated line ID up to 40 chars and line number. Unique line position per document. At least one line is a transactional service invariant. |
| Item and Warehouse references | Inventory owns these records. Resolve item_code (up to 30) / warehouse_id (up to 20) against its company-scoped keys before final FKs. Warehouse is inherited from header. Preserve saved history. |
| Line numeric capacity | Quantity NUMBER(12,3), Unit Price NUMBER(12,2), amount NUMBER(18,2) are candidate Oracle types. Positive/nonnegative checks. Validate input precision before insert to prevent silent scale rounding. |
| Source exclusivity | Proposed ME_OE_SOURCE_LINKS stores immediate source/target ownership and enforces one target per source, claimed at Draft save. Claim/release and lines save atomically. Type/company constraints must reject invalid source chains. |
| Source line traceability | Persist source_line_id with document ownership; nullable for standalone, disallowed for Quote. Validate correspondence with the immediate predecessor and copied content. |
| Invoice totals and balance | Document total may be derived or persisted with transactional recomputation; choose one policy. Derive payment totals, due and Customer balance from Submitted Invoices and actual payments. Avoid independently editable balance/status columns. |
| Immutable payment history | Proposed ME_OE_PAYMENTS: company, payment number, Invoice FK, amount NUMBER(18,2) > 0, Payment Date, reference VARCHAR2(50 CHAR), created_by and created_at. No edit/delete/refund fields or separate Payment revision. |
| Payment concurrency | Lock Invoice; check current revision/state/due; insert payment and increment Invoice revision in one transaction. An ordinary CHECK constraint cannot enforce aggregate payment sum <= Invoice total. |
| Safe retries | Reuse shared action storage after verifying it can persist request identity and original response; company/action-reference uniqueness. Replay must not duplicate a payment, number or source claim. |
| Audit | Reuse user/company/audit framework; trusted actor and timestamps. PaymentRecord requires created_by and UTC created_at in output. Preserve historical references. |
| List access patterns | Index company + Customer ID/name/status as query plan requires; document type/date/status/Customer/Warehouse, source claims, and Invoice/payment history ordering. Review real query plans before adding broad indexes. |
| Authorization | Reuse existing admin identity and capability registry; no separate API-01 permission system. Integrate Logistics provider for Shipper submit; do not add caller-set authorization columns. |

### Decisions before writing or applying migrations

1. Confirm shared document headers/lines versus separate physical tables, number allocation scope/prefixes, revision and audit conventions, deletion/history policy, Customer ID case matching, and storage mappings for API enums.
2. Confirm Inventory company/key types and code-to-internal-ID mapping before foreign keys. Current YAML defines no Item/Warehouse lookup endpoints. Agree the internal read provider separately; do not invent new routes in this summary.
3. Agree the internal Logistics dispatch authorization interface. Only Shipper submission requires the additional check; payment recording/history has no Inventory or Logistics dependency.
4. Check shared action/audit infrastructure for safe replay and atomic transactions; wire the migration runner to Sales and verify its plan/status behavior before applying anything.
5. Build and verify schema in a disposable test environment, including empty Invoice/Payment tables so Customer balances can use their real query. Then implement and test API-02 onward in order.

The login response specification remains incomplete compared with the observed backend response. There is no combined all-document list route in the current contract. These are contract review items, not extra existing operations.

Source fingerprint (SHA-256): `8ad97b07f4ef0b26f8ab2eba309e4557e4f9cc54a091b76aa1d5dbaef7213f1f`. Regenerate/review this reference when the contract changes.
