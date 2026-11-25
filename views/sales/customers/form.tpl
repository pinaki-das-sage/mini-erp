<div class="panel">
    <p>
        <strong>Customer code:</strong>
        {if isset($data.customer.customer_code)}
            {$data.customer.customer_code}
        {else}
            Generated automatically when saved
        {/if}
    </p>

    <form method="post" action="{$data.formUrl}">
        <input type="hidden" name="csrf_token" value="{$csrf}">
        <input type="hidden" name="action_reference"
               value="{$data.actionReference}">

        {foreach $data.fields as $field}
            <label>
                {$field.label}{if $field.required} *{/if}
                <input type="{$field.type}"
                       name="{$field.name}"
                       maxlength="{$field.maxLength}"
                       value="{$data.customer[$field.name]}"
                       {if $field.required}required{/if}>
            </label>
        {/foreach}

        <label>
            Payment terms
            <select name="payment_terms">
                <option value="DUE_ON_RECEIPT"
                    {if $data.customer.payment_terms === 'DUE_ON_RECEIPT'}selected{/if}>
                    Due on receipt
                </option>
                <option value="NET_15"
                    {if $data.customer.payment_terms === 'NET_15'}selected{/if}>
                    Net 15 days
                </option>
                <option value="NET_30"
                    {if $data.customer.payment_terms === 'NET_30'}selected{/if}>
                    Net 30 days
                </option>
            </select>
        </label>

        <label>
            Status
            <select name="is_active">
                <option value="T"
                    {if $data.customer.is_active === 'T'}selected{/if}>
                    Active
                </option>
                <option value="F"
                    {if $data.customer.is_active === 'F'}selected{/if}>
                    Inactive
                </option>
            </select>
        </label>

        <label>
            Notes
            <textarea name="notes" maxlength="500">{$data.customer.notes}</textarea>
        </label>

        <button type="submit">Save customer</button>
        <a href="{$data.listUrl}">Cancel</a>
    </form>
</div>