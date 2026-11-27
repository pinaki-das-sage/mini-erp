<link rel="stylesheet" href="{$basePath}/assets/inventory-products.css?v=1">
<section class="inventory-products" aria-label="Product list">
  <p class="product-intro">Review the products your company stocks. Use the filter icons in the column headings to find products by SKU, name or status.</p>
  <p><a class="button" href="{$basePath}/modules/inventory/products/new">Add product</a></p>
  <form method="get" action="{$basePath}/modules/inventory/products" aria-label="Filter products" data-product-filters>
    <div class="product-results">
      <div class="product-list-heading">
        <p><strong>{$data.total}</strong> product(s). Showing {$data.first}-{$data.last}.</p>
        <p class="product-muted">Ordered by SKU</p>
      </div>
      <div class="table-scroll product-table-wrap" tabindex="0" role="region" aria-label="Products table">
        <table>
          <caption class="product-sr-only">Products matching your column filters</caption>
          <thead>
            <tr>
              <th scope="col">
                <button type="button" class="product-filter-trigger{if $data.columns.sku.filtered} is-filtered{/if}" data-filter-column="sku" data-filter-label="SKU" data-filter-type="text" data-filter-length="100" aria-haspopup="dialog" aria-expanded="false" aria-label="Filter SKU" disabled>
                  <span class="product-header-text">SKU</span>
                  <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" aria-hidden="true"><path d="M4 5h16l-6 7v6l-4 2v-8z"/></svg>
                  {if $data.columns.sku.filtered}<span class="product-sr-only">Filter applied</span>{/if}
                </button>
                <input type="hidden" name="sku" value="{$data.columns.sku.value}">
              </th>
              <th scope="col">
                <button type="button" class="product-filter-trigger{if $data.columns.name.filtered} is-filtered{/if}" data-filter-column="name" data-filter-label="Product name" data-filter-type="text" data-filter-length="100" aria-haspopup="dialog" aria-expanded="false" aria-label="Filter Product name" disabled>
                  <span class="product-header-text">Name</span>
                  <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" aria-hidden="true"><path d="M4 5h16l-6 7v6l-4 2v-8z"/></svg>
                  {if $data.columns.name.filtered}<span class="product-sr-only">Filter applied</span>{/if}
                </button>
                <input type="hidden" name="name" value="{$data.columns.name.value}">
              </th>
              <th scope="col">Unit</th>
              <th scope="col" class="product-number">Weight per unit (kg)</th>
              <th scope="col">
                <button type="button" class="product-filter-trigger{if $data.columns.status.filtered} is-filtered{/if}" data-filter-column="status" data-filter-label="Status" data-filter-type="status" data-filter-length="0" aria-haspopup="dialog" aria-expanded="false" aria-label="Filter Status" disabled>
                  <span class="product-header-text">Status</span>
                  <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" aria-hidden="true"><path d="M4 5h16l-6 7v6l-4 2v-8z"/></svg>
                  {if $data.columns.status.filtered}<span class="product-sr-only">Filter applied</span>{/if}
                </button>
                <input type="hidden" name="status" value="{$data.columns.status.value}">
              </th>
            </tr>
          </thead>
          <tbody>
          {foreach $data.products as $product}
            <tr>
              <td><a class="product-sku" href="{$basePath}/modules/inventory/products/{$product.id}">{$product.sku}</a></td>
              <td>{$product.name}</td>
              <td><span class="product-uom">{$product.unit}</span></td>
              <td class="product-number">{$product.weightKg}</td>
              <td><span class="product-status product-status-{$product.statusCode}">{if $product.active}Active{else}Inactive{/if}</span></td>
            </tr>
          {foreachelse}
            <tr><td colspan="5" class="product-empty"><strong>No products found.</strong><p>Try different column filters or clear them.</p></td></tr>
          {/foreach}
          </tbody>
        </table>
      </div>
      <div class="product-table-controls">
        <div class="product-filter-actions"><a href="{$basePath}/modules/inventory/products">Clear filters</a></div>
        <label class="product-page-size">Rows per page
          <select name="page_size" aria-label="Rows per page">
            <option value="10"{if $data.pageSize == 10} selected{/if}>10</option>
            <option value="20"{if $data.pageSize == 20} selected{/if}>20</option>
            <option value="50"{if $data.pageSize == 50} selected{/if}>50</option>
          </select>
        </label>
        <button type="submit">Update</button>
        <nav class="product-pagination" aria-label="Product pages">
          {if $data.previousQuery !== ''}<a class="button" href="{$basePath}/modules/inventory/products?{$data.previousQuery}" rel="prev">Previous</a>{else}<span class="product-disabled" aria-disabled="true">Previous</span>{/if}
          <span>Page {$data.page} of {$data.pages}</span>
          {if $data.nextQuery !== ''}<a class="button" href="{$basePath}/modules/inventory/products?{$data.nextQuery}" rel="next">Next</a>{else}<span class="product-disabled" aria-disabled="true">Next</span>{/if}
        </nav>
      </div>
    </div>
  </form>
  <noscript><p>Enable JavaScript to use the column filter menus.</p></noscript>
  <template id="product-filter-template">
    <div class="product-filter-popup" role="dialog" aria-modal="false" aria-labelledby="product-filter-heading">
      <h2 id="product-filter-heading" class="product-filter-title"></h2>
      <label class="product-filter-text-label"><span class="product-filter-input-label">Enter text</span>
        <input type="text" class="product-filter-text" autocomplete="off">
      </label>
      <label class="product-filter-status-label" hidden>Status
        <select class="product-filter-status">
          <option value="">All statuses</option>
          <option value="ACTIVE">Active</option>
          <option value="INACTIVE">Inactive</option>
        </select>
      </label>
      <div class="product-filter-buttons">
        <button type="button" class="product-filter-clear" data-filter-clear>Clear filter</button>
        <button type="button" data-filter-ok>OK</button>
        <button type="button" class="product-filter-cancel" data-filter-cancel>Cancel</button>
      </div>
    </div>
  </template>
  <script src="{$basePath}/assets/inventory-products.js?v=1" defer></script>
</section>
