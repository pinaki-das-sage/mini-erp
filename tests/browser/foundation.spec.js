'use strict';

const { test, expect } = require('@playwright/test');

test('company login renders and anonymous module access is denied', async ({ page, request }) => {
  await page.goto('/');
  await expect(page.getByRole('heading', { name: 'Sign in to Mini ERP' })).toBeVisible();
  await expect(page.getByLabel('Company')).toHaveValue('');
  for (const module of ['inventory', 'purchasing', 'sales', 'logistics']) {
    expect((await request.get(`/modules/${module}`)).status()).toBe(403);
  }
});

test('liveness explicitly does not prove database readiness', async ({ request }) => {
  const response = await request.get('/health');
  expect(response.status()).toBe(200);
  expect((await response.json()).result.database).toBe('not_checked');
});

test('login form and browser session have security controls', async ({ page }) => {
  const response = await page.goto('/login');
  await expect(page.getByLabel('Username')).toBeVisible();
  await expect(page.getByLabel('Password')).toHaveAttribute('type', 'password');
  expect(await page.locator('[name="csrf_token"]').inputValue()).toMatch(/^[a-f0-9]{64}$/);
  expect(response.headers()['content-security-policy']).toContain("frame-ancestors 'none'");
  const cookies = await page.context().cookies();
  expect(cookies.find((cookie) => cookie.name === 'mini_erp_session')).toMatchObject({ httpOnly: true, sameSite: 'Lax' });
});

test('direct login post without csrf is rejected before database access', async ({ request }) => {
  const response = await request.post('/login', { form: { username: 'buyer', password: 'not-a-real-password' } });
  expect(response.status()).toBe(403);
  expect(await response.text()).toContain('Invalid CSRF token');
});

test('forged actor id cannot authenticate a direct write', async ({ request }) => {
  const login = await request.get('/login');
  const token = (await login.text()).match(/name="csrf_token" value="([a-f0-9]+)"/)[1];
  const response = await request.post('/api/note', {
    headers: { 'X-CSRF-Token': token },
    data: { actor_id: 'lab-supervisor', action_reference: 'browser-probe', text: 'new', expected_version: 1 },
  });
  expect(response.status()).toBe(403);
  expect((await response.json()).error.message).toContain('Sign in');
});

test('malformed json is a controlled validation failure', async ({ request }) => {
  const response = await request.post('/api/note', {
    headers: { 'Content-Type': 'application/json' },
    data: '{"broken":',
  });
  expect(response.status()).toBe(400);
  expect((await response.json()).error.code).toBe('VALIDATION_ERROR');
});

test('an empty json object reaches normal csrf validation', async ({ request }) => {
  const response = await request.post('/api/note', { data: {} });
  expect(response.status()).toBe(403);
  expect((await response.json()).error.code).toBe('FORBIDDEN');
});

test('private configuration and application sources are not served', async ({ request }) => {
  for (const path of ['/config.ini', '/.env', '/bootstrap.php', '/vendor/autoload.php']) {
    const response = await request.get(path);
    expect(response.status()).toBe(404);
    expect((await response.json()).error.code).toBe('NOT_FOUND');
  }
});
