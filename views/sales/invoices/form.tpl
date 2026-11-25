<p><a href="{$data.listUrl}">Back to invoices</a></p>

<div class="panel">
    <p>
        <strong>Invoice number:</strong>
        {$data.invoice.invoice_number|default:'Generated when saved'}
    </p>

    {if $data.isLinked}
        <p><strong>Source order:</strong> {$data.invoice.source_order_number}</p>
        <p>Customer, warehouse, payment terms and item lines are inherited from this order.</p>
    {/if}

    <form method="post" action="{$data.formUrl}" id="invoice-form">
        <input type="hidden" name="csrf_token" value="{$csrf}">
        <input type="hidden" name="action_reference"
               value="{$data.actionReference}">

        {if $data.invoice.current_version}
            <input type="hidden" name="expected_version"
                   value="{$data.invoice.current_version}">
        {/if}

        <div class="filters">
            <label>
                Customer *
                <select name="customer_code"
                        id="invoice-customer"
                        required
                        {if $data.isLinked}disabled{/if}>
                    <option value="">Select a customer</option>
                    {foreach $data.customerOptions as $customer}
                        <option value="{$customer.customer_code}"
                                data-payment-terms="{$customer.payment_terms}"
                                {if $customer.customer_code === $data.invoice.customer_code}selected{/if}>
                            {$customer.customer_code} — {$customer.customer_name}
                        </option>
                    {/foreach}
                </select>
            </label>

            <label>
                Warehouse *
                <select name="warehouse_id"
                        id="invoice-warehouse"
                        required
                        {if $data.isLinked}disabled{/if}>
                    <option value="">Select a warehouse</option>
                    {foreach $data.warehouses as $warehouse}
                        <option value="{$warehouse}"
                            {if $warehouse === $data.invoice.warehouse_id}selected{/if}>
                            {$warehouse}
                        </option>
                    {/foreach}
                </select>
            </label>
        </div>

        <div class="filters">
            <label>
                Invoice date *
                <input type="date" name="invoice_date"
                       value="{$data.invoice.invoice_date}" required>
            </label>

            <label>
                Payment terms
                <select name="payment_terms"
                        id="invoice-payment-terms"
                        {if $data.isLinked}disabled{/if}>
                    <option value="">Use customer's terms</option>
                    <option value="DUE_ON_RECEIPT"
                        {if $data.invoice.payment_terms === 'DUE_ON_RECEIPT'}selected{/if}>
                        Due on receipt
                    </option>
                    <option value="NET_15"
                        {if $data.invoice.payment_terms === 'NET_15'}selected{/if}>
                        Net 15 days
                    </option>
                    <option value="NET_30"
                        {if $data.invoice.payment_terms === 'NET_30'}selected{/if}>
                        Net 30 days
                    </option>
                </select>
            </label>

            <label>
                Due date
                <input type="date" name="due_date"
                       value="{$data.invoice.due_date}">
                <small>Leave blank to calculate from payment terms.</small>
            </label>
        </div>

        <label>
            Notes
            <textarea name="notes" maxlength="500">{$data.invoice.notes}</textarea>
        </label>

        <h2>Items</h2>

        <div class="table-scroll">
            <table>
                <thead>
                    <tr>
                        <th scope="col">Item</th>
                        <th scope="col">Description</th>
                        <th scope="col">Quantity</th>
                        <th scope="col">Unit price</th>
                        <th scope="col">Amount</th>
                        <th scope="col">Action</th>
                    </tr>
                </thead>
                <tbody id="invoice-lines">
                    {foreach $data.invoice.lines as $index => $line}
                        {include file='sales/invoices/line.tpl'
                                 line=$line index=$index}
                    {/foreach}
                </tbody>
            </table>
        </div>

        {if !$data.isLinked}
            <p>
                <button type="button" id="add-invoice-line">Add item</button>
            </p>

            <template id="invoice-line-template">
                {include file='sales/invoices/line.tpl'
                         line=$data.emptyLine index='__INDEX__'}
            </template>
        {/if}

        <p>
            <strong>Invoice total:</strong>
            <output id="invoice-total">{$data.invoice.document_total}</output>
        </p>

        <p id="invoice-form-status" role="status" aria-live="polite"></p>

        <button type="submit">Save draft</button>
        <a href="{$data.listUrl}">Cancel</a>
    </form>
</div>

<script src="{$data.scriptUrl}" defer></script>