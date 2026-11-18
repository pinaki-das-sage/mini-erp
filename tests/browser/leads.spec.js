import { test, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import path from 'node:path';

const base = process.env.BROWSER_TEST_BASE_PATH;
const origin = 'http://127.0.0.1:8098';
const render = (view) => execFileSync(process.env.PHP_BINARY || 'php', [
  path.resolve('tests/browser/render.php'), view, base,
], { encoding: 'utf8' });

test('create lead sends JSON and CSRF, preserving the reference after an uncertain result', async ({ page }) => {
  await page.route(`**${base}/sales/leads/new`, route => route.fulfill({
    contentType: 'text/html', body: render('lead-new'),
  }));
  const requests = [];
  await page.route(`**${base}/api/sales/leads`, async route => {
    requests.push(route.request().postDataJSON());
    expect(route.request().headers()['x-csrf-token']).toBe('browser-fixture-token');
    if (requests.length === 1) {
      await route.abort();
    } else {
      await route.fulfill({ status: 400, contentType: 'application/json',
        body: JSON.stringify({ success: false, error: { message: 'Example validation error.' } }) });
    }
  });
  await page.goto(`${origin}${base}/sales/leads/new`);
  await page.getByLabel('Contact name').fill('Alex Smith');
  await page.getByLabel('Phone', { exact: true }).fill('+44 00123');
  await page.getByRole('button', { name: 'Create lead', exact: true }).click();
  await expect(page.getByRole('status')).toContainText('No confirmed response');
  await page.getByLabel('Contact name').fill('Changed');
  await page.getByRole('button', { name: 'Create lead', exact: true }).click();
  await expect(page.getByRole('status')).toContainText('unchanged');
  expect(requests).toHaveLength(1);
  await page.getByLabel('Contact name').fill('Alex Smith');
  await page.getByRole('button', { name: 'Create lead', exact: true }).click();
  await expect(page.getByRole('status')).toHaveText('Example validation error.');
  expect(requests[1]).toEqual(requests[0]);
  expect(requests[0]).toMatchObject({ operation: 'create', contact_name: 'Alex Smith', phone: '+44 00123' });
  expect(requests[0].action_reference).toMatch(/^[a-f0-9]{32}$/);
});

test('edit lead sends a numeric version and redirects to its mounted detail page', async ({ page }) => {
  await page.route(`**${base}/sales/leads/*/edit`, route => route.fulfill({
    contentType: 'text/html', body: render('lead-edit'),
  }));
  await page.route(`**${base}/sales/leads/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa`, route => route.fulfill({
    contentType: 'text/html', body: render('lead-detail'),
  }));
  let payload;
  await page.route(`**${base}/api/sales/leads`, async route => {
    payload = route.request().postDataJSON();
    await route.fulfill({ contentType: 'application/json', body: JSON.stringify({
      success: true, result: { id: 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', current_version: 3 },
    }) });
  });
  await page.goto(`${origin}${base}/sales/leads/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa/edit`);
  await page.getByRole('combobox', { name: 'Status', exact: true }).selectOption('qualified');
  await page.getByRole('button', { name: 'Save lead' }).click();
  await expect(page).toHaveURL(`${origin}${base}/sales/leads/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa`);
  expect(payload).toMatchObject({ operation: 'update', expected_version: 2, status: 'qualified' });
});

test('archive sends an explicit versioned action and shows stale-version rejection', async ({ page }) => {
  await page.route(`**${base}/sales/leads/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa`, route => route.fulfill({
    contentType: 'text/html', body: render('lead-detail'),
  }));
  let payload;
  await page.route(`**${base}/api/sales/leads`, async route => {
    payload = route.request().postDataJSON();
    await route.fulfill({ status: 409, contentType: 'application/json',
      body: JSON.stringify({ success: false, error: { message: 'Lead changed. Refresh before starting a new action.' } }) });
  });
  await page.goto(`${origin}${base}/sales/leads/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa`);
  await page.getByRole('button', { name: 'Archive lead' }).click();
  await expect(page.getByRole('status')).toContainText('Lead changed.');
  expect(payload).toMatchObject({ operation: 'archive', expected_version: 2, id: 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa' });
});
