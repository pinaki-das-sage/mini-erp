const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { yaml } = require('../../node_modules/playwright-core/lib/utilsBundle.js');

const root = path.resolve(__dirname, '../..');
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

test('Quote writes accept optional retry references and keep saved-document versions', () => {
  for (const name of ['QuoteCreateRequest', 'QuoteUpdateRequest', 'QuoteActionRequest']) {
    assert(!schemas[name].required.includes('action_reference'));
  }
  assert(schemas.QuoteUpdateRequest.required.includes('expected_version'));
  assert(schemas.QuoteActionRequest.required.includes('expected_version'));
  assert(spec.paths['/sales/quotes/{quote_number}/update'].post);
  assert.equal(schemas.QuoteDeleteResponse.allOf[1].properties.operation_id.type[1], 'null');
  assert.match(operation('/sales/quotes').description, /INVENTORY_UNAVAILABLE/);
  assert.equal(operation('/sales/orders').requestBody.content['application/json'].schema.$ref, '#/components/schemas/OrderCreateRequest');
  assert(schemas.SalesDocumentActionRequest.required.includes('action_reference'));
});

test('Customer contract matches the updated backend without changing login company support', () => {
  assert.equal(schemas.CustomerRecordId.maximum, 999999999999999);
  assert(!schemas.CustomerCreateRequest.required.includes('action_reference'));
  assert(!(schemas.CustomerUpdateRequest.required || []).includes('action_reference'));
  assert.match(schemas.Customer.allOf[1].properties.amount_customer_owes_us.description, /stored/);
  assert.equal(operation('/auth/login').requestBody.content['application/json'].schema.properties.company.enum, undefined);
  assert(spec.paths['/sales/customers/{id}/update']);
});

test('Invoice sources are Orders and removed Shipper definitions leave no references', () => {
  assert(!/shipper/i.test(raw));
  assert(spec.paths['/sales/invoices/eligible-orders']);
  assert(schemas.InvoiceFields.properties.source_order_number);
  for (const key of ['InvoiceCreateRequest', 'InvoiceUpdateRequest']) {
    assert(schemas[key].if.required.includes('source_order_number'));
  }
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

test('lifecycle permissions and reservation rollback remain explicit', () => {
  for (const type of ['quotes', 'orders', 'invoices']) {
    const singular = { quotes: 'quote', orders: 'order', invoices: 'invoice' }[type];
    const route = `/sales/${type}/{${singular}_number}`;
    assert.match(operation(route + '/submit').description, new RegExp(`sales\\.${type}:submit`));
    if (type !== 'quotes') assert.match(operation(route + '/move-to-draft').description, new RegExp(`sales\\.${type}:return_to_draft`));
  }
  const submit = operation('/sales/orders/{order_number}/submit');
  assert(submit.responses['409']);
  assert(submit.responses['503']);
  assert.match(submit.description, /shared transaction/);
  assert.match(operation('/sales/orders/{order_number}/move-to-draft').description, /no current valid Trip assignment and no Invoice reference/);
  assert.match(operation('/sales/orders/{order_number}/delete').summary, /Draft/);
  assert.match(operation('/sales/orders/{order_number}/delete').description, /Submitted, In Progress and Closed Orders cannot be deleted/);
});
