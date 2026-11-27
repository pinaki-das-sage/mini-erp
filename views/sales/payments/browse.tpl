<link rel="stylesheet" href="{$data.styleUrl}">
<div class="sales-document document-detail">
    <p><a href="{$data.salesUrl}">Back to Sales</a></p>
    <div class="panel">
        <h2>Find a Submitted Invoice</h2>
        <p>Choose an Invoice below to record a payment or review its payment history.</p>
        <form method="get" action="{$data.listUrl}">
            <div class="document-grid document-entry-grid">
                <label>Invoice number<input name="invoice_number" maxlength="20" value="{$data.filters.invoice_number}" placeholder="SI-000000000000001"></label>
                <label>Customer code<input name="customer_code" maxlength="20" value="{$data.filters.customer_code}" placeholder="CUST-001"></label>
                <label>Payment status<select name="payment_status">
                    <option value="">All</option>
                    {foreach ['Unpaid', 'Partially Paid', 'Paid'] as $status}<option value="{$status}" {if $data.filters.payment_status === $status}selected{/if}>{$status}</option>{/foreach}
                </select></label>
            </div>
            <div class="document-toolbar"><button type="submit">Search</button><a href="{$data.listUrl}">Clear filters</a></div>
        </form>
    </div>
    <div class="table-scroll"><table class="payment-invoices">
        <caption class="document-caption">Submitted Invoices · Page {$data.invoices.page}</caption>
        <thead><tr><th scope="col">Invoice number</th><th scope="col">Customer code</th><th scope="col">Invoice date</th>
            <th scope="col" class="document-money">Invoice total</th><th scope="col" class="document-money">Amount paid</th><th scope="col" class="document-money">Amount due</th><th scope="col">Payment status</th><th scope="col">Actions</th></tr></thead>
        <tbody>{foreach $data.invoices.items as $invoice}<tr>
            <td><a href="{$invoice.viewUrl}">{$invoice.invoice_number}</a></td><td>{$invoice.customer_code}</td><td>{$invoice.invoice_date}</td>
            <td class="document-money">{$invoice.document_total}</td><td class="document-money">{$invoice.total_paid}</td><td class="document-money">{$invoice.amount_due}</td>
            <td>{include file='sales/partials/status_badge.tpl' badgeStatus=$invoice.payment_status badgeKind='payment'}</td>
            <td><div class="document-toolbar">
                {if $invoice.recordUrl}<a class="button" href="{$invoice.recordUrl}">Record payment</a>{/if}
                {if $invoice.historyUrl}<a href="{$invoice.historyUrl}">View history</a>{/if}
                {if !$invoice.recordUrl && !$invoice.historyUrl}<span class="document-muted">{if $invoice.payment_status === 'Paid'}Paid{else}View Invoice for details{/if}</span>{/if}
            </div></td>
        </tr>{foreachelse}<tr><td colspan="8">No Submitted Invoices match your search.</td></tr>{/foreach}</tbody>
    </table></div>
    <nav class="sales-pagination" aria-label="Submitted Invoice pages">
        {if $data.previousUrl}<a rel="prev" href="{$data.previousUrl}">&lt; Previous</a>{/if}<span aria-current="page">Page {$data.invoices.page}</span>
        {if $data.nextUrl}<a rel="next" href="{$data.nextUrl}">Next &gt;</a>{/if}
    </nav>
</div>
