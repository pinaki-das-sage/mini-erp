const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { yaml } = require('../../../node_modules/playwright-core/lib/utilsBundle.js');

const root = path.resolve(__dirname, '../../..');
const raw = fs.readFileSync(path.join(root, 'public/openapi.yaml'), 'utf8');
const spec = yaml.parse(raw, { uniqueKeys: true });
const schemas = spec.components.schemas;
const operation = (route, method = 'post') => spec.paths[route][method];

test('all local references resolve and operation IDs remain unique', () => {
  function visit(node) {
    if (!node || typeof node !== 'object') return;
    if (typeof node.$ref === 'string') {
      assert(node.$ref.startsWith('#/'), 'This specification uses local references.');
      let target = spec;
      for (const part of node.$ref.slice(2).split('/')) target = target?.[part.replace(/~1/g, '/').replace(/~0/g, '~')];
      assert.notEqual(target, undefined, node.$ref);
    }
    Object.values(node).forEach(visit);
  }
  visit(spec);
  const ids = Object.values(spec.paths).flatMap(route =>
    ['get', 'post', 'patch', 'put', 'delete'].filter(method => route[method]).map(method => route[method].operationId));
  assert(ids.every(Boolean));
  assert.equal(new Set(ids).size, ids.length);
});

test('all documented updates use POST update routes', () => {
  for (const [route, operations] of Object.entries(spec.paths)) {
    assert.equal(operations.patch, undefined, route);
  }
  for (const route of ['/sales/customers/{id}', '/sales/quotes/{quote_number}', '/sales/orders/{order_number}', '/sales/invoices/{invoice_number}']) {
    assert(spec.paths[route + '/update'].post, route);
    assert(spec.paths[route].get, route);
  }
});

test('Customer contract matches the updated backend without changing login company support', () => {
  assert.equal(schemas.CustomerRecordId.maximum, 999999999999999);
  assert(!schemas.CustomerCreateRequest.required.includes('action_reference'));
  assert(!schemas.CustomerCreateRequest.required.includes('customer_code'));
  assert.equal(schemas.CustomerCreateRequest.allOf[1].properties.customer_code, undefined);
  assert.equal(schemas.Customer.allOf[1].properties.customer_code.readOnly, true);
  assert.equal(operation('/sales/customers').requestBody.content['application/json'].example.customer_code, undefined);
  assert.match(operation('/sales/customers').description, /CUST-001/);
  assert(!(schemas.CustomerUpdateRequest.required || []).includes('action_reference'));
  assert.match(schemas.Customer.allOf[1].properties.amount_customer_owes_us.description, /stored/);
  assert.equal(operation('/auth/login').requestBody.content['application/json'].schema.properties.company.enum, undefined);
  assert(spec.paths['/sales/customers/{id}/update']);
});

test('Quote contract matches optional retry keys, workflow grants and joined Customer name', () => {
  for (const name of ['QuoteCreateRequest', 'QuoteUpdateRequest', 'QuoteActionRequest']) {
    assert(!schemas[name].required.includes('action_reference'));
  }
  assert(schemas.QuoteUpdateRequest.required.includes('expected_version'));
  assert(schemas.QuoteActionRequest.required.includes('expected_version'));
  assert(spec.paths['/sales/quotes/{quote_number}/update'].post);
  assert(schemas.QuoteSummary.allOf[1].required.includes('customer_name'));
  assert.match(operation('/sales/quotes/{quote_number}/move-to-draft').description, /sales.quotes:update/);
  assert.match(operation('/sales/quotes').description, /Draft saves validate local fields without calling Inventory/);
  assert.match(operation('/sales/quotes').description, /INVENTORY_UNAVAILABLE \(HTTP 503\)/);
});

test('login and component schemas use valid JSON Schema types', () => {
  const types = new Set(['array', 'boolean', 'integer', 'null', 'number', 'object', 'string']);
  function visit(node) {
    if (!node || typeof node !== 'object') return;
    if (node.type) for (const type of [].concat(node.type)) assert(types.has(type), `Invalid schema type: ${type}`);
    for (const [key, value] of Object.entries(node)) if (!key.startsWith('x-')) visit(value);
  }
  const login = operation('/auth/login').requestBody.content['application/json'].schema;
  visit(login);
  visit(schemas);
  assert.equal(login.properties.username.type, 'string');
  assert.equal(login.properties.password.type, 'string');
});

test('storage documentation matches Product migration without leaking database columns into API fields', () => {
  const stored = schemas.DatabaseSalesDocumentLineRow;
  assert(stored.required.includes('PRODUCT_ID'));
  assert.equal(stored.properties.ITEM_CODE, undefined);
  assert.equal(stored['x-database-columns'].PRODUCT_ID.type, 'NUMBER(10)');
  assert(stored['x-database-constraints'].some(value => /ME_OE_LINE_PRODUCT_FK/.test(value)));
  assert.match(schemas.SalesLine['x-database-field-mapping'].product_id, /PRODUCT_ID/);
  assert.equal(schemas.Customer.allOf[1].properties['RECORD#'], undefined);
  for (const [name, schema] of Object.entries(schemas)) {
    for (const definition of Object.values(schema['x-database-columns'] || {})) assert.notEqual(definition.default, 'AS', name);
    if (name.startsWith('Database')) assert.equal(schema.properties['RECORD#'].default, undefined, name);
  }
});

test('Invoice sources are Orders and removed Shipper definitions leave no references', () => {
  assert(!/shipper/i.test(raw));
  assert(spec.paths['/sales/invoices/eligible-orders']);
  assert(schemas.InvoiceFields.properties.source_order_number);
  assert(schemas.InvoiceCreateRequest.required.includes('source_order_number'));
  assert.equal(schemas.InvoiceFields.properties.source_order_number.$ref, '#/components/schemas/SalesDocumentNumber');
  assert.match(schemas.InvoiceUpdateRequest.description, /immutable Order source/);
  assert.deepEqual(schemas.EligibleOrderSummary.allOf[1].properties.status.enum, ['Submitted', 'In Progress', 'Closed']);
  assert.deepEqual(spec.components.parameters.DocumentType.schema.enum, ['Quote', 'Order', 'Invoice']);
});

test('whole-number quantities match the line migration and preserve valid Quote fields', () => {
  const quantity = new RegExp(schemas.SalesQuantity.pattern);
  for (const value of ['1', '12', '999999999']) assert(quantity.test(value), value);
  for (const value of ['0', '-1', '1.25', '1.000', '1e3', '1000000000', ' 1 ']) assert(!quantity.test(value), value);
  const migration = fs.readFileSync(path.join(root, 'database/migrations/sales/006_oe_document_lines.php'), 'utf8');
  assert.match(migration, /QUANTITY = TRUNC\(QUANTITY\)/);
  assert(schemas.QuoteFields.properties.requested_delivery_date);
});

test('Sales product references use numeric Inventory IDs with the product_id field', () => {
  const productId = new RegExp(schemas.SalesLineInput.properties.product_id.pattern);
  for (const value of ['1', '83', '9999999999']) assert(productId.test(value), value);
  for (const value of ['ITEM-001', '0', '-1', '1.5', '1e2', '10000000000']) assert(!productId.test(value), value);
  assert(schemas.SalesLineInput.required.includes('product_id'));
  assert.equal(schemas.SalesLineInput.properties.item_code, undefined);
  assert.deepEqual(schemas.QuoteFields.properties.warehouse_id.enum, ['W01']);
  assert.equal(schemas.QuoteCreateRequest.example.lines[0].product_id, '163');
});

test('money, phone and payment reference patterns accept actual API values', () => {
  const price = new RegExp(schemas.SalesUnitPrice.pattern);
  assert(price.test('125.50'));
  assert(!price.test('125.501'));
  const amount = new RegExp(schemas.SalesAmount.pattern);
  assert(amount.test('9999999999999999.99'));
  assert(!amount.test('100.001'));
  const balance = new RegExp(schemas.Customer.allOf[1].properties.amount_customer_owes_us.pattern);
  assert(balance.test('700.00'));
  assert(!balance.test('-1.00'));
  const payment = new RegExp(schemas.PaymentAmount.pattern);
  assert(payment.test('300.00'));
  assert(!payment.test('0.00'));
  const reference = new RegExp(schemas.PaymentCreateRequest.properties.payment_reference.pattern);
  assert(reference.test('BANK-123'));
  assert(!reference.test('   '));
  const phone = new RegExp(schemas.CustomerDetails.properties.phone_number.pattern);
  assert(phone.test('+91 (80) 1234-5678'));
  assert(!phone.test('123abc'));
});

test('Order progress and Trip responses match the three-document model', () => {
  const summary = schemas.OrderSummary.allOf[1];
  assert.deepEqual(summary.properties.status.enum, ['Draft', 'Submitted', 'In Progress', 'Closed']);
  assert.equal(summary.properties.logistics_started_at, undefined);
  assert.deepEqual(schemas.OrderSummary.allOf[3].then.properties.available_actions.items.enum, ['move_to_draft']);
  assert.equal(schemas.OrderSummary.allOf[4].then.properties.available_actions.maxItems, 0);
  assert.equal(schemas.Order.allOf[1].properties.lines.items.$ref, '#/components/schemas/OrderLine');
  assert.deepEqual(schemas.OrderTripFields.then.properties.trip_status.enum, ['Draft', 'Dispatched', 'Delivered']);
  assert.equal(schemas.OrderTripFields.else.properties.trip_reference.type, 'null');
});

test('Order flow requires a Quote and creates its Draft Invoice with reservations on Submit', () => {
  assert(schemas.OrderCreateRequest.required.includes('source_quote_number'));
  assert.equal(schemas.OrderFields.properties.source_quote_number.$ref, '#/components/schemas/SalesDocumentNumber');
  assert.equal(schemas.InvoiceFields.properties.source_order_number.$ref, '#/components/schemas/SalesDocumentNumber');
  assert.deepEqual(schemas.OrderCreateRequest.required, ['source_quote_number']);
  assert.equal(schemas.OrderCreateRequest.properties.lines, undefined);
  assert.equal(schemas.OrderCreateRequest.additionalProperties, false);
  for (const field of ['customer_code', 'warehouse_id', 'shipping_address', 'payment_terms']) {
    assert.equal(schemas.OrderCreateRequest.properties[field], undefined, field);
  }
  for (const name of ['OrderUpdateRequest']) {
    assert.equal(schemas[name].properties.lines.items.$ref, '#/components/schemas/OrderLineInput', name);
    assert.equal(schemas[name].else, undefined, name);
  }
  assert(!schemas.OrderLineInput.required.includes('source_line_id'));
  assert(schemas.OrderLineInput.required.includes('product_id'));
  assert.equal(schemas.OrderLineInput.properties.item_code, undefined);
  const submit = operation('/sales/orders/{order_number}/submit');
  assert.equal(submit.responses['200'].$ref, '#/components/responses/Order');
  assert.equal(schemas.OrderSubmitResponse, undefined);
  assert(!schemas.OrderCreateRequest.required.includes('action_reference'));
  assert(!schemas.OrderUpdateRequest.required.includes('action_reference'));
  assert.match(submit.description, /Create exactly one linked Draft Invoice on Submit/);
  assert.match(submit.description, /any failure rolls everything back/);
  assert.match(submit.description, /without duplicate reservations/);
  const progress = operation('/sales/orders/{order_number}', 'get').description;
  assert.match(progress, /Reads never change status or create Invoices/);
  assert.match(progress, /On final delivery/);
  assert.match(progress, /exactly one source-linked Draft Invoice/);
  assert.match(progress, /same shared transaction/);
  assert.match(progress, /different action reference/);
  const move = operation('/sales/orders/{order_number}/move-to-draft');
  assert.equal(move['x-implementation-status'], 'implemented');
  assert.match(move.description, /release Inventory reservations/);
  assert(schemas.OrderSummary.allOf[1].properties.available_actions.items.enum.includes('move_to_draft'));
});

test('Order Submit and saved-field return checks are connected', () => {
  for (const [route, method] of [
    ['/sales/orders', 'post'], ['/sales/orders', 'get'], ['/sales/orders/eligible-quotes', 'get'],
    ['/sales/orders/{order_number}', 'get'], ['/sales/orders/{order_number}/update', 'post'],
    ['/sales/orders/{order_number}/delete', 'post']]) {
    assert.equal(operation(route, method)['x-implementation-status'], 'implemented', route);
  }
  assert.equal(operation('/sales/orders/{order_number}/submit')['x-implementation-status'], 'implemented');
  assert.equal(operation('/sales/orders/{order_number}/move-to-draft')['x-implementation-status'], 'implemented');
  assert.deepEqual(schemas.OrderActionRequest.required, ['expected_version']);
  assert.match(operation('/sales/orders/{order_number}/submit').description, /inventory.reservations:reserve/);
  assert.match(operation('/sales/orders/{order_number}/move-to-draft').description, /SalesTripAssignmentGuard/);
  assert.match(operation('/sales/orders/{order_number}/move-to-draft').description, /both references have been cleared/);
  const product = new RegExp(schemas.OrderLineInput.properties.product_id.pattern);
  assert(product.test('163'));
  assert(!product.test('ITEM-001'));
  assert(!product.test('0'));
  assert.equal(schemas.OrderLineInput.additionalProperties, false);
});

test('Draft Order updates permit editable lines and header-only changes with managed provenance', () => {
  assert.deepEqual(schemas.OrderUpdateRequest.required, ['expected_version']);
  assert(!schemas.OrderLineInput.required.includes('line_id'));
  assert(!schemas.OrderLineInput.required.includes('source_line_id'));
  assert.deepEqual(schemas.OrderLineInput.properties.source_line_id.type, ['string', 'null']);
  assert.deepEqual(schemas.OrderLine.allOf[0].properties.source_line_id.type, ['string', 'null']);
  assert.match(schemas.OrderUpdateRequest.description, /omit line_id to add a line/);
  assert.match(schemas.OrderUpdateRequest.description, /Omitted header fields and omitted lines are preserved/);
  assert.match(schemas.OrderLineInput.properties.source_line_id.description, /Replacing a Product clears/);
  assert.equal(schemas.OrderUpdateRequest.example.lines[0].description, 'Sagar Testing order update');
});

test('lifecycle permissions and reservation rollback remain explicit', () => {
  for (const type of ['quotes', 'orders', 'invoices']) {
    const singular = { quotes: 'quote', orders: 'order', invoices: 'invoice' }[type];
    const route = `/sales/${type}/{${singular}_number}`;
    assert.match(operation(route + '/submit').description, new RegExp(`sales\\.${type}:${type === 'quotes' ? 'update' : 'submit'}`));
    if (type !== 'quotes') assert.match(operation(route + '/move-to-draft').description, new RegExp(`sales\\.${type}:return_to_draft`));
  }
  const submit = operation('/sales/orders/{order_number}/submit');
  assert(submit.responses['409']);
  assert(submit.responses['503']);
  assert.match(submit.description, /shared transaction/);
  assert.match(operation('/sales/orders/{order_number}/move-to-draft').description, /no current valid Trip assignment and an eligible linked Draft Invoice/);
  assert.match(operation('/sales/orders/{order_number}/delete').summary, /Draft/);
  assert.match(operation('/sales/orders/{order_number}/delete').description, /Submitted, In Progress and Closed Orders cannot be deleted/);
});
