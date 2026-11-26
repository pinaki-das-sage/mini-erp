<form id="purchasing-document" class="panel" method="post" action="{$basePath}/api/purchasing/documents" hidden>
  <h2>New document</h2>
  <div class="purchasing-grid">
    <label>Document type <select name="recordtype"><option value="order">Purchase order</option><option value="receiver">Receiver</option><option value="invoice">Invoice</option></select></label>
    <label>Document number <input name="docno" required maxlength="20" placeholder="PO-0001" pattern="PO-.+"></label>
    <label>Vendor record ID <input name="vendor_id" required maxlength="40"></label>
    <label>Document date <input name="doc_date" type="date" required></label>
    <label>Vendor reference <input name="vendor_ref" maxlength="40"></label>
    {include file='purchasing/payment_terms.tpl'}
    <label>Delivery address <textarea name="delivery_addr" maxlength="400"></textarea></label>
  </div>
  <p>Use the record ID from Vendors. Document numbers begin with PO-, RCV- or INV-. Invoice due dates are calculated from the document date and payment terms.</p>
  <fieldset><legend>Document lines</legend>
    <p>Use an existing inventory product ID. The server calculates line amounts and totals.</p>
    <div id="purchasing-lines"></div>
    <button type="button" id="purchasing-add-line">Add line</button>
    <p>Estimated total: <output id="purchasing-total">0.00</output></p>
  </fieldset>
  <button type="submit">Create document</button> <button type="button" data-close>Cancel</button>
</form>
<template id="purchasing-line-template">
  <fieldset class="purchasing-line"><legend>Line</legend><div class="purchasing-grid">
    <label>Product ID <input name="product_id" type="number" min="1" max="9999999999" step="1" required></label>
    <label>Description <input name="description" maxlength="200" required></label>
    <label>Quantity <input name="quantity" type="number" min="0.001" step="0.001" required value="1"></label>
    <label>Unit cost <input name="unitcost" type="number" step="0.01" required value="0"></label>
    <label>Notes <input name="notes" maxlength="500"></label>
  </div><button type="button" data-remove-line>Remove line</button></fieldset>
</template>
