<p><a href="{$data.listUrl}">Back to invoices</a></p>

<div class="panel">
    <h2>{$data.invoice.invoice_number}</h2>

    <div class="filters">
        {if $data.editUrl}
            <a class="button" href="{$data.editUrl}">Edit draft</a>
        {/if}

        {foreach $data.actions as $action}
            <a class="button" href="{$action.url}">{$action.label}</a>
        {/foreach}

        {if $data.recordPaymentUrl}
            <a class="button" href="{$data.recordPaymentUrl}">Record payment</a>
        {/if}

        {if $data.paymentHistoryUrl}
            <a class="button" href="{$data.paymentHistoryUrl}">Payment history</a>
        {/if}
    </div>

    <dl>
        <dt>Customer code</dt>
        <dd>{$data.invoice.customer_code}</dd>

        <dt>Warehouse</dt>
        <dd>{$data.invoice.warehouse_id}</dd>

        <dt>Invoice date</dt>
        <dd>{$data.invoice.invoice_date}</dd>

        <dt>Due date</dt>
        <dd>{$data.invoice.due_date}</dd>

        <dt>Payment terms</dt>
        <dd>{$data.paymentTermsLabel}</dd>

        <dt>Status</dt>
        <dd>{$data.invoice.status}</dd>

        <dt>Payment status</dt>
        <dd>{$data.invoice.payment_status|default:'-'}</dd>

        <dt>Source order</dt>
        <dd>{$data.invoice.source_order_number|default:'Standalone invoice'}</dd>
    </dl>
</div>

<div class="table-scroll">
    <table>
        <thead>
            <tr>
                <th scope="col">Line</th>
                <th scope="col">Item</th>
                <th scope="col">Description</th>
                <th scope="col">Quantity</th>
                <th scope="col">Unit price</th>
                <th scope="col">Amount</th>
            </tr>
        </thead>
        <tbody>
            {foreach $data.invoice.lines as $line}
                <tr>
                    <td>{$line.line_number}</td>
                    <td>{$line.product_label}</td>
                    <td>{$line.description}</td>
                    <td>{$line.quantity}</td>
                    <td>{$line.unit_price}</td>
                    <td>{$line.line_amount}</td>
                </tr>
            {/foreach}
        </tbody>
    </table>
</div>

<div class="panel">
    <dl>
        <dt>Invoice total</dt>
        <dd>{$data.invoice.document_total}</dd>

        <dt>Total paid</dt>
        <dd>{$data.invoice.total_paid}</dd>

        <dt>Amount due</dt>
        <dd>{$data.invoice.amount_due}</dd>
    </dl>

    <h2>Notes</h2>
    <p>{$data.invoice.notes|default:'No notes.'}</p>
</div>