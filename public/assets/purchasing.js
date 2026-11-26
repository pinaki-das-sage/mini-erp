'use strict';

(() => {
  const root = document.querySelector('#purchasing');
  if (!root) return;
  const find = (selector) => root.querySelector(selector);
  const status = find('#purchasing-status');
  const vendorForm = find('#purchasing-vendor');
  const documentForm = find('#purchasing-document');
  const updateForm = find('#purchasing-update');
  let section = 'documents';
  let records = [];
  let page = 1;
  let selected = null;
  let loadVersion = 0;
  let saving = false;
  const label = (value) => String(value ?? '').replaceAll('_', ' ');
  const message = (text, error = false) => {
    status.textContent = text;
    status.classList.toggle('error', error);
  };
  const request = async (resource, payload) => {
    const response = await fetch(appUrl('/api/purchasing/' + resource), {
      method: payload === undefined ? 'GET' : 'POST',
      headers: payload === undefined ? {} : { 'Content-Type': 'application/json', 'X-CSRF-Token': root.dataset.csrf },
      body: payload === undefined ? undefined : JSON.stringify(payload),
    });
    const body = await response.json();
    if (!response.ok || !body.success) throw new Error(body.error?.message || 'Purchasing request failed.');
    return body.result;
  };
  const cell = (row, value, tag = 'td') => {
    const element = document.createElement(tag);
    element.textContent = String(value ?? '?');
    row.append(element);
    return element;
  };
  const close = () => {
    vendorForm.hidden = documentForm.hidden = find('#purchasing-detail').hidden = true;
    find('#purchasing-list').hidden = false;
  };
  const render = () => {
    const vendors = section === 'vendors';
    const columns = find('#purchasing-columns');
    columns.replaceChildren();
    const header = document.createElement('tr');
    for (const title of vendors ? ['Vendor code', 'Name', 'Status', 'Payment terms', 'Action'] : ['Document', 'Type', 'Date', 'Status', 'Total', 'Action']) cell(header, title, 'th').scope = 'col';
    columns.append(header);
    const rows = find('#purchasing-rows');
    rows.replaceChildren();
    const search = find('#purchasing-search').value.toLowerCase();
    const type = find('#purchasing-type').value;
    const filtered = records.filter((record) => (!type || vendors || record.recordtype === type)
      && [record.vendor_id, record.name, record.docno, record.status].join(' ').toLowerCase().includes(search));
    for (const record of filtered) {
      const row = document.createElement('tr');
      for (const value of vendors
        ? [record.vendor_id, record.name, record.status, label(record.payment_terms)]
        : [record.docno, record.recordtype, record.doc_date?.slice(0, 10), record.status, Number(record.total ?? 0).toFixed(2)]) cell(row, value);
      const action = cell(row, '');
      const button = document.createElement('button');
      button.type = 'button';
      button.textContent = 'View';
      button.setAttribute('aria-label', 'View ' + (record.docno || record.name));
      button.addEventListener('click', () => showDetail(record.id));
      action.append(button);
      rows.append(row);
    }
    if (!filtered.length) cell(rows.appendChild(document.createElement('tr')), 'No matching records.').colSpan = vendors ? 5 : 6;
  };
  const load = async () => {
    const version = ++loadVersion;
    const resource = section;
    records = [];
    render();
    message('Loading ' + resource + '...');
    find('#vendors-previous').disabled = true;
    find('#vendors-next').disabled = true;
    try {
      const result = await request(resource + (resource === 'vendors' ? '?page=' + page + '&page_size=25' : ''));
      if (version !== loadVersion) return;
      records = result;
      render();
      find('#vendors-page').textContent = 'Page ' + page + ' ? Search applies to this page';
      find('#vendors-previous').disabled = page === 1;
      find('#vendors-next').disabled = records.length < 25;
      message(records.length + ' ' + resource + ' loaded.');
      return true;
    } catch (error) {
      if (version === loadVersion) message(error.message, true);
      return false;
    }
  };
  async function showDetail(id) {
    const resource = section;
    const version = ++loadVersion;
    message('Loading record...');
    try {
      const record = await request(resource + '/' + encodeURIComponent(id));
      if (version !== loadVersion) return;
      selected = record;
      close();
      find('#purchasing-list').hidden = true;
      find('#purchasing-detail').hidden = false;
      const fields = find('#purchasing-detail-fields');
      fields.replaceChildren();
      for (const [key, value] of Object.entries(record)) {
        if (key === 'entries') continue;
        const term = document.createElement('dt');
        term.textContent = label(key);
        const description = document.createElement('dd');
        description.textContent = value ?? '?';
        fields.append(term, description);
      }
      const lines = find('#purchasing-detail-lines');
      lines.replaceChildren();
      if (record.entries) {
        const table = document.createElement('table');
        const caption = document.createElement('caption');
        caption.textContent = 'Document lines';
        table.append(caption);
        const head = table.createTHead().insertRow();
        for (const title of ['Line', 'Product', 'Description', 'Quantity', 'Unit cost', 'Amount', 'Notes']) cell(head, title, 'th').scope = 'col';
        const body = table.createTBody();
        for (const entry of record.entries) {
          const row = body.insertRow();
          for (const key of ['lineno', 'product_id', 'description', 'quantity', 'unitcost', 'lineamount', 'notes']) cell(row, entry[key]);
        }
        lines.append(table);
      }
      const choices = resource === 'vendors' ? ['active', 'inactive'] : ['draft', 'pending', 'received', 'submitted', 'completed'];
      updateForm.elements.status.replaceChildren(...choices.map((value) => new Option(label(value), value)));
      updateForm.elements.status.value = record.status;
      updateForm.elements.payment_terms.value = record.payment_terms;
      message('Record loaded.');
    } catch (error) {
      if (version === loadVersion) message(error.message, true);
    }
  }
  const total = () => {
    let amount = 0;
    const lines = [...find('#purchasing-lines').children];
    lines.forEach((line, index) => {
      line.querySelector('legend').textContent = 'Line ' + (index + 1);
      line.querySelector('[data-remove-line]').disabled = lines.length === 1;
      const inputs = line.querySelectorAll('input');
      const values = Object.fromEntries([...inputs].map((input) => [input.name, input.value]));
      amount += Math.round((Number(values.quantity) * Number(values.unitcost) + Number.EPSILON) * 100) / 100;
    });
    find('#purchasing-total').textContent = amount.toFixed(2);
  };
  const addLine = () => {
    find('#purchasing-lines').append(find('#purchasing-line-template').content.cloneNode(true));
    total();
  };
  find('#purchasing-add-line').addEventListener('click', addLine);
  documentForm.addEventListener('input', total);
  documentForm.addEventListener('click', (event) => {
    if (event.target.matches('[data-remove-line]') && find('#purchasing-lines').children.length > 1) {
      event.target.closest('fieldset').remove();
      total();
    }
  });
  const documentType = () => {
    const prefix = { order: 'PO-', receiver: 'RCV-', invoice: 'INV-' }[documentForm.elements.recordtype.value];
    documentForm.elements.docno.placeholder = prefix + '0001';
    documentForm.elements.docno.pattern = prefix + '.+';
  };
  documentForm.elements.recordtype.addEventListener('change', documentType);
  for (const button of root.querySelectorAll('[data-section]')) {
    button.addEventListener('click', () => {
      if (saving) return;
      section = button.dataset.section;
      page = 1;
      close();
      find('#purchasing-search').value = '';
      for (const item of root.querySelectorAll('[data-section]')) item.setAttribute('aria-pressed', String(item === button));
      find('#purchasing-heading').textContent = section === 'vendors' ? 'Vendors' : 'Documents';
      find('#purchasing-caption').textContent = section === 'vendors' ? 'Vendor directory' : 'Purchasing documents';
      find('#purchasing-new').textContent = section === 'vendors' ? 'New vendor' : 'New document';
      find('#purchasing-type-label').hidden = section === 'vendors';
      find('#vendor-pagination').hidden = section !== 'vendors';
      load();
    });
  }
  for (const button of root.querySelectorAll('[data-close]')) button.addEventListener('click', () => {
    if (!saving) { ++loadVersion; close(); }
  });
  find('#purchasing-new').addEventListener('click', () => {
    ++loadVersion;
    close();
    find('#purchasing-list').hidden = true;
    const form = section === 'vendors' ? vendorForm : documentForm;
    form.reset();
    form.hidden = false;
    if (form === documentForm) {
      find('#purchasing-lines').replaceChildren();
      addLine();
      documentType();
      const date = new Date();
      documentForm.elements.doc_date.value = [date.getFullYear(), String(date.getMonth() + 1).padStart(2, '0'), String(date.getDate()).padStart(2, '0')].join('-');
    }
    message('');
    form.querySelector('input, select').focus();
  });
  find('#purchasing-refresh').addEventListener('click', load);
  find('#purchasing-search').addEventListener('input', render);
  find('#purchasing-type').addEventListener('change', render);
  find('#vendors-previous').addEventListener('click', () => { if (page > 1) { page--; load(); } });
  find('#vendors-next').addEventListener('click', () => { page++; load(); });
  for (const form of [vendorForm, documentForm, updateForm]) {
    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      if (saving) return;
      const resource = section;
      const payload = {};
      for (const element of form.elements) {
        if (element.name && element.value.trim() !== '' && !element.closest('.purchasing-line')) payload[element.name] = element.value.trim();
      }
      if (form === documentForm) {
        payload.entries = [...find('#purchasing-lines').children].map((line, index) => {
          const entry = { lineno: index + 1 };
          for (const input of line.querySelectorAll('input')) {
            if (input.value.trim() !== '') entry[input.name] = ['product_id', 'quantity', 'unitcost'].includes(input.name) ? Number(input.value) : input.value.trim();
          }
          return entry;
        });
      }
      saving = true;
      const buttons = [...root.querySelectorAll('button')].map((button) => [button, button.disabled]);
      for (const [button] of buttons) button.disabled = true;
      message('Saving...');
      try {
        const saved = await request(resource + (form === updateForm ? '/' + encodeURIComponent(selected.id) : ''), payload);
        close();
        const refreshed = await load();
        message('Saved successfully. Record ID: ' + saved.id
          + (refreshed ? '' : '. The list could not refresh. Use Refresh to load the latest records.'), !refreshed);
      } catch (error) {
        message(error instanceof SyntaxError || error instanceof TypeError
          ? 'No confirmed response. Refresh and check the saved records before retrying.'
          : error.message, true);
      } finally {
        saving = false;
        for (const [button, disabled] of buttons) if (button.isConnected) button.disabled = disabled;
        find('#vendors-previous').disabled = page === 1;
        find('#vendors-next').disabled = records.length < 25;
      }
    });
  }
  load();
})();
