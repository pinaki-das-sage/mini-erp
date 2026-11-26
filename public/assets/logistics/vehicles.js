// vehicles: table rendering, dialogs and feature-specific interactions.
export function initVehicles(context) {
  const {
    apiRequest,
    openRecord,
    capabilities,
    getField,
    textCell,
    activeCell,
    formatKilograms,
    errorMessage,
    setFormError,
    setListMessage,
    makeButton,
    showDialog,
    closeDialog,
    loadList,
    logisticsState
  } = context;
  const renderVehicles = (items) => {
    const body = document.querySelector('#vehicles-table-body');
    body.replaceChildren();
    for (const vehicle of items) {
      const row = document.createElement('tr');
      textCell(row, getField(vehicle, 'VEHICLE_CODE', 'vehicle_code'));
      textCell(row, getField(vehicle, 'REGISTRATION_NUMBER', 'registration_number'));
      textCell(row, getField(vehicle, 'DESCRIPTION', 'description'));
      textCell(row, formatKilograms(getField(vehicle, 'CAPACITY', 'capacity')));
      activeCell(row, getField(vehicle, 'is_active', 'ISACTIVE', 'active'));
      textCell(row, { ASSIGNED: 'Assigned', AVAILABLE: 'Available' }[vehicle.assignment_status] || '-');
      const actionsCell = document.createElement('td');
      actionsCell.className = 'logistics-actions';
      actionsCell.append(makeButton('View', 'button-secondary', () => openRecord('vehicles', vehicle.id)));
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


  const vehicleDialog = document.querySelector('#vehicle-dialog');
  const vehicleForm = document.querySelector('#vehicle-form');

  const vehicleSubmit = document.querySelector('#vehicle-submit-button');
  const description = vehicleForm.elements.description;
  const updateDescriptionCount = () => { document.querySelector('#vehicle-description-count').textContent = (255 - description.value.length) + ' characters remaining'; };
  description.addEventListener('input', updateDescriptionCount);
  const filterForm = document.querySelector('#vehicles-filter');
  const applyFilters = () => {
    if (logisticsState.vehicles.loading) return;
    const filters = {};
    for (const [key, value] of new FormData(filterForm)) if (value.trim()) filters[key] = value.trim();
    logisticsState.vehicles.filters = filters; logisticsState.vehicles.page = 1; loadList('vehicles');
  };
  filterForm.addEventListener('submit', event => { event.preventDefault(); applyFilters(); });
  document.querySelector('#vehicles-clear-filter').addEventListener('click', () => { if (!logisticsState.vehicles.loading) { filterForm.reset(); applyFilters(); } });


  const populateVehicleForm = (vehicle = null) => {
    vehicleForm.elements.vehicle_code.value = vehicle?.vehicle_code ?? '';
    vehicleForm.elements.registration_number.value = vehicle?.registration_number ?? '';
    vehicleForm.elements.description.value = vehicle?.description ?? '';
    vehicleForm.elements.capacity_kg.value = vehicle?.capacity ?? '';
    vehicleForm.elements.isactive.value = vehicle?.is_active ?? 'T';
    updateDescriptionCount();
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


  return { renderVehicles };
}
