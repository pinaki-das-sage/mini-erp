'use strict';

const fs = require('node:fs');
const path = require('node:path');
const { test, expect } = require('@playwright/test');

const script = fs.readFileSync(path.resolve(__dirname, '../../public/assets/sales/document-navigation.js'), 'utf8');
const origin = 'https://sales-navigation.test';
const base = '/training/modules/sales';
const filters = '?customer_code=CUST-001&status=Submitted&date_range=2026-11-24+to+2026-11-25&page=2';

async function serve(page) {
  await page.route(`${origin}/**`, route => {
    const url = new URL(route.request().url());
    const match = url.pathname.match(/^\/training\/modules\/sales\/(quotes|orders|invoices)(?:\/([^/]+))?$/);
    if (!match) return route.fulfill({ status: 404, body: 'Not found' });
    const [, kind, number] = match;
    const list = `${base}/${kind}`;
    const content = number
      ? `<h1>${number}</h1><a href="${list}" data-document-back>Back to ${kind}</a>`
      : `<div data-document-list="${list}"><table><tbody>${['044', '061', '062', '081']
        .map(id => `<tr><td><a href="${list}/DOC-${id}" data-document-link>DOC-${id}</a></td><td>Saved data ${id}</td></tr>`).join('')}
        </tbody></table><a href="${list}?page=3" rel="next">Next</a></div>`;
    return route.fulfill({ contentType: 'text/html', body: `<!doctype html><html><body>${content}<script>${script}</script></body></html>` });
  });
}

for (const kind of ['quotes', 'orders', 'invoices']) {
  test(`${kind}: return preserves filters/page and moves the viewed row first`, async ({ page }) => {
    await serve(page);
    const list = `${origin}${base}/${kind}${filters}`;
    await page.goto(list);
    await expect(page.locator('tbody tr').first()).toContainText('DOC-044');
    await page.getByRole('link', { name: 'DOC-081', exact: true }).click();
    await expect(page.getByRole('heading')).toHaveText('DOC-081');
    await expect(page.locator('[data-document-back]')).toHaveAttribute('href', `${base}/${kind}${filters}`);
    await page.locator('[data-document-back]').click();
    await expect(page).toHaveURL(list);
    await expect(page.locator('tbody tr').first()).toHaveText('DOC-081Saved data 081');
    await expect(page.locator('tbody tr')).toHaveCount(4);
    expect(await page.locator('[data-document-link]').allTextContents()).toEqual(['DOC-081', 'DOC-044', 'DOC-061', 'DOC-062']);
    // The next viewed document replaces the previous choice, keeping the other rows.
    await page.getByRole('link', { name: 'DOC-061', exact: true }).click();
    await page.locator('[data-document-back]').click();
    await expect(page.locator('tbody tr').first()).toContainText('DOC-061');
  });
}

test('browser Back restores the viewed row; other filters and document types stay independent', async ({ page }) => {
  await serve(page);
  await page.goto(`${origin}${base}/quotes${filters}`);
  await page.getByRole('link', { name: 'DOC-081', exact: true }).click();
  await page.goBack();
  await expect(page.locator('tbody tr').first()).toContainText('DOC-081');
  await page.goto(`${origin}${base}/quotes?page=1`);
  await expect(page.locator('tbody tr').first()).toContainText('DOC-044');
  await page.goto(`${origin}${base}/orders${filters}`);
  await expect(page.locator('tbody tr').first()).toContainText('DOC-044');
});

test('direct detail visits and unavailable browser storage retain normal navigation', async ({ page }) => {
  await serve(page);
  await page.goto(`${origin}${base}/invoices/DOC-081`);
  await expect(page.locator('[data-document-back]')).toHaveAttribute('href', `${base}/invoices`);
  await page.addInitScript(() => {
    Storage.prototype.getItem = () => { throw new Error('Storage disabled'); };
    Storage.prototype.setItem = () => { throw new Error('Storage disabled'); };
  });
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  await page.goto(`${origin}${base}/invoices${filters}`);
  await page.getByRole('link', { name: 'DOC-081', exact: true }).click();
  await page.locator('[data-document-back]').click();
  await expect(page.locator('tbody tr')).toHaveCount(4);
  expect(errors).toEqual([]);
});

test('untrusted stored URLs cannot replace the Back link', async ({ page }) => {
  await serve(page);
  await page.goto(`${origin}${base}/quotes`);
  await page.evaluate(({ base }) => sessionStorage.setItem(`sales-document-navigation:${base}/quotes`, JSON.stringify({
    list: 'https://outside.test/phishing', detail: `${base}/quotes/DOC-081`, viewed: true,
  })), { base });
  await page.goto(`${origin}${base}/quotes/DOC-081`);
  await expect(page.locator('[data-document-back]')).toHaveAttribute('href', `${base}/quotes`);
});
