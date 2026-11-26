<p>Manage customers, sales documents and customer payments.</p>

<div class="cards">
    <article>
        <h2>Customers</h2>
        <p>Create and maintain customer details, payment terms and account status.</p>
        {if isset($data.salesUrls.customers)}
        <a class="button" href="{$data.salesUrls.customers}">
            View customers
        </a>
        {else}<p>No read access.</p>{/if}
    </article>

    <article>
        <h2>Quotes</h2>
        <p>Prepare quotations, maintain item lines and submit quotes.</p>
        {if isset($data.salesUrls.quotes)}
        <a class="button" href="{$data.salesUrls.quotes}">
            View quotes
        </a>
        {else}<p>No read access.</p>{/if}
    </article>

    <article>
        <h2>Orders</h2>
        <p>Create Orders from Submitted Quotes and follow their fulfillment.</p>
        {if isset($data.salesUrls.orders)}
        <a class="button" href="{$data.salesUrls.orders}">View orders</a>
        {else}<p>No read access.</p>{/if}
    </article>

    <article>
        <h2>Invoices</h2>
        <p>Create draft invoices, review totals and submit invoices.</p>
        {if isset($data.salesUrls.invoices)}
        <a class="button" href="{$data.salesUrls.invoices}">
            View invoices
        </a>
        {else}<p>No read access.</p>{/if}
    </article>

    <article>
        <h2>Payments</h2>
        <p>Record customer payments against submitted invoices and view payment history.</p>
        {if isset($data.salesUrls.payments)}
        <a class="button" href="{$data.salesUrls.payments}">
            View payments
        </a>
        {else}<p>Coming soon.</p>{/if}
    </article>
</div>
