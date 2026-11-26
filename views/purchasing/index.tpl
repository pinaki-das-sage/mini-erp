<div id="purchasing" data-csrf="{$csrf}">
  <p>Manage vendors, purchase orders, receivers and invoices.</p>
  <nav class="purchasing-nav" aria-label="Purchasing">
    <button type="button" data-section="documents" aria-pressed="true">Documents</button>
    <button type="button" data-section="vendors" aria-pressed="false">Vendors</button>
  </nav>
  <p id="purchasing-status" role="status" aria-live="polite"></p>
  <section id="purchasing-list" class="panel">
    <div class="purchasing-toolbar">
      <h2 id="purchasing-heading">Documents</h2>
      <button type="button" id="purchasing-new">New document</button>
      <button type="button" id="purchasing-refresh">Refresh</button>
    </div>
    <div class="filters">
      <label>Search <input type="search" id="purchasing-search" maxlength="100" placeholder="Search loaded records"></label>
      <label id="purchasing-type-label">Document type
        <select id="purchasing-type"><option value="">All types</option><option value="order">Purchase orders</option><option value="receiver">Receivers</option><option value="invoice">Invoices</option></select>
      </label>
    </div>
    <div class="table-scroll"><table><caption id="purchasing-caption">Purchasing documents</caption><thead id="purchasing-columns"></thead><tbody id="purchasing-rows"></tbody></table></div>
    <div id="vendor-pagination" class="purchasing-toolbar" hidden>
      <button type="button" id="vendors-previous">Previous</button><span id="vendors-page"></span><button type="button" id="vendors-next">Next</button>
    </div>
  </section>
  {include file='purchasing/vendor.tpl'}
  {include file='purchasing/document.tpl'}
  <section id="purchasing-detail" class="panel" hidden>
    <h2>Record details</h2>
    <dl id="purchasing-detail-fields"></dl>
    <div class="table-scroll" id="purchasing-detail-lines"></div>
    <form id="purchasing-update" method="post">
      <label>Status <select name="status" required></select></label>
      {include file='purchasing/payment_terms.tpl'}
      <button type="submit">Save changes</button>
      <button type="button" data-close>Close</button>
    </form>
  </section>
  <noscript><p class="error">Enable JavaScript to load purchasing records and use these forms.</p></noscript>
</div>
<script src="{$basePath}/assets/purchasing.js" defer></script>
