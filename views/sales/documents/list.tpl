<link rel="stylesheet" href="{$data.styleUrl}">
<link rel="stylesheet" href="{$data.dateRangeStyleUrl}">
<div class="sales-document">
    <div class="document-toolbar">
        <a href="{$data.salesUrl}">Back to Sales</a>
        {if $data.createUrl}<a class="button" href="{$data.createUrl}">New {$data.documentLabel}</a>{/if}
    </div>
    <div class="panel">
        <form method="get" action="{$data.listUrl}">
            <div class="document-grid">
                <label>Customer code<input name="customer_code" maxlength="20" value="{$data.filters.customer_code}" placeholder="CUST-001"></label>
                <label>{$data.documentLabel} number<input name="{$data.kind}_number" maxlength="20" value="{$data.numberFilterValue}" placeholder="{if $data.kind === 'quote'}SQ{else}SO{/if}-000000000000001"></label>
                <label>Status<select name="status"><option value="">All statuses</option>
                    {foreach $data.statuses as $status}<option value="{$status}" {if $data.filters.status === $status}selected{/if}>{$status}</option>{/foreach}
                </select></label>
                {include file='sales/documents/date_range.tpl'}
            </div>
            <div class="document-toolbar"><button type="submit">Search</button><a href="{$data.listUrl}">Clear filters</a></div>
        </form>
    </div>
    <div class="table-scroll">
        <table>
            <caption class="document-caption">{$data.documentLabel}s · Page {$data.list.page}</caption>
            <thead><tr><th scope="col">{$data.documentLabel} number</th>{if $data.kind === 'order'}<th scope="col">Source Quote</th>{/if}
                <th scope="col">Customer code</th>{if $data.kind === 'quote'}<th scope="col">Customer name</th>{/if}<th scope="col">Date</th>
                <th scope="col">Delivery date</th><th scope="col">Status</th><th scope="col" class="document-money">Total</th></tr></thead>
            <tbody>{foreach $data.list.items as $document}
                <tr><td><a href="{$document.viewUrl}">{$document.number}</a></td>
                    {if $data.kind === 'order'}<td>{$document.source_quote_number}</td>{/if}
                    <td>{$document.customer_code}</td>{if $data.kind === 'quote'}<td>{$document.customer_name}</td>{/if}
                    <td>{$document.date}</td><td>{$document.requested_delivery_date|default:'—'}</td>
                    <td><span class="document-status">{$document.status}</span></td><td class="document-money">{$document.document_total}</td></tr>
            {foreachelse}<tr><td colspan="7">No {$data.kind}s match your search.</td></tr>{/foreach}</tbody>
        </table>
    </div>
    <nav class="document-toolbar" aria-label="{$data.documentLabel} pages">
        {if $data.previousUrl}<a class="button" href="{$data.previousUrl}">Previous</a>{/if}
        <span>Page {$data.list.page}</span>
        {if $data.nextUrl}<a class="button" href="{$data.nextUrl}">Next</a>{/if}
    </nav>
</div>
<script src="{$data.dateRangeScriptUrl}" defer></script>
