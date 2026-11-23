# Simple purchasing class diagram

A purchase order contains the purchase header details and its item lines. A receiver records what arrived from the vendor. A vendor invoice records the amount owed for that receipt.

```mermaid
classDiagram
    direction TB

    class Vendor {
        vendorId
        name
        contactDetails
        address
        status : Active or Inactive
        amountDue
    }

    class PurchaseOrder {
        orderNumber
        orderDate
        deliveryAddress
        status : Draft or Submitted
        total
        editDraft()
        submit()
    }

    class PurchaseOrderLine {
        item
        description
        quantityOrdered
        unitCost
        lineAmount
    }

    class Receiver {
        receiverNumber
        receiptDate
        status : Draft or Received
        receive()
    }

    class ReceiverLine {
        item
        quantityReceived
        unitCost
        lineAmount
    }

    class VendorInvoice {
        invoiceNumber
        invoiceDate
        dueDate
        status : Draft or Submitted
        total
        amountDue
        submit()
    }

    class InvoiceLine {
        item
        quantity
        unitCost
        lineAmount
    }

    Vendor "1" --> "0..*" PurchaseOrder : receives orders
    PurchaseOrder "1" *-- "1..*" PurchaseOrderLine : contains
    PurchaseOrder "1" --> "0..1" Receiver : received through
    Receiver "1" *-- "1..*" ReceiverLine : contains
    PurchaseOrderLine "1" --> "0..1" ReceiverLine : received as
    Receiver "1" --> "0..1" VendorInvoice : invoiced through
    VendorInvoice "1" *-- "1..*" InvoiceLine : contains
    ReceiverLine "1" --> "0..1" InvoiceLine : billed as
    Vendor "1" --> "0..*" VendorInvoice : is owed payment for

    note for Vendor "Inactive: no new orders or order submission. Existing amounts owed remain payable."
    note for PurchaseOrder "Header and lines can change only in Draft. Submit freezes both."
    note for Receiver "Receive all order quantities together. Received documents cannot change."
    note for VendorInvoice "Copy receipt lines and costs. Submit adds the total to the vendor amount due once."
```

- **Vendor:** Deactivation blocks new orders and order submission. It preserves existing orders, invoices and outstanding amounts. An existing receipt can still be invoiced for an inactive vendor; any amount owed remains payable.
- **Purchase order:** The order class is the document header; purchase order lines hold the items. Only Draft orders and their lines can be edited or deleted. Submit requires an active vendor and makes the document read-only.
- **Receiver:** Created from a submitted order. It records all received items and full quantities through receiver lines. A Draft receiver has no stock effect; Receive completes it once.
- **Vendor invoice:** Created from a received receiver. Vendor, items, quantities and costs are copied from the receipt. Only the invoice date is editable in Draft. Submit freezes the invoice and increases the vendor balance once. Draft invoices add nothing to the balance.

Each order has at most one receiver, and each receiver has at most one invoice, including Draft documents. Every document contains at least one line. A line belongs to exactly one document.

This simplified diagram uses **Submitted** for orders and invoices as requested. The detailed [purchasing design](purchasing-design.md) calls that state **Pending** and additionally marks an order **Received** when receiving completes. Payment processing is outside this diagram; making a vendor inactive never clears an unpaid balance.

## Purchasing flow diagram

```mermaid
flowchart TD
    Start([Select vendor]) --> Active{Vendor active?}
    Active -->|No| Block[Block new order]
    Block --> Due[Existing invoices and unpaid amounts remain payable]
    Active -->|Yes| Draft[Create Draft purchase order with header and lines]
    Draft --> Edit[Edit purchase details and item lines]
    Edit --> Submit{Submit order?}
    Submit -->|Not yet| Edit
    Submit -->|Yes| Check{Vendor still active and order valid?}
    Check -->|No| Fix[Keep Draft and resolve validation errors]
    Fix --> Edit
    Check -->|Yes| Order[Order Submitted: header and lines read-only]

    Order --> Receiver[Create Draft receiver with copied order lines]
    Receiver --> Receipt[Set receipt date and notes: copied values read-only]
    Receipt --> Receive{Receive all items?}
    Receive -->|Not yet| Receipt
    Receive -->|Yes| ValidReceipt{Receipt valid and warehouse active?}
    ValidReceipt -->|No| Receipt
    ValidReceipt -->|Yes| Received[Complete receiving atomically: receiver and order Received; Inventory posts stock once]

    Received --> Invoice[Create Draft vendor invoice with copied receiver lines]
    Invoice --> Date[Set invoice date and calculate due date: no balance change]
    Date --> SubmitInvoice{Submit invoice?}
    SubmitInvoice -->|Not yet| Date
    SubmitInvoice -->|Yes| ValidInvoice{Dates valid, source values match and total positive?}
    ValidInvoice -->|No| Date
    ValidInvoice -->|Yes| Posted[Atomically mark invoice Submitted and add total to vendor amount due once]
    Posted --> End([Invoice read-only; amount remains due])
```

Vendor deactivation after order submission does not clear existing obligations or block invoicing an existing receipt. Draft receivers have no stock effect, and Draft invoices have no balance effect. Repeated Receive or invoice Submit must not add stock or vendor debt again. Receiving is full quantity only; copied receiver and invoice lines cannot be edited.

Only eligible sources without an existing child, including a Draft, can create the next document. Deleting an eligible Draft releases its source for a replacement. All actions require permission and validation; failed posting leaves documents, stock and balances unchanged. Order and invoice **Submitted** use the simplified terminology above; completed receiving also marks the order **Received**, as in the detailed design.
