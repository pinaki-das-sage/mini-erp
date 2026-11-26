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
    <h2>Payments for {$data.invoiceNumber}</h2>

    {if $data.invoiceUrl}
        <p><a href="{$data.invoiceUrl}">View invoice</a></p>
    {/if}

    <div class="table-scroll">
        <table>
            <thead>
                <tr>
                    <th scope="col">Payment number</th>
                    <th scope="col">Payment date</th>
                    <th scope="col">Amount</th>
                    <th scope="col">Reference</th>
                    <th scope="col">Recorded by</th>
                    <th scope="col">Recorded at (UTC)</th>
                </tr>
            </thead>
            <tbody>
                {foreach $data.payments.items as $payment}
                    <tr>
                        <td>{$payment.payment_number}</td>
                        <td>{$payment.payment_date}</td>
                        <td>{$payment.payment_amount}</td>
                        <td>{$payment.payment_reference}</td>
                        <td>{$payment.created_by}</td>
                        <td>{$payment.created_at}</td>
                    </tr>
                {foreachelse}
                    <tr>
                        <td colspan="6">
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