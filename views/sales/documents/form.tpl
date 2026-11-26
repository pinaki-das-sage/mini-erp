<link rel="stylesheet" href="{$data.styleUrl}">
<div class="sales-document">
    <p><a href="{$data.listUrl}">Back to {$data.kind}s</a></p>
    <form method="post" action="{$data.formUrl}" id="document-form">
        <input type="hidden" name="csrf_token" value="{$csrf}">
        <input type="hidden" name="action_reference" value="{$data.actionReference}">
        {if !$data.isNew}<input type="hidden" name="expected_version" value="{$data.expectedVersion}">{/if}
        <div class="panel">
            <h2>{if $data.isNew}New{else}Edit Draft{/if} {$data.documentLabel}</h2>
            {if $data.kind === 'order' && $data.isNew}
                <p>Select a Submitted Quote. Its Customer, warehouse, shipping address, payment terms and all item lines will be copied.</p>
                <label>Source Quote number *<input name="source_quote_number" maxlength="20" value="{$data.document.source_quote_number}" list="source-quotes" placeholder="SQ-000000000000001" required></label>
                <datalist id="source-quotes">{foreach $data.sources as $source}<option value="{$source.quote_number}">{$source.customer_code} · {$source.document_total}</option>{/foreach}</datalist>
                <small>Suggestions show up to 100 eligible Quotes. You can also enter another Submitted Quote number.</small>
            {elseif $data.kind === 'order'}
                <dl class="document-summary">
                    <div><dt>Source Quote</dt><dd>{$data.document.source_quote_number}</dd></div><div><dt>Customer</dt><dd>{$data.document.customer_code}</dd></div>
                    <div><dt>Payment terms</dt><dd>{$data.document.payment_terms}</dd></div>
                    <div><dt>Shipping address</dt><dd>{$data.document.shipping_address}</dd></div>
                </dl>
            {else}
                <div class="document-grid">
                    <label>Customer code *<input name="customer_code" value="{$data.document.customer_code}" maxlength="20" list="document-customers" required></label>
                    <input type="hidden" name="warehouse_id" value="W01">
                </div>
                <datalist id="document-customers">{foreach $data.customers as $customer}<option value="{$customer.CUSTOMER_CODE}">{$customer.CUSTOMER_NAME}</option>{/foreach}</datalist>
            {/if}
            <div class="document-grid">
                {if $data.kind === 'quote'}
                    <label>Quote date *<input type="date" name="quote_date" value="{$data.document.quote_date}" required data-document-date></label>
                    <label>Valid until<input type="date" name="valid_until" value="{$data.document.valid_until}" data-following-date></label>
                {else}
                    <label>Order date{if !$data.isNew} *{/if}<input type="date" name="order_date" value="{$data.document.order_date}" {if !$data.isNew}required{/if} data-document-date>{if $data.isNew}<small>Leave blank to use today's date.</small>{/if}</label>
                {/if}
                <label>Requested delivery date<input type="date" name="requested_delivery_date" value="{$data.document.requested_delivery_date}" data-following-date>{if $data.kind === 'order' && $data.isNew}<small>Leave blank to use the Quote's delivery date.</small>{/if}</label>
            </div>
            {if $data.kind === 'quote'}
                <label>Shipping address{if !$data.isNew} *{/if}<textarea name="shipping_address" maxlength="500" {if !$data.isNew}required{/if}>{$data.document.shipping_address}</textarea>{if $data.isNew}<small>Leave blank to use the Customer's billing address.</small>{/if}</label>
                <label>Payment terms<select name="payment_terms">{if $data.isNew}<option value="">Use Customer's payment terms</option>{/if}
                    <option value="DUE_ON_RECEIPT" {if $data.document.payment_terms === 'DUE_ON_RECEIPT'}selected{/if}>Due on receipt</option>
                    <option value="NET_15" {if $data.document.payment_terms === 'NET_15'}selected{/if}>Net 15 days</option>
                    <option value="NET_30" {if $data.document.payment_terms === 'NET_30'}selected{/if}>Net 30 days</option>
                </select></label>
            {/if}
            <label>Notes<textarea name="notes" maxlength="500">{$data.document.notes}</textarea></label>
        </div>
        {if $data.kind === 'quote' || !$data.isNew}
            <div class="panel">
                <h2>Items</h2><p>Enter a Product ID or select a suggested item. Quantities must be positive whole numbers.</p>
                <input type="hidden" name="lines_present" value="1">
                <datalist id="document-products">{foreach $data.products as $product}<option value="{$product.PRODUCT_ID}">{$product.SKU}</option>{/foreach}</datalist>
                <div class="table-scroll"><table class="document-lines"><thead><tr><th scope="col">Line</th><th scope="col">Product ID</th><th scope="col">Description</th><th scope="col">Quantity</th><th scope="col">Unit price</th><th scope="col">Amount</th><th scope="col">Actions</th></tr></thead>
                    <tbody data-document-lines>{foreach $data.document.lines as $index => $line}{include file='sales/documents/line.tpl' index=$index line=$line}{/foreach}</tbody>
                </table></div>
                <p class="error" role="alert" data-items-error hidden>Add at least one item line.</p>
                <div class="document-toolbar"><button type="button" data-add-line>Add item</button><p class="document-total">Total preview: <output data-document-total>—</output></p></div>
                <small>The final total is calculated when you save.</small>
            </div>
        {/if}
        <div class="document-toolbar"><button type="submit">{if $data.isNew && $data.kind === 'order'}Create Draft from Quote{else}Save Draft{/if}</button><a href="{$data.listUrl}">Cancel</a></div>
        {if $data.error && !$data.isNew}<p><a href="{$data.formUrl}">Reload the latest saved {$data.kind} before retrying a version conflict.</a></p>{/if}
    </form>
    {if $data.kind === 'quote' || !$data.isNew}
        <template id="document-line-template">{include file='sales/documents/line.tpl' index=0 line=['line_id'=>'', 'product_id'=>'', 'description'=>'', 'quantity'=>'1', 'unit_price'=>'0.00']}</template>
    {/if}
</div>
<script src="{$data.scriptUrl}" defer></script>
