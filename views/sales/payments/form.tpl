<p><a href="{$data.homeUrl}">Back to payments</a></p>

<div class="panel">
    <h2>{$data.invoice.invoice_number}</h2>

    <dl>
        <dt>Customer code</dt>
        <dd>{$data.invoice.customer_code}</dd>

        <dt>Invoice date</dt>
        <dd>{$data.invoice.invoice_date}</dd>

        <dt>Invoice status</dt>
        <dd>{$data.invoice.status}</dd>

        <dt>Invoice total</dt>
        <dd>{$data.invoice.document_total}</dd>

        <dt>Total paid</dt>
        <dd>{$data.invoice.total_paid}</dd>

        <dt>Amount due</dt>
        <dd>{$data.invoice.amount_due}</dd>
    </dl>
</div>

{if $data.canRecord}
    <div class="panel">
        <form method="post" action="{$data.formUrl}">
            <input type="hidden" name="csrf_token" value="{$csrf}">
            <input type="hidden" name="action_reference"
                   value="{$data.actionReference}">
            <input type="hidden" name="expected_version"
                   value="{$data.invoice.current_version}">

            <label>
                Payment amount *
                <input type="text"
                       name="payment_amount"
                       inputmode="decimal"
                       maxlength="19"
                       pattern="(?=[0-9.]*[1-9])[0-9]{ldelim}1,16{rdelim}([.][0-9]{ldelim}1,2{rdelim})?"
                       value="{$data.payment.payment_amount}"
                       required>
                <small>Enter an amount greater than zero and no more than the amount due.</small>
            </label>

            <label>
                Payment date *
                <input type="date"
                       name="payment_date"
                       min="{$data.invoice.invoice_date}"
                       max="{$data.businessDate}"
                       value="{$data.payment.payment_date}"
                       required>
            </label>

            <label>
                Payment reference (optional)
                <input type="text"
                       name="payment_reference"
                       maxlength="50"
                       value="{$data.payment.payment_reference}">
                <small>For example, a bank transaction or receipt reference.</small>
            </label>

            <p>Recorded payments cannot be edited or deleted.</p>

            <button type="submit">Record payment</button>
            <a href="{$data.cancelUrl}">Cancel</a>
        </form>
    </div>
{else}
    <div class="panel">
        <p role="status">{$data.blockReason}</p>
        <a href="{$data.cancelUrl}">View invoice</a>
    </div>
{/if}
