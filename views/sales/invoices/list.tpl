<link rel="stylesheet" href="{$data.dateRangeStyleUrl}">
<link rel="stylesheet" href="{$data.styleUrl}">
<div class="sales-document">
    <div class="document-toolbar">
        <a href="{$data.salesUrl}">Back to Sales</a>
        {if $data.createUrl}<a class="button" href="{$data.createUrl}">New Invoice</a>{/if}
    </div>
</div>

<div class="panel">
    <form method="get" action="{$data.listUrl}">
        <div class="filters">
            <label>Invoice number
                <input name="invoice_number" maxlength="20" value="{$data.filters.invoice_number}" placeholder="SI-000000000000001">
            </label>
            <label>
                Customer code
                <input type="text" name="customer_code" maxlength="20"
                       value="{$data.filters.customer_code}">
            </label>

            <label>
                Invoice status
                <select name="status">
                    <option value="">All</option>
                    <option value="Draft"
                        {if $data.filters.status === 'Draft'}selected{/if}>
                        Draft
                    </option>
                    <option value="Submitted"
                        {if $data.filters.status === 'Submitted'}selected{/if}>
                        Submitted
                    </option>
                </select>
            </label>

            <label>
                Payment status
                <select name="payment_status">
                    <option value="">All</option>
                    <option value="Unpaid"
                        {if $data.filters.payment_status === 'Unpaid'}selected{/if}>
                        Unpaid
                    </option>
                    <option value="Partially Paid"
                        {if $data.filters.payment_status === 'Partially Paid'}selected{/if}>
                        Partially paid
                    </option>
                    <option value="Paid"
                        {if $data.filters.payment_status === 'Paid'}selected{/if}>
                        Paid
                    </option>
                </select>
            </label>
        </div>

        <div class="filters">
            {include file='sales/documents/date_range.tpl'}
        </div>

        <button type="submit">Search</button>
        <a href="{$data.listUrl}">Clear filters</a>
    </form>
</div>

<div class="table-scroll">
    <table>
        <thead>
            <tr>
                <th scope="col">Invoice number</th>
                <th scope="col">Customer</th>
                <th scope="col">Invoice date</th>
                <th scope="col">Due date</th>
                <th scope="col">Status</th>
                <th scope="col">Total</th>
                <th scope="col">Paid</th>
                <th scope="col">Amount due</th>
                <th scope="col">Payment status</th>
            </tr>
        </thead>
        <tbody>
            {foreach $data.invoices.items as $invoice}
                <tr>
                    <td>
                        <a href="{$invoice.viewUrl}">
                            {$invoice.invoice_number}
                        </a>
                    </td>
                    <td>{$invoice.customer_code}</td>
                    <td>{$invoice.invoice_date}</td>
                    <td>{$invoice.due_date}</td>
                    <td>{$invoice.status}</td>
                    <td>{$invoice.document_total}</td>
                    <td>{$invoice.total_paid}</td>
                    <td>{$invoice.amount_due}</td>
                    <td>{$invoice.payment_status|default:'-'}</td>
                </tr>
            {foreachelse}
                <tr>
                    <td colspan="9">No invoices match your search.</td>
                </tr>
            {/foreach}
        </tbody>
    </table>
</div>

<p>Page {$data.invoices.page}</p>

<nav aria-label="Invoice pages">
    {if $data.previousUrl}
        <a class="button" href="{$data.previousUrl}">Previous</a>
    {/if}
    {if $data.nextUrl}
        <a class="button" href="{$data.nextUrl}">Next</a>
    {/if}
</nav>
<script src="{$data.dateRangeScriptUrl}" defer></script>
