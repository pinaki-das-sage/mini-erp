import { test, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import path from 'node:path';

const base = process.env.BROWSER_TEST_BASE_PATH;
const origin = 'http://127.0.0.1:8098';
const render = (view) => execFileSync(process.env.PHP_BINARY || 'php', [
  path.resolve('tests/browser/render.php'), view, base,
], { encoding: 'utf8' });

test('Apache-style nested landing loads real assets and keeps login under its prefix', async ({ page, request }) => {
  const response = await page.goto(`${origin}${base}/`);
  expect(response.status()).toBe(200);
  expect(await page.context().cookies()).toEqual(expect.arrayContaining([
    expect.objectContaining({ name: 'mini_erp_session', path: `${base}/` }),
  ]));
  await expect(page.getByLabel('Company')).toBeVisible();
  await expect(page.locator('form[action]')).toHaveAttribute('action', `${base}/login`);
  const css = await request.get(`${origin}${base}/assets/app.css`);
  expect(css.headers()['content-type']).toContain('text/css');
  const js = await request.get(`${origin}${base}/assets/app.js`);
  expect(js.headers()['content-type']).toContain('application/javascript');
  const health = await request.get(`${origin}${base}/health`);
  expect((await health.json()).result.status).toBe('alive');
  const login = await request.post(`${origin}${base}/login`, { form: { csrf_token: 'invalid' } });
  expect(login.status()).toBe(403);
  expect(await login.text()).toContain('Company');
  expect((await request.get(`${origin}/health`)).status()).toBe(404);
});

test('nested Settings submits and redirects inside the application', async ({ page }) => {
  await page.route(`**${base}/settings/users/new`, (route) => route.fulfill({
    contentType: 'text/html', body: render('user-new'),
  }));
  await page.route(`**${base}/settings/users/u`, (route) => route.fulfill({
    contentType: 'text/html', body: render('user-edit'),
  }));
  let payload;
  await page.route(`**${base}/api/settings/users`, async (route) => {
    payload = route.request().postDataJSON();
    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({ success: true, result: { id: 'u' } }),
    });
  });
  await page.goto(`${origin}${base}/settings/users/new`);
  await page.getByLabel('Username', { exact: true }).fill('graduate');
  await page.getByLabel('Display name').fill('Graduate');
  await page.getByLabel('Temporary password').fill('fixture-temporary-password');
  await page.getByRole('button', { name: 'Create user' }).click();
  await expect.poll(() => payload?.username).toBe('graduate');
  await expect(page).toHaveURL(`${origin}${base}/settings/users/u`);
  await expect(page.getByRole('link', { name: 'Company', exact: true })).toHaveAttribute('href', `${base}/settings/company`);
});

test('nested note save uses prefixed API and updates version', async ({ page }) => {
  await page.route(`**${base}/example`, (route) => route.fulfill({
    contentType: 'text/html', body: render('example'),
  }));
  let payload;
  await page.route(`**${base}/api/note`, async (route) => {
    payload = route.request().postDataJSON();
    await route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({ success: true, current_version: 2 }),
    });
  });
  await page.goto(`${origin}${base}/example`);
  await page.getByLabel('Training note').fill('Updated nested note');
  await page.getByRole('button', { name: 'Save note' }).click();
  await expect(page.getByRole('status')).toHaveText('Saved. Version 2.');
  expect(payload.text).toBe('Updated nested note');
});
