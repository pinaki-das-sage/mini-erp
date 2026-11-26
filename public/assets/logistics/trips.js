import { initTripReview } from './trip-review.js';
// trips: table rendering, dialogs and feature-specific interactions.
export function initTrips(context) {
  const {
    apiRequest,
    capabilities,
    getField,
    textCell,
    stackedCell,
    formatKilograms,
    formatDeparture,
    toLocalDateTimeInput,
    errorMessage,
    setFormError,
    setListMessage,
    makeButton,
    showDialog,
    closeDialog,
    loadList
  } = context;
  const tripReview = initTripReview(context);
  const renderTrips = (items) => {
    const body = document.querySelector('#trips-table-body');
    body.replaceChildren();
    for (const trip of items) {
      const row = document.createElement('tr');
      textCell(row, getField(trip, 'trip_code'));
      textCell(row, getField(trip, 'sales_order_id'));
      textCell(row, getField(trip, 'vehicle_id'));
      textCell(row, getField(trip, 'driver_id'));
      textCell(row, formatDeparture(getField(trip, 'planned_departure_at')));

      const status = String(getField(trip, 'status') || 'UNKNOWN');
      const statusCell = document.createElement('td');
      const badge = document.createElement('span');
      badge.className = `logistics-badge logistics-badge-${['DRAFT', 'DISPATCHED', 'DELIVERED', 'CANCELLED'].includes(status) ? status.toLowerCase() : 'unknown'}`;
      badge.textContent = status;
      statusCell.append(badge);
      row.append(statusCell);

      textCell(row, formatKilograms(getField(trip, 'planned_load_kg')));
      textCell(row, formatKilograms(getField(trip, 'capacity_kg')));
      textCell(row, formatKilograms(getField(trip, 'remaining_capacity_kg')));

      const actionsCell = document.createElement('td');
      actionsCell.className = 'logistics-actions';
      actionsCell.append(makeButton('View', 'button-secondary', () => tripReview.open(trip.id))); 
      if (status === 'DRAFT' && capabilities.trips.update) {
        if (capabilities.vehicles.read && capabilities.drivers.read) {
          actionsCell.append(makeButton('Edit', 'button-secondary', () => openTripDialog('update', trip)));
        }
        actionsCell.append(
          makeButton('Cancel', 'button-danger', () => openCancelDialog(trip)),
          makeButton('Review Dispatch', 'button-secondary', () => confirmTripAction(trip, 'dispatch')),
        );
      } else if (status === 'DISPATCHED' && capabilities.trips.update) {
        actionsCell.append(makeButton('Review Delivery', 'button-secondary', () => confirmTripAction(trip, 'deliver')));
      }
      row.append(actionsCell);
      body.append(row);
    }
  };


  const fillResourceSelect = (select, items, type, selectedId = null) => {
    select.replaceChildren();
    const placeholder = document.createElement('option');
    placeholder.value = '';
    placeholder.textContent = `Select an active ${type.toLowerCase()}`;
    placeholder.disabled = true;
    placeholder.selected = selectedId === null;
    select.append(placeholder);

    for (const item of items) {
      const id = getField(item, 'ID', 'id');
      const option = document.createElement('option');
      option.value = String(id);
      if (type === 'Vehicle') option.dataset.capacity = String(getField(item, 'capacity') ?? '');
      option.textContent = type === 'Vehicle'
        ? `${getField(item, 'vehicle_code')} | ${getField(item, 'registration_number')} | ${formatKilograms(getField(item, 'capacity'))} KG`
        : `${getField(item, 'driver_code')} | ${getField(item, 'first_name')} ${getField(item, 'last_name')} | Licence expires ${getField(item, 'licence_expiry')}`;
      option.selected = selectedId !== null && String(id) === String(selectedId);
      select.append(option);
    }

    if (selectedId !== null && items.some((item) => String(getField(item, 'id')) === String(selectedId))) {
      select.value = String(selectedId);
    }
  };

  const loadTripResources = async (tripId = null) => {
    const exclude = tripId === null ? '' : `&exclude_trip_id=${encodeURIComponent(tripId)}`;
    const vehicles = await apiRequest(`/eligible-vehicles?page=1&page_size=100${exclude}`);
    if (!Array.isArray(vehicles?.items)) {
      throw new Error('The server returned an unexpected resources response. Refresh and try again.');
    }
    return { vehicles: vehicles.items };
  };

  const tripOrderSelect = document.querySelector('#trip-order-number');
  const tripOrderSearch = document.querySelector('#trip-order-search');
  const tripOrderStatus = document.querySelector('#trip-order-status');
  const searchTripOrdersButton = document.querySelector('#search-trip-orders');
  let loadingSubmittedOrders = false;
  const loadSubmittedOrders = async () => {
    if (loadingSubmittedOrders) return;
    loadingSubmittedOrders = true;
    orderPreviewSequence += 1;
    loadingOrderPreview = false;
    resetOrderPreview();
    searchTripOrdersButton.disabled = true;
    tripOrderSelect.disabled = true;
    tripOrderStatus.textContent = 'Loading Submitted Sales Orders...';
    const query = new URLSearchParams({ page: '1', page_size: '100' });
    const searchTerm = tripOrderSearch.value.trim();
    if (searchTerm !== '') query.set('search', searchTerm);
    try {
      const result = await apiRequest(`/sales-orders?${query.toString()}`);
      if (!Array.isArray(result?.items)) throw new Error('The server returned an unexpected Sales Order list. Refresh and try again.');
      tripOrderSelect.replaceChildren();
      const placeholder = document.createElement('option');
      placeholder.value = '';
      placeholder.textContent = result.items.length === 0 ? 'No eligible Submitted Sales Orders' : 'Select a Submitted Sales Order';
      placeholder.disabled = true;
      placeholder.selected = true;
      tripOrderSelect.append(placeholder);
      for (const order of result.items) {
        const option = document.createElement('option');
        option.value = String(order.order_number);
        option.textContent = `${order.order_number} | ${order.customer_code}`;
        tripOrderSelect.append(option);
      }
      tripOrderStatus.textContent = `${result.items.length} Submitted Sales Order${result.items.length === 1 ? '' : 's'} loaded.`;
    } catch (error) {
      tripOrderSelect.replaceChildren();
      const option = document.createElement('option');
      option.value = '';
      option.textContent = 'Sales Orders could not be loaded';
      option.disabled = true;
      option.selected = true;
      tripOrderSelect.append(option);
      tripOrderStatus.textContent = errorMessage(error);
    } finally {
      loadingSubmittedOrders = false;
      searchTripOrdersButton.disabled = false;
      tripOrderSelect.disabled = false;
    }
  };
  searchTripOrdersButton.addEventListener('click', loadSubmittedOrders);
  tripOrderSearch.addEventListener('keydown', (event) => {
    if (event.key === 'Enter') {
      event.preventDefault();
      loadSubmittedOrders();
    }
  });

  const localDateTimeWithOffset = (value) => {
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) throw new Error('Enter a valid local planned departure time.');
    const [datePart, timePart] = value.split('T');
    const [year, month, day] = datePart.split('-').map(Number);
    const [hour, minute] = timePart.split(':').map(Number);
    if (date.getFullYear() !== year || date.getMonth() + 1 !== month || date.getDate() !== day
      || date.getHours() !== hour || date.getMinutes() !== minute) {
      throw new Error('That local time does not exist because of a daylight-saving time change. Choose another time.');
    }
    const offsetMinutes = date.getTimezoneOffset();
    const sign = offsetMinutes <= 0 ? '+' : '-';
    const absoluteOffset = Math.abs(offsetMinutes);
    const offsetHours = String(Math.floor(absoluteOffset / 60)).padStart(2, '0');
    const offsetRemainder = String(absoluteOffset % 60).padStart(2, '0');
    return `${value}:00${sign}${offsetHours}:${offsetRemainder}`;
  };

  const tripDialog = document.querySelector('#trip-dialog');
  const tripForm = document.querySelector('#trip-form');
  const tripOrderLabel = tripForm.elements.order_number.closest('label');
  const tripOrderSearchLabel = tripOrderSearch.closest('label');
  const tripCodeLabel = tripForm.elements.trip_code.closest('label');
  const tripSubmit = document.querySelector('#trip-submit-button');
  const tripDriverSelect = tripForm.elements.driver_id;
  const tripDriverStatus = document.querySelector('#trip-driver-status');

  let plannerTrip = null;
  let orderPreviewSequence = 0;
  let loadingOrderPreview = false;
  let selectedOrder = null;
  const updateLoadSummary = () => {
    const capacity = Number(tripForm.elements.vehicle_id.selectedOptions[0]?.dataset.capacity);
    const load = plannerTrip?.planned_load_kg;
    const knownLoad = load !== null && load !== undefined && Number.isFinite(Number(load));
    document.querySelector('#planner-load-value').textContent = (knownLoad ? formatKilograms(load) : '-') + ' / ' + (capacity > 0 ? formatKilograms(capacity) : '-') + ' KG';
    const progress = document.querySelector('#planner-capacity-progress');
    progress.hidden = !knownLoad || !(capacity > 0);
    progress.value = knownLoad && capacity > 0 ? Math.min(100, Number(load) / capacity * 100) : 0;
    document.querySelector('#planner-load-help').textContent = knownLoad && capacity > 0
      ? 'Remaining capacity: ' + formatKilograms(capacity - Number(load)) + ' KG'
      : 'Planned load is calculated from product weights when the draft is saved.';
  };
  const resetOrderPreview = (message = 'Select a submitted Sales Order to see its lines.') => {
    selectedOrder = null;
    const row = document.createElement('tr'); const cell = textCell(row, message); cell.colSpan = 5;
    cell.className = 'logistics-empty'; document.querySelector('#planner-order-lines').replaceChildren(row);
    document.querySelector('#planner-warehouse').textContent = '-';
    document.querySelector('#planner-order-quantity').textContent = '-';
  };
  // The planner previews Sales data. Saving still assigns the complete order on the server.
  const loadOrderPreview = async () => {
    const sequence = ++orderPreviewSequence;
    const number = tripOrderSelect.value;
    loadingOrderPreview = false;
    resetOrderPreview(number ? 'Loading order lines...' : undefined);
    if (!number) return;
    loadingOrderPreview = true;
    try {
      const order = await apiRequest('/sales-orders/' + encodeURIComponent(number));
      if (sequence !== orderPreviewSequence) return;
      if (!order || !Array.isArray(order.lines) || order.status !== 'Submitted') {
        throw new Error('This order is no longer Submitted. Search again and select an eligible order.');
      }
      selectedOrder = order;
      const body = document.querySelector('#planner-order-lines'); body.replaceChildren();
      for (const line of order.lines) {
        const row = document.createElement('tr'); textCell(row, order.order_number);
        stackedCell(row, line.description || 'Product #' + line.product_id, 'Line ' + line.line_number + ' / Product #' + line.product_id);
        const badge = document.createElement('span'); badge.className = 'logistics-badge logistics-badge-draft'; badge.textContent = order.status;
        textCell(row, '').replaceChildren(badge); textCell(row, line.quantity); textCell(row, 'Included'); body.append(row);
      }
      if (!order.lines.length) resetOrderPreview('This order has no lines. Select another order.');
      document.querySelector('#planner-warehouse').textContent = order.warehouse_id ?? '-';
      const quantities = order.lines.map(line => Number(line.quantity));
      document.querySelector('#planner-order-quantity').textContent = quantities.every(Number.isFinite) ? quantities.reduce((sum, value) => sum + value, 0) : '-';
    } catch (failure) {
      if (sequence === orderPreviewSequence) resetOrderPreview(errorMessage(failure));
    } finally {
      if (sequence === orderPreviewSequence) loadingOrderPreview = false;
    }
  };
  tripOrderSelect.addEventListener('change', loadOrderPreview);
  tripForm.elements.vehicle_id.addEventListener('change', updateLoadSummary);
  let driverLookupSequence = 0;
  let loadingEligibleTripDrivers = false;

  const refreshEligibleTripDrivers = async (selectedDriverId = tripDriverSelect.value || null, tripId = tripForm.dataset.tripId || null) => {
    const sequence = ++driverLookupSequence;
    loadingEligibleTripDrivers = false;
    tripDriverSelect.disabled = true;

    loadingEligibleTripDrivers = true;
    tripSubmit.disabled = true;
    try {
      const query = new URLSearchParams({
        page: '1',
        page_size: '100',
      });
      if (tripId !== null && tripId !== '') query.set('exclude_trip_id', tripId);
      tripDriverStatus.textContent = 'Loading available drivers...';
      const result = await apiRequest(`/eligible-drivers?${query.toString()}`);
      if (sequence !== driverLookupSequence) return;
      if (!Array.isArray(result?.items)) throw new Error('The server returned an unexpected eligible Driver list. Refresh and try again.');

      const retained = selectedDriverId !== null
        && result.items.some((driver) => String(getField(driver, 'id')) === String(selectedDriverId))
        ? selectedDriverId
        : null;
      fillResourceSelect(tripDriverSelect, result.items, 'Driver', retained);
      tripDriverSelect.disabled = false;
      if (selectedDriverId !== null && retained === null) {
        tripDriverStatus.textContent = 'The selected driver is no longer available. Select another driver.';
      } else {
        tripDriverStatus.textContent = result.items.length === 0
          ? 'No drivers are available.'
          : `${result.items.length} Driver${result.items.length === 1 ? '' : 's'} available.`;
      }
    } catch (error) {
      if (sequence !== driverLookupSequence) return;
      fillResourceSelect(tripDriverSelect, [], 'Driver');
      tripDriverSelect.disabled = true;
      tripDriverStatus.textContent = errorMessage(error);
    } finally {
      if (sequence === driverLookupSequence) {
        loadingEligibleTripDrivers = false;
        tripSubmit.disabled = false;
      }
    }
  };


  const openTripDialog = async (mode, trip = null) => {
    setFormError(document.querySelector('#trip-form-error'), '');
    tripForm.reset();
    plannerTrip = trip;
    orderPreviewSequence += 1;
    loadingOrderPreview = false;
    resetOrderPreview();
    document.querySelector('#planner-sales-section').hidden = mode !== 'create';
    tripForm.dataset.mode = mode;
    tripForm.dataset.tripId = trip ? String(trip.id) : '';
    tripOrderLabel.hidden = mode !== 'create';
    tripOrderSearchLabel.hidden = mode !== 'create';
    tripCodeLabel.hidden = mode !== 'create';
    tripForm.elements.order_number.disabled = mode !== 'create';
    tripForm.elements.trip_code.disabled = mode !== 'create';
    document.querySelector('#trip-dialog-title').textContent = mode === 'create' ? 'Draft Trip Planner' : `Edit Trip ${trip.trip_code}`;
    tripSubmit.textContent = mode === 'create' ? 'Save Draft' : 'Save Trip';
    tripSubmit.disabled = true;
    showDialog(tripDialog);
    try {
      const resources = await loadTripResources(trip?.id ?? null);
      fillResourceSelect(tripForm.elements.vehicle_id, resources.vehicles, 'Vehicle', trip?.vehicle_id ?? null);
      updateLoadSummary();
      if (mode === 'update') {
        tripForm.elements.planned_departure_at.value = toLocalDateTimeInput(trip.planned_departure_at);
      } else {
        await loadSubmittedOrders();
      }
      await refreshEligibleTripDrivers(trip?.driver_id ?? null, trip?.id ?? null);
    } catch (error) {
      setFormError(document.querySelector('#trip-form-error'), errorMessage(error));
    } finally {
      tripSubmit.disabled = false;
    }
  };

  document.querySelector('#create-trip-button').addEventListener('click', () => openTripDialog('create'));
  tripForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    setFormError(document.querySelector('#trip-form-error'), '');
    if (loadingSubmittedOrders || loadingOrderPreview) return;
    if (tripForm.dataset.mode === 'create' && !selectedOrder) {
      setFormError(document.querySelector('#trip-form-error'), 'Select a submitted Sales Order and wait for its lines to load.');
      return;
    }
    if (loadingEligibleTripDrivers || tripDriverSelect.disabled || tripDriverSelect.value === '') {
      setFormError(document.querySelector('#trip-form-error'), 'Choose an available driver before saving.');
      return;
    }
    if (!tripForm.reportValidity()) return;
    const mode = tripForm.dataset.mode;
    const payload = {
      vehicle_id: Number(tripForm.elements.vehicle_id.value),
      driver_id: Number(tripForm.elements.driver_id.value),
      planned_departure_at: '',
    };
    if (mode === 'create') {
      payload.order_number = tripForm.elements.order_number.value.trim();
      payload.trip_code = Number(tripForm.elements.trip_code.value);
    }
    try {
      payload.planned_departure_at = localDateTimeWithOffset(tripForm.elements.planned_departure_at.value);
    } catch (error) {
      setFormError(document.querySelector('#trip-form-error'), errorMessage(error));
      return;
    }
    tripSubmit.disabled = true;
    try {
      const isCreate = mode === 'create';
      const path = isCreate ? '/trips' : `/trips/${encodeURIComponent(tripForm.dataset.tripId)}/update`;
      const result = await apiRequest(path, { method: 'POST', payload });
      closeDialog(tripDialog);
      tripForm.reset();
      const successMessage = isCreate
        ? `Trip ${result.trip_code ?? payload.trip_code} was created successfully.`
        : `Trip ${result.trip_code ?? 'update'} was updated successfully.`;
      if (await loadList('trips')) setListMessage('trips', successMessage);
    } catch (error) {
      setFormError(document.querySelector('#trip-form-error'), errorMessage(error));
    } finally {
      tripSubmit.disabled = false;
    }
  });

  const cancelDialog = document.querySelector('#cancel-trip-dialog');
  const cancelForm = document.querySelector('#cancel-trip-form');
  let cancelTrip = null;
  const openCancelDialog = (trip) => {
    cancelTrip = trip;
    cancelForm.reset();
    setFormError(document.querySelector('#cancel-trip-error'), '');
    document.querySelector('#cancel-trip-confirmation').textContent = `Cancel Trip ${trip.trip_code} (Sales order ID ${trip.sales_order_id})? Enter a reason to confirm.`;
    showDialog(cancelDialog);
  };

  const confirmTripAction = (trip, action) => tripReview.open(trip.id, action);

  cancelForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    setFormError(document.querySelector('#cancel-trip-error'), '');
    if (!cancelForm.reportValidity() || !cancelTrip) return;
    const reason = cancelForm.elements.reason.value.trim();
    if (reason === '' || reason.length > 500) {
      setFormError(document.querySelector('#cancel-trip-error'), 'Enter a nonblank reason of at most 500 characters.');
      return;
    }
    const submit = document.querySelector('#cancel-trip-submit');
    submit.disabled = true;
    try {
      const result = await apiRequest(`/trips/${encodeURIComponent(cancelTrip.id)}/cancel`, {
        method: 'POST',
        payload: { reason },
      });
      closeDialog(cancelDialog);
      const successMessage = `Trip ${result.trip_code ?? cancelTrip.trip_code} was cancelled successfully.`;
      cancelTrip = null;
      if (await loadList('trips')) setListMessage('trips', successMessage);
    } catch (error) {
      setFormError(document.querySelector('#cancel-trip-error'), errorMessage(error));
    } finally {
      submit.disabled = false;
    }
  });


  return { renderTrips };
}
