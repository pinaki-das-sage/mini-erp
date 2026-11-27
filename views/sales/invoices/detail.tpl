<link rel="stylesheet" href="{$basePath}/assets/sales/documents.css">
<div class="sales-document document-detail">
<p><a href="{$data.listUrl}" data-document-back>Back to invoices</a></p>

<div class="panel">
    <h2>Invoice information</h2>

    <div class="document-toolbar">
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

    <dl class="document-blocks">
        <div class="document-block"><dt>Customer code</dt>
        <dd>{$data.invoice.customer_code}</dd></div>

        <div class="document-block"><dt>Invoice date</dt>
        <dd>{$data.invoice.invoice_date}</dd></div>

        <div class="document-block"><dt>Due date</dt>
        <dd>{$data.invoice.due_date}</dd></div>

        <div class="document-block"><dt>Payment terms</dt>
        <dd>{$data.paymentTermsLabel}</dd></div>

        <div class="document-block"><dt>Status</dt>
        <dd>{include file='sales/partials/status_badge.tpl' badgeStatus=$data.invoice.status badgeKind='invoice'}</dd></div>

        <div class="document-block"><dt>Payment status</dt>
        <dd>{include file='sales/partials/status_badge.tpl' badgeStatus=$data.invoice.payment_status|default:'' badgeKind='payment'}</dd></div>

        <div class="document-block"><dt>Source order</dt>
        <dd>{$data.invoice.source_order_number|default:'Standalone invoice'}</dd></div>
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
    <h2>Payment summary</h2>
    <dl class="document-blocks">
        <div class="document-block"><dt>Invoice total</dt>
        <dd>{$data.invoice.document_total}</dd></div>

        <div class="document-block"><dt>Total paid</dt>
        <dd>{$data.invoice.total_paid}</dd></div>

        <div class="document-block"><dt>Amount due</dt>
        <dd>{$data.invoice.amount_due}</dd></div>
    </dl>

</div>

<div class="panel">
    <h2>Notes</h2>
    <p class="document-notes">{$data.invoice.notes|default:'No notes.'}</p>
</div>

</div>
<script src="{$basePath}/assets/sales/document-navigation.js" defer></script>
