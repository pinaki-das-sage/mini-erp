import { test, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import path from 'node:path';

const render = (view) => execFileSync(process.env.PHP_BINARY || 'php', [
  path.resolve('tests/browser/render.php'), view,
], { encoding: 'utf8' });

test('create user submits JSON with explicit non-admin flag and shows errors', async ({ page }) => {
  await page.route('**/settings/users/new', (route) => route.fulfill({
    contentType: 'text/html', body: render('user-new'),
  }));
  let payload;
  let csrf;
  await page.route('**/api/settings/users', async (route) => {
    payload = route.request().postDataJSON();
    csrf = route.request().headers()['x-csrf-token'];
    await route.fulfill({
      status: 409, contentType: 'application/json',
      body: JSON.stringify({ success: false, error: { message: 'Username already exists.' } }),
    });
  });
  await page.goto('/settings/users/new');
  await page.getByLabel('Username', { exact: true }).fill('graduate');
  await page.getByLabel('Display name').fill('Graduate');
  await page.getByLabel('Temporary password').fill('private-temporary-password');
  await page.getByRole('button', { name: 'Create user' }).click();
  await expect.poll(() => payload).toEqual({
    operation: 'create', username: 'graduate', display_name: 'Graduate',
    password: 'private-temporary-password', is_admin: false,
  });
  expect(csrf).toBe('browser-fixture-token');
  await expect(page.getByRole('status')).toHaveText('Username already exists.');
  await expect(page.getByRole('button', { name: 'Create user' })).toBeEnabled();
});

test('permission editor submits only selected CRUD and named actions with version', async ({ page }) => {
  await page.route('**/settings/users/u', (route) => route.fulfill({
    contentType: 'text/html', body: render('user-edit'),
  }));
  let payload;
  await page.route('**/api/settings/users', async (route) => {
    payload = route.request().postDataJSON();
    await route.fulfill({
      status: 409, contentType: 'application/json',
      body: JSON.stringify({ success: false, error: { message: 'User changed. Refresh before saving.' } }),
    });
  });
  await page.goto('/settings/users/u');
  await page.getByLabel('Post', { exact: true }).check();
  await page.getByRole('button', { name: 'Save permissions' }).click();
  await expect.poll(() => payload).toEqual({
    operation: 'permissions', id: 'u', expected_version: 3,
    grants: ['inventory.products:read', 'inventory.products:post'],
  });
  await expect(page.locator('[data-operation="permissions"] .form-status')).toHaveText('User changed. Refresh before saving.');
  await expect(page.getByLabel('Create', { exact: true })).not.toBeChecked();
  await expect(page.getByLabel('Delete', { exact: true })).toHaveCount(0);
});

test('users can be searched and filtered without a neutral note form', async ({ page }) => {
  await page.route('**/settings/users', (route) => route.fulfill({
    contentType: 'text/html', body: render('users'),
  }));
  await page.goto('/settings/users');
  await page.getByLabel('Status', { exact: true }).selectOption('1');
  await expect(page.locator('#users-table tbody tr:visible')).toHaveCount(1);
  await page.getByLabel('Search users').fill('missing');
  await expect(page.locator('#users-table tbody tr:visible')).toHaveCount(0);
});

test('without JavaScript password form fallback uses POST, never URL parameters', async ({ browser, baseURL }) => {
  const context = await browser.newContext({ javaScriptEnabled: false, baseURL });
  try {
    const page = await context.newPage();
    await page.route('**/settings/users/new', (route) => route.fulfill({
      contentType: 'text/html', body: render('user-new'),
    }));
    await page.route('**/api/settings/users', (route) => route.fulfill({
      status: 400, contentType: 'text/plain', body: 'Settings forms require JavaScript.',
    }));
    await page.goto('/settings/users/new');
    await page.getByLabel('Username', { exact: true }).fill('graduate');
    await page.getByLabel('Display name').fill('Graduate');
    await page.getByLabel('Temporary password').fill('fixture-temporary-password');
    const submitted = page.waitForRequest('**/api/settings/users');
    await page.getByRole('button', { name: 'Create user' }).click();
    const request = await submitted;
    expect(request.method()).toBe('POST');
    expect(request.url()).not.toContain('password');
    expect(request.postData()).toContain('password=fixture-temporary-password');
  } finally {
    await context.close();
  }
});
