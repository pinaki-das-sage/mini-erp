import { initRecordDetails } from './details.js';
// Logistics entry point: shared state, permissions, tabs, pagination and requests.
import { initTrips } from './trips.js';
import { initDrivers } from './drivers.js';
import { initVehicles } from './vehicles.js';
import { initTripLines } from './trip-lines.js';

const appUrl = (path) => `${document.body.dataset.basePath}${path}`;

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
    'trip-lines': { read: false },
    sales_orders_read: false,
  };
  logisticsState['trip-lines'] = { page: 1, pageSize: 25, loading: false, loaded: false, tripId: '', itemCount: 0 };
  const tabNames = ['trips', 'drivers', 'vehicles', 'trip-lines'];
  let focusBeforeDialog = null;

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
      if (error.status === 400) return error.message;
      if (error.status === 401 || ['UNAUTHENTICATED', 'SESSION_EXPIRED'].includes(error.code)) {
        return `Your session is no longer authorized. Sign in again. ${error.message}`;
      }
      if (error.code === 'PASSWORD_CHANGE_REQUIRED') return error.message;
      if (error.status === 403) return error.message;
      if (error.status === 404) return error.message;
      if (error.status === 409) return error.message;
      if (error.status === 503) return error.message;
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

  // Keep API values as text, including nested product and sales details.
  const stackedCell = (row, primary, secondary) => {
    const cell = textCell(row, primary);
    if (secondary) {
      const note = document.createElement('small');
      note.className = 'logistics-cell-note';
      note.textContent = secondary;
      cell.append(note);
    }
    return cell;
  };
  const activeCell = (row, value) => {
    const label = activeLabel(value); const badge = document.createElement('span');
    badge.className = 'logistics-badge ' + (label === 'Active' ? 'logistics-badge-delivered' : 'logistics-badge-unknown');
    badge.textContent = label; textCell(row, '').replaceChildren(badge);
  };
  const loadList = async (name) => {
    const state = logisticsState[name];
    const label = name.replaceAll('-', ' ');
    if (state.loading) return;
    if (!capabilities[name].read) {
      setListMessage(name, `You do not have permission to read ${name}.`, true);
      return false;
    }
    state.loading = true;
    setListMessage(name, `Loading ${label}...`);
    document.querySelector(`#${name}-table-body`).setAttribute('aria-busy', 'true');
    document.querySelector(`#${name}-refresh-button`)?.setAttribute('disabled', '');
    updatePagination(name, state.page, state.pageSize, 0);
    try {
      const filters = new URLSearchParams(state.filters || {});
      if (name === 'trip-lines' && state.tripId) filters.set('trip_id', state.tripId);
      const filter = filters.size ? '&' + filters.toString() : '';
      const result = await apiRequest(`/${name}?page=${state.page}&page_size=${state.pageSize}${filter}`);
      if (!result || !Array.isArray(result.items) || !Number.isInteger(result.page) || !Number.isInteger(result.page_size)) {
        throw new Error('The server returned an unexpected list response. Refresh and try again.');
      }
      const render = { trips: renderTrips, drivers: renderDrivers, vehicles: renderVehicles, 'trip-lines': renderTripLines }[name];
      render(result.items);
      state.loaded = true;
      state.itemCount = result.items.length;
      if (!state.itemCount) {
        const row = document.createElement('tr'); const cell = textCell(row, 'No records found. Try another page or filter.');
        cell.colSpan = { trips: 10, drivers: 7, vehicles: 7, 'trip-lines': 8 }[name]; cell.className = 'logistics-empty';
        document.querySelector('#' + name + '-table-body').append(row);
      }
      updatePagination(name, state.page, state.pageSize, result.items.length);
      setListMessage(name, result.items.length === 0
        ? (state.page === 1 ? `No ${label} found.` : `No additional ${label} on this page.`)
        : `Loaded ${result.items.length} ${label}.`);
      return true;
    } catch (error) {
      // Do not leave results from a different page/filter visible after a failed request.
      state.itemCount = 0;
      state.loaded = false;
      document.querySelector(`#${name}-table-body`).replaceChildren();
      setListMessage(name, errorMessage(error), true);
      return false;
    } finally {
      state.loading = false;
      document.querySelector(`#${name}-table-body`).setAttribute('aria-busy', 'false');
      document.querySelector(`#${name}-refresh-button`)?.removeAttribute('disabled');
      updatePagination(name, state.page, state.pageSize, state.itemCount || 0);
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
      const readableTabs = tabNames.filter((tab) => capabilities[tab].read);
      if (!readableTabs.length) return;
      const currentIndex = readableTabs.indexOf(name);
      let nextIndex = currentIndex;
      if (event.key === 'ArrowRight') nextIndex = (currentIndex + 1) % readableTabs.length;
      else if (event.key === 'ArrowLeft') nextIndex = (currentIndex + readableTabs.length - 1) % readableTabs.length;
      else if (event.key === 'Home') nextIndex = 0;
      else if (event.key === 'End') nextIndex = readableTabs.length - 1;
      else return;
      event.preventDefault();
      const nextName = readableTabs[nextIndex];
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
    if (dialog.classList.contains('logistics-fleet-editor')) {
      if (!dialog.open) dialog.show();
      dialog.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    } else dialog.showModal();
    dialog.querySelector('input:not([type="hidden"]):not(:disabled), select:not(:disabled), textarea:not(:disabled)')?.focus();
  };

  // Features share requests and UI helpers; each owns its own forms and table.
  const context = {
    apiRequest,
    capabilities,
    getField,
    textCell,
    stackedCell,
    activeCell,
    formatKilograms,
    formatDeparture,
    toLocalDateTimeInput,
    errorMessage,
    setFormError,
    setListMessage,
    makeButton,
    showDialog,
    closeDialog,
    loadList,
    logisticsState
  };
  context.openRecord = initRecordDetails(context);
  const { renderTrips } = initTrips(context);
  const { renderDrivers } = initDrivers(context);
  const { renderVehicles } = initVehicles(context);
  const { renderTripLines } = initTripLines(context);

  const loadCapabilities = async () => {
    const result = await apiRequest('/capabilities');
    for (const name of tabNames) {
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
    // Resource read grants are needed for the edit pickers, not dispatch/cancel/delivery.
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
