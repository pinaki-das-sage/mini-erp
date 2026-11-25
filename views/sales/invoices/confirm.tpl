<p><a href="{$data.viewUrl}">Back to invoice</a></p>

<div class="panel">
    <h2>{$data.actionTitle}</h2>
    <p>{$data.actionMessage}</p>

    <dl>
        <dt>Invoice number</dt>
        <dd>{$data.invoice.invoice_number}</dd>

        <dt>Customer code</dt>
        <dd>{$data.invoice.customer_code}</dd>

        <dt>Current status</dt>
        <dd>{$data.invoice.status}</dd>

        <dt>Invoice total</dt>
        <dd>{$data.invoice.document_total}</dd>

        <dt>Amount due</dt>
        <dd>{$data.invoice.amount_due}</dd>
    </dl>

    <form method="post" action="{$data.actionUrl}">
        <input type="hidden" name="csrf_token" value="{$csrf}">
        <input type="hidden" name="action_reference"
               value="{$data.actionReference}">
        <input type="hidden" name="expected_version"
               value="{$data.invoice.current_version}">

        <button type="submit">{$data.submitLabel}</button>
        <a href="{$data.viewUrl}">Cancel</a>
    </form>
</div>