<p>Weight is for one unit, in kilograms, up to 3 decimals (for example 0.5). The SKU cannot be changed after the product is created.</p>
<form id="product-form" class="panel" method="post" action="{$basePath}/api/inventory/products">
  <input type="hidden" name="csrf_token" value="{$csrf}">
  <label>Product name <input name="name" required maxlength="255"></label>
  <label>SKU <input name="sku" required maxlength="50" pattern="[A-Za-z0-9][A-Za-z0-9._-]*" title="Letters, digits, dot, underscore and hyphen; start with a letter or digit"></label>
  <label>Unit (for example box) <input name="unitLabel" required maxlength="50"></label>
  <label>Weight per unit (kg) <input name="unitWeightKg" required inputmode="decimal" pattern="[0-9]{ldelim}1,7{rdelim}([.][0-9]{ldelim}1,3{rdelim})?" title="A number of kilograms with up to 3 decimals, for example 0.5"></label>
  <button type="submit">Save product</button>
  <a href="{$basePath}/modules/inventory/products">Cancel</a>
  <p class="form-status" role="status"></p>
</form>
<script src="{$basePath}/assets/inventory.js" defer></script>
