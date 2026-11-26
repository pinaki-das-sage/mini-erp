<div class="customer-detail">
<div class="customer-detail__actions">
    <a href="{$data.listUrl}">Back to customers</a>

{if $data.editUrl}
    <a class="button" href="{$data.editUrl}">Edit customer</a>
{/if}
</div>

<section class="panel" aria-labelledby="customer-account-heading">
    <h2 id="customer-account-heading">Account information</h2>

    <dl class="customer-detail__grid">
        <div class="customer-detail__field">
        <dt>Customer code</dt>
        <dd>{$data.customer.customer_code}</dd>
        </div>

        <div class="customer-detail__field">
        <dt>Status</dt>
        <dd>
            {if $data.customer.is_active === 'T'}
                Active
            {else}
                Inactive
            {/if}
        </dd>
        </div>

        <div class="customer-detail__field">
        <dt>Payment terms</dt>
        <dd>{$data.paymentTermsLabel}</dd>
        </div>

        <div class="customer-detail__field">
        <dt>Amount owed</dt>
        <dd>{$data.customer.amount_customer_owes_us}</dd>
        </div>
    </dl>
</section>

<section class="panel" aria-labelledby="customer-details-heading">
    <h2 id="customer-details-heading">Customer details</h2>

    <dl class="customer-detail__grid">
        {foreach $data.fields as $field}
            <div class="customer-detail__field">
            <dt>{$field.label}</dt>
            <dd>{$data.customer[$field.name]|default:'—'}</dd>
            </div>
        {/foreach}
    </dl>
</section>

<section class="panel" aria-labelledby="customer-notes-heading">
    <h2 id="customer-notes-heading">Notes</h2>
    <p class="customer-detail__notes">{$data.customer.notes|default:'No notes.'}</p>
</section>
</div>
