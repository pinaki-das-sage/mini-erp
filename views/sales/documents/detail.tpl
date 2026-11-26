<link rel="stylesheet" href="{$data.styleUrl}">
<div class="sales-document document-detail">
    <p><a href="{$data.listUrl}">Back to {$data.kind}s</a></p>
    <div class="panel">
        <div class="document-toolbar">
            <span class="document-status">{$data.document.status}</span>
            {if $data.editUrl}<a class="button" href="{$data.editUrl}">Edit Draft</a>{/if}
            {foreach $data.actions as $action}<a class="button" href="{$action.url}">{$action.label}</a>{/foreach}
            {if $data.createOrderUrl}<a class="button" href="{$data.createOrderUrl}">Create Order</a>{/if}
            {if $data.invoiceAction}
                {if $data.invoiceAction.create}
                    <form method="post" action="{$data.invoiceAction.url}">
                        <input type="hidden" name="csrf_token" value="{$csrf}">
                        <input type="hidden" name="source_order_number" value="{$data.invoiceAction.sourceNumber}">
                        <input type="hidden" name="action_reference" value="{$data.invoiceAction.actionReference}">
                        <button type="submit">Create Invoice</button>
                    </form>
                {else}<a class="button" href="{$data.invoiceAction.url}">View Invoice</a>{/if}
            {/if}
        </div>
        <h2>{if $data.kind === 'quote'}Quote{else}Order{/if} information</h2>
        <dl class="document-blocks">
            {if $data.kind === 'quote'}
                <div><dt>Quote date</dt><dd>{$data.document.quote_date}</dd></div>
                <div><dt>Valid until</dt><dd>{if $data.document.valid_until}{$data.document.valid_until}{else}&mdash;{/if}</dd></div>
            {else}
                <div><dt>Source Quote</dt><dd>{$data.document.source_quote_number}</dd></div>
                <div><dt>Order date</dt><dd>{$data.document.order_date}</dd></div>
            {/if}
            <div><dt>Total</dt><dd>{$data.document.document_total}</dd></div>
        </dl>
    </div>
    <section class="panel">
        <h2>Customer and delivery details</h2>
        <dl class="document-blocks">
            <div><dt>Customer code</dt><dd>{$data.document.customer_code}</dd></div>
            {if isset($data.document.customer_name)}<div><dt>Customer name</dt><dd>{$data.document.customer_name}</dd></div>{/if}
            <div><dt>Requested delivery</dt><dd>{if $data.document.requested_delivery_date}{$data.document.requested_delivery_date}{else}&mdash;{/if}</dd></div>
            <div><dt>Payment terms</dt><dd>{$data.document.payment_terms}</dd></div>
            <div><dt>Shipping address</dt><dd>{$data.document.shipping_address}</dd></div>
        </dl>
    </section>
    <div class="table-scroll"><table>
        <caption class="document-caption">Items</caption>
        <thead><tr><th scope="col">Line</th><th scope="col">Product ID</th><th scope="col">Description</th><th scope="col">Quantity</th>
            <th scope="col">Unit price</th><th scope="col">Amount</th>{if $data.kind === 'order'}<th scope="col">Trip</th>{/if}</tr></thead>
        <tbody>{foreach $data.document.lines as $line}<tr>
            <td>{$line.line_number}</td><td>{$line.product_id}</td><td>{$line.description}</td><td>{$line.quantity}</td><td>{$line.unit_price}</td><td>{$line.line_amount}</td>
            {if $data.kind === 'order'}<td>{if $line.trip_assigned}{$line.trip_reference|default:'Assigned'}<small class="document-muted">{$line.trip_status|default:''}</small>{else}Unassigned{/if}</td>{/if}
        </tr>{/foreach}</tbody>
    </table></div>
    <div class="panel"><h2>Notes</h2><p class="document-notes">{$data.document.notes|default:'No notes.'}</p></div>
</div>
