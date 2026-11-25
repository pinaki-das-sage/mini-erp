<p><a href="{$data.listUrl}">Back to customers</a></p>

{if $data.editUrl}
    <p>
        <a class="button" href="{$data.editUrl}">Edit customer</a>
    </p>
{/if}

<div class="panel">
    <h2>Account information</h2>

    <dl>
        <dt>Customer code</dt>
        <dd>{$data.customer.customer_code}</dd>

        <dt>Status</dt>
        <dd>
            {if $data.customer.is_active === 'T'}
                Active
            {else}
                Inactive
            {/if}
        </dd>

        <dt>Payment terms</dt>
        <dd>{$data.paymentTermsLabel}</dd>

        <dt>Amount owed</dt>
        <dd>{$data.customer.amount_customer_owes_us}</dd>
    </dl>
</div>

<div class="panel">
    <h2>Customer details</h2>

    <dl>
        {foreach $data.fields as $field}
            <dt>{$field.label}</dt>
            <dd>{$data.customer[$field.name]|default:'—'}</dd>
        {/foreach}
    </dl>

    <h3>Notes</h3>
    <p>{$data.customer.notes|default:'No notes.'}</p>
</div>