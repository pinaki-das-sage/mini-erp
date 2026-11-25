<tr data-invoice-line>
    <td>
        {if $line.line_id}
            <input type="hidden"
                   name="lines[{$index}][line_id]"
                   value="{$line.line_id}">
        {/if}

        {if $line.source_line_id}
            <input type="hidden"
                   name="lines[{$index}][source_line_id]"
                   value="{$line.source_line_id}">
        {/if}

        <select name="lines[{$index}][product_id]"
                aria-label="Item"
                data-product-select
                required
                {if $data.isLinked}disabled{/if}>
            <option value="">Select an item</option>
            {foreach $data.products as $product}
                <option value="{$product.product_id}"
                        data-warehouse="{$product.warehouse_id}"
                        {if $product.product_id === $line.product_id}selected{/if}>
                    {$product.sku} ({$product.warehouse_id})
                </option>
            {/foreach}
        </select>

        {if $data.isLinked}
            <input type="hidden"
                   name="lines[{$index}][product_id]"
                   value="{$line.product_id}">
        {/if}
    </td>

    <td>
        <input type="text"
               name="lines[{$index}][description]"
               aria-label="Description"
               maxlength="200"
               value="{$line.description}"
               required
               {if $data.isLinked}readonly{/if}>
    </td>

    <td>
        <input type="text"
               name="lines[{$index}][quantity]"
               aria-label="Quantity"
               inputmode="numeric"
               pattern="[1-9][0-9]{ldelim}0,8{rdelim}"
               value="{$line.quantity}"
               data-line-quantity
               required
               {if $data.isLinked}readonly{/if}>
    </td>

    <td>
        <input type="text"
               name="lines[{$index}][unit_price]"
               aria-label="Unit price"
               inputmode="decimal"
               pattern="[0-9]{ldelim}1,10{rdelim}([.][0-9]{ldelim}1,2{rdelim})?"
               maxlength="13"
               value="{$line.unit_price}"
               data-line-price
               required
               {if $data.isLinked}readonly{/if}>
    </td>

    <td>
        <output data-line-amount>{$line.line_amount}</output>
    </td>

    <td>
        {if !$data.isLinked}
            <button type="button" data-remove-line>Remove</button>
        {/if}
    </td>
</tr>