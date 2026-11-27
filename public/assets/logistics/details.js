// Read-only fleet details use the existing company-scoped GET endpoints.
export function appendDetails(parent, title, fields) {
  const section = document.createElement('section');
  section.className = 'logistics-detail-section';
  const heading = document.createElement('h3'); heading.textContent = title;
  const list = document.createElement('dl'); list.className = 'logistics-detail-grid';
  for (const [label, value] of fields) {
    const group = document.createElement('div'); const term = document.createElement('dt'); const definition = document.createElement('dd');
    term.textContent = label; definition.textContent = value === null || value === undefined || value === '' ? '-' : value;
    group.append(term, definition); list.append(group);
  }
  section.append(heading, list); parent.append(section);
}
export function initRecordDetails({ apiRequest, formatKilograms, errorMessage, setFormError, showDialog }) {
  const dialog = document.querySelector('#logistics-record-dialog');
  let sequence = 0;
  return async (resource, id) => {
    const request = ++sequence;
    const content = document.querySelector('#logistics-record-content');
    const status = document.querySelector('#logistics-record-status');
    const error = document.querySelector('#logistics-record-error');
    document.querySelector('#logistics-record-title').textContent = resource === 'vehicles' ? 'Vehicle details' : 'Driver details';
    content.replaceChildren(); setFormError(error, ''); status.textContent = 'Loading record...'; showDialog(dialog);
    try {
      const record = await apiRequest('/' + resource + '/' + encodeURIComponent(id));
      if (!dialog.open || request !== sequence) return;
      if (!record || typeof record !== 'object' || record.id == null) throw new Error('The server returned unexpected record details.');
      const active = (resource === 'vehicles' ? record.is_active : record.isactive) === 'T' ? 'Active' : 'Inactive';
      appendDetails(content, 'Record details', resource === 'vehicles' ? [
        ['Vehicle ID', record.id], ['Vehicle code', record.vehicle_code], ['Registration', record.registration_number],
        ['Capacity (KG)', formatKilograms(record.capacity)], ['Description', record.description], ['Status', active],
        ['Availability', {AVAILABLE:'Available',ASSIGNED:'Assigned'}[record.assignment_status] || 'Not supplied']
      ] : [
        ['Driver ID', record.id], ['Driver code', record.driver_code], ['First name', record.first_name], ['Last name', record.last_name],
        ['Linked user ID', record.user_id], ['Phone', record.phone], ['Licence number', record.licence_number], ['Licence expiry', record.licence_expiry], ['Status', active],
        ['Assignment', {AVAILABLE:'Available',ASSIGNED:'Assigned'}[record.assignment_status] || 'Not supplied']
      ]);
    } catch (failure) {
      if (request === sequence && dialog.open) setFormError(error, errorMessage(failure));
    } finally { if (request === sequence) status.textContent = ''; }
  };
}
