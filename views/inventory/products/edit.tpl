<p>The SKU cannot be changed. Unit and weight can only be changed while the product has no stock movements or reservations; the name and active setting can always be changed. A product with reserved stock cannot be deactivated.</p>
<form id="product-edit-form" class="panel" method="post" action="#" data-product-id="{$data.product.id}">
  <input type="hidden" name="csrf_token" value="{$csrf}">
  <label>SKU <input value="{$data.product.sku}" readonly disabled></label>
  <label>Product name <input name="name" required maxlength="255" value="{$data.product.name}"></label>
  <label>Unit (for example box) <input name="unitLabel" required maxlength="50" value="{$data.product.unit}"></label>
  <label>Weight per unit (kg) <input name="unitWeightKg" required inputmode="decimal" value="{$data.product.weightKg}" pattern="[0-9]{ldelim}1,7{rdelim}([.][0-9]{ldelim}1,3{rdelim})?" title="A number of kilograms with up to 3 decimals, for example 0.5"></label>
  <label><input type="checkbox" name="active" value="1"{if $data.product.active} checked{/if}> Active</label>
  <button type="submit">Save changes</button>
  <a href="{$basePath}/modules/inventory/products/{$data.product.id}">Cancel</a>
  <p class="form-status" role="status"></p>
</form>
<h2>Delete product</h2>
<p>Only a product with no stock, no movements and no reservations can be deleted. This cannot be undone. If the product is in use, deactivate it instead.</p>
<form id="product-delete-form" class="panel" data-product-id="{$data.product.id}" data-sku="{$data.product.sku}">
  <input type="hidden" name="csrf_token" value="{$csrf}">
  <button type="submit">Delete product</button>
  <p class="form-status" role="status"></p>
</form>
<script src="{$basePath}/assets/inventory.js" defer></script>
