<link rel="stylesheet" href="{$basePath}/assets/inventory-reservations.css?v=15">
<section class="inventory-reservations" aria-label="Reservation list">
  <p class="reservation-intro">Review stock committed to Sales order lines, including released and dispatched history.</p>
  <form method="get" action="{$data.list_url}" aria-label="Filter reservations" data-reservation-filters>
    {if $data.filters.q != ''}<input type="hidden" name="q" value="{$data.filters.q}">{/if}
    {if $data.filters.product_id != ''}<input type="hidden" name="product_id" value="{$data.filters.product_id}">{/if}
    {if $data.filters.sales_order_id != ''}<input type="hidden" name="sales_order_id" value="{$data.filters.sales_order_id}">{/if}
    <div class="reservation-results">
      <div class="reservation-list-heading">
        <p><strong>{$data.total}</strong> reservation(s). Showing {$data.first}-{$data.last}.</p>
        <p class="reservation-muted">Newest reservation first</p>
      </div>
      <div class="table-scroll reservation-table-wrap" tabindex="0" role="region" aria-label="Reservations table">
        <table>
          <caption class="reservation-sr-only">Reservations matching your column filters, including historical cycles</caption>
          <thead>
            <tr>
            {foreach $data.columns as $column}
              {if $column.key == 'status'}
              <th scope="col">UOM</th>
              <th scope="col" class="reservation-number"><span class="reservation-header-text">Active<br>Reservation</span></th>
              <th scope="col" class="reservation-number"><span class="reservation-header-text">Claimed<br>Reservation</span></th>
              <th scope="col" class="reservation-number"><span class="reservation-header-text">Dispatched<br>Quantity</span></th>
              {/if}
              {if $column.unit == ''}
              <th scope="col"{if $column.unit != '' || $column.key == 'line_number'} class="reservation-number"{/if}>
                <button type="button" class="reservation-filter-trigger{if $column.filtered} is-filtered{/if}" data-filter-column="{$column.key}" data-filter-label="{$column.label} {$column.unit}" data-filter-type="{$column.type}" data-filter-length="{$column.length}" data-filter-min="{$column.minimum}" aria-haspopup="dialog" aria-expanded="false" aria-label="Filter {$column.label} {$column.unit}" disabled>
                  <span class="reservation-header-text">{if $column.key == 'line_number'}Source Document Line<br>Number{else}{$column.label}{/if}{if $column.unit != ''} qty{/if}</span>
                  <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" aria-hidden="true"><path d="M4 5h16l-6 7v6l-4 2v-8z"/></svg>
                  {if $column.filtered}<span class="reservation-sr-only">Filter applied</span>{/if}
                </button>
                <input type="hidden" name="{$column.key}" value="{$column.value}">
              </th>
              {/if}
            {/foreach}
            </tr>
          </thead>
          <tbody>
          {foreach $data.items as $item}
            <tr>
              <th scope="row"><a href="{$item.detail_url}">#{$item.reservation_id}</a></th>
              <td class="reservation-document">{$item.document_number}</td>
              <td class="reservation-number">{if $item.line_number === null}—{else}{$item.line_number}{/if}</td>
              <td><a class="reservation-sku" href="{$item.product_url}">{$item.sku}</a></td>
              <td><span class="reservation-uom">{$item.unit_label}</span></td>
              <td class="reservation-number">{$item.active_quantity}</td>
              <td class="reservation-number">{if $item.claimed_quantity === null}<span title="Logistics claim information unavailable">Unknown</span>{else}{$item.claimed_quantity}{/if}</td>
              <td class="reservation-number">{if $item.status == 'DISPATCHED'}{$item.reserved_quantity}{else}0{/if}</td>
              <td><span class="reservation-status reservation-status-{$item.status}">{if $item.status == 'ACTIVE'}Active{elseif $item.status == 'DISPATCHED'}Dispatched{else}Released{/if}</span></td>
            </tr>
          {foreachelse}
            <tr><td colspan="9" class="reservation-empty"><strong>No reservations found.</strong><p>Try different column filters or clear them.</p></td></tr>
          {/foreach}
          </tbody>
        </table>
      </div>
      <div class="reservation-table-controls">
        <div class="reservation-filter-actions"><a href="{$data.list_url}">Clear filters</a></div>
        <label class="reservation-page-size">Rows per page
          <select name="page_size" aria-label="Rows per page">
            <option value="10"{if $data.page_size == 10} selected{/if}>10</option>
            <option value="20"{if $data.page_size == 20} selected{/if}>20</option>
            <option value="50"{if $data.page_size == 50} selected{/if}>50</option>
          </select>
        </label>
        <button type="submit">Update</button>
        <nav class="reservation-pagination" aria-label="Reservation pages">
          {if $data.previous_url}<a class="button" href="{$data.previous_url}" rel="prev">Previous</a>{else}<span class="reservation-disabled" aria-disabled="true">Previous</span>{/if}
          <span>Page {$data.page} of {$data.pages}</span>
          {if $data.next_url}<a class="button" href="{$data.next_url}" rel="next">Next</a>{else}<span class="reservation-disabled" aria-disabled="true">Next</span>{/if}
        </nav>
      </div>
    </div>
  </form>
  <p class="reservation-note">Active quantity is the remaining stock commitment. Claimed quantity is allocated to Draft Logistics trips. Dispatched quantity shows stock consumed by dispatch.</p>
  <noscript><p>Enable JavaScript to use the column filter menus.</p></noscript>
  <template id="reservation-filter-template">
    <div class="reservation-filter-popup" role="dialog" aria-modal="false" aria-labelledby="reservation-filter-heading">
      <h2 id="reservation-filter-heading" class="reservation-filter-title"></h2>
      <label class="reservation-filter-text-label"><span class="reservation-filter-input-label">Enter text</span>
        <input type="text" class="reservation-filter-text" autocomplete="off">
      </label>
      <label class="reservation-filter-status-label" hidden>Status
        <select class="reservation-filter-status">
          <option value="">All statuses</option>
          <option value="ACTIVE">Active</option>
          <option value="RELEASED">Released</option>
          <option value="DISPATCHED">Dispatched</option>
        </select>
      </label>
      <div class="reservation-filter-buttons">
        <button type="button" class="reservation-filter-clear" data-filter-clear>Clear filter</button>
        <button type="button" data-filter-ok>OK</button>
        <button type="button" class="reservation-filter-cancel" data-filter-cancel>Cancel</button>
      </div>
    </div>
  </template>
  <script src="{$basePath}/assets/inventory-reservations.js?v=2" defer></script>
</section>
