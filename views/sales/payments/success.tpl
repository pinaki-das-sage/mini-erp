<link rel="stylesheet" href="{$data.styleUrl}">
<div class="sales-document document-detail">
<div class="panel">
    <h2>Payment recorded</h2>

    <dl class="document-blocks">
        <div class="document-block"><dt>Payment number</dt>
        <dd>{$data.payment.payment_number}</dd></div>

        <div class="document-block"><dt>Invoice number</dt>
        <dd>{$data.payment.invoice_number}</dd></div>

        <div class="document-block"><dt>Amount paid</dt>
        <dd>{$data.payment.payment_amount}</dd></div>

        <div class="document-block"><dt>Payment date</dt>
        <dd>{$data.payment.payment_date}</dd></div>

        <div class="document-block"><dt>Payment reference</dt>
        <dd>{$data.payment.payment_reference|default:'—'}</dd></div>
    </dl>
</div>

<div class="panel">
    <h2>Invoice balance after this payment</h2>
    <p>These totals show the balance immediately after this payment was recorded.</p>

    <dl class="document-blocks">
        <div class="document-block"><dt>Invoice total</dt>
        <dd>{$data.invoice.document_total}</dd></div>

        <div class="document-block"><dt>Total paid</dt>
        <dd>{$data.invoice.total_paid}</dd></div>

        <div class="document-block"><dt>Amount due</dt>
        <dd>{$data.invoice.amount_due}</dd></div>

        <div class="document-block"><dt>Payment status</dt>
        <dd>{include file='sales/partials/status_badge.tpl' badgeStatus=$data.invoice.payment_status badgeKind='payment'}</dd></div>
    </dl>

    {if $data.invoiceUrl}
        <p><a href="{$data.invoiceUrl}">View current invoice</a></p>
    {/if}

    {if $data.historyUrl}
        <p><a href="{$data.historyUrl}">View payment history</a></p>
    {/if}

    <p><a href="{$data.homeUrl}">Back to payments</a></p>
</div>

</div>
