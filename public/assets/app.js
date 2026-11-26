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
