const fs = require('fs');
const crypto = require('crypto');
const { yaml } = require('../node_modules/playwright-core/lib/utilsBundle.js');
const raw = fs.readFileSync('public/openapi.yaml', 'utf8');
const parsed = yaml.parseDocument(raw, { uniqueKeys: true });
if (parsed.errors.length) throw new Error(parsed.errors.join('\n'));
const api = parsed.toJS();
const hash = crypto.createHash('sha256').update(raw).digest('hex');
const ref = s => s.split('/').slice(1).reduce((a, k) => a[k.replace(/~1/g, '/').replace(/~0/g, '~')], api);
function resolve(s) {
  if (!s || typeof s !== 'object') return s || {};
  return s.$ref ? { ...resolve(ref(s.$ref)), ...Object.fromEntries(Object.entries(s).filter(([k]) => k !== '$ref')) } : s;
}
function flat(s) {
  s = resolve(s);
  const out = { ...s, properties: {}, required: [] };
  for (const part of [...(s.allOf || []), { properties: s.properties, required: s.required }]) {
    const p = part.allOf || part.$ref ? flat(part) : part;
    for (const [name, value] of Object.entries(p.properties || {})) out.properties[name] = { ...out.properties[name], ...resolve(value) };
    out.required.push(...(p.required || []));
  }
  out.required = [...new Set(out.required)];
  return out;
}
const schema = n => flat(api.components.schemas[n]);
const c = v => '`' + String(v) + '`';
const names = a => a.length ? a.map(c).join(', ') : 'None';
const esc = v => String(v ?? '').replace(/\|/g, '\\|').replace(/\r?\n/g, ' ').replace(/\s+/g, ' ').trim();
const out = [];
function add(...parts) {
  for (let i = 0; i < parts.length; i++) {
    out.push(parts[i]);
    const list = /^(- |\d+\. )/.test(parts[i]);
    const nextList = i + 1 < parts.length && /^(- |\d+\. )/.test(parts[i + 1]);
    if (!list || !nextList) out.push('');
  }
}
function table(head, rows) {
  out.push('| ' + head.join(' | ') + ' |', '| ' + head.map(() => '---').join(' | ') + ' |');
  for (const row of rows) out.push('| ' + row.map(esc).join(' | ') + ' |');
  out.push('');
}
function type(s) {
  s = resolve(s);
  if (!s.type && (s.anyOf || s.oneOf)) return (s.anyOf || s.oneOf).map(type).join(' OR ') + (s.default !== undefined ? '; default ' + c(JSON.stringify(s.default)) : '');
  let t = Array.isArray(s.type) ? s.type.join(' / ') : s.type || (s.properties || s.allOf ? 'object' : 'value');
  if (s.type === 'array') t += ' of ' + (s.items?.$ref ? s.items.$ref.split('/').pop() : type(s.items));
  if (s.format) t += ' (' + s.format + ')';
  if (s.enum) t += '; ' + names(s.enum);
  if (s.const !== undefined) t += '; constant ' + c(JSON.stringify(s.const));
  if (s.minLength !== undefined || s.maxLength !== undefined) t += '; length ' + (s.minLength ?? 0) + '..' + (s.maxLength ?? 'unbounded');
  if (s.minimum !== undefined || s.maximum !== undefined) t += '; range ' + (s.minimum ?? 'unbounded') + '..' + (s.maximum ?? 'unbounded');
  if (s.minItems !== undefined) t += '; at least ' + s.minItems + ' item';
  if (s.pattern) t += '; pattern ' + c(s.pattern);
  if (s.default !== undefined) t += '; default ' + c(JSON.stringify(s.default));
  return t;
}
const ops = [];
for (const [path, item] of Object.entries(api.paths)) for (const [method, operation] of Object.entries(item)) {
  if (['get', 'post', 'patch', 'put', 'delete'].includes(method)) ops.push({ path, method: method.toUpperCase(), operation, item });
}
const groups = [
  ['Authentication', o => o.path.startsWith('/auth/')],
  ['API 02 Customers', o => o.path.startsWith('/sales/customers')],
  ['API 03 Quotes', o => o.path.startsWith('/sales/quotes')],
  ['API 04 Orders', o => o.path.startsWith('/sales/orders')],
  ['API 05 Shippers', o => o.path.startsWith('/sales/shippers')],
  ['API 06 Invoices', o => o.path.startsWith('/sales/invoices') && !o.path.endsWith('/payments')],
  ['API 07 Payments', o => o.path.endsWith('/payments')],
];
const usedRequests = new Set(), usedResponses = new Set();
add('# Mini ERP API contract summary',
  'Contract snapshot: 19 November 2026. Source: [public/openapi.yaml](../public/openapi.yaml). This reference covers every current operation, its input, output and declared errors, followed by database implications.',
  '**' + ops.length + ' operations across ' + Object.keys(api.paths).length + ' paths: ' + ops.filter(o => o.method === 'POST').length + ' POST, ' + ops.filter(o => o.method === 'GET').length + ' GET and ' + ops.filter(o => o.method === 'PATCH').length + ' PATCH.** The 37 Sales operations await backend implementation. Authentication has existing endpoints. Swagger contracts do not establish working Sales endpoints or applied migrations.',
  'Use the operation tables to choose a request, the field tables to construct it, and the response and error sections to interpret the result. API-01 defines permissions/common rules and has no separate endpoint.',
  '[Operations](#operations) · [Input fields](#input-fields) · [Outputs](#outputs) · [Errors](#errors) · [Migration mapping](#migration-mapping)');
add('## Common request rules',
  '- Base URL: `./api/v1`, relative to `public/`. Every path parameter in braces is mandatory. All current query parameters are optional. GET operations have no request body.',
  '- Send `Authorization: Bearer <access_token>` except on login. POST/PATCH require a JSON body and `Content-Type: application/json`.',
  '- Sales uses the signed-in company and trusted admin identity. Do not send company ID, actor ID, permission flags or an admin flag in Sales request bodies. Shipper submission also requires Logistics dispatch authorization.',
  '- **M** = mandatory input; **O** = optional input; **C** = conditionally mandatory; **D** = optional input with a creation default. A field optional in a request may still be mandatory on a valid saved record.',
  '- Trim text before validation. Optional text can be cleared with `""`; nullable optional dates and source references can be cleared with `null`. Omission in PATCH preserves an existing value, except documented dependent defaults such as Invoice Due Date.',
  '- Calculated values, generated document/payment numbers and document status are output fields. Document status changes through actions. Customer status changes through PATCH. There is no HTTP DELETE operation.');
add('Sales write schemas reject unsupported fields. Quotes, Orders and Shippers do not post stock or change Customer balance. Invoice submission affects balance through Submitted due, without stock posting; recorded payments reduce that due.');
add('## Operations',
  'Each request names all mandatory top-level JSON fields and all other accepted top-level fields. Field types and conditional rules follow below. Error numbers are the HTTP statuses declared for that operation; the error catalog explains their conditions and messages.');
table(['Area', 'GET', 'POST', 'PATCH', 'Total'], groups.map(([title, include]) => {
  const selected = ops.filter(include);
  return [title, ...['GET','POST','PATCH'].map(m => selected.filter(o => o.method === m).length), selected.length];
}));
for (const [title, include] of groups) {
  add('### ' + title);
  const rows = [];
  for (const o of ops.filter(include)) {
    const body = o.operation.requestBody?.content?.['application/json']?.schema;
    const b = flat(body || {});
    if (body?.$ref) usedRequests.add(body.$ref.split('/').pop());
    let mandatory = names(b.required);
    if (o.method === 'POST' && /^\/sales\/(orders|shippers|invoices)$/.test(o.path)) mandatory += '; standalone also requires ' + names(['customer_id', 'warehouse_id']);
    if (o.method === 'PATCH' && o.path.startsWith('/sales/customers')) mandatory += '; at least one editable Customer field';
    const params = [...(o.item.parameters || []), ...(o.operation.parameters || [])].map(resolve);
    let optional = names(Object.keys(b.properties).filter(k => !b.required.includes(k)));
    if (params.some(p => p.in === 'query')) optional = 'Query: ' + names(params.filter(p => p.in === 'query').map(p => p.name));
    const successes = [], errors = [];
    for (const [status, r0] of Object.entries(o.operation.responses)) {
      const r = resolve(r0), s = r.content?.['application/json']?.schema;
      if (+status < 400) successes.push(status + ' ' + (s?.$ref ? c(s.$ref.split('/').pop()) : o.path === '/auth/login' ? '`success`, `result.access_token`' : 'JSON'));
      else {
        if (r0.$ref) usedResponses.add(r0.$ref.split('/').pop());
        errors.push(status);
      }
    }
    rows.push([c(o.method + ' ' + o.path), mandatory, optional, successes.join('; '), errors.join('; ')]);
  }
  table(['Method and path', 'Mandatory JSON', 'Optional JSON or query', 'Success', 'Declared errors'], rows);
}
add('## Input fields', '### Query and path parameters',
  'The same query name can have different descriptions on document lists and source lookups, so the table retains its endpoint context. Query values do not belong in JSON.');
const parameterRows = [], parameterGroups = new Map();
for (const o of ops) for (const p0 of [...(o.item.parameters || []), ...(o.operation.parameters || [])]) {
  const p = resolve(p0);
  const key = JSON.stringify(p);
  if (!parameterGroups.has(key)) parameterGroups.set(key, { p, operations: [] });
  parameterGroups.get(key).operations.push(o.method + ' ' + o.path);
}
for (const { p, operations } of parameterGroups.values()) parameterRows.push([names(operations), c(p.name), p.in, p.required ? 'M' : 'O', type(p.schema), p.description || '']);
table(['Operation', 'Parameter', 'Location', 'Required', 'Type and limits', 'Rule'], parameterRows);
add('### Request controls',
  '`action_reference` is the safe-retry key, unique within the company; it is not the YAML `operationId` or the business Payment Reference. An identical authorized retry returns the committed result. Reusing a reference for different input conflicts.',
  '`expected_version` is the current saved revision for Customer/document mutations. Payment recording uses the **Invoice revision**, not a Payment revision. New creates do not accept it.');
table(['Field', 'Type and limits', 'Rule'], [
  ['`action_reference`', type(api.components.schemas.ActionReference), 'M on every Sales write. Preserve the original value and full input on retry.'],
  ['`expected_version`', type(schema('SalesDocumentActionRequest').properties.expected_version), 'M on PATCH, lifecycle actions and Payment POST. Read the latest record first.'],
]);
add('### Authentication input');
table(['Field', 'Required', 'Type'], Object.entries(flat(api.paths['/auth/login'].post.requestBody.content['application/json'].schema).properties).map(([n, s]) => [c(n), 'M', type(s)]));
add('GET `/auth/me` needs only the bearer token. POST `/auth/logout` needs the bearer token, Content-Type application/json and an empty JSON body (`{}`). Logout revokes only the supplied token; it does not end the separate browser session. An already revoked token returns 401.');
add('### Customer fields',
  'Create requirements are shown below. On PATCH, provide both request controls plus at least one editable field; all editable Customer fields are individually optional. The server generates numeric `id` for URLs such as `/sales/customers/1`; it is required in Customer outputs and forbidden in create/update input. The entered business `customer_id` remains unique within the company and immutable. Document requests/filters continue to use business `customer_id`; no `customer_code` is introduced.');
const customer = schema('CustomerCreateRequest');
table(['Field', 'Create', 'PATCH', 'Type and limits', 'Rule'], Object.entries(customer.properties).filter(([n]) => n !== 'action_reference').map(([n,s]) => [c(n), customer.required.includes(n) ? 'M' : s.default !== undefined ? 'D' : 'O', n === 'customer_id' ? 'Forbidden' : 'O', type(s), n === 'customer_id' ? 'Unique within company; immutable after save.' : s.description || '']));
add('`status` defaults to `ACTIVE`; PATCH can deactivate/reactivate. Inactive Customers disappear from new selections; historical references and payments against existing Submitted Invoices remain valid. `amount_customer_owes_us` is calculated and rejected in input.');
for (const [label, model] of [['Quote', 'QuoteFields'], ['Order', 'OrderFields'], ['Shipper', 'ShipperFields'], ['Invoice', 'InvoiceFields']]) {
  add('### ' + label + ' header fields');
  const create = schema(label + 'CreateRequest');
  table(['Field', 'Create', 'Type and limits', 'Rule'], Object.entries(schema(model).properties).map(([n,s]) => [c(n), create.required.includes(n) ? 'M' : ['customer_id','warehouse_id'].includes(n) && label !== 'Quote' ? 'C: standalone' : 'O / default or copy as described', type(s), s.description || '']));
  add('PATCH uses these header fields as optional changes and **requires the complete intended `lines` collection**, `action_reference` and `expected_version`. The resulting saved document must remain valid.');
}
add('### Header defaults and source conditions',
  '- Creation date defaults to the configured business date. Quote/Order/Invoice dates may be future dates; Shipment Date cannot be in the future. Valid Until and Requested Delivery Date cannot precede their document date. Linked Order Date is on/after Quote Date; Shipment Date is on/after Order Date; Invoice Date is on/after Shipment Date.',
  '- For standalone creation, address/terms default from Customer where those fields exist. Saved address/terms remain required where applicable. Invoice has no shipping-address input; Shipper has no payment-terms field.',
  '- Quote has no source. Order may copy a full Submitted Quote; Shipper a full Submitted Order; Invoice a full Submitted Shipper. Linked creation can omit inherited headers; supplied inherited values must match. Always submit every copied line. Inherited Customer, Warehouse and lines are locked; address/terms are locked where inherited by the target contract.',
  '- Source omission on PATCH retains the link, `null` clears it, and a number selects/replaces it. Changing source requires complete validation and atomic release/claim. Replacing source uses the new source line IDs and omits old target line IDs. Clearing source removes `source_line_id` inputs.',
  '- A source is claimed on Draft save, with one target per source. Eligible-source lookup does not reserve it. Deleting the target Draft releases it; returning the target to Draft retains its claim. A downstream Draft already blocks returning its source to Draft.',
  '- Shipper Carrier may be empty in a Draft but is mandatory for submission. Shipper submission confirms dispatch and checks Logistics authorization for both standalone and linked documents.',
  '- Invoice Payment Terms default from Customer when omitted, including when linked because Shipper has no terms. Invoice Due Date defaults to Invoice Date plus 0/15/30 calendar days. When Invoice Date or terms actually change on PATCH and Due Date is omitted, recompute it; an explicit valid Due Date wins. Due Date cannot precede Invoice Date.');
add('### Document lines',
  'All four document creates and PATCH operations require at least one line and the complete intended collection in display order. Retain saved line IDs when editing; omit IDs for new lines; omit saved lines to remove them. Create rejects `line_id`. The same Item can appear on multiple distinct lines.');
const line = schema('SalesSourceLineInput');
table(['Field', 'Required input', 'Type and limits', 'Rule'], Object.entries(line.properties).map(([n,s]) => [c(n), n === 'line_id' ? 'O on PATCH; forbidden on create' : n === 'source_line_id' ? 'C: linked document' : line.required.includes(n) ? 'M' : 'O', type(s), s.description || '']));
add('`source_line_id` belongs to the immediate predecessor. It is required on copied lines, forbidden for standalone inputs and absent from Quote line inputs. A retained linked document must echo its unchanged complete lines, including its own line IDs and source line IDs. The service must check existing source state when source is omitted on PATCH.',
  'Server-generated line fields are `line_number`, `warehouse_id` and `line_amount`. Warehouse comes from the header; no per-line Warehouse selection. Round each quantity × unit price half up to 2 decimals, then sum rounded line amounts into Document Total. Reject capacity overflow.');
add('### Payment input', 'All five JSON fields are mandatory; there are no optional Payment input fields. Invoice Number is mandatory in the path.');
table(['Field', 'Type and limits', 'Rule'], Object.entries(schema('PaymentCreateRequest').properties).map(([n,s]) => [c(n), type(s), s.description || '']));
add('Payment Amount is a positive decimal string with up to 16 whole digits and 2 decimal places, no more than the current locked Invoice due. Payment Date must be on/after Invoice Date and no later than the business date. Payment Reference is trimmed, nonblank business text; it is not required to be unique.',
  'Only Submitted Invoices with a positive due accept new payments. Lock/recheck the Invoice, insert the immutable payment and increment the Invoice revision atomically. Authorize before replay; resolve identical committed retries before current revision/state/due checks, including after the final payment. A new stale request against a Submitted Invoice receives the current-due conflict message.');
add('### Lifecycle action input');
table(['Action', 'Required body', 'Condition and effect'], [
  ['`submit`', '`action_reference`, `expected_version`', 'Saved valid Draft → Submitted. Invoice total must be greater than zero; Shipper adds Carrier/date/Logistics checks.'],
  ['`move-to-draft`', '`action_reference`, `expected_version`', 'Submitted → Draft only when no downstream use. Any recorded payment blocks Invoice return.'],
  ['`delete`', '`action_reference`, `expected_version`', 'POST action, Draft only, no downstream documents. Releases source claim.'],
]);
add('These actions operate on the saved record and accept no unsaved header/line edits. Payment has no edit/delete lifecycle and Customer has no delete endpoint.');
add('## Outputs', '### Envelopes',
  'Except for the intentionally minimal login schema, success responses require `success`, `operation_id`, `current_version`, `result` and `error`. `success` is true and `error` is null. `operation_id` echoes the Sales action reference on writes; list and identity responses use null. `current_version` identifies the saved record revision; lists, identity and delete results use null. Payment POST returns the updated Invoice revision.',
  'Lists return `result: {items: [...], page, page_size}`. The current contract does not promise a total count or next-page token. Detail/create/update/submit/return responses return the full relevant entity in `result`. Delete returns its document number and `deleted: true`.',
  'Login currently documents only `success` and `result.access_token`, without required-property lists. The backend response shown earlier contains more envelope/token fields; those are not yet specified by the login contract.');
add('`LogoutResponse` uses the success envelope with null operation_id/current_version and required `result.revoked: true`. Logout declares 400 for malformed/oversized JSON, 401 for missing/invalid/revoked token, 415 for wrong Content-Type, 500 for unexpected failure and 503 for database/configuration unavailability.');
add('### Entity fields',
  'The following are fields inside the entity, not the outer envelope. **Required in output** means the key must be present; it does not mean the caller must supply it. Optional text normally returns an empty string; nullable dates and source references return null when absent.');
for (const model of ['Identity','Customer','Quote','Order','Shipper','Invoice','SalesSourceLine','PaymentRecord','PaymentInvoiceSnapshot']) {
  const s = schema(model);
  add('#### ' + model);
  table(['Field', 'Required in output', 'Type and limits', 'Meaning'], Object.entries(s.properties).map(([n,p]) => [c(n), s.required.includes(n) ? 'Yes' : 'No', type(p), model === 'PaymentInvoiceSnapshot' && n === 'payment_status' ? 'After this positive payment: Partially Paid or Paid; never null.' : model === 'PaymentInvoiceSnapshot' && n === 'total_paid' ? 'Positive total recorded payments after this payment.' : p.description || (n === 'lines' ? 'Full lines, including generated IDs, display order, inherited Warehouse and calculated amount.' : '')]));
}
add('### List and delete output models');
table(['Models', 'Result'], [
  ['`CustomerListResponse`', '`items` are full Customer objects; `page`, `page_size`.'],
  ['`QuoteListResponse`, `OrderListResponse`, `ShipperListResponse`, `InvoiceListResponse`', '`items` are the corresponding Summary objects: saved header, number, status, totals, version and available actions; full `lines` are excluded. Invoice summaries include payment totals/status.'],
  ['Eligible Quote / Order / Shipper GETs', 'The corresponding source ListResponse. Dates/filter rules refer to the source type; only eligible Submitted sources are returned.'],
  ['`QuoteDeleteResponse`, `OrderDeleteResponse`, `ShipperDeleteResponse`, `InvoiceDeleteResponse`', '`result` contains the corresponding `*_number` and `deleted: true`; envelope `current_version` is null.'],
  ['`PaymentCreateResponse`', '`result: {payment: PaymentRecord, invoice: PaymentInvoiceSnapshot}`; snapshot revision matches envelope revision.'],
  ['`PaymentHistoryResponse`', '`result: {items: PaymentRecord[], page, page_size}`; empty items are valid for an existing Invoice with no payments, including a Draft. Sort by created_at then payment_number. Missing/cross-company Invoice is 404.'],
]);
add('### Calculations and output actions',
  '- Quantity is a positive decimal string (9 whole, 3 fractional digits); Unit Price is nonnegative (10 whole, 2 fractional digits). Line Amount/Document Total and balance totals use decimal strings up to 16 whole digits with 2 output decimals. Payment Amount input allows one or two fractional digits or none; output has exactly 2.',
  '- Invoice `total_paid` = sum of recorded payments, `amount_due` = document_total − total_paid. Draft `payment_status` is null; Submitted status is `Unpaid`, `Partially Paid` or `Paid`. A zero-total Draft is allowed; submission requires a positive total.',
  '- Customer `amount_customer_owes_us` = sum of all that Customer’s Submitted Invoice dues. Draft invoices contribute nothing, even though Draft amount_due reflects their total. No opening balances, credits or refunds.',
  '- `available_actions` can contain `edit`, `submit`, `move_to_draft`, `delete`; Invoice also has `record_payment`. These reflect current permissions/state/dependencies. Fully paid Invoices have no further mutations. The action value `move_to_draft` corresponds to the route suffix `move-to-draft`.',
  '- Example: Submitted Invoice 1,000.00 → record 300.00 → due 700.00; record another 700.00 → due 0.00. Payment creation returns that transaction’s Invoice snapshot. A replay can return an older snapshot; GET the Invoice for its latest state.');
add('## Errors',
  'The operation tables list each route’s exact declared HTTP error statuses. Components below supply the messages and conditions. Customer operations use Customer validation/conflict/not-found components. Quotes use SalesDocument variants; Orders and Shippers use SalesSourceConflict; Invoices use InvoiceConflict. Shipper submit overrides 403/503 with ShipperDispatchForbidden/ShipperSubmitUnavailable; Invoice submit overrides 400 with InvoiceSubmitValidationError. Payment POST uses PaymentValidationError/PaymentConflict; Payment GET uses common document validation/not-found errors. Login 401/500 currently have descriptions only; they do not declare an error body schema.',
  'Other declared error responses use `ErrorResponse`: required `success: false`, `operation_id: null`, `current_version: null`, `result: null`, and `error`. Inside `error`, `code` and `message` are required strings and `request_id` is optional. A stale error does not promise the current version in the envelope.');
table(['HTTP status', 'Purpose'], [['400','Invalid fields, dates, calculated input, or business validation.'],['401','Missing/invalid authentication; protected routes declare WWW-Authenticate: Bearer.'],['403','Sales admin/password-change restriction; Shipper submit additionally checks dispatch permission.'],['404','Resource absent or outside the signed-in company.'],['409','Duplicate ID, stale version, action-reference reuse, state/source conflict.'],['415','Write is not application/json.'],['500','Unexpected server failure.'],['503','Database/configuration unavailable; Shipper submission can also fail on dispatch dependency.']]);
for (const [name, r] of Object.entries(api.components.responses)) {
  if (!usedResponses.has(name)) continue;
  add('### ' + name, r.description.replace(/\n- /, '\n\n- ').replace(/\n(?!- )([^\n]+)/g, '\n\n$1'));
  const example = r.content?.['application/json']?.example;
  if (example?.error) add('Example error: ' + c(example.error.code) + ' — ' + example.error.message);
}
add('### Additional validation conditions',
  'Inherited header mismatches use `{Field label} must match the source document.` Linked-line edits use `Source-linked lines cannot be added, changed or removed.` Shipper rules include `Enter Carrier before submitting the Shipper.`, `Shipment Date cannot be in the future.` and `Shipment Date must be on or after Order Date.` Source eligibility and dependencies must be rechecked when saving/submitting, not trusted from an earlier lookup.');
add('## Migration mapping',
  '**Proposed storage mapping, not an applied migration.** API field names describe the public contract; physical names and shared-table choices need not match it. Use request requirements together with saved-record invariants, not request `required` lists alone, to decide database constraints.');
table(['Contract requirement', 'Proposed storage or constraint'], [
  ['Company isolation', 'COMPANY_ID on every Sales entity; company-scoped keys, foreign keys and queries. Derive company from actor.'],
  ['Customer public identity', 'ME_OE_CUSTOMERS primary key ID NUMBER(10), default ME_OE_CUSTOMER_SEQ.NEXTVAL starting at 1. Expose it as read-only numeric id for Customer URLs; scope lookups by company. Keep unique (COMPANY_ID, CUSTOMER_ID), VARCHAR2(20 CHAR), character check and business ID immutability. Existing draft follows this mapping; document business-key references remain valid. Sequence gaps are possible.'],
  ['Customer metadata', 'Customer text lengths follow the field table. Saved name/address line 1/city/country required. Status ACTIVE/INACTIVE and payment-term checks/defaults. No writable balance column.'],
  ['Quote / Order / Shipper / Invoice headers', 'Proposed ME_OE_DOCUMENTS with type, company, generated document number, Customer/Warehouse, applicable dates, optional text, terms and revision. Type-specific checks enforce required saved fields. Separate tables remain a physical alternative.'],
  ['Generated public numbers', 'Numbers are strings up to 20 characters, unique per company/type as applicable and never reused after deletion. Configure prefixes/sequence scope and durable issued-number tracking before DDL; Oracle sequences can have gaps. Do not accept caller numbers.'],
  ['Saved optional fields', 'Fields absent from a request may receive defaults/copies before insert. Shared header table must allow fields not applicable to some types. Oracle empty text becomes NULL; normalize optional text to empty strings in responses.'],
  ['Document lifecycle', 'Persist Draft/Submitted state with an explicit mapping to any uppercase storage values. Use service checks for downstream use, revision and payments. Customer status is a separate enum.'],
  ['Line ownership and order', 'Proposed ME_OE_DOCUMENT_LINES with document FK, generated line ID up to 40 chars and line number. Unique line position per document. At least one line is a transactional service invariant.'],
  ['Item and Warehouse references', 'Inventory owns these records. Resolve item_code (up to 30) / warehouse_id (up to 20) against its company-scoped keys before final FKs. Warehouse is inherited from header. Preserve saved history.'],
  ['Line numeric capacity', 'Quantity NUMBER(12,3), Unit Price NUMBER(12,2), amount NUMBER(18,2) are candidate Oracle types. Positive/nonnegative checks. Validate input precision before insert to prevent silent scale rounding.'],
  ['Source exclusivity', 'Proposed ME_OE_SOURCE_LINKS stores immediate source/target ownership and enforces one target per source, claimed at Draft save. Claim/release and lines save atomically. Type/company constraints must reject invalid source chains.'],
  ['Source line traceability', 'Persist source_line_id with document ownership; nullable for standalone, disallowed for Quote. Validate correspondence with the immediate predecessor and copied content.'],
  ['Invoice totals and balance', 'Document total may be derived or persisted with transactional recomputation; choose one policy. Derive payment totals, due and Customer balance from Submitted Invoices and actual payments. Avoid independently editable balance/status columns.'],
  ['Immutable payment history', 'Proposed ME_OE_PAYMENTS: company, payment number, Invoice FK, amount NUMBER(18,2) > 0, Payment Date, reference VARCHAR2(50 CHAR), created_by and created_at. No edit/delete/refund fields or separate Payment revision.'],
  ['Payment concurrency', 'Lock Invoice; check current revision/state/due; insert payment and increment Invoice revision in one transaction. An ordinary CHECK constraint cannot enforce aggregate payment sum <= Invoice total.'],
  ['Safe retries', 'Reuse shared action storage after verifying it can persist request identity and original response; company/action-reference uniqueness. Replay must not duplicate a payment, number or source claim.'],
  ['Audit', 'Reuse user/company/audit framework; trusted actor and timestamps. PaymentRecord requires created_by and UTC created_at in output. Preserve historical references.'],
  ['List access patterns', 'Index company + Customer ID/name/status as query plan requires; document type/date/status/Customer/Warehouse, source claims, and Invoice/payment history ordering. Review real query plans before adding broad indexes.'],
  ['Authorization', 'Reuse existing admin identity and capability registry; no separate API-01 permission system. Integrate Logistics provider for Shipper submit; do not add caller-set authorization columns.'],
]);
add('### Decisions before writing or applying migrations',
  '1. Confirm shared document headers/lines versus separate physical tables, number allocation scope/prefixes, revision and audit conventions, deletion/history policy, Customer ID case matching, and storage mappings for API enums.',
  '2. Confirm Inventory company/key types and code-to-internal-ID mapping before foreign keys. Current YAML defines no Item/Warehouse lookup endpoints. Agree the internal read provider separately; do not invent new routes in this summary.',
  '3. Agree the internal Logistics dispatch authorization interface. Only Shipper submission requires the additional check; payment recording/history has no Inventory or Logistics dependency.',
  '4. Check shared action/audit infrastructure for safe replay and atomic transactions; wire the migration runner to Sales and verify its plan/status behavior before applying anything.',
  '5. Build and verify schema in a disposable test environment, including empty Invoice/Payment tables so Customer balances can use their real query. Then implement and test API-02 onward in order.',
  'The login response specification remains incomplete compared with the observed backend response. There is no combined all-document list route in the current contract. These are contract review items, not extra existing operations.',
  'Source fingerprint (SHA-256): ' + c(hash) + '. Regenerate/review this reference when the contract changes.');
if (groups.flatMap(([, p]) => ops.filter(p)).length !== ops.length) throw new Error('Operation coverage mismatch');
const content = out.join('\n');
for (const o of ops) if (!content.includes(c(o.method + ' ' + o.path))) throw new Error('Missing operation: ' + o.path);
fs.writeFileSync('docs/sales-api-contract-summary.md', content);
if (fs.readFileSync('public/openapi.yaml','utf8') !== raw) throw new Error('Source changed');
console.log(JSON.stringify({ operations: ops.length, paths: Object.keys(api.paths).length, requestModels: [...usedRequests], errorComponents: usedResponses.size, lines: out.length, sourceUnchanged: true }));
