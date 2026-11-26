'use strict';

const appUrl = (path) => `${document.body.dataset.basePath}${path}`;

const form = document.querySelector('#note-form');
if (form) {
  let actionReference = crypto.randomUUID();
  const status = document.querySelector('#note-status');
  const button = form.querySelector('button');
  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    button.disabled = true;
    try {
      const response = await fetch(appUrl('/api/note'), {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-Token': form.elements.csrf_token.value,
        },
        body: JSON.stringify({
          action_reference: actionReference,
          text: form.elements.text.value,
          expected_version: Number(form.dataset.version),
        }),
      });
      const body = await response.json();
      if (!response.ok || !body.success) {
        status.textContent = body.error.message;
        return;
      }

      form.dataset.version = String(body.current_version);
      status.textContent = `Saved. Version ${body.current_version}.`;
      actionReference = crypto.randomUUID();
    } catch {
      status.textContent = 'No confirmed response. Retry unchanged input with this same action reference, or refresh to check the result.';
    } finally {
      button.disabled = false;
    }
  });
}

for (const adminForm of document.querySelectorAll('.admin-form')) {
  adminForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    const status = adminForm.querySelector('.form-status');
    const button = adminForm.querySelector('button');
    const operation = adminForm.dataset.operation;
    const payload = { operation };
    if (operation !== 'create') {
      payload.id = adminForm.dataset.id;
      payload.expected_version = Number(adminForm.dataset.version);
    }
    for (const name of ['username', 'display_name', 'password']) {
      if (adminForm.elements[name]) payload[name] = adminForm.elements[name].value;
    }
    for (const name of ['is_admin', 'active']) {
      if (adminForm.elements[name]) payload[name] = adminForm.elements[name].checked;
    }
    if (operation === 'permissions') {
      payload.grants = [...adminForm.querySelectorAll('[name="grants"]:checked')].map((input) => input.value);
    }
    button.disabled = true;
    try {
      const response = await fetch(appUrl('/api/settings/users'), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': adminForm.elements.csrf_token.value },
        body: JSON.stringify(payload),
      });
      const result = await response.json();
      if (!response.ok || !result.success) {
        status.textContent = result.error.message;
        return;
      }
      window.location.assign(appUrl(`/settings/users/${encodeURIComponent(result.result.id)}`));
    } catch {
      status.textContent = 'No confirmed response. Refresh and check the saved state before retrying.';
    } finally {
      button.disabled = false;
    }
  });
}

const search = document.querySelector('#user-search');
if (search) {
  const status = document.querySelector('#user-status');
  const filter = () => {
    for (const row of document.querySelectorAll('#users-table tbody tr')) {
      row.hidden = !row.textContent.toLowerCase().includes(search.value.toLowerCase())
        || (status.value !== '' && row.dataset.active !== status.value);
    }
  };
  search.addEventListener('input', filter);
  status.addEventListener('change', filter);
}

const logisticsWorkspace = document.querySelector('#logistics-workspace');
if (logisticsWorkspace && logisticsWorkspace.dataset.initialized !== 'true') {
  logisticsWorkspace.dataset.initialized = 'true';

  const csrfToken = document.querySelector('#logistics-csrf').value;
  const logisticsState = {
    trips: { page: 1, pageSize: 25, loading: false, loaded: false },
    drivers: { page: 1, pageSize: 25, loading: false, loaded: false },
    vehicles: { page: 1, pageSize: 25, loading: false, loaded: false },
  };
  const capabilities = {
    trips: { read: false, create: false, update: false },
    drivers: { read: false, create: false, update: false, delete: false },
    vehicles: { read: false, create: false, update: false, delete: false },
    sales_orders_read: false,
  };
  const tabNames = ['trips', 'drivers', 'vehicles'];
  let focusBeforeDialog = null;
  let currentDriverUser = null;

  class LogisticsRequestError extends Error {
    constructor(message, code, status) {
      super(message);
      this.code = code;
      this.status = status;
    }
  }

  const apiRequest = async (path, options = {}) => {
    const requestOptions = { method: options.method || 'GET', headers: {} };
    if (requestOptions.method !== 'GET') {
      requestOptions.headers['Content-Type'] = 'application/json';
      requestOptions.headers['X-CSRF-Token'] = csrfToken;
      requestOptions.body = JSON.stringify(options.payload);
    }
    const response = await fetch(appUrl(`/ui-api/logistics${path}`), requestOptions);
    let body;
    try {
      body = await response.json();
    } catch {
      throw new Error('The server returned an unreadable response. Refresh and try again.');
    }
    if (!body || typeof body !== 'object' || Array.isArray(body) || typeof body.success !== 'boolean') {
      throw new Error('The server returned an unexpected response. Refresh and try again.');
    }
    if (!response.ok || body.success !== true) {
      const code = typeof body.error?.code === 'string' ? body.error.code : 'REQUEST_FAILED';
      const message = typeof body.error?.message === 'string' ? body.error.message : 'The request could not be completed.';
      throw new LogisticsRequestError(message, code, response.status);
    }
    if (!Object.prototype.hasOwnProperty.call(body, 'result')) {
      throw new Error('The server response did not include a result. Refresh and try again.');
    }
    return body.result;
  };

  const errorMessage = (error) => {
    if (error instanceof LogisticsRequestError) {
      if (error.status === 400) return `Validation failed: ${error.message}`;
      if (error.status === 401 || ['UNAUTHENTICATED', 'SESSION_EXPIRED'].includes(error.code)) {
        return `Your session is no longer authorized. Sign in again. ${error.message}`;
      }
      if (error.code === 'PASSWORD_CHANGE_REQUIRED') return error.message;
      if (error.status === 403) return `The server denied this request (permission or CSRF check). ${error.message}`;
      if (error.status === 404) return `The requested record was not found. ${error.message}`;
      if (error.status === 409) return `The request conflicts with current workflow or resource state. Refresh and correct the data before trying again. ${error.message}`;
      if (error.status === 503) return `A required service is unavailable. Try again later. ${error.message}`;
      return error.message;
    }
    if (error instanceof TypeError) return 'A network error prevented the request. Check your connection and refresh before retrying.';
    return error instanceof Error ? error.message : 'An unexpected error occurred. Refresh and try again.';
  };

  const getField = (record, ...names) => {
    for (const name of names) {
      if (Object.prototype.hasOwnProperty.call(record, name)) return record[name];
    }
    return null;
  };

  const textCell = (row, value) => {
    const cell = document.createElement('td');
    cell.textContent = value === null || value === undefined || value === '' ? '-' : String(value);
    row.append(cell);
    return cell;
  };

  const formatKilograms = (value) => {
    if (value === null || value === undefined || value === '' || !Number.isFinite(Number(value))) return '-';
    return Number(value).toFixed(3);
  };

  const formatDeparture = (value) => {
    if (typeof value !== 'string' || value === '') return '-';
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? value : date.toLocaleString();
  };

  const toLocalDateTimeInput = (value) => {
    if (typeof value !== 'string' || value === '') return '';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return '';
    const pad = (part) => String(part).padStart(2, '0');
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
  };

  const activeLabel = (value) => {
    if (value === true || value === 'T' || value === 't' || value === 1 || value === '1') return 'Active';
    if (value === false || value === 'F' || value === 'f' || value === 0 || value === '0') return 'Inactive';
    return '-';
  };

  const setListMessage = (name, message, error = false) => {
    const status = document.querySelector(`#${name}-status`);
    const errorElement = document.querySelector(`#${name}-error`);
    status.textContent = error ? '' : message;
    errorElement.textContent = error ? message : '';
    errorElement.hidden = !error;
  };

  const setFormError = (element, message) => {
    element.textContent = message;
    element.hidden = message === '';
  };

  const makeButton = (label, className, handler) => {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = className;
    button.textContent = label;
    button.addEventListener('click', handler);
    return button;
  };

  const updatePagination = (name, page, pageSize, itemCount) => {
    document.querySelector(`#${name}-page-label`).textContent = `Page ${page}`;
    document.querySelector(`#${name}-previous`).disabled = page <= 1 || logisticsState[name].loading;
    document.querySelector(`#${name}-next`).disabled = itemCount < pageSize || logisticsState[name].loading;
  };

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
      if (status === 'DRAFT' && capabilities.trips.update) {
        actionsCell.append(
          makeButton('Edit', 'button-secondary', () => openTripDialog('update', trip)),
          makeButton('Cancel', 'button-danger', () => openCancelDialog(trip)),
          makeButton('Dispatch', 'button-secondary', () => confirmTripAction(trip, 'dispatch')),
        );
      } else if (status === 'DISPATCHED' && capabilities.trips.update) {
        actionsCell.append(makeButton('Mark Delivered', 'button-secondary', () => confirmTripAction(trip, 'deliver')));
      } else {
        actionsCell.textContent = '-';
      }
      row.append(actionsCell);
      body.append(row);
    }
  };

  const renderDrivers = (items) => {
    const body = document.querySelector('#drivers-table-body');
    body.replaceChildren();
    for (const driver of items) {
      const row = document.createElement('tr');
      textCell(row, getField(driver, 'driver_code'));
      textCell(row, getField(driver, 'first_name'));
      textCell(row, getField(driver, 'last_name'));
      const userId = getField(driver, 'user_id');
      textCell(row, userId === null ? 'Not linked' : `User #${userId}`);
      textCell(row, getField(driver, 'phone'));
      textCell(row, getField(driver, 'licence_number'));
      textCell(row, getField(driver, 'licence_expiry'));
      textCell(row, activeLabel(getField(driver, 'isactive')));
      const actionsCell = document.createElement('td');
      actionsCell.className = 'logistics-actions';
      if (capabilities.drivers.update) {
        actionsCell.append(makeButton('Edit', 'button-secondary', () => openDriverDialog('update', driver.id)));
      }
      if (capabilities.drivers.delete) {
        actionsCell.append(makeButton('Delete / Deactivate', 'button-danger', () => deleteDriver(driver)));
      }
      if (actionsCell.children.length === 0) actionsCell.textContent = '-';
      row.append(actionsCell);
      body.append(row);
    }
  };

  const renderVehicles = (items) => {
    const body = document.querySelector('#vehicles-table-body');
    body.replaceChildren();
    for (const vehicle of items) {
      const row = document.createElement('tr');
      textCell(row, getField(vehicle, 'VEHICLE_CODE', 'vehicle_code'));
      textCell(row, getField(vehicle, 'REGISTRATION_NUMBER', 'registration_number'));
      textCell(row, getField(vehicle, 'DESCRIPTION', 'description'));
      textCell(row, formatKilograms(getField(vehicle, 'CAPACITY', 'capacity')));
      textCell(row, activeLabel(getField(vehicle, 'is_active', 'ISACTIVE', 'active')));
      textCell(row, getField(vehicle, 'assignment_status') === 'ASSIGNED' ? 'Assigned to active Trip' : 'Available');
      const actionsCell = document.createElement('td');
      actionsCell.className = 'logistics-actions';
      if (capabilities.vehicles.update) {
        actionsCell.append(makeButton('Edit', 'button-secondary', () => openVehicleDialog('update', vehicle.id)));
      }
      if (capabilities.vehicles.delete) {
        actionsCell.append(makeButton('Delete', 'button-danger', () => deleteVehicle(vehicle)));
      }
      if (actionsCell.children.length === 0) actionsCell.textContent = '-';
      row.append(actionsCell);
      body.append(row);
    }
  };

  const loadList = async (name) => {
    const state = logisticsState[name];
    if (state.loading) return;
    if (!capabilities[name].read) {
      setListMessage(name, `You do not have permission to read ${name}.`, true);
      return false;
    }
    state.loading = true;
    setListMessage(name, `Loading ${name}...`);
    document.querySelector(`#${name}-refresh-button`)?.setAttribute('disabled', '');
    updatePagination(name, state.page, state.pageSize, 0);
    try {
      const result = await apiRequest(`/${name}?page=${state.page}&page_size=${state.pageSize}`);
      if (!result || !Array.isArray(result.items) || !Number.isInteger(result.page) || !Number.isInteger(result.page_size)) {
        throw new Error('The server returned an unexpected list response. Refresh and try again.');
      }
      const render = { trips: renderTrips, drivers: renderDrivers, vehicles: renderVehicles }[name];
      render(result.items);
      state.loaded = true;
      updatePagination(name, state.page, state.pageSize, result.items.length);
      setListMessage(name, result.items.length === 0
        ? (state.page === 1 ? `No ${name} found.` : `No additional ${name} on this page.`)
        : `Loaded ${result.items.length} ${name}.`);
      return true;
    } catch (error) {
      setListMessage(name, errorMessage(error), true);
      return false;
    } finally {
      state.loading = false;
      document.querySelector(`#${name}-refresh-button`)?.removeAttribute('disabled');
      updatePagination(name, state.page, state.pageSize, document.querySelector(`#${name}-table-body`).children.length);
    }
  };

  const switchTab = (name) => {
    for (const tabName of tabNames) {
      const selected = tabName === name;
      const tab = document.querySelector(`#${tabName}-tab`);
      tab.setAttribute('aria-selected', String(selected));
      tab.tabIndex = selected ? 0 : -1;
      document.querySelector(`#${tabName}-panel`).hidden = !selected;
    }
    if (!logisticsState[name].loaded && !logisticsState[name].loading) {
      loadList(name);
    }
  };

  for (const name of tabNames) {
    document.querySelector(`#${name}-tab`).addEventListener('click', () => switchTab(name));
    document.querySelector(`#${name}-tab`).addEventListener('keydown', (event) => {
      const currentIndex = tabNames.indexOf(name);
      let nextIndex = currentIndex;
      if (event.key === 'ArrowRight') nextIndex = (currentIndex + 1) % tabNames.length;
      else if (event.key === 'ArrowLeft') nextIndex = (currentIndex + tabNames.length - 1) % tabNames.length;
      else if (event.key === 'Home') nextIndex = 0;
      else if (event.key === 'End') nextIndex = tabNames.length - 1;
      else return;
      event.preventDefault();
      const nextName = tabNames[nextIndex];
      document.querySelector(`#${nextName}-tab`).focus();
      switchTab(nextName);
    });
  }

  for (const name of tabNames) {
    document.querySelector(`#${name}-refresh-button`).addEventListener('click', () => loadList(name));
    document.querySelector(`#${name}-previous`).addEventListener('click', () => {
      if (logisticsState[name].page > 1) {
        logisticsState[name].page -= 1;
        loadList(name);
      }
    });
    document.querySelector(`#${name}-next`).addEventListener('click', () => {
      logisticsState[name].page += 1;
      loadList(name);
    });
  }

  const closeDialog = (dialog) => {
    if (dialog.open) dialog.close();
  };
  for (const closeButton of document.querySelectorAll('.logistics-dialog [data-close-dialog]')) {
    closeButton.addEventListener('click', () => closeDialog(closeButton.closest('dialog')));
  }
  for (const dialog of document.querySelectorAll('.logistics-dialog')) {
    dialog.addEventListener('close', () => {
      if (focusBeforeDialog?.isConnected) focusBeforeDialog.focus();
      focusBeforeDialog = null;
    });
  }
  const showDialog = (dialog) => {
    focusBeforeDialog = document.activeElement;
    dialog.showModal();
    dialog.querySelector('input:not([type="hidden"]):not(:disabled), select:not(:disabled), textarea:not(:disabled)')?.focus();
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
  let driverLookupSequence = 0;
  let loadingEligibleTripDrivers = false;

  const refreshEligibleTripDrivers = async (selectedDriverId = tripDriverSelect.value || null, tripId = tripForm.dataset.tripId || null) => {
    const sequence = ++driverLookupSequence;
    const departureValue = tripForm.elements.planned_departure_at.value;
    loadingEligibleTripDrivers = false;
    tripDriverSelect.disabled = true;
    if (departureValue === '') {
      fillResourceSelect(tripDriverSelect, [], 'Driver');
      tripDriverStatus.textContent = 'Choose a planned departure to load eligible Drivers.';
      tripSubmit.disabled = false;
      return;
    }

    loadingEligibleTripDrivers = true;
    tripSubmit.disabled = true;
    try {
      const departure = localDateTimeWithOffset(departureValue);
      const query = new URLSearchParams({
        page: '1',
        page_size: '100',
        planned_departure_at: departure,
      });
      if (tripId !== null && tripId !== '') query.set('exclude_trip_id', tripId);
      tripDriverStatus.textContent = 'Loading Drivers with licences valid for the planned departure...';
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
        tripDriverStatus.textContent = 'The selected Driver is not eligible for this departure. Select another Driver.';
      } else {
        tripDriverStatus.textContent = result.items.length === 0
          ? 'No Drivers are eligible for this planned departure.'
          : `${result.items.length} Driver${result.items.length === 1 ? '' : 's'} eligible for this planned departure.`;
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

  tripForm.elements.planned_departure_at.addEventListener('change', () => {
    refreshEligibleTripDrivers();
  });

  const openTripDialog = async (mode, trip = null) => {
    setFormError(document.querySelector('#trip-form-error'), '');
    tripForm.reset();
    tripForm.dataset.mode = mode;
    tripForm.dataset.tripId = trip ? String(trip.id) : '';
    tripOrderLabel.hidden = mode !== 'create';
    tripOrderSearchLabel.hidden = mode !== 'create';
    tripCodeLabel.hidden = mode !== 'create';
    tripForm.elements.order_number.disabled = mode !== 'create';
    tripForm.elements.trip_code.disabled = mode !== 'create';
    document.querySelector('#trip-dialog-title').textContent = mode === 'create' ? 'Create Trip' : `Edit Trip ${trip.trip_code}`;
    tripSubmit.textContent = mode === 'create' ? 'Create Trip' : 'Save Trip';
    tripSubmit.disabled = true;
    showDialog(tripDialog);
    try {
      const resources = await loadTripResources(trip?.id ?? null);
      fillResourceSelect(tripForm.elements.vehicle_id, resources.vehicles, 'Vehicle', trip?.vehicle_id ?? null);
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
    if (loadingSubmittedOrders) return;
    if (loadingEligibleTripDrivers || tripDriverSelect.disabled || tripDriverSelect.value === '') {
      setFormError(document.querySelector('#trip-form-error'), 'Choose a planned departure and an eligible Driver before saving.');
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

  const confirmTripAction = async (trip, action) => {
    const isDispatch = action === 'dispatch';
    const warning = isDispatch
      ? 'Dispatch will affect Logistics, Sales, and Inventory through the backend workflow.'
      : 'Marking this Trip delivered will post its delivery through the backend workflow.';
    if (!window.confirm(`${warning}\n\nContinue with Trip ${trip.trip_code}?`)) return;
    const button = [...document.querySelectorAll('#trips-table-body button')].find((item) => {
      return item.textContent === (isDispatch ? 'Dispatch' : 'Mark Delivered')
        && item.closest('tr')?.firstElementChild?.textContent === String(trip.trip_code);
    });
    const actionRow = button?.closest('tr');
    const actionButtons = actionRow ? [...actionRow.querySelectorAll('button')] : [];
    for (const actionButton of actionButtons) actionButton.disabled = true;
    try {
      const result = await apiRequest(`/trips/${encodeURIComponent(trip.id)}/${action}`, {
        method: 'POST',
        payload: {},
      });
      const committedStatus = typeof result.status === 'string' ? ` Server result: ${result.status}.` : '';
      const successMessage = `Trip ${trip.trip_code} ${isDispatch ? 'dispatch' : 'delivery'} completed.${committedStatus}`;
      if (await loadList('trips')) setListMessage('trips', successMessage);
    } catch (error) {
      setListMessage('trips', errorMessage(error), true);
    } finally {
      if (actionRow?.isConnected) {
        for (const actionButton of actionButtons) actionButton.disabled = false;
      }
    }
  };

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

  const driverDialog = document.querySelector('#driver-dialog');
  const driverForm = document.querySelector('#driver-form');
  const driverSubmit = document.querySelector('#driver-submit-button');
  const driverUserSelect = driverForm.elements.user_id;
  const driverUserSearch = document.querySelector('#driver-user-search');
  const driverUserStatus = document.querySelector('#driver-user-status');
  const searchDriverUsersButton = document.querySelector('#search-driver-users');
  const setDriverUserMessage = (message) => { driverUserStatus.textContent = message; };

  const showDriverUserFallback = () => {
    driverUserSelect.replaceChildren();
    const unlinked = document.createElement('option');
    unlinked.value = '';
    unlinked.textContent = 'No linked user';
    driverUserSelect.append(unlinked);
    if (currentDriverUser) {
      const selected = document.createElement('option');
      selected.value = String(currentDriverUser.id);
      selected.textContent = `User #${currentDriverUser.id} (currently linked)`;
      selected.selected = true;
      driverUserSelect.append(selected);
    }
  };

  const loadDriverUsers = async () => {
    searchDriverUsersButton.disabled = true;
    setDriverUserMessage('Loading eligible users...');
    const query = new URLSearchParams({ page: '1', page_size: '100' });
    const searchTerm = driverUserSearch.value.trim();
    if (searchTerm !== '') query.set('search', searchTerm);
    try {
      const result = await apiRequest(`/driver-users?${query.toString()}`);
      if (!Array.isArray(result?.items)) throw new Error('The server returned an unexpected user list. Refresh and try again.');
      driverUserSelect.replaceChildren();
      const unlinked = document.createElement('option');
      unlinked.value = '';
      unlinked.textContent = 'No linked user';
      driverUserSelect.append(unlinked);
      if (currentDriverUser) {
        const selected = document.createElement('option');
        selected.value = String(currentDriverUser.id);
        selected.textContent = `${currentDriverUser.username ? `${currentDriverUser.username} - ` : ''}User #${currentDriverUser.id} (currently linked)`;
        driverUserSelect.append(selected);
      }
      for (const user of result.items.filter((item) => !currentDriverUser || String(item.id) !== String(currentDriverUser.id))) {
        const option = document.createElement('option');
        option.value = String(user.id);
        option.textContent = `${user.display_name || user.username} (${user.username}) - ${user.foundation_user_id}`;
        driverUserSelect.append(option);
      }
      if (currentDriverUser) driverUserSelect.value = String(currentDriverUser.id);
      setDriverUserMessage(`${result.items.length} eligible user${result.items.length === 1 ? '' : 's'} loaded. Search users explicitly; active profiles already linked to a user are excluded.`);
    } catch (error) {
      showDriverUserFallback();
      setDriverUserMessage(errorMessage(error));
    } finally {
      searchDriverUsersButton.disabled = false;
    }
  };

  const populateDriverForm = (driver = null) => {
    driverForm.elements.driver_code.value = driver?.driver_code ?? '';
    driverForm.elements.first_name.value = driver?.first_name ?? '';
    driverForm.elements.last_name.value = driver?.last_name ?? '';
    driverForm.elements.phone.value = driver?.phone ?? '';
    driverForm.elements.licence_number.value = driver?.licence_number ?? '';
    driverForm.elements.licence_expiry.value = driver?.licence_expiry ?? '';
    driverForm.elements.isactive.value = driver?.isactive ?? 'T';
  };

  const openDriverDialog = async (mode, driverId = null) => {
    setFormError(document.querySelector('#driver-form-error'), '');
    driverForm.reset();
    currentDriverUser = null;
    driverForm.dataset.mode = mode;
    driverForm.dataset.driverId = driverId === null ? '' : String(driverId);
    document.querySelector('#driver-dialog-title').textContent = mode === 'create' ? 'Create Driver' : 'Edit Driver';
    driverSubmit.textContent = mode === 'create' ? 'Create Driver' : 'Save Driver';
    driverSubmit.disabled = true;
    driverUserSearch.value = '';
    if (mode === 'update') {
      try {
        const driver = await apiRequest(`/drivers/${encodeURIComponent(driverId)}`);
        populateDriverForm(driver);
        if (driver.user_id !== null && driver.user_id !== undefined) {
          currentDriverUser = { id: driver.user_id };
        }
      } catch (error) {
        setListMessage('drivers', errorMessage(error), true);
        driverSubmit.disabled = false;
        return;
      }
    } else {
      populateDriverForm();
    }
    showDialog(driverDialog);
    await loadDriverUsers();
    driverSubmit.disabled = false;
  };

  document.querySelector('#create-driver-button').addEventListener('click', () => openDriverDialog('create'));
  searchDriverUsersButton.addEventListener('click', loadDriverUsers);
  driverForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    setFormError(document.querySelector('#driver-form-error'), '');
    if (!driverForm.reportValidity()) return;
    const userId = driverForm.elements.user_id.value;
    const payload = {
      driver_code: driverForm.elements.driver_code.value.trim(),
      user_id: userId === '' ? null : Number(userId),
      first_name: driverForm.elements.first_name.value.trim(),
      last_name: driverForm.elements.last_name.value.trim(),
      phone: driverForm.elements.phone.value.trim() === '' ? null : driverForm.elements.phone.value.trim(),
      licence_number: driverForm.elements.licence_number.value.trim(),
      licence_expiry: driverForm.elements.licence_expiry.value,
      isactive: driverForm.elements.isactive.value,
    };
    driverSubmit.disabled = true;
    try {
      const isCreate = driverForm.dataset.mode === 'create';
      const path = isCreate ? '/drivers' : `/drivers/${encodeURIComponent(driverForm.dataset.driverId)}/update`;
      const result = await apiRequest(path, { method: 'POST', payload });
      closeDialog(driverDialog);
      driverForm.reset();
      const successMessage = `Driver ${result.driver_code ?? payload.driver_code} was ${isCreate ? 'created' : 'updated'} successfully.`;
      if (await loadList('drivers')) setListMessage('drivers', successMessage);
    } catch (error) {
      setFormError(document.querySelector('#driver-form-error'), errorMessage(error));
    } finally {
      driverSubmit.disabled = false;
    }
  });

  const deleteDriver = async (driver) => {
    if (!window.confirm(`Delete or deactivate Driver ${driver.driver_code}? The server may preserve Driver history by deactivating the profile. Continue?`)) return;
    const button = [...document.querySelectorAll('#drivers-table-body button')].find((item) => {
      return item.textContent === 'Delete / Deactivate'
        && item.closest('tr')?.firstElementChild?.textContent === String(driver.driver_code);
    });
    const actionRow = button?.closest('tr');
    const actionButtons = actionRow ? [...actionRow.querySelectorAll('button')] : [];
    for (const actionButton of actionButtons) actionButton.disabled = true;
    try {
      const result = await apiRequest(`/drivers/${encodeURIComponent(driver.id)}/delete`, { method: 'POST', payload: {} });
      if (result.deleted !== true) throw new Error('The server returned an unexpected delete response. Refresh and check the Driver state.');
      if (await loadList('drivers')) {
        setListMessage('drivers', `The API reported deleted: true for Driver ${driver.driver_code}. This does not confirm physical deletion; history may be preserved through server-side deactivation.`);
      }
    } catch (error) {
      setListMessage('drivers', errorMessage(error), true);
    } finally {
      if (actionRow?.isConnected) {
        for (const actionButton of actionButtons) actionButton.disabled = false;
      }
    }
  };

  const vehicleDialog = document.querySelector('#vehicle-dialog');
  const vehicleForm = document.querySelector('#vehicle-form');
  const vehicleSubmit = document.querySelector('#vehicle-submit-button');

  const populateVehicleForm = (vehicle = null) => {
    vehicleForm.elements.vehicle_code.value = vehicle?.vehicle_code ?? '';
    vehicleForm.elements.registration_number.value = vehicle?.registration_number ?? '';
    vehicleForm.elements.description.value = vehicle?.description ?? '';
    vehicleForm.elements.capacity_kg.value = vehicle?.capacity ?? '';
    vehicleForm.elements.isactive.value = vehicle?.is_active ?? 'T';
  };

  const openVehicleDialog = async (mode, vehicleId = null) => {
    setFormError(document.querySelector('#vehicle-form-error'), '');
    vehicleForm.reset();
    vehicleForm.dataset.mode = mode;
    vehicleForm.dataset.vehicleId = vehicleId === null ? '' : String(vehicleId);
    document.querySelector('#vehicle-dialog-title').textContent = mode === 'create' ? 'Create Vehicle' : 'Edit Vehicle';
    vehicleSubmit.textContent = mode === 'create' ? 'Create Vehicle' : 'Save Vehicle';
    vehicleSubmit.disabled = true;
    if (mode === 'update') {
      try {
        populateVehicleForm(await apiRequest(`/vehicles/${encodeURIComponent(vehicleId)}`));
      } catch (error) {
        setListMessage('vehicles', errorMessage(error), true);
        vehicleSubmit.disabled = false;
        return;
      }
    } else {
      populateVehicleForm();
    }
    showDialog(vehicleDialog);
    vehicleSubmit.disabled = false;
  };

  document.querySelector('#create-vehicle-button').addEventListener('click', () => openVehicleDialog('create'));
  vehicleForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    setFormError(document.querySelector('#vehicle-form-error'), '');
    if (!vehicleForm.reportValidity()) return;
    const payload = {
      vehicle_code: Number(vehicleForm.elements.vehicle_code.value),
      registration_number: vehicleForm.elements.registration_number.value.trim(),
      description: vehicleForm.elements.description.value.trim() || null,
      capacity: vehicleForm.elements.capacity_kg.value,
      is_active: vehicleForm.elements.isactive.value,
    };
    vehicleSubmit.disabled = true;
    try {
      const isCreate = vehicleForm.dataset.mode === 'create';
      const path = isCreate ? '/vehicles' : `/vehicles/${encodeURIComponent(vehicleForm.dataset.vehicleId)}/update`;
      const result = await apiRequest(path, { method: 'POST', payload });
      closeDialog(vehicleDialog);
      vehicleForm.reset();
      if (await loadList('vehicles')) {
        setListMessage('vehicles', `Vehicle ${result.vehicle_code ?? payload.vehicle_code} was ${isCreate ? 'created' : 'updated'} successfully.`);
      }
    } catch (error) {
      setFormError(document.querySelector('#vehicle-form-error'), errorMessage(error));
    } finally {
      vehicleSubmit.disabled = false;
    }
  });

  const deleteVehicle = async (vehicle) => {
    if (!window.confirm(`Delete Vehicle ${vehicle.vehicle_code} (${vehicle.registration_number})? Vehicles with Trip history must be deactivated from Edit instead.`)) return;
    const button = [...document.querySelectorAll('#vehicles-table-body button')].find((item) => {
      return item.textContent === 'Delete'
        && item.closest('tr')?.firstElementChild?.textContent === String(vehicle.vehicle_code);
    });
    const actionRow = button?.closest('tr');
    const actionButtons = actionRow ? [...actionRow.querySelectorAll('button')] : [];
    for (const actionButton of actionButtons) actionButton.disabled = true;
    try {
      const result = await apiRequest(`/vehicles/${encodeURIComponent(vehicle.id)}/delete`, { method: 'POST', payload: {} });
      if (result.deleted !== true && result.deactivated !== true) {
        throw new Error('The server returned an unexpected Vehicle delete response. Refresh and check its state.');
      }
      if (await loadList('vehicles')) {
        setListMessage('vehicles', `Vehicle ${vehicle.vehicle_code} was deleted.`);
      }
    } catch (error) {
      setListMessage('vehicles', errorMessage(error), true);
    } finally {
      if (actionRow?.isConnected) {
        for (const actionButton of actionButtons) actionButton.disabled = false;
      }
    }
  };

  const loadCapabilities = async () => {
    const result = await apiRequest('/capabilities');
    for (const name of ['trips', 'drivers', 'vehicles']) {
      for (const action of Object.keys(capabilities[name])) {
        if (typeof result?.[name]?.[action] !== 'boolean') {
          throw new Error('The server returned invalid Logistics permissions.');
        }
        capabilities[name][action] = result[name][action];
      }
    }
    if (typeof result.sales_orders_read !== 'boolean') throw new Error('The server returned invalid Sales Order permissions.');
    capabilities.sales_orders_read = result.sales_orders_read;
    const canReadTripResources = capabilities.vehicles.read && capabilities.drivers.read;
    capabilities.trips.create = capabilities.trips.create && capabilities.sales_orders_read && canReadTripResources;
    capabilities.trips.update = capabilities.trips.update && canReadTripResources;
    document.querySelector('#create-trip-button').hidden = !capabilities.trips.create;
    document.querySelector('#create-driver-button').hidden = !capabilities.drivers.create;
    document.querySelector('#create-vehicle-button').hidden = !capabilities.vehicles.create;
    for (const name of tabNames) {
      const allowed = capabilities[name].read;
      document.querySelector(`#${name}-tab`).hidden = !allowed;
      document.querySelector(`#${name}-panel`).hidden = !allowed;
    }
    if (!tabNames.some((name) => capabilities[name].read)) {
      document.querySelector('#logistics-capabilities-error').textContent = 'You do not have permission to view Logistics information.';
      document.querySelector('#logistics-capabilities-error').hidden = false;
      return false;
    }
    return true;
  };

  const initialLoad = async () => {
    try {
      const hasReadableModule = await loadCapabilities();
      if (hasReadableModule) {
        const firstReadable = tabNames.find((name) => capabilities[name].read);
        switchTab(firstReadable);
      }
    } catch (error) {
      const message = errorMessage(error);
      const errorElement = document.querySelector('#logistics-capabilities-error');
      errorElement.textContent = `Unable to load Logistics permissions. ${message}`;
      errorElement.hidden = false;
      if (capabilities.trips.read) await loadList('trips');
    }
  };
  initialLoad();
}
