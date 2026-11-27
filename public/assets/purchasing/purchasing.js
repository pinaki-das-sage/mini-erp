'use strict';

(() => {
  const root = document.querySelector('#purchasing');
  if (!root) return;
  const find = selector => root.querySelector(selector);
  const vendorForm = find('#purchasing-vendor');
  const docForm = find('#purchasing-document');
  const vendorUpdate = find('#purchasing-update');
  const state = { section: 'documents', records: [], selected: null, editing: null, source: null,
    receivingAvailable: false, permissions: new Set(), vendors: new Map(), products: new Map(), page: 1, version: 0, busy: false, dirty: false, nextLine: 1 };
  const names = { order: 'Purchase order', receiver: 'Receiver', invoice: 'Vendor invoice' };
  const can = operation => state.permissions.has(operation);
  const money = value => Number(value || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  const label = value => String(value ?? '').replaceAll('_', ' ').replace(/\b\w/g, char => char.toUpperCase());
  const url = path => document.body.dataset.basePath + '/api/purchasing/' + path;
  const node = (tag, text, className) => {
    const element = document.createElement(tag);
    if (text !== undefined) element.textContent = String(text);
    if (className) element.className = className;
    return element;
  };
  const message = (text, error = false) => {
    const target = find('#purchasing-status');
    target.textContent = text;
    target.classList.toggle('error', error);
  };
  const request = async (path, payload) => {
    let response;
    try {
      response = await fetch(url(path), {
        method: payload === undefined ? 'GET' : 'POST', credentials: 'same-origin', cache: 'no-store',
        headers: payload === undefined ? { Accept: 'application/json' }
          : { 'Content-Type': 'application/json', 'X-CSRF-Token': root.dataset.csrf, Accept: 'application/json' },
        body: payload === undefined ? undefined : JSON.stringify(payload),
      });
    } catch {
      throw new Error(payload === undefined ? 'Unable to load records. Check your connection.'
        : 'No confirmed response. Refresh and check the saved document before trying again.');
    }
    let body;
    try { body = await response.json(); }
    catch { throw new Error('The server returned an unreadable response. Refresh and check the saved state before retrying.'); }
    if (!response.ok || !body.success) {
      throw new Error((body.error?.message || 'Purchasing request failed.')
        + (body.error?.request_id ? ' Reference: ' + body.error.request_id : ''));
    }
    return body.result;
  };
  const cell = (row, value, tag = 'td') => {
    const element = node(tag, value ?? '—');
    if (tag === 'th') element.scope = 'col';
    row.append(element);
    return element;
  };
  const badge = value => node('span', label(value), 'purchase-state ' + (['draft', 'submitted', 'received', 'completed', 'pending'].includes(value) ? value : ''));
  const action = (text, handler, disabled = false) => {
    const button = node('button', text);
    button.type = 'button';
    button.disabled = disabled;
    if (disabled) button.title = handler ? 'Your account does not have permission for this action.' : 'This posting integration is not connected yet.';
    if (handler) button.addEventListener('click', handler);
    return button;
  };
  const vendorName = id => state.vendors.get(id)?.name || id || '—';
  const isDocument = () => state.section === 'documents';

  function close(force = false) {
    if (!force && state.dirty && !window.confirm('Discard unsaved draft changes?')) return false;
    vendorForm.hidden = docForm.hidden = find('#purchasing-detail').hidden = true;
    find('#purchasing-list').hidden = false;
    state.dirty = false;
    state.version++;
    return true;
  }

  function render() {
    const vendors = !isDocument();
    find('#purchasing-heading').textContent = vendors ? 'Vendors' : 'Documents';
    find('#purchasing-caption').textContent = vendors ? 'Vendor directory' : 'Purchasing documents';
    find('#purchasing-new').textContent = vendors ? 'New vendor' : 'New purchase order';
    find('#purchasing-new').disabled = !can('purchasing.' + (vendors ? 'vendor' : 'document') + ':create');
    find('#purchasing-type-label').hidden = find('#purchasing-state-label').hidden = vendors;
    find('#vendor-pagination').hidden = !vendors;
    const head = document.createElement('tr');
    for (const title of vendors ? ['Vendor code', 'Name', 'Status', 'Payment terms', 'Action']
      : ['Document', 'Type', 'Vendor', 'Date', 'State', 'Total', 'Action']) cell(head, title, 'th');
    find('#purchasing-columns').replaceChildren(head);
    const body = find('#purchasing-rows');
    body.replaceChildren();
    const type = find('#purchasing-type').value;
    const filterStatus = find('#purchasing-state').value;
    const search = find('#purchasing-search').value.trim().toLowerCase();
    const filtered = state.records.filter(record =>
      (vendors || !type || record.recordtype === type) && (vendors || !filterStatus || record.status === filterStatus)
      && [record.name, record.vendor_id, vendorName(record.vendor_id), record.docno, record.status].join(' ').toLowerCase().includes(search));
    for (const record of filtered) {
      const row = document.createElement('tr');
      if (vendors) {
        for (const value of [record.vendor_id, record.name, record.status, label(record.payment_terms)]) cell(row, value);
      } else {
        for (const value of [record.docno, names[record.recordtype] || record.recordtype, vendorName(record.vendor_id), record.doc_date?.slice(0, 10)]) cell(row, value);
        cell(row, '').append(badge(record.status));
        cell(row, money(record.total));
      }
      cell(row, '').append(action('View', () => showDetail(record.id)));
      body.append(row);
    }
    if (!filtered.length) {
      const row = document.createElement('tr');
      const text = can('purchasing.' + (vendors ? 'vendor' : 'document') + ':read')
        ? 'No matching records. Create a draft purchase order to get started.'
        : 'You do not have permission to view these records.';
      const empty = cell(row, vendors && text.startsWith('No matching') ? 'No matching vendors.' : text);
      empty.colSpan = vendors ? 5 : 7;
      empty.className = 'purchase-empty';
      body.append(row);
    }
    find('#vendors-page').textContent = 'Page ' + state.page + ' · Search applies to this page';
    find('#vendors-previous').disabled = state.page === 1 || state.busy;
    find('#vendors-next').disabled = state.records.length < 25 || state.busy;
  }

  async function load() {
    const version = ++state.version;
    message('Loading purchasing records…');
    try {
      const context = await request('context');
      if (version !== state.version) return false;
      state.permissions = new Set(context.permissions);
      state.receivingAvailable = context.receiving_available === true;
      const resource = state.section;
      if (!can('purchasing.' + (isDocument() ? 'document' : 'vendor') + ':read')) {
        state.records = [];
      } else {
        const records = await request(resource + (isDocument() ? '' : '?page=' + state.page + '&page_size=25'));
        if (version !== state.version) return false;
        state.records = records;
      }
      if (isDocument() && can('purchasing.document:read')) {
        try {
          const vendors = await request('references/vendors');
          if (version !== state.version) return false;
          vendors.forEach(vendor => state.vendors.set(vendor.id, vendor));
        } catch { /* IDs still identify vendors when reference lookup is unavailable. */ }
      }
      render();
      message('');
      return true;
    } catch (error) {
      if (version === state.version) {
        state.records = [];
        render();
        message(error.message, true);
      }
      return false;
    }
  }

  function detailField(term, value) {
    find('#purchasing-detail-fields').append(node('dt', term), node('dd', value ?? '—'));
  }

  function lineTable(entries) {
    const table = document.createElement('table');
    table.append(node('caption', 'Saved item lines'));
    const head = table.createTHead().insertRow();
    ['Line', 'Product', 'Description', 'Quantity', 'Unit cost', 'Amount', 'Notes'].forEach(text => cell(head, text, 'th'));
    const body = table.createTBody();
    for (const entry of entries) {
      const row = body.insertRow();
      for (const value of [entry.lineno, state.products.get(entry.product_id)?.name || entry.product_id,
        entry.description, entry.quantity, money(entry.unitcost), money(entry.lineamount), entry.notes]) cell(row, value);
    }
    return table;
  }

  async function showDetail(id) {
    const resource = state.section;
    let version = ++state.version;
    message('Loading record…');
    try {
      const record = await request(resource + '/' + encodeURIComponent(id));
      if (version !== state.version) return;
      close(true);
      version = state.version;
      state.selected = record;
      find('#purchasing-list').hidden = true;
      find('#purchasing-detail').hidden = false;
      find('#purchasing-detail-title').textContent = record.docno || record.name;
      const stateLabel = find('#purchasing-detail-state');
      stateLabel.replaceChildren(badge(record.status));
      find('#purchasing-detail-fields').replaceChildren();
      const actions = find('#purchasing-workflow-actions');
      actions.replaceChildren();
      find('#purchasing-integration-note').textContent = '';
      const lines = find('#purchasing-detail-lines');
      lines.replaceChildren();
      vendorUpdate.hidden = true;
      if (resource === 'vendors') {
        for (const key of ['vendor_id', 'name', 'contact', 'email', 'phone', 'addr_1', 'addr_2', 'city', 'state', 'postal_code', 'country', 'payment_terms']) detailField(label(key), record[key]);
        find('#purchasing-state-description').textContent = record.status === 'active'
          ? 'Active vendor: new purchase orders can be created.'
          : 'Inactive vendor: new orders are blocked. Existing invoices and unpaid amounts remain payable.';
        vendorUpdate.hidden = !can('purchasing.vendor:update');
        vendorUpdate.elements.status.value = record.status;
        vendorUpdate.elements.payment_terms.value = record.payment_terms;
      } else {
        for (const [term, value] of [
          ['Document type', names[record.recordtype]], ['Warehouse', 'W01'], ['Vendor', vendorName(record.vendor_id)],
          ['Document date', record.doc_date?.slice(0, 10)], ['Vendor reference', record.vendor_ref],
          ['Payment terms', label(record.payment_terms)], ['Delivery address', record.delivery_addr],
          ['Document notes', record.notes], ['Total', money(record.total)],
        ]) detailField(term, value);
        if (record.recordtype === 'invoice') {
          detailField('Due date', record.duedate);
          detailField('Amount due', record.status === 'draft' ? '0.00 (Draft, not posted)' : money(record.amountdue));
        }
        lines.append(lineTable(record.entries || []));
        const draft = record.status === 'draft';
        find('#purchasing-state-description').textContent = draft
          ? record.recordtype === 'order'
            ? 'Draft: edit purchase details and item lines. Submit only after all changes are saved.'
            : 'Draft: edit the date and document notes. Values copied from the source are read-only.'
          : 'This document is ' + label(record.status) + '. Its header and lines are read-only.';
        if (draft && record.recordtype !== 'order' && !record.source_document_id) {
          find('#purchasing-state-description').textContent = 'This is an older, unlinked Draft. A receiver created from a Submitted purchase order is required for goods receiving.';
        }
        if (draft && can('purchasing.document:update')) actions.append(action('Edit draft', () => openDocument({ record })));
        if (record.source_document_id) actions.append(action('View source document', () => showDetail(record.source_document_id)));
        const child = state.records.find(doc => doc.source_document_id === record.id);
        if (child) actions.append(action('View ' + (names[child.recordtype] || 'next document').toLowerCase(), () => showDetail(child.id)));
        if (record.recordtype === 'order' && draft) {
          actions.append(action('Submit order', () => submitOrder(record),
            !can('purchasing.document:update') || !can('purchasing.document:submit')));
        }
        if (record.recordtype === 'order' && record.status === 'submitted' && !child) {
          actions.append(action('Create draft receiver', () => openDocument({ type: 'receiver', source: record }),
            !can('purchasing.document:create') || !can('purchasing.document:create_receiver')));
        }
        if (record.recordtype === 'receiver' && draft) {
          const linked = Boolean(record.source_document_id);
          const allowed = linked && state.receivingAvailable && can('purchasing.document:update') && can('inventory.stock:receive');
          const receiveButton = action('Receive all items into W01', () => receiveAll(record), !allowed);
          if (!linked) receiveButton.title = 'Create a receiver from a Submitted purchase order first.';
          actions.append(receiveButton);
          find('#purchasing-integration-note').textContent = !linked
            ? 'This receiver has no linked purchase order. Open a Submitted purchase order, choose Create draft receiver, then receive the new document.'
            : !state.receivingAvailable ? 'Warehouse W01 only. Deploy the Inventory StockReceiptService before receiving goods.'
              : allowed ? 'Whole-unit quantities are received into W01. Stock, order and receiver are saved together, once.'
                : 'Receiving requires document update and Inventory stock receive permissions.';
        }
        if (record.recordtype === 'receiver' && record.status === 'received' && !child) {
          actions.append(action('Create draft invoice', () => openDocument({ type: 'invoice', source: record }),
            !can('purchasing.document:create') || !can('purchasing.document:create_invoice')));
        }
        if (record.recordtype === 'invoice' && draft) {
          actions.append(action('Post invoice', null, true));
          find('#purchasing-integration-note').textContent = 'Invoice posting is not connected yet. This draft adds nothing to the vendor amount due. Posting will be enabled when the vendor balance ledger is integrated.';
        }
        if (record.status === 'pending') find('#purchasing-integration-note').textContent = 'This record uses the previous Pending state. It remains read-only; no automatic state conversion is performed.';
      }
      message('');
    } catch (error) { if (version === state.version) message(error.message, true); }
  }

  async function saving(operation) {
    if (state.busy) return;
    state.busy = true;
    root.inert = true;
    const previous = [...root.querySelectorAll('button')].map(button => [button, button.disabled]);
    previous.forEach(([button]) => { button.disabled = true; });
    vendorForm.inert = docForm.inert = vendorUpdate.inert = true;
    message('Saving…');
    try { await operation(); }
    catch (error) { message(error.message, true); }
    finally {
      state.busy = false;
      root.inert = false;
      vendorForm.inert = docForm.inert = vendorUpdate.inert = false;
      previous.forEach(([button, disabled]) => { if (button.isConnected) button.disabled = disabled; });
      if (!find('#purchasing-list').hidden) render();
    }
  }

  async function submitOrder(record) {
    if (!window.confirm('Submit ' + record.docno + '? Quantities round to whole units and amounts are recalculated. Its header and lines will become read-only.')) return;
    await saving(async () => {
      await request('documents/' + encodeURIComponent(record.id), { status: 'submitted' });
      await load();
      await showDetail(record.id);
      message('Order submitted. Header and lines are read-only.');
    });
  }

  async function receiveAll(record) {
    await saving(async () => {
      const result = await request('documents/' + encodeURIComponent(record.id), { status: 'received' });
      await load();
      await showDetail(record.id);
      message(result.message || 'Goods received into W01.');
    });
  }

  function normalizeQuantity(input) {
    input.setCustomValidity('');
    if (input.value === '') return;
    const raw = Number(input.value);
    const rounded = Math.round(raw);
    if (!Number.isFinite(raw) || raw <= 0 || rounded < 1 || rounded > 9999999999) {
      input.setCustomValidity('Enter a quantity that rounds to between 1 and 9999999999 whole units.');
      return;
    }
    input.value = String(rounded);
  }

  function fillVendors(selected = '') {
    const select = docForm.elements.vendor_id;
    select.replaceChildren(new Option('Select an active vendor', ''));
    for (const vendor of state.vendors.values()) {
      const option = new Option(vendor.name + ' · ' + vendor.code + (vendor.status === 'inactive' ? ' (inactive)' : ''), vendor.id);
      option.disabled = vendor.status !== 'active' && vendor.id !== selected;
      select.append(option);
    }
    if (selected && !state.vendors.has(selected)) select.append(new Option('Vendor ' + selected, selected));
    select.value = selected;
  }

  function fillProducts(select, selected = '', description = '') {
    select.replaceChildren(new Option('Select an inventory product', ''));
    for (const product of state.products.values()) {
      const option = new Option(product.name + ' · ' + (product.sku || product.product_id) + (product.active ? '' : ' (inactive)'), product.product_id);
      select.append(option);
    }
    if (selected && !state.products.has(Number(selected))) select.append(new Option(description || 'Product ' + selected, selected));
    select.value = String(selected || '');
  }

  function total() {
    let amount = 0;
    const lines = [...find('#purchasing-lines').children];
    for (const line of lines) {
      const quantity = Math.round(Number(line.querySelector('[name="quantity"]').value));
      const cost = Number(line.querySelector('[name="unitcost"]').value);
      const value = Math.round((quantity * cost + Number.EPSILON) * 100) / 100;
      if (Number.isFinite(value)) amount += value;
      line.querySelector('[data-line-total]').textContent = 'Line amount: ' + money(Number.isFinite(value) ? value : 0);
      line.querySelector('[data-remove-line]').disabled = state.busy || lines.length === 1;
    }
    find('#purchasing-total').textContent = money(amount);
  }

  function addLine(entry = null, copied = false) {
    const fragment = find('#purchasing-line-template').content.cloneNode(true);
    const line = fragment.querySelector('fieldset');
    line.dataset.lineno = entry?.lineno || state.nextLine++;
    if (state.editing && entry?.id) line.dataset.id = entry.id;
    line.querySelector('legend').textContent = 'Line ' + line.dataset.lineno;
    const product = line.querySelector('[name="product_id"]');
    fillProducts(product, entry?.product_id, entry?.description);
    for (const key of ['description', 'quantity', 'unitcost', 'notes']) if (entry) line.querySelector('[name="' + key + '"]').value = entry[key] ?? '';
    if (copied) {
      line.querySelectorAll('input, select').forEach(input => { input.disabled = true; });
      line.querySelector('[data-remove-line]').hidden = true;
    }
    product.addEventListener('change', () => {
      const selected = state.products.get(Number(product.value));
      if (selected) line.querySelector('[name="description"]').value = selected.name.slice(0, 200);
    });
    find('#purchasing-lines').append(fragment);
    total();
  }

  function dueDate() {
    if (docForm.elements.recordtype.value !== 'invoice') { find('#purchasing-due-date').textContent = ''; return; }
    const value = docForm.elements.doc_date.value;
    if (!value) return;
    const date = new Date(value + 'T12:00:00Z');
    date.setUTCDate(date.getUTCDate() + ({ due_on_receipt: 0, net_15: 15, net_30: 30 }[docForm.elements.payment_terms.value] ?? 30));
    find('#purchasing-due-date').textContent = 'Invoice due date: ' + date.toISOString().slice(0, 10) + '. No vendor balance change while Draft.';
  }

  async function openDocument({ record = null, type = 'order', source = null } = {}) {
    const version = ++state.version;
    message('Loading vendor and inventory choices…');
    try {
      const [vendors, products] = await Promise.all([request('references/vendors'), request('references/products')]);
      if (version !== state.version) return;
      if (record && record.status !== 'draft') throw new Error('Only Draft documents can be edited.');
      if (!close()) return;
      vendors.forEach(vendor => state.vendors.set(vendor.id, vendor));
      products.forEach(product => state.products.set(product.product_id, product));
      state.editing = record;
      state.source = source;
      type = record?.recordtype || type;
      docForm.reset();
      docForm.querySelectorAll('input, select, textarea').forEach(input => { input.disabled = false; input.readOnly = false; });
      const fields = docForm.elements;
      fields.recordtype.value = type;
      const prefix = { order: 'PO-', receiver: 'RCV-', invoice: 'INV-' }[type];
      fields.docno.placeholder = prefix + '0001';
      fields.docno.pattern = prefix + '.+';
      fields.docno.value = record?.docno || '';
      fields.docno.readOnly = Boolean(record);
      const header = record || source;
      fillVendors(header?.vendor_id || '');
      for (const name of ['vendor_ref', 'delivery_addr', 'payment_terms']) fields[name].value = header?.[name] || (name === 'payment_terms' ? 'net_30' : '');
      fields.notes.value = record?.notes || '';
      const today = new Date();
      fields.doc_date.value = record?.doc_date?.slice(0, 10)
        || [today.getFullYear(), String(today.getMonth() + 1).padStart(2, '0'), String(today.getDate()).padStart(2, '0')].join('-');
      fields.doc_date.min = source?.doc_date?.slice(0, 10) || '';
      find('#purchasing-form-title').textContent = (record ? 'Edit draft ' : 'New draft ') + names[type].toLowerCase();
      find('#purchasing-date-label').textContent = ({ order: 'Order date *', receiver: 'Receipt date *', invoice: 'Invoice date *' })[type];
      find('#purchasing-document-save').textContent = record ? 'Save draft changes' : 'Create draft';
      const copied = type !== 'order';
      if (copied) ['vendor_id', 'vendor_ref', 'delivery_addr', 'payment_terms'].forEach(name => { fields[name].disabled = true; });
      const sourceText = find('#purchasing-source-summary');
      sourceText.hidden = !copied;
      sourceText.textContent = source ? 'Copying ' + source.docno + '. The server copies its saved vendor, terms and lines; those values cannot be overridden.'
        : 'Source-linked draft. Copied values are read-only; edit only the date and document notes.';
      find('#purchasing-reference-tools').hidden = copied;
      find('#purchasing-add-line').hidden = copied;
      find('#purchasing-lines-help').textContent = copied ? 'Copied item values are read-only.' : 'Edit items while Draft. Submitting makes the header and lines read-only.';
      find('#purchasing-lines').replaceChildren();
      const entries = header?.entries || [];
      state.nextLine = Math.max(0, ...entries.map(entry => Number(entry.lineno || 0))) + 1;
      entries.forEach(entry => addLine(entry, copied));
      if (!entries.length && !copied) addLine();
      total();
      dueDate();
      state.dirty = false;
      find('#purchasing-list').hidden = true;
      find('#purchasing-detail').hidden = true;
      docForm.hidden = false;
      message('');
      (record ? fields.doc_date : fields.docno).focus();
    } catch (error) { message(error.message, true); }
  }

  function documentPayload() {
    const record = state.editing;
    const type = docForm.elements.recordtype.value;
    const fields = docForm.elements;
    if (type !== 'order') {
      return record ? { doc_date: fields.doc_date.value, notes: fields.notes.value.trim() }
        : { docno: fields.docno.value.trim(), recordtype: type, source_document_id: state.source.id,
          doc_date: fields.doc_date.value, notes: fields.notes.value.trim() };
    }
    const payload = { vendor_id: fields.vendor_id.value, doc_date: fields.doc_date.value,
      vendor_ref: fields.vendor_ref.value.trim(), delivery_addr: fields.delivery_addr.value.trim(),
      payment_terms: fields.payment_terms.value, notes: fields.notes.value.trim() };
    if (!record) Object.assign(payload, { docno: fields.docno.value.trim(), recordtype: 'order' });
    const lines = [...find('#purchasing-lines').children];
    if (!lines.length) throw new Error('Add at least one line.');
    payload.entries = lines.map(line => {
      const value = name => line.querySelector('[name="' + name + '"]').value;
      return { ...(line.dataset.id ? { id: line.dataset.id } : {}), lineno: Number(line.dataset.lineno),
        product_id: Number(value('product_id')), description: value('description').trim(),
        quantity: Math.round(Number(value('quantity'))), unitcost: Number(value('unitcost')), notes: value('notes').trim() };
    });
    if (record) {
      const savedIds = lines.map(line => line.dataset.id).filter(Boolean);
      const removed = record.entries.filter(entry => !savedIds.includes(entry.id)).map(entry => entry.id);
      if (removed.length) payload.remove_entry_ids = removed;
    }
    return payload;
  }

  docForm.addEventListener('submit', event => {
    event.preventDefault();
    let payload;
    try { payload = documentPayload(); } catch (error) { message(error.message, true); return; }
    const recordId = state.editing?.id;
    saving(async () => {
      const saved = await request('documents' + (recordId ? '/' + encodeURIComponent(recordId) : ''), payload);
      close(true);
      await load();
      await showDetail(saved.id || recordId);
      message('Draft saved. No stock or vendor balance has been posted.');
    });
  });

  vendorForm.addEventListener('submit', event => {
    event.preventDefault();
    const payload = {};
    for (const element of vendorForm.elements) if (element.name && element.value.trim()) payload[element.name] = element.value.trim();
    saving(async () => {
      await request('vendors', payload);
      close(true); await load(); message('Vendor created.');
    });
  });

  vendorUpdate.addEventListener('submit', event => {
    event.preventDefault();
    const id = state.selected.id;
    const payload = { status: vendorUpdate.elements.status.value, payment_terms: vendorUpdate.elements.payment_terms.value };
    saving(async () => { await request('vendors/' + encodeURIComponent(id), payload); await load(); await showDetail(id); message('Vendor settings saved.'); });
  });

  root.querySelectorAll('[data-section]').forEach(button => button.addEventListener('click', () => {
    if (state.busy || !close()) return;
    state.section = button.dataset.section; state.page = 1; state.records = [];
    find('#purchasing-search').value = '';
    root.querySelectorAll('[data-section]').forEach(item => item.setAttribute('aria-pressed', String(item === button)));
    render(); load();
  }));
  root.querySelectorAll('[data-close]').forEach(button => button.addEventListener('click', () => { if (!state.busy) close(); }));
  find('#purchasing-new').addEventListener('click', () => {
    if (isDocument()) { openDocument(); return; }
    if (!close()) return;
    vendorForm.reset(); find('#purchasing-list').hidden = true; vendorForm.hidden = false; state.dirty = false; message('');
    vendorForm.querySelector('input').focus();
  });
  find('#purchasing-refresh').addEventListener('click', load);
  for (const id of ['purchasing-search', 'purchasing-type', 'purchasing-state']) find('#' + id).addEventListener('input', render);
  find('#vendors-previous').addEventListener('click', () => { state.page--; load(); });
  find('#vendors-next').addEventListener('click', () => { state.page++; load(); });
  [docForm, vendorForm].forEach(form => form.addEventListener('input', () => { state.dirty = true; }));
  docForm.addEventListener('input', () => { total(); dueDate(); });
  docForm.addEventListener('change', event => {
    if (event.target.name === 'quantity') { normalizeQuantity(event.target); total(); }
    dueDate();
  });
  docForm.addEventListener('input', event => {
    if (event.target.name === 'quantity') event.target.setCustomValidity('');
  });
  docForm.elements.vendor_id.addEventListener('change', () => {
    const vendor = state.vendors.get(docForm.elements.vendor_id.value);
    if (vendor) { docForm.elements.payment_terms.value = vendor.payment_terms; dueDate(); }
  });
  find('#purchasing-add-line').addEventListener('click', () => { addLine(); state.dirty = true; });
  docForm.addEventListener('click', event => {
    if (event.target.matches('[data-remove-line]') && find('#purchasing-lines').children.length > 1) {
      event.target.closest('.purchasing-line').remove(); total(); state.dirty = true;
    }
  });
  find('#purchasing-find-references').addEventListener('click', async () => {
    const version = state.version;
    const search = encodeURIComponent(find('#purchasing-reference-search').value.trim());
    const button = find('#purchasing-find-references');
    button.disabled = true;
    try {
      const [vendors, products] = await Promise.all([request('references/vendors?search=' + search), request('references/products?search=' + search)]);
      if (version !== state.version) return;
      vendors.forEach(vendor => state.vendors.set(vendor.id, vendor));
      products.forEach(product => state.products.set(product.product_id, product));
      fillVendors(docForm.elements.vendor_id.value);
      find('#purchasing-lines').querySelectorAll('[name="product_id"]').forEach(select => fillProducts(select, select.value));
      find('#purchasing-reference-status').textContent = vendors.length + ' vendors and ' + products.length + ' products found (up to 200 each).';
    } catch (error) { message(error.message, true); }
    finally { button.disabled = false; }
  });
  find('#purchasing-reference-search').addEventListener('keydown', event => {
    if (event.key === 'Enter') { event.preventDefault(); find('#purchasing-find-references').click(); }
  });
  load();
})();
