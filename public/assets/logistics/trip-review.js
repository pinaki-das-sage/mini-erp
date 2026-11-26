import { appendDetails } from './details.js';

// Review the saved trip before whole-trip dispatch/delivery. This is not a manifest API.
export function initTripReview(context) {
  const { apiRequest, capabilities, textCell, stackedCell, formatKilograms, formatDeparture, errorMessage, setFormError, setListMessage, showDialog, closeDialog, loadList } = context;
  const dialog = document.querySelector('#trip-review-dialog');
  const confirm = document.querySelector('#trip-review-confirm');
  const refresh = document.querySelector('#trip-review-refresh');
  const error = document.querySelector('#trip-review-error');
  const status = document.querySelector('#trip-review-status');
  const lineStatus = document.querySelector('#trip-review-lines-status');
  const previous = document.querySelector('#trip-review-lines-previous');
  const next = document.querySelector('#trip-review-lines-next');
  let currentId = null, currentTrip = null, action = null, page = 1, sequence = 0, lineSequence = 0, pending = false;
  const loadLines = async () => {
    const request = ++lineSequence;
    const body = document.querySelector('#trip-review-lines'); body.replaceChildren();
    previous.disabled = true; next.disabled = true;
    previous.parentElement.hidden = true;
    document.querySelector('#trip-review-lines-page').textContent = 'Page ' + page;
    if (!capabilities['trip-lines'].read) { lineStatus.textContent = 'Trip-line read permission is required to view line details.'; return; }
    lineStatus.textContent = 'Loading trip lines...';
    try {
      const result = await apiRequest('/trip-lines?trip_id=' + encodeURIComponent(currentId) + '&page=' + page + '&page_size=25');
      if (!dialog.open || request !== lineSequence) return;
      if (!Array.isArray(result?.items)) throw new Error('The server returned unexpected trip lines.');
      for (const line of result.items) {
        const row = document.createElement('tr'); textCell(row, line.id); textCell(row, line.sales_line_id);
        stackedCell(row, line.product?.product_name ?? ('Product #' + line.product_id), line.product?.sku);
        textCell(row, line.quantity); textCell(row, formatKilograms(line.line_load)); body.append(row);
      }
      lineStatus.textContent = result.items.length ? result.items.length + ' lines on this page. Load totals above cover the complete trip.' : 'No lines on this page.';
      previous.parentElement.hidden = page === 1 && result.items.length < 25;
      previous.disabled = page <= 1; next.disabled = result.items.length < 25;
    } catch (failure) { if (request === lineSequence) lineStatus.textContent = errorMessage(failure); }
  };
  const load = async () => {
    const request = ++sequence;
    confirm.disabled = true; refresh.disabled = true; currentTrip = null;
    status.textContent = 'Loading saved trip...'; setFormError(error, '');
    document.querySelector('#trip-review-summary').replaceChildren();
    document.querySelector('#trip-review-activity').replaceChildren();
    try {
      const trip = await apiRequest('/trips/' + encodeURIComponent(currentId));
      if (!dialog.open || request !== sequence) return;
      if (!trip || trip.id == null) throw new Error('The server returned unexpected trip details.');
      currentTrip = trip;
      document.querySelector('#trip-review-title').textContent = (action === 'dispatch' ? 'Review dispatch' : action === 'deliver' ? 'Review full delivery' : 'Trip details') + ' - ' + trip.trip_code;
      appendDetails(document.querySelector('#trip-review-summary'), 'Saved trip', [
        ['Trip ID', trip.id], ['Trip reference', trip.trip_code], ['Status', trip.status], ['Sales order ID', trip.sales_order_id],
        ['Vehicle ID', trip.vehicle_id], ['Driver ID', trip.driver_id], ['Planned departure', formatDeparture(trip.planned_departure_at)],
        ['Planned load (KG)', formatKilograms(trip.planned_load_kg)], ['Capacity (KG)', formatKilograms(trip.capacity_kg)], ['Remaining (KG)', formatKilograms(trip.remaining_capacity_kg)]
      ]);
      const load = Number(trip.planned_load_kg), capacity = Number(trip.capacity_kg);
      const known = trip.planned_load_kg != null && trip.capacity_kg != null && Number.isFinite(load) && capacity > 0;
      const meter = document.querySelector('#trip-review-capacity'); meter.hidden = !known; meter.value = known ? Math.min(100, load / capacity * 100) : 0;
      document.querySelector('#trip-review-capacity-label').textContent = known ? formatKilograms(load) + ' / ' + formatKilograms(capacity) + ' KG' + (load > capacity ? ' - exceeds capacity' : '') : 'Capacity data is unavailable.';
      appendDetails(document.querySelector('#trip-review-activity'), 'Recorded times', [
        ['Assigned', formatDeparture(trip.assigned_at)], ['Dispatched', formatDeparture(trip.dispatched_at)],
        ['Delivered', formatDeparture(trip.delivered_at)], ['Cancelled', formatDeparture(trip.cancelled_at)], ['Cancellation reason', trip.cancellation_reason]
      ]);
      confirm.hidden = !action || !capabilities.trips.update;
      confirm.textContent = action === 'dispatch' ? 'Confirm Dispatch' : 'Confirm Full Delivery';
      const stateMatches = trip.status === (action === 'dispatch' ? 'DRAFT' : 'DISPATCHED');
      confirm.disabled = !stateMatches || (action === 'dispatch' && known && load > capacity);
      document.querySelector('#trip-review-confirmation').textContent = action === 'dispatch'
        ? 'Dispatch the complete saved trip. All lines are included. Availability, capacity, order and stock are checked again when you confirm.'
        : action === 'deliver' ? 'Record full delivery for the complete trip. All saved line quantities are delivered together; quantities cannot be edited here.'
        : 'Saved trip and line information is read only. Activity times are returned by the server.';
      if (action && !stateMatches) setFormError(error, 'This trip is now ' + trip.status + '. The requested action is unavailable.');
      await loadLines();
    } catch (failure) { if (request === sequence && dialog.open) setFormError(error, errorMessage(failure)); }
    finally { if (request === sequence) { status.textContent = ''; refresh.disabled = false; } }
  };
  confirm.addEventListener('click', async () => {
    if (pending || confirm.disabled || !currentTrip || !action) return;
    pending = true; confirm.disabled = true; refresh.disabled = true; setFormError(error, '');
    const id = currentId, command = action;
    try {
      await apiRequest('/trips/' + encodeURIComponent(id) + '/' + command, { method: 'POST', payload: {} });
      closeDialog(dialog);
      if (await loadList('trips')) setListMessage('trips', 'Trip ' + currentTrip.trip_code + (command === 'dispatch' ? ' was dispatched.' : ' was delivered.'));
    } catch (failure) { setFormError(error, errorMessage(failure)); }
    finally { pending = false; confirm.disabled = false; refresh.disabled = false; }
  });
  refresh.addEventListener('click', () => { if (!pending) load(); });
  previous.addEventListener('click', () => { if (page > 1) { page--; loadLines(); } });
  next.addEventListener('click', () => { page++; loadLines(); });
  dialog.addEventListener('cancel', event => { if (pending) event.preventDefault(); });
  return { open(id, command = null) {
    if (pending) return;
    currentId = id; action = command; page = 1; confirm.hidden = true; lineSequence++;
    document.querySelector('#trip-review-title').textContent = 'Trip review';
    document.querySelector('#trip-review-lines').replaceChildren();
    document.querySelector('#trip-review-capacity').hidden = true;
    document.querySelector('#trip-review-capacity-label').textContent = '';
    document.querySelector('#trip-review-confirmation').textContent = '';
    document.querySelector('.logistics-activity').open = false;
    showDialog(dialog); load();
  } };
}
