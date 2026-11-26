<p><a href="{$data.listUrl}">Back to invoices</a></p>
<div class="panel">
    <form method="post" action="{$data.formUrl}" id="invoice-form">
        <input type="hidden" name="csrf_token" value="{$csrf}">
        <input type="hidden" name="action_reference" value="{$data.actionReference}">
        {if $data.isNew}
            <p>Create a Draft Invoice from a Submitted Order that has no Invoice. Its saved details and item lines will be copied.</p>
            <label>
                Submitted Order number *
                <input type="text" name="source_order_number" maxlength="20" required
                       pattern="[A-Za-z0-9_-]{ldelim}1,20{rdelim}" list="invoice-order-options"
                       value="{$data.invoice.source_order_number}" placeholder="SO-000000000000001">
            </label>
            <datalist id="invoice-order-options">
                {foreach $data.orders as $order}
                    <option value="{$order.order_number}">{$order.customer_name} - {$order.document_total}</option>
                {/foreach}
            </datalist>
            <p>The Customer, items, quantities, prices and payment terms come from the Order.</p>
        {else}
            <input type="hidden" name="expected_version" value="{$data.expectedVersion}">
            <dl>
                <dt>Invoice number</dt><dd>{$data.invoice.invoice_number}</dd>
                <dt>Source Order</dt><dd>{$data.invoice.source_order_number}</dd>
                <dt>Customer</dt><dd>{$data.invoice.customer_code}</dd>
                <dt>Payment terms</dt><dd>{$data.invoice.payment_terms}</dd>
            </dl>
            <div class="filters">
                <label>Invoice date *
                    <input type="date" name="invoice_date" value="{$data.invoice.invoice_date}" required>
                </label>
                <label>Due date
                    <input type="date" name="due_date" value="{$data.invoice.due_date}">
                    <small>Leave blank to use the saved date or calculate it when Invoice date changes.</small>
                </label>
            </div>
            <label>Notes
                <textarea name="notes" maxlength="500">{$data.invoice.notes}</textarea>
            </label>
            <h2>Items</h2>
            <div class="table-scroll">
                <table>
                    <thead><tr><th scope="col">Item</th><th scope="col">Description</th><th scope="col">Quantity</th><th scope="col">Unit price</th><th scope="col">Amount</th></tr></thead>
                    <tbody>
                        {foreach $data.invoice.lines as $line}
                            {include file='sales/invoices/line.tpl' line=$line}
                        {/foreach}
                    </tbody>
                </table>
            </div>
            <p><strong>Invoice total:</strong> {$data.invoice.document_total}</p>
        {/if}
        <button type="submit">Save draft</button>
        <a href="{$data.listUrl}">Cancel</a>
    </form>
</div>
<script src="{$data.scriptUrl}" defer></script>
