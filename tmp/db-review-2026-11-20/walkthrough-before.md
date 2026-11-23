# Sales Flow and Database Walkthrough

This walkthrough follows one Customer through Quote, Order, Inventory reservation, Shipper, Logistics, Invoice and payment. Each step shows the Sales database fields and the identifiers passed to other teams.

The example uses the [current database design](sales-and-order-entry-db-design.md) and the linked flow agreed in this discussion. Inventory reservation on Order submission is the chosen integration proposal; Inventory and Logistics must confirm their service contracts and transaction guarantees. These are illustrative records, not operations executed against a database.

Standalone Shippers are deferred for this walkthrough. Standalone Orders use the same reservation flow; their difference is explained at the end. This document does not remove standalone behavior from the broader requirements or implement endpoints.

## Flow at a glance

```text
Customer ID 1
    ↓
Quote ID 5: Draft → Submitted
    ↓ copy into a new document
Order ID 10: Draft
    ↓ submit and reserve all Order lines through Inventory
Order ID 10: Submitted
    ↓ copy into a new document
Shipper ID 20: Draft → Submitted
    ↓ Logistics assignment: remains Submitted
    ↓ Logistics dispatch: consume the Order reservations
Shipper ID 20: In Progress
    ↓ Logistics Trip closure
Shipper ID 20: Closed
    ↓ copy into a new document
Invoice ID 30: Draft → Submitted
    ↓ payment 100.00, then payment 163.50
Invoice remains Submitted; payment status becomes Paid
```

## Example identifiers and reference data

| Record | Internal ID | Business identifier | Line IDs |
| --- | --- | --- | --- |
| Customer | `1` | `CUST-001` | Not applicable |
| Quote | `5` | `SQ-001` | `51`, `52` |
| Order | `10` | `SO-001` | `101`, `102` |
| Shipper | `20` | `SH-001` | `201`, `202` |
| Invoice | `30` | `INV-001` | `301`, `302` |
| First payment | `1000` | `PAY-001` | Not applicable |
| Second payment | `1001` | `PAY-002` | Not applicable |

IDs are generated examples; document numbers are generated independently. Customer URLs use the internal ID, such as `/api/v1/sales/customers/1`. Document URLs use the business number, such as `/api/v1/sales/orders/SO-001`.

The authenticated company is `1`. Inventory supplies Warehouse `WH-001` and the following illustrative Product lookup:

| Sales Item Code | Inventory Product ID | Description | Quantity | Unit Price | Calculated Line Amount |
| --- | --- | --- | --- | --- | --- |
| `ITEM-A` | `25` | Example item A | `2.000` | `125.50` | `251.00` |
| `ITEM-B` | `26` | Example item B | `1.250` | `10.00` | `12.50` |

Every document in this example totals **263.50**. Inventory must confirm the Item Code-to-Product ID mapping, Warehouse identifier and decimal-quantity support. `PRODUCT_ID` is obtained through Inventory's provider; the current Sales line table stores `ITEM_CODE`.

## 1 Create the Customer

**Sales operation:** create Customer; later read it through `/api/v1/sales/customers/1`.

**Insert into `ME_OE_CUSTOMERS`:**

| Database field | Example value |
| --- | --- |
| `ID` | `1` |
| `COMPANY_ID` | `1`, from trusted authentication context |
| `CUSTOMER_CODE` | `CUST-001` |
| `CUSTOMER_NAME` | `Example Traders` |
| `IS_ACTIVE` | `T` |
| `CONTACT_NAME` | `Example Contact` |
| `EMAIL_ADDRESS` | `contact@example.com` |
| `PHONE_NUMBER` | `NULL` |
| `BILLING_ADDRESS_LINE_1` | `12 Market Road` |
| `BILLING_ADDRESS_LINE_2` | `NULL` |
| `CITY` | `Bengaluru` |
| `STATE_PROVINCE` | `Karnataka` |
| `POSTAL_CODE` | `560001` as text |
| `COUNTRY` | `India` |
| `PAYMENT_TERMS` | `NET_30` |
| `NOTES` | `NULL` |

`ID` identifies the row. `CUSTOMER_CODE` is the company-unique entered business code. Document `CUSTOMER_ID` will reference numeric Customer `ID = 1`.

No balance column is written. With no Submitted Invoice yet, the calculated Customer balance is `0.00`.

## 2 Create and submit a Quote

**Sales operations:** create a numbered Draft Quote, then submit the saved Quote.

**Insert into `ME_OE_DOCUMENTS`:**

| Database field | Draft value |
| --- | --- |
| `ID` | `5` |
| `DOCUMENT_TYPE` | `SALES_QUOTE` |
| `DOCUMENT_NUMBER` | `SQ-001` |
| `CUSTOMER_ID` | `1` |
| `WAREHOUSE_ID` | `WH-001` |
| `DOCUMENT_DATE` | `2026-11-19` |
| `STATUS` | `DRAFT` |
| `VALID_UNTIL` | `2026-12-01` |
| `REQUESTED_DELIVERY_DATE` | `2026-11-25` |
| `SHIPPING_ADDRESS` | `12 Market Road, Bengaluru, India` |
| `PAYMENT_TERMS` | `NET_30` |
| `NOTES` | `NULL` |

**Insert into `ME_OE_DOCUMENT_LINES`:**

| ID | DOCUMENT_ID | LINE_NUMBER | ITEM_CODE | DESCRIPTION | QUANTITY | UNIT_PRICE | SOURCE_LINE_ID |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 51 | 5 | 1 | ITEM-A | Example item A | 2.000 | 125.50 | NULL |
| 52 | 5 | 2 | ITEM-B | Example item B | 1.250 | 10.00 | NULL |

Quote has no source, so no `ME_OE_SOURCE_LINKS` row is inserted. Type-inapplicable header fields are `NULL`; Company is obtained by joining the Customer.

**On submission:** update document `ID = 5`, setting `STATUS` from `DRAFT` to `SUBMITTED`. Quote submission creates no Inventory reservation and changes no Customer balance.

## 3 Create an Order from the Submitted Quote

**Sales operation:** create a Draft Order with source Quote `SQ-001`. It receives new document and line IDs while copying all source lines.

**Insert into `ME_OE_DOCUMENTS`:**

| Database field | Draft value |
| --- | --- |
| `ID` | `10` |
| `DOCUMENT_TYPE` | `SALES_ORDER` |
| `DOCUMENT_NUMBER` | `SO-001` |
| `CUSTOMER_ID` | `1` |
| `WAREHOUSE_ID` | `WH-001` |
| `DOCUMENT_DATE` | `2026-11-19` |
| `STATUS` | `DRAFT` |
| `REQUESTED_DELIVERY_DATE` | `2026-11-25` |
| `CUSTOMER_PO_NUMBER` | `PO-100` |
| `SHIPPING_ADDRESS` | Copied `12 Market Road, Bengaluru, India` |
| `PAYMENT_TERMS` | Copied `NET_30` |
| `NOTES` | `NULL` |

**Insert into `ME_OE_DOCUMENT_LINES`:**

| ID | DOCUMENT_ID | LINE_NUMBER | ITEM_CODE | DESCRIPTION | QUANTITY | UNIT_PRICE | SOURCE_LINE_ID |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 101 | 10 | 1 | ITEM-A | Example item A | 2.000 | 125.50 | 51 |
| 102 | 10 | 2 | ITEM-B | Example item B | 1.250 | 10.00 | 52 |

**Insert into `ME_OE_SOURCE_LINKS`:**

| SOURCE_DOCUMENT_ID | TARGET_DOCUMENT_ID | Meaning |
| --- | --- | --- |
| 5 | 10 | Quote 5 supplies Order 10 |

Here Order line `101` has predecessor Quote line `51`. The IDs identify different rows. Source ownership is recorded at Draft save; copied fields and lines remain locked while the source is retained. No Inventory reservation exists from this Draft save.

## 4 Submit the Order and reserve stock

**Sales operation:** submit saved Order `SO-001`.

Validate the Order and all lines, resolve Product IDs through Inventory, then call the proposed reservation service with the **Order's own IDs**:

```json
{
  "action_reference": "reserve-order-10-001",
  "source_type": "SALES_ORDER",
  "source_document_id": 10,
  "warehouse_id": "WH-001",
  "lines": [
    {
      "source_line_id": 101,
      "product_id": 25,
      "quantity": "2.000"
    },
    {
      "source_line_id": 102,
      "product_id": 26,
      "quantity": "1.250"
    }
  ]
}
```

Company and actor accompany the call through trusted context. The request reference remains unchanged on an identical retry.

### Exact field mapping for the reservation call

| Inventory request field | Sales database value used |
| --- | --- |
| `source_type` | Order header `DOCUMENT_TYPE = SALES_ORDER` |
| `source_document_id` | Order header `ID = 10` |
| `lines[0].source_line_id` | Order line's own `ID = 101` |
| `lines[1].source_line_id` | Order line's own `ID = 102` |
| `warehouse_id` | Order header `WAREHOUSE_ID = WH-001` |
| Each `product_id` | Inventory lookup for the Order line's `ITEM_CODE` |
| Each `quantity` | Order line `QUANTITY` |

**The request's `source_line_id` is not read from the Order line's database `SOURCE_LINE_ID`.** At this step the database `SOURCE_LINE_ID` values are `51` and `52`, the Quote predecessors. Inventory needs Order-line IDs `101` and `102`.

**Proposed Inventory result:**

| Reservation ID | Source type | Source document ID | Source line ID | Product ID | Reserved quantity |
| --- | --- | --- | --- | --- | --- |
| 501 | SALES_ORDER | 10 | 101 | 25 | 2.000 |
| 502 | SALES_ORDER | 10 | 102 | 26 | 1.250 |

These are Inventory-owned records. Their actual table/column names and result shape must be agreed with Inventory. If its existing fields remain named `SALES_ORDER_ID` and `SALES_LINE_ID`, they receive `10` and `101`/`102` respectively.

**On complete success:** update `ME_OE_DOCUMENTS.ID = 10`, setting `STATUS` to `SUBMITTED`, and commit with all reservations. No reservation ID column is added to Sales; Inventory must support lookup by the original Order references.

**On any failure:** the Order stays `DRAFT`, and no new reservations from this attempt remain. A separate availability read is insufficient; the reservation operation must recheck under locks. Sales and Inventory must agree a shared transaction or another protocol that guarantees this outcome; independent commits do not provide it automatically.

## 5 Create and submit a Shipper from the Order

**Sales operation:** create a Draft Shipper from Submitted Order `SO-001`, then submit it when valid.

**Insert into `ME_OE_DOCUMENTS`:**

| Database field | Draft value |
| --- | --- |
| `ID` | `20` |
| `DOCUMENT_TYPE` | `SHIPPER` |
| `DOCUMENT_NUMBER` | `SH-001` |
| `CUSTOMER_ID` | `1` |
| `WAREHOUSE_ID` | `WH-001` |
| `DOCUMENT_DATE` | `2026-11-19`, the Shipment Date |
| `STATUS` | `DRAFT` |
| `SHIPPING_ADDRESS` | Copied `12 Market Road, Bengaluru, India` |
| `CARRIER` | `Example Carrier` |
| `TRACKING_NUMBER` | `TRACK-001` |
| `NOTES` | `NULL` |

**Insert into `ME_OE_DOCUMENT_LINES`:**

| ID | DOCUMENT_ID | LINE_NUMBER | ITEM_CODE | DESCRIPTION | QUANTITY | UNIT_PRICE | SOURCE_LINE_ID |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 201 | 20 | 1 | ITEM-A | Example item A | 2.000 | 125.50 | 101 |
| 202 | 20 | 2 | ITEM-B | Example item B | 1.250 | 10.00 | 102 |

**Insert into `ME_OE_SOURCE_LINKS`:**

| SOURCE_DOCUMENT_ID | TARGET_DOCUMENT_ID | Meaning |
| --- | --- | --- |
| 10 | 20 | Order 10 supplies Shipper 20 |

Carrier may be blank while Draft but is required on submission. Submitting document `20` changes `STATUS` to `SUBMITTED`, meaning ready for Logistics pickup.

The Shipper reuses reservations `501` and `502` associated with its source Order lines. Do not request another reservation for lines `201` and `202`. Order `10` remains `SUBMITTED`.

## 6 Resolve the Shipper for Logistics

Logistics starts with Shipper `ID = 20` and company context. An agreed Sales read service resolves the mapping; this is not an assumed existing Logistics endpoint.

| Lookup | Database relationship | Result |
| --- | --- | --- |
| Shipper header | `ME_OE_DOCUMENTS.ID = 20`, type `SHIPPER` | Shipper `SH-001` |
| Its source Order | Source Link with `TARGET_DOCUMENT_ID = 20` | `SOURCE_DOCUMENT_ID = 10` |
| Source type | Source document `ID = 10` | `DOCUMENT_TYPE = SALES_ORDER` |
| First original Order line | Shipper line `ID = 201` | `SOURCE_LINE_ID = 101` |
| Second original Order line | Shipper line `ID = 202` | `SOURCE_LINE_ID = 102` |
| Company | Header `CUSTOMER_ID = 1` joins Customer `ID = 1` | `COMPANY_ID = 1` |

Verify that each predecessor line belongs to Order `10`, that source and target belong to the authenticated company, and that the copied business values match.

**Proposed Sales response for Logistics:**

```json
{
  "shipper_id": 20,
  "source_type": "SALES_ORDER",
  "source_document_id": 10,
  "warehouse_id": "WH-001",
  "lines": [
    {
      "shipper_line_id": 201,
      "source_line_id": 101,
      "product_id": 25,
      "quantity": "2.000"
    },
    {
      "shipper_line_id": 202,
      "source_line_id": 102,
      "product_id": 26,
      "quantity": "1.250"
    }
  ]
}
```

At this step `source_line_id` does come from the Shipper line's database `SOURCE_LINE_ID`: its predecessor is the reserved Order line. It is the same Order-line reference originally sent to Inventory in step 4.

Logistics queries Inventory using trusted company plus `SALES_ORDER`, document `10` and line `101`/`102`, obtaining reservations `501`/`502`. Validate Product, Warehouse, quantity and reservation state before consumption. Reservation lookup/consumption signatures remain team contracts to confirm.

## 7 Logistics assigns dispatches and closes its Trip

Logistics owns the Trip and its association with Shipper `20`. Its current Order-based claim design must be reconciled with this Shipper-based handoff; no Trip column is assumed to exist in a Sales table.

| Logistics event | Sales table and row | Changed Sales field | Inventory effect |
| --- | --- | --- | --- |
| Trip assignment | `ME_OE_DOCUMENTS`, `ID = 20` | None; `STATUS` remains `SUBMITTED` | Existing reservations remain |
| Trip dispatch | `ME_OE_DOCUMENTS`, `ID = 20` | `STATUS = IN_PROGRESS` | Logistics consumes reservations `501` and `502` through Inventory |
| Trip closure | `ME_OE_DOCUMENTS`, `ID = 20` | `STATUS = CLOSED` | No second consumption |

Only authorized Logistics integration updates the last two states. Dispatch, Inventory consumption, Sales status, audit and replay effects must satisfy the agreed atomicity guarantees. Duplicate updates must not consume twice, and delayed updates must not regress a Closed Shipper. Obtain authoritative Trip state to recover missed or out-of-order notifications.

Source links and line rows remain unchanged. Order `10` stays Submitted. An assigned Submitted Shipper cannot return to Draft; In Progress and Closed Shippers can never return.

## 8 Create an Invoice from the Closed Shipper

**Sales operation:** create a Draft Invoice using eligible Closed Shipper `SH-001`. Recheck that it is Closed, same-company and unused by another Invoice.

**Insert into `ME_OE_DOCUMENTS`:**

| Database field | Draft value |
| --- | --- |
| `ID` | `30` |
| `DOCUMENT_TYPE` | `SALES_INVOICE` |
| `DOCUMENT_NUMBER` | `INV-001` |
| `CUSTOMER_ID` | `1` |
| `WAREHOUSE_ID` | `WH-001` |
| `DOCUMENT_DATE` | `2026-11-19`, the Invoice Date |
| `STATUS` | `DRAFT` |
| `PAYMENT_TERMS` | `NET_30`, defaulted from Customer because Shipper has no terms |
| `DUE_DATE` | `2026-12-19` |
| `NOTES` | `NULL` |

**Insert into `ME_OE_DOCUMENT_LINES`:**

| ID | DOCUMENT_ID | LINE_NUMBER | ITEM_CODE | DESCRIPTION | QUANTITY | UNIT_PRICE | SOURCE_LINE_ID |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 301 | 30 | 1 | ITEM-A | Example item A | 2.000 | 125.50 | 201 |
| 302 | 30 | 2 | ITEM-B | Example item B | 1.250 | 10.00 | 202 |

**Insert into `ME_OE_SOURCE_LINKS`:**

| SOURCE_DOCUMENT_ID | TARGET_DOCUMENT_ID | Meaning |
| --- | --- | --- |
| 20 | 30 | Closed Shipper 20 supplies Invoice 30 |

Invoice Total is calculated as `263.50`. Draft Total Paid is `0.00` and Amount Due is `263.50`; its payment status is empty and it contributes `0.00` to Customer balance. No payment is accepted while Draft.

## 9 Submit the Invoice

**Sales operation:** submit saved Invoice `INV-001`. Revalidate its positive total and Closed source eligibility.

**Update `ME_OE_DOCUMENTS.ID = 30`:**

| Field | Before | After |
| --- | --- | --- |
| `STATUS` | `DRAFT` | `SUBMITTED` |

No lines or source references are replaced by submission. The resulting values are calculated:

| Calculated value | Result |
| --- | --- |
| Invoice Total | `263.50` |
| Total Paid | `0.00` |
| Amount Due | `263.50` |
| Payment Status | `Unpaid` |
| Customer balance, assuming no other Invoices | `263.50` |

These totals/stamps are not columns in the proposed tables. Customer balance includes the Invoice because its document state is now Submitted; no separate editable balance is incremented.

## 10 Record partial and final payments

**Sales operation:** record payments against `/api/v1/sales/invoices/INV-001/payments`.

Lock Invoice `30`, validate its state/current due and resolve an identical committed retry before applying another payment. Each accepted recording saves payment, shared action result and audit together.

**First insert into `ME_OE_PAYMENTS`:**

| Database field | Example value |
| --- | --- |
| `ID` | `1000` |
| `PAYMENT_NUMBER` | `PAY-001` |
| `INVOICE_ID` | `30` |
| `PAYMENT_AMOUNT` | `100.00` |
| `PAYMENT_DATE` | `2026-11-19` |
| `PAYMENT_REFERENCE` | `RECEIPT-001` |
| `ACTION_REFERENCE` | `payment-inv-30-001` |

**Second insert after checking the remaining due:**

| Database field | Example value |
| --- | --- |
| `ID` | `1001` |
| `PAYMENT_NUMBER` | `PAY-002` |
| `INVOICE_ID` | `30` |
| `PAYMENT_AMOUNT` | `163.50` |
| `PAYMENT_DATE` | `2026-11-19` |
| `PAYMENT_REFERENCE` | `RECEIPT-002` |
| `ACTION_REFERENCE` | `payment-inv-30-002` |

**Associated existing `ME_ACTIONS` records provide recording identity and time:**

| COMPANY_ID | ACTION_REFERENCE | ACTOR_ID | CREATED_AT |
| --- | --- | --- | --- |
| 1 | payment-inv-30-001 | example-user-id | 2026-11-19T10:00:00Z |
| 1 | payment-inv-30-002 | example-user-id | 2026-11-19T11:00:00Z |

The company comes from Payment → Invoice → Customer. Join company plus `ACTION_REFERENCE` to the existing shared action; return its actor/time as `created_by` / `created_at`. The action's recording timestamp is used by the current design proposal. Audit uses existing `ME_AUDIT`, not another Sales table.

| Stage | Total Paid | Amount Due | Payment Status | Customer balance |
| --- | --- | --- | --- | --- |
| Submitted, before payment | 0.00 | 263.50 | Unpaid | 263.50 |
| After PAY-001 | 100.00 | 163.50 | Partially Paid | 163.50 |
| After PAY-002 | 263.50 | 0.00 | Paid | 0.00 |

The Invoice remains `SUBMITTED` throughout. No payment-status field is written to its row. Any recorded payment blocks returning the Invoice to Draft; a fully Paid Invoice accepts no new payments.

## Final source relationships

**Rows in `ME_OE_SOURCE_LINKS`:**

| SOURCE_DOCUMENT_ID | TARGET_DOCUMENT_ID |
| --- | --- |
| 5 | 10 |
| 10 | 20 |
| 20 | 30 |

**Line lineage:**

```text
Quote line 51 → Order line 101 → Shipper line 201 → Invoice line 301
Quote line 52 → Order line 102 → Shipper line 202 → Invoice line 302

Inventory reservations reference Order lines 101 and 102.
Logistics consumes those reservations when dispatching Shipper 20.
```

Each line's database `SOURCE_LINE_ID` points only to the immediate predecessor. It is not always the original Order-line ID: Invoice line `301` points to Shipper line `201`, which then points to Order line `101`.

## Difference for a standalone Order

Start at step 3 with manually entered Order fields and lines. There is no Quote-to-Order row in `ME_OE_SOURCE_LINKS`, and Order lines have `SOURCE_LINE_ID = NULL`.

Step 4 still sends the Order's own document ID and line IDs to Inventory. For example, Order `10` still sends `source_document_id = 10` and request `source_line_id = 101`/`102`, even though its database `SOURCE_LINE_ID` values are empty. The linked Shipper, Logistics, Invoice and payment steps then work the same way.

## What is stored and what is obtained through joins

| Value | Location or derivation |
| --- | --- |
| Customer business code | `ME_OE_CUSTOMERS.CUSTOMER_CODE` |
| Document's Customer reference | `ME_OE_DOCUMENTS.CUSTOMER_ID` → Customer `ID` |
| Document type and number | `ME_OE_DOCUMENTS.DOCUMENT_TYPE` / `DOCUMENT_NUMBER` |
| Parent of a line | `ME_OE_DOCUMENT_LINES.DOCUMENT_ID` |
| Display order | `ME_OE_DOCUMENT_LINES.LINE_NUMBER` |
| Immediate predecessor line | `ME_OE_DOCUMENT_LINES.SOURCE_LINE_ID` |
| Selected source document | `ME_OE_SOURCE_LINKS.SOURCE_DOCUMENT_ID` for the target |
| Company on documents/lines/payments | Join through the owning Customer |
| Product ID for integration | Inventory's agreed lookup for `ITEM_CODE` |
| Reservation records and stock | Inventory-owned services/storage |
| Trip assignment and authoritative Trip status | Logistics-owned services/storage |
| Line Amount | Quantity × Unit Price, each line rounded half up to 2 decimals |
| Document Total | Sum rounded line amounts |
| Total Paid / Amount Due / payment status | Invoice lines plus recorded payments |
| Customer balance | Sum dues of that Customer's Submitted Invoices |

## Decisions before implementation

- Confirm Inventory reservation, lookup, consumption and release contracts, all-or-nothing behavior, safe retries and trusted company context.
- Confirm Product/Warehouse identifiers and decimal quantities. The Logistics draft currently restricts delivery to whole quantities, which conflicts with this valid `1.250` example.
- Agree the Logistics-owned Trip-Shipper association, authorization, callbacks or status reads, missed-update recovery and assignment/return coordination.
- Finalize company-key references and transaction ownership with both teams. Define how an eligible Order's reservations are released when returning it to Draft; a linked Shipper returning to Draft must not independently release its Submitted Order's reservations.
- Resolve the revision mechanism required for stale-edit rejection. This walkthrough adds no revision column and does not invent values for the current API's revision controls.
- Align Swagger and the earlier migration draft with the approved database naming and linked-flow scope. External JSON above is a proposed integration contract, not proof that the teams have implemented it.
