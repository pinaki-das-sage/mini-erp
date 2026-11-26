<tr data-document-line>
    <td><span data-line-number>{$index + 1}</span><input type="hidden" name="lines[{$index}][line_id]" value="{$line.line_id|default:''}"></td>
    <td><input aria-label="Product ID" name="lines[{$index}][product_id]" value="{$line.product_id}" inputmode="numeric" maxlength="10" pattern="[1-9][0-9]{ldelim}0,9{rdelim}" list="document-products" required></td>
    <td><input aria-label="Description" name="lines[{$index}][description]" value="{$line.description}" maxlength="200" {if $data.kind === 'quote'}required{/if}></td>
    <td><input aria-label="Quantity" name="lines[{$index}][quantity]" value="{$line.quantity}" inputmode="numeric" maxlength="9" pattern="[0-9]{ldelim}1,9{rdelim}" required data-quantity></td>
    <td><input aria-label="Unit price" name="lines[{$index}][unit_price]" value="{$line.unit_price}" inputmode="decimal" maxlength="13" pattern="[0-9]{ldelim}1,10{rdelim}(\.[0-9]{ldelim}1,2{rdelim})?" required data-price></td>
    <td class="document-money"><output data-line-amount>—</output></td>
    <td><div class="document-line-actions"><button type="button" data-move="up" aria-label="Move line up">↑</button><button type="button" data-move="down" aria-label="Move line down">↓</button><button type="button" data-remove-line>Remove</button></div></td>
</tr>
