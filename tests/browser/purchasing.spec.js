const { test, expect } = require('@playwright/test');
const { execFileSync } = require('node:child_process');
const path = require('node:path');

const render = (base = '') => execFileSync(process.env.PHP_BINARY || 'php', [
  path.resolve('tests/browser/render.php'), 'purchasing', base,
], { encoding: 'utf8' });
const documentRecord = {
  id: 'd1', docno: 'PO-001', recordtype: 'order', vendor_id: 'v1',
  doc_date: '2026-11-26', status: 'draft', payment_terms: 'net_30', total: 12,
  entries: [{ lineno: 1, product_id: 1, description: '<img src=x onerror=alert(1)>', quantity: 2, unitcost: 6, lineamount: 12, notes: null }],
};
test.beforeEach(async ({ page }) => {
  await page.route('**/modules/purchasing', route => route.fulfill({ contentType: 'text/html', body: render() }));
});

test('document creation sends numeric lines, CSRF and preserves input after rejection', async ({ page }) => {
  let payload;
  let csrf;
  await page.route('**/api/purchasing/documents', async route => {
    if (route.request().method() === 'GET') return route.fulfill({ json: { success: true, result: [] } });
    payload = route.request().postDataJSON();
    csrf = route.request().headers()['x-csrf-token'];
    await route.fulfill({ status: 409, json: { success: false, error: { message: 'Document number already exists.' } } });
  });
  await page.goto('/modules/purchasing');
  await page.getByRole('button', { name: 'New document' }).click();
  await page.getByLabel('Document number').fill('PO-001');
  await page.getByLabel('Vendor record ID').fill('v1');
  await page.getByLabel('Document date', { exact: true }).fill('2026-11-26');
  await page.getByLabel('Product ID', { exact: true }).fill('1');
  await page.getByLabel('Description', { exact: true }).fill('Product A');
  await page.getByLabel('Quantity', { exact: true }).fill('2');
  await page.getByLabel('Unit cost', { exact: true }).fill('6');
  await expect(page.locator('#purchasing-total')).toHaveText('12.00');
  await page.getByRole('button', { name: 'Add line' }).click();
  await expect(page.locator('.purchasing-line')).toHaveCount(2);
  await page.getByRole('button', { name: 'Remove line' }).last().click();
  await page.getByRole('button', { name: 'Create document' }).click();
  await expect(page.getByRole('status')).toHaveText('Document number already exists.');
  expect(payload).toEqual({
    recordtype: 'order', docno: 'PO-001', vendor_id: 'v1', doc_date: '2026-11-26', payment_terms: 'net_30',
    entries: [{ lineno: 1, product_id: 1, description: 'Product A', quantity: 2, unitcost: 6 }],
  });
  expect(csrf).toBe('browser-fixture-token');
  await expect(page.getByLabel('Document number')).toHaveValue('PO-001');
  await expect(page.getByRole('button', { name: 'Create document' })).toBeEnabled();
});

test('details render text safely and update only supported fields', async ({ page }) => {
  let payload;
  await page.route('**/api/purchasing/documents', route => route.fulfill({ json: { success: true, result: [documentRecord] } }));
  await page.route('**/api/purchasing/documents/d1', async route => {
    if (route.request().method() === 'GET') return route.fulfill({ json: { success: true, result: documentRecord } });
    payload = route.request().postDataJSON();
    await route.fulfill({ status: 403, json: { success: false, error: { message: 'You do not have permission for this operation.' } } });
  });
  await page.goto('/modules/purchasing');
  await page.getByRole('button', { name: 'View PO-001' }).click();
  await expect(page.locator('#purchasing-detail-lines')).toContainText('<img src=x onerror=alert(1)>');
  await expect(page.locator('#purchasing-detail-lines img')).toHaveCount(0);
  await page.locator('#purchasing-update').getByLabel('Status', { exact: true }).selectOption('pending');
  await page.getByRole('button', { name: 'Save changes' }).click();
  await expect(page.getByRole('status')).toContainText('You do not have permission');
  expect(payload).toEqual({ status: 'pending', payment_terms: 'net_30' });
});

test('vendor creation omits blank optional contacts and refreshes saved records', async ({ page }) => {
  let payload;
  await page.route('**/api/purchasing/**', async route => {
    if (route.request().method() === 'GET') return route.fulfill({ json: { success: true, result: [] } });
    payload = route.request().postDataJSON();
    await route.fulfill({ json: { success: true, result: { id: 'v1' } } });
  });
  await page.goto('/modules/purchasing');
  await page.getByRole('button', { name: 'Vendors', exact: true }).click();
  await page.getByRole('button', { name: 'New vendor' }).click();
  for (const [label, value] of [['Vendor code', 'V-1'], ['Vendor name', 'Supplier'], ['Address line 1', '1 Main St'], ['City', 'Chennai'], ['Country', 'India']]) {
    await page.getByLabel(label, { exact: true }).fill(value);
  }
  await page.getByRole('button', { name: 'Create vendor' }).click();
  await expect(page.getByRole('status')).toHaveText('Saved successfully. Record ID: v1');
  expect(payload).toEqual({ vendor_id: 'V-1', name: 'Supplier', addr_1: '1 Main St', city: 'Chennai', country: 'India', status: 'active', payment_terms: 'net_30' });
  await expect(page.locator('#purchasing-list')).toBeVisible();
  await expect(page.locator('#vendors-previous')).toBeDisabled();
});
