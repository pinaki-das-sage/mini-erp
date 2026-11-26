<div class="panel">
    <h2>Payment recorded</h2>

    <dl>
        <dt>Payment number</dt>
        <dd>{$data.payment.payment_number}</dd>

        <dt>Invoice number</dt>
        <dd>{$data.payment.invoice_number}</dd>

        <dt>Amount paid</dt>
        <dd>{$data.payment.payment_amount}</dd>

        <dt>Payment date</dt>
        <dd>{$data.payment.payment_date}</dd>

        <dt>Payment reference</dt>
        <dd>{$data.payment.payment_reference}</dd>
    </dl>
</div>

<div class="panel">
    <h2>Invoice balance after this payment</h2>
    <p>These totals show the balance immediately after this payment was recorded.</p>

    <dl>
        <dt>Invoice total</dt>
        <dd>{$data.invoice.document_total}</dd>

        <dt>Total paid</dt>
        <dd>{$data.invoice.total_paid}</dd>

        <dt>Amount due</dt>
        <dd>{$data.invoice.amount_due}</dd>

        <dt>Payment status</dt>
        <dd>{$data.invoice.payment_status}</dd>
    </dl>

    {if $data.invoiceUrl}
        <p><a href="{$data.invoiceUrl}">View current invoice</a></p>
    {/if}

    {if $data.historyUrl}
        <p><a href="{$data.historyUrl}">View payment history</a></p>
    {/if}

    <p><a href="{$data.homeUrl}">Back to payments</a></p>
</div>