<form id="purchasing-document" class="panel" method="post" action="{$basePath}/api/purchasing/documents" hidden>
  <div class="purchasing-toolbar"><h2 id="purchasing-form-title">New purchase order</h2><span class="purchase-state draft">Draft</span></div>
  <p id="purchasing-source-summary" class="purchase-notice" hidden></p>
  <input type="hidden" name="recordtype" value="order">
  <div class="purchasing-grid">
    <label>Document number * <input name="docno" required maxlength="20" placeholder="PO-0001" pattern="PO-.+"></label>
    <label>Vendor * <select name="vendor_id" required><option value="">Select an active vendor</option></select></label>
    <label><span id="purchasing-date-label">Order date *</span><input name="doc_date" type="date" required></label>
    <div class="purchase-summary"><strong>Warehouse</strong><span>W01 (single warehouse)</span></div>
    <label>Vendor reference <input name="vendor_ref" maxlength="40"></label>
    {include file='purchasing/payment_terms.tpl'}
    <label>Delivery address <textarea name="delivery_addr" maxlength="400"></textarea></label>
    <label>Document notes <textarea name="notes" maxlength="500"></textarea></label>
  </div>
  <p id="purchasing-due-date" class="purchase-muted"></p>
  <div id="purchasing-reference-tools" class="purchase-reference-tools">
    <label>Find vendor or product <input type="search" id="purchasing-reference-search" maxlength="100" placeholder="Search by name, vendor code or SKU"></label>
    <button type="button" id="purchasing-find-references">Find choices</button>
    <span id="purchasing-reference-status" role="status"></span>
  </div>
  <fieldset><legend>Item lines</legend>
    <p class="purchase-muted">Quantities use whole units in W01. Decimals round to the nearest whole unit, with .5 rounded up. Quantities that round to zero are not allowed.</p>
    <p id="purchasing-lines-help">Edit the items while this order is Draft. Submitting makes the header and lines read-only.</p>
    <div id="purchasing-lines"></div>
    <button type="button" id="purchasing-add-line">Add line</button>
    <p class="purchase-total">Total preview: <output id="purchasing-total">0.00</output></p>
  </fieldset>
  <div class="purchase-actions"><button type="submit" id="purchasing-document-save">Save draft</button><button type="button" data-close>Cancel</button></div>
  <p class="purchase-muted">Save the draft first, then submit from its details page. Invoice due dates are calculated from the invoice date and copied payment terms.</p>
</form>
<template id="purchasing-line-template">
  <fieldset class="purchasing-line"><legend>Line</legend><div class="purchasing-grid">
    <label>Inventory product * <select name="product_id" required><option value="">Select a product</option></select></label>
    <label>Description * <input name="description" maxlength="200" required></label>
    <label>Quantity (whole units) * <input name="quantity" type="number" min="0.5" max="9999999999.49" step="any" required value="1"></label>
    <label>Unit cost * <input name="unitcost" type="number" min="0" step="0.01" required value="0"></label>
    <label>Line notes <input name="notes" maxlength="500"></label>
  </div><div class="purchasing-toolbar"><span data-line-total class="purchase-muted"></span><button type="button" data-remove-line>Remove line</button></div></fieldset>
</template>
