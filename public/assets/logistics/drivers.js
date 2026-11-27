// drivers: table rendering, dialogs and feature-specific interactions.
export function initDrivers(context) {
  const {
    apiRequest,
    openRecord,
    capabilities,
    getField,
    textCell,
    stackedCell,
    activeCell,
    errorMessage,
    setFormError,
    setListMessage,
    makeButton,
    showDialog,
    closeDialog,
    loadList
  } = context;
  let currentDriverUser = null;
  const renderDrivers = (items) => {
    const body = document.querySelector('#drivers-table-body');
    body.replaceChildren();
    for (const driver of items) {
      const row = document.createElement('tr');
      textCell(row, getField(driver, 'driver_code'));
      textCell(row, [driver.first_name, driver.last_name].filter(Boolean).join(' '));
      const userId = getField(driver, 'user_id');
      textCell(row, userId === null ? 'Not linked' : `User #${userId}`);
      textCell(row, getField(driver, 'phone'));
      stackedCell(row, driver.licence_number, driver.licence_expiry ? `Expires ${driver.licence_expiry}` : 'Expiry not recorded');
      activeCell(row, getField(driver, 'isactive'));
      textCell(row, { ASSIGNED: 'Assigned', AVAILABLE: 'Available' }[driver.assignment_status] || '-');
      const actionsCell = document.createElement('td');
      actionsCell.className = 'logistics-actions';
      actionsCell.append(makeButton('View', 'button-secondary', () => openRecord('drivers', driver.id)));
      if (capabilities.drivers.update) {
        actionsCell.append(makeButton('Edit', 'button-secondary', () => openDriverDialog('update', driver.id)));
      }
      if (capabilities.drivers.delete) {
        actionsCell.append(makeButton('Delete', 'button-danger', () => deleteDriver(driver)));
      }
      if (actionsCell.children.length === 0) actionsCell.textContent = '-';
      row.append(actionsCell);
      body.append(row);
    }
  };


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
    // Keep the numeric current-user option present even while the eligible-user request runs.
    showDriverUserFallback();
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
    if (!window.confirm(`Delete Driver ${driver.driver_code}? The server may preserve Driver history by deactivating the profile. Continue?`)) return;
    const button = [...document.querySelectorAll('#drivers-table-body button')].find((item) => {
      return item.textContent === 'Delete'
        && item.closest('tr')?.firstElementChild?.textContent === String(driver.driver_code);
    });
    const actionRow = button?.closest('tr');
    const actionButtons = actionRow ? [...actionRow.querySelectorAll('button')] : [];
    for (const actionButton of actionButtons) actionButton.disabled = true;
    try {
      // DriverService returns an empty result after successful delete/deactivation.
      await apiRequest(`/drivers/${encodeURIComponent(driver.id)}/delete`, { method: 'POST', payload: {} });
      if (await loadList('drivers')) {
        setListMessage('drivers', `Driver ${driver.driver_code} removal completed. Profiles with trip history are deactivated.`);
      }
    } catch (error) {
      setListMessage('drivers', errorMessage(error), true);
    } finally {
      if (actionRow?.isConnected) {
        for (const actionButton of actionButtons) actionButton.disabled = false;
      }
    }
  };


  return { renderDrivers };
}
