<form id="purchasing-vendor" class="panel" method="post" action="{$basePath}/api/purchasing/vendors" hidden>
  <h2>New vendor</h2><div class="purchasing-grid">
  <label>Vendor code <input name="vendor_id" type="text" maxlength="20" required pattern="[A-Za-z0-9-]+"></label>
  <label>Vendor name <input name="name" type="text" maxlength="100" required></label>
  <label>Contact person <input name="contact" type="text" maxlength="100"></label>
  <label>Email <input name="email" type="email" maxlength="100"></label>
  <label>Phone <input name="phone" type="tel" maxlength="20" pattern="[6-9][0-9]{ldelim}9{rdelim}" placeholder="10-digit Indian mobile number"></label>
  <label>Address line 1 <input name="addr_1" type="text" maxlength="150" required></label>
  <label>Address line 2 <input name="addr_2" type="text" maxlength="250"></label>
  <label>City <input name="city" type="text" maxlength="80" required></label>
  <label>State <input name="state" type="text" maxlength="80"></label>
  <label>Postal code <input name="postal_code" type="text" maxlength="10"></label>
  <label>Country <input name="country" type="text" maxlength="80" required></label>
  <label>Status <select name="status"><option value="active">Active</option><option value="inactive">Inactive</option></select></label>
  {include file='purchasing/payment_terms.tpl'}
  </div><button type="submit">Create vendor</button> <button type="button" data-close>Cancel</button>
</form>
