<link rel="stylesheet" href="{$basePath}/assets/inventory-reservations.css?v=13">
<section class="inventory-reservations" aria-label="Reservation detail">
  <nav class="reservation-detail-nav" aria-label="Reservation navigation"><a href="{$data.list_url}">← Back to Reservations</a><a href="{$data.product_url}">View product {$data.sku}</a></nav>
  <div class="reservation-detail-heading"><p>Sales order <strong>{$data.document_number}</strong> / line <strong>{if $data.line_number === null}—{else}{$data.line_number}{/if}</strong></p><span class="reservation-status reservation-status-{$data.status}">{if $data.status == 'ACTIVE'}Active{elseif $data.status == 'DISPATCHED'}Dispatched{else}Released{/if}</span></div>
  <p class="reservation-intro">Quantities below belong to reservation #{$data.reservation_id}.</p>
  <div class="reservation-quantities">
    <article><h2>Active quantity</h2><p class="reservation-value">{$data.active_quantity}</p></article>
    <article><h2>Claimed quantity</h2><p class="reservation-value">{if $data.claimed_quantity === null}<span class="reservation-unknown">Unknown</span>{else}{$data.claimed_quantity}{/if}</p></article>
    <article><h2>Dispatched quantity</h2><p class="reservation-value">{$data.consumed_quantity}</p></article>
  </div>
  {if $data.status != 'ACTIVE'}
    <div class="reservation-notice"><strong>This reservation is closed.</strong> Its active quantity is zero. The source references remain available for review.</div>
  {elseif !$data.claim_check_available}
    <div class="reservation-notice"><strong>Claim information unavailable.</strong> Release eligibility cannot be confirmed until the Logistics claim lookup is connected.</div>
  {elseif $data.claimed_quantity > 0}
    <div class="reservation-notice"><strong>Stock is claimed by Logistics.</strong> The current whole-line release requires all claims to be removed first, even when some quantity is unclaimed.</div>
  {/if}
  <div class="reservation-detail-grid">
    <article><h2>Source references</h2><dl class="reservation-facts">
      <div><dt>Reservation ID</dt><dd>{$data.reservation_id}</dd></div>
      <div><dt>Source Document ID</dt><dd>{$data.document_number}</dd></div>
      <div><dt>Source Document Line Number</dt><dd>{if $data.line_number === null}—{else}{$data.line_number}{/if}</dd></div>
      <div><dt>Product SKU</dt><dd><a href="{$data.product_url}">{$data.sku}</a></dd></div>
      <div><dt>UOM</dt><dd><span class="reservation-uom">{$data.unit_label}</span></dd></div>
    </dl><a href="{$data.same_line_url}">Reservations for this Sales order</a></article>
    <article><h2>Trip references</h2><dl class="reservation-facts">
      <div><dt>Trip ID</dt><dd>{if $data.trip_id === null}—{else}{$data.trip_id}{/if}</dd></div>
      <div><dt>Trip-line ID</dt><dd>{if $data.trip_line_id === null}—{else}{$data.trip_line_id}{/if}</dd></div>
    </dl></article>
  </div>
</section>
