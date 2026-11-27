<link rel="stylesheet" href="{$basePath}/assets/inventory-products.css?v=1">
<section class="inventory-products" aria-label="Product detail">
  <nav class="product-detail-nav" aria-label="Product navigation">
    <a href="{$basePath}/modules/inventory/products">&larr; Back to products</a>
    <a href="{$basePath}/modules/inventory/products/{$data.product.id}/edit">Edit product</a>
  </nav>
  <div class="product-detail-heading">
    <p>SKU <strong>{$data.product.sku}</strong></p>
    <span class="product-status product-status-{$data.product.statusCode}">{if $data.product.active}Active{else}Inactive{/if}</span>
  </div>
  <p class="product-intro">Stock levels and the movement history for this product.</p>

  <div class="product-quantities">
    <article><h2>On hand</h2><p class="product-value">{$data.product.onHand}</p></article>
    <article><h2>Reserved</h2><p class="product-value">{$data.product.reserved}</p></article>
    <article><h2>Available</h2><p class="product-value">{$data.product.available}</p></article>
  </div>

  <div class="product-detail-grid">
    <article><h2>Product details</h2><dl class="product-facts">
      <div><dt>Name</dt><dd>{$data.product.name}</dd></div>
      <div><dt>SKU</dt><dd>{$data.product.sku}</dd></div>
      <div><dt>Unit</dt><dd><span class="product-uom">{$data.product.unit}</span></dd></div>
      <div><dt>Weight per unit (kg)</dt><dd>{$data.product.weightKg}</dd></div>
      <div><dt>Warehouse</dt><dd>{$data.product.warehouse}</dd></div>
    </dl></article>
    <article><h2>Related</h2><dl class="product-facts">
      <div><dt>Reservations</dt><dd><a href="{$basePath}/modules/inventory/reservations?product_id={$data.product.id}">Reservations for this product</a></dd></div>
    </dl></article>
  </div>

  <h2>Stock movements</h2>
  {if $data.movements === null}
    <div class="product-notice"><strong>Movement history is not available.</strong> You do not have permission to see stock movements.</div>
  {else}
  <form method="get" action="{$basePath}/modules/inventory/products/{$data.product.id}" aria-label="Movement paging">
    <div class="product-results">
      <div class="product-list-heading">
        <p><strong>{$data.movementsTotal}</strong> movement(s). Showing {$data.first}-{$data.last}.</p>
        <p class="product-muted">Oldest first. Times are in UTC.</p>
      </div>
      <div class="table-scroll product-table-wrap" tabindex="0" role="region" aria-label="Stock movements table">
        <table id="movements-table">
          <caption class="product-sr-only">Stock movements for {$data.product.sku}, oldest first</caption>
          <thead>
            <tr>
              <th scope="col">Type</th>
              <th scope="col">Source</th>
              <th scope="col">Reservation ID</th>
              <th scope="col" class="product-number">Opening Balance</th>
              <th scope="col" class="product-number">Received Quantity</th>
              <th scope="col" class="product-number">Dispatched Quantity</th>
              <th scope="col" class="product-number">Closing Balance</th>
              <th scope="col">Timestamp</th>
            </tr>
          </thead>
          <tbody>
          {foreach $data.movements as $movement}
            <tr>
              <td>{$movement.type}</td>
              <td>{$movement.sourceType} {$movement.sourceId}</td>
              <td>{if $movement.reservationId === null}&mdash;{else}<a href="{$basePath}/modules/inventory/reservations/{$movement.reservationId}">#{$movement.reservationId}</a>{/if}</td>
              <td class="product-number">{$movement.opening}</td>
              <td class="product-number">{$movement.received}</td>
              <td class="product-number">{$movement.dispatched}</td>
              <td class="product-number">{$movement.closing}</td>
              <td class="product-document">{$movement.when}</td>
            </tr>
          {foreachelse}
            <tr><td colspan="8" class="product-empty"><strong>No stock movements yet.</strong><p>Movements appear when stock is received or dispatched.</p></td></tr>
          {/foreach}
          </tbody>
        </table>
      </div>
      <div class="product-table-controls">
        <label class="product-page-size">Rows per page
          <select name="page_size" aria-label="Rows per page">
            <option value="10"{if $data.pageSize == 10} selected{/if}>10</option>
            <option value="20"{if $data.pageSize == 20} selected{/if}>20</option>
            <option value="50"{if $data.pageSize == 50} selected{/if}>50</option>
          </select>
        </label>
        <button type="submit">Update</button>
        <nav class="product-pagination" aria-label="Movement pages">
          {if $data.previousQuery !== ''}<a class="button" href="{$basePath}/modules/inventory/products/{$data.product.id}?{$data.previousQuery}" rel="prev">Previous</a>{else}<span class="product-disabled" aria-disabled="true">Previous</span>{/if}
          <span>Page {$data.page} of {$data.pages}</span>
          {if $data.nextQuery !== ''}<a class="button" href="{$basePath}/modules/inventory/products/{$data.product.id}?{$data.nextQuery}" rel="next">Next</a>{else}<span class="product-disabled" aria-disabled="true">Next</span>{/if}
        </nav>
      </div>
    </div>
  </form>
  {/if}
</section>
