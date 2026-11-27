// trip-lines: table rendering, dialogs and feature-specific interactions.
export function initTripLines(context) {
  const {
    apiRequest,
    textCell,
    stackedCell,
    formatKilograms,
    formatDeparture,
    errorMessage,
    setFormError,
    makeButton,
    showDialog,
    loadList,
    logisticsState
  } = context;
  const tripStatus = (value) => ({ D: 'Draft', S: 'Dispatched', C: 'Delivered', X: 'Cancelled', DRAFT: 'Draft', DISPATCHED: 'Dispatched', DELIVERED: 'Delivered', CANCELLED: 'Cancelled' }[value] || 'Unknown');
  const statusBadge = (value) => {
    const badge = document.createElement('span'); const label = tripStatus(value);
    badge.className = 'logistics-badge logistics-badge-' + label.toLowerCase(); badge.textContent = label; return badge;
  };
  const renderTripLines = (items) => {
    const body = document.querySelector('#trip-lines-table-body'); body.replaceChildren();
    for (const line of items) {
      const row = document.createElement('tr'); textCell(row, line.id);
      stackedCell(row, line.trip?.trip_code ?? line.trip_id, 'Trip #' + line.trip_id);
      stackedCell(row, line.product?.product_name ?? ('Product #' + line.product_id), line.product?.sku);
      stackedCell(row, line.sales_line_id, line.trip?.sales_order_id ? 'Order #' + line.trip.sales_order_id : null);
      textCell(row, line.quantity); textCell(row, formatKilograms(line.line_load));
      textCell(row, '').replaceChildren(statusBadge(line.trip?.status));
      const actions = textCell(row, ''); actions.replaceChildren(); actions.className = 'logistics-actions';
      actions.append(makeButton('View details', 'button-secondary', () => openTripLine(line.id))); body.append(row);
    }
  };

  const lineFilter = document.querySelector('#trip-lines-filter');
  const applyLineFilter = () => {
    const state = logisticsState['trip-lines']; if (state.loading) return;
    state.tripId = document.querySelector('#trip-lines-trip-id').value; state.page = 1; loadList('trip-lines');
  };
  lineFilter.addEventListener('submit', (event) => { event.preventDefault(); applyLineFilter(); });
  document.querySelector('#trip-lines-clear').addEventListener('click', () => {
    if (logisticsState['trip-lines'].loading) return;
    lineFilter.reset(); applyLineFilter();
  });
  let lineDetailRequest = 0;
  const openTripLine = async (id) => {
    const request = ++lineDetailRequest;
    const dialog = document.querySelector('#trip-line-dialog');
    const content = document.querySelector('#trip-line-detail-content');
    const status = document.querySelector('#trip-line-detail-status');
    const error = document.querySelector('#trip-line-detail-error');
    document.querySelector('#trip-line-dialog-title').textContent = 'Line #' + id;
    content.replaceChildren(); setFormError(error, ''); status.textContent = 'Loading details...'; showDialog(dialog);
    try {
      const line = await apiRequest('/trip-lines/' + id);
      if (!dialog.open || request !== lineDetailRequest) return;
      if (!line || typeof line !== 'object' || line.id == null) throw new Error('The server returned unexpected trip-line details.');
      const section = (title, fields, empty = '') => {
        const block = document.createElement('section'); block.className = 'logistics-detail-section';
        const heading = document.createElement('h3'); heading.textContent = title; block.append(heading);
        if (empty) { const note = document.createElement('p'); note.className = 'logistics-help'; note.textContent = empty; block.append(note); }
        else {
          const list = document.createElement('dl'); list.className = 'logistics-detail-grid';
          for (const [label, value] of fields) {
            const group = document.createElement('div'); const term = document.createElement('dt'); const definition = document.createElement('dd');
            term.textContent = label; definition.textContent = value ?? '-'; group.append(term, definition); list.append(group);
          } block.append(list);
        } content.append(block);
      };
      const t = line.trip, sale = line.sales_line, product = line.product, stock = product?.stock_balance;
      section('Line summary', [['Line ID', line.id], ['Trip ID', line.trip_id], ['Sales line ID', line.sales_line_id], ['Product ID', line.product_id], ['Quantity', line.quantity], ['Line load (KG)', formatKilograms(line.line_load)], ['Total weight (KG)', formatKilograms(line.total_weight)], ['Created', formatDeparture(line.created_at)], ['Updated', formatDeparture(line.updated_at)]]);
      section('Trip', t ? [['Trip code', t.trip_code], ['Status', tripStatus(t.status)], ['Sales order ID', t.sales_order_id], ['Vehicle ID', t.vehicle_id], ['Driver ID', t.driver_id], ['Planned departure', formatDeparture(t.planned_departure_at)], ['Cancellation reason', t.cancellation_reason]] : [], t ? '' : 'Trip details are unavailable.');
      if (tripStatus(t?.status) === 'Cancelled') {
        section('Linked details', [], 'This trip was cancelled. Original line values are preserved; live sales, product, and stock details are not linked.');
      } else {
        section('Sales line', sale ? [['Line ID', sale.id], ['Order ID', sale.document_record_id], ['Line number', sale.line_number], ['Description', sale.description], ['Quantity', sale.quantity], ['Unit price', sale.unit_price]] : [], sale ? '' : 'Linked sales line is unavailable.');
        section('Product', product ? [['Product ID', product.id], ['Name', product.product_name], ['SKU', product.sku], ['Warehouse', product.warehouse_id], ['Unit', product.unit_label], ['Unit weight (g)', product.unit_weight_grams]] : [], product ? '' : 'Linked product is unavailable.');
        section('Current stock balance', stock ? [['On hand', stock.on_hand], ['Reserved', stock.reserved], ['Available', stock.available], ['Last updated', formatDeparture(stock.when_updated)]] : [], stock ? '' : 'Stock balance is unavailable.');
      }
    } catch (failure) { if (dialog.open && request === lineDetailRequest) setFormError(error, errorMessage(failure)); }
    finally { if (request === lineDetailRequest) status.textContent = ''; }
  };


  return { renderTripLines };
}
