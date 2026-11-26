<link rel="stylesheet" href="{$data.styleUrl}">
<div class="sales-document document-detail">
<p><a href="{$data.salesUrl}">Back to Sales</a></p>

<div class="panel">
    <form method="get" action="{$data.lookupUrl}">
        <label>
            Invoice number *
            <input type="text"
                   name="invoice_number"
                   maxlength="20"
                   value="{$data.invoiceNumber}"
                   placeholder="SI-000000000000001"
                   required>
        </label>

        {if $data.canRead}
            <button type="submit">View history</button>
        {/if}

        {if $data.canRecord}
            <button type="submit" formaction="{$data.newUrl}">
                Record payment
            </button>
        {/if}
    </form>
</div>

{if $data.payments !== null}
    <section class="panel">
    <h2>Payments for {$data.invoiceNumber}</h2>
    <dl class="document-blocks"><div><dt>Invoice number</dt><dd>{$data.invoiceNumber}</dd></div><div><dt>Page</dt><dd>{$data.payments.page}</dd></div></dl>
    <p>Amount due and payment status show the balance after each payment, in recording order.</p>

    {if $data.invoiceUrl}
        <p><a href="{$data.invoiceUrl}">View invoice</a></p>
    {/if}

    </section>
    <div class="table-scroll">
        <table>
            <thead>
                <tr>
                    <th scope="col">Payment number</th>
                    <th scope="col">Payment date</th>
                    <th scope="col" class="document-money">Amount paid</th>
                    <th scope="col" class="document-money">Amount due</th>
                    <th scope="col">Payment status</th>
                    <th scope="col">Reference</th>
                    <th scope="col">Recorded at (UTC)</th>
                </tr>
            </thead>
            <tbody>
                {foreach $data.payments.items as $payment}
                    <tr>
                        <td>{$payment.payment_number}</td>
                        <td>{$payment.payment_date}</td>
                        <td class="document-money">{$payment.payment_amount}</td>
                        <td class="document-money">{$payment.amount_due}</td>
                        <td><span class="document-status">{$payment.payment_status}</span></td>
                        <td>{$payment.payment_reference|default:'—'}</td>
                        <td>{$payment.created_at}</td>
                    </tr>
                {foreachelse}
                    <tr>
                        <td colspan="7">
                            No payments have been recorded for this invoice.
                        </td>
                    </tr>
                {/foreach}
            </tbody>
        </table>
    </div>

    <p>Page {$data.payments.page}</p>

    <nav aria-label="Payment history pages">
        {if $data.previousUrl}
            <a class="button" href="{$data.previousUrl}">Previous</a>
        {/if}
        {if $data.nextUrl}
            <a class="button" href="{$data.nextUrl}">Next</a>
        {/if}
    </nav>
{/if}
</div>
