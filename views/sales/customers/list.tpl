<p><a href="{$data.salesUrl}">Back to Sales</a></p>

{if $data.createUrl}
    <p>
        <a class="button" href="{$data.createUrl}">New customer</a>
    </p>
{/if}
<div class="panel">
    <form method="get" action="{$data.listUrl}">
        <div class="filters">
            <label>
                Search customers
                <input type="search" name="search"
                       value="{$data.filters.search}"
                       placeholder="Customer code or name">
            </label>

            <label>
                Status
                <select name="is_active">
                    <option value="">All</option>
                    <option value="T"
                        {if $data.filters.is_active === 'T'}selected{/if}>
                        Active
                    </option>
                    <option value="F"
                        {if $data.filters.is_active === 'F'}selected{/if}>
                        Inactive
                    </option>
                </select>
            </label>
        </div>

        <button type="submit">Search</button>
    </form>
</div>

<div class="table-scroll">
    <table>
        <thead>
            <tr>
                <th scope="col">Customer code</th>
                <th scope="col">Customer name</th>
                <th scope="col">City</th>
                <th scope="col">Status</th>
                <th scope="col">Amount owed</th>
            </tr>
        </thead>
        <tbody>
            {foreach $data.customers.items as $customer}
                <tr>
                    <td>
                        <a href="{$customer.viewUrl}">
                            {$customer.customer_code}
                        </a>
                    </td>
                    <td>{$customer.customer_name}</td>
                    <td>{$customer.city}</td>
                    <td>
                        {if $customer.is_active === 'T'}Active{else}Inactive{/if}
                    </td>
                    <td>{$customer.amount_customer_owes_us}</td>
                </tr>
            {foreachelse}
                <tr>
                    <td colspan="5">No customers match your search.</td>
                </tr>
            {/foreach}
        </tbody>
    </table>
</div>

<p>Page {$data.customers.page}</p>

<nav aria-label="Customer pages">
    {if $data.previousUrl}
        <a class="button" href="{$data.previousUrl}">Previous</a>
    {/if}
    {if $data.nextUrl}
        <a class="button" href="{$data.nextUrl}">Next</a>
    {/if}
</nav>