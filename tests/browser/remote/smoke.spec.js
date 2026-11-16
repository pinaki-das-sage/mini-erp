'use strict';

const { test, expect } = require('@playwright/test');

test('deployed login, assets and session cookie use the configured application directory', async ({ page, request, baseURL }) => {
  const mount = new URL(baseURL).pathname;
  const response = await page.goto(baseURL);
  expect(response.status()).toBe(200);
  await expect(page).toHaveURL(baseURL);
  await expect(page.getByRole('heading', { name: 'Sign in to Mini ERP' })).toBeVisible();
  await expect(page.getByLabel('Company', { exact: true })).toBeVisible();
  await expect(page.getByLabel('Username', { exact: true })).toBeVisible();
  await expect(page.getByLabel('Password', { exact: true })).toHaveAttribute('type', 'password');
  await expect(page.locator('form[action]')).toHaveAttribute('action', `${mount}login`);
  const cookieMetadata = (await page.context().cookies()).map(({ name, path, httpOnly, sameSite, secure }) => ({
    name, path, httpOnly, sameSite, secure,
  }));
  expect(cookieMetadata).toEqual(expect.arrayContaining([
    expect.objectContaining({
      name: 'mini_erp_session', path: mount, httpOnly: true, sameSite: 'Lax',
      secure: new URL(baseURL).protocol === 'https:',
    }),
  ]));
  const css = await request.get('assets/app.css');
  expect(css.status()).toBe(200);
  expect(css.headers()['content-type']).toContain('text/css');
  const js = await request.get('assets/app.js');
  expect(js.status()).toBe(200);
  expect(js.headers()['content-type']).toMatch(/(?:application|text)\/javascript/);
});

test('deployed liveness does not claim Oracle readiness', async ({ request }) => {
  const response = await request.get('health');
  expect(response.status()).toBe(200);
  expect((await response.json()).result).toMatchObject({ status: 'alive', database: 'not_checked' });
});

test('deployed Oracle foundation is ready', async ({ request }) => {
  const response = await request.get('ready');
  expect(response.status(), 'Remote /ready failed: check Oracle configuration, migrations and bootstrap.').toBe(200);
  expect((await response.json()).result.foundation).toBe('ready');
});

test('anonymous module access and private checkout paths are denied', async ({ request }) => {
  for (const module of ['inventory', 'purchasing', 'sales', 'logistics']) {
    expect((await request.get(`modules/${module}`)).status()).toBe(403);
  }
  for (const path of ['../.env', '../config.ini', '../src/shared/Kernel.php', '../vendor/autoload.php']) {
    const response = await request.get(path);
    expect([403, 404], `Private path must not be served: ${path}`).toContain(response.status());
  }
});

test('configured account signs in to the deployed company and signs out', async ({ page, baseURL }) => {
  const { BROWSER_TEST_COMPANY: company, BROWSER_TEST_USERNAME: username, BROWSER_TEST_PASSWORD: password } = process.env;
  test.skip(!username, 'Remote login is not verified: set BROWSER_TEST_COMPANY, BROWSER_TEST_USERNAME and BROWSER_TEST_PASSWORD privately.');
  await page.goto(baseURL);
  await expect(page).toHaveURL(baseURL);
  await expect(page.locator('form[action]')).toHaveAttribute('action', `${new URL(baseURL).pathname}login`);
  await expect(page.locator('form[action]')).toHaveAttribute('method', 'post');
  await page.getByLabel('Company', { exact: true }).fill(company);
  await page.getByLabel('Username', { exact: true }).fill(username);
  const passwordInput = page.getByLabel('Password', { exact: true });
  await expect(passwordInput).toBeVisible();
  // Avoid exposing the password in Playwright's fill failure logs.
  await passwordInput.evaluate((input, value) => { input.value = value; }, password);
  await page.getByRole('button', { name: 'Sign in', exact: true }).click();
  await expect(page).toHaveURL(baseURL);
  await expect(page.locator('.user-label')).toContainText(username.trim().toLowerCase());
  await expect(page.getByRole('navigation', { name: 'Main navigation' })).toBeVisible();
  await page.getByRole('button', { name: 'Sign out', exact: true }).click();
  await expect(page).toHaveURL(baseURL);
  await expect(page.getByLabel('Company', { exact: true })).toBeVisible();
});
