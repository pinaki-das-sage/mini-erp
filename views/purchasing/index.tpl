<link rel="stylesheet" href="{$basePath}/assets/purchasing/purchasing.css">
<div id="purchasing" data-csrf="{$csrf}">
  <div class="purchase-intro"><p>Purchase from active vendors, edit drafts, and follow each document through its next step.</p><span class="purchase-tag">Company workspace</span></div>
  <ol class="purchase-flow" aria-label="Purchasing workflow">
    <li><strong>Draft order</strong><span>Edit header and item lines</span></li>
    <li><strong>Submitted order</strong><span>Header and lines locked</span></li>
    <li><strong>Receiver</strong><span>Copy order; receive all items</span></li>
    <li><strong>Vendor invoice</strong><span>Copy receipt; post amount due</span></li>
  </ol>
  <nav class="purchasing-nav" aria-label="Purchasing">
    <button type="button" data-section="documents" aria-pressed="true">Documents</button>
    <button type="button" data-section="vendors" aria-pressed="false">Vendors</button>
  </nav>
  <p id="purchasing-status" role="status" aria-live="polite"></p>
  <section id="purchasing-list" class="panel">
    <div class="purchasing-toolbar">
      <h2 id="purchasing-heading">Documents</h2>
      <button type="button" id="purchasing-new" disabled>New purchase order</button>
      <button type="button" id="purchasing-refresh">Refresh</button>
    </div>
    <div class="filters purchase-filters">
      <label>Search <input type="search" id="purchasing-search" maxlength="100" placeholder="Document number, vendor or status"></label>
      <label id="purchasing-type-label">Document type
        <select id="purchasing-type"><option value="">All types</option><option value="order">Purchase orders</option><option value="receiver">Receivers</option><option value="invoice">Invoices</option></select>
      </label>
      <label id="purchasing-state-label">Workflow state
        <select id="purchasing-state"><option value="">All states</option><option value="draft">Draft</option><option value="submitted">Submitted</option><option value="received">Received</option><option value="completed">Completed</option></select>
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
    <div class="purchasing-toolbar"><h2 id="purchasing-detail-title">Record details</h2><span id="purchasing-detail-state" class="purchase-state"></span><button type="button" data-close>Back to list</button></div>
    <p id="purchasing-state-description" class="purchase-notice"></p>
    <dl id="purchasing-detail-fields"></dl>
    <div class="table-scroll" id="purchasing-detail-lines"></div>
    <div id="purchasing-workflow-actions" class="purchase-actions"></div>
    <p id="purchasing-integration-note" class="purchase-muted"></p>
    <form id="purchasing-update" method="post" hidden>
      <h3>Vendor settings</h3>
      <div class="purchasing-grid"><label>Status <select name="status" required><option value="active">Active</option><option value="inactive">Inactive</option></select></label>
      {include file='purchasing/payment_terms.tpl'}</div>
      <p class="purchase-muted">Inactive vendors cannot receive new orders. Existing invoices and unpaid amounts remain payable.</p>
      <button type="submit">Save vendor settings</button>
    </form>
  </section>
  <noscript><p class="error">Enable JavaScript to use Purchasing.</p></noscript>
</div>
<script src="{$basePath}/assets/purchasing/purchasing.js" defer></script>
