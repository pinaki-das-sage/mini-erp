'use strict';

const fs = require('node:fs');
const path = require('node:path');
const { test, expect } = require('@playwright/test');

const root = path.resolve(__dirname, '../..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');

async function openFilters(page, range = '') {
  const dates = read('views/sales/documents/date_range.tpl')
    .replace('{$data.filters.date_range}', range);
  await page.setContent(`<main><div class="sales-document sales-list-page">
    <div class="panel sales-filter-panel"><form><div class="sales-filter-grid">
      <label>Quote number<input name="quote_number" value="SQ-001"></label>
      <label>Customer code<input name="customer_code" value="CUST-001"></label>
      <label>Status<select name="status"><option>Submitted</option></select></label>
      ${dates}<div class="sales-filter-actions"><button type="submit">Search</button></div>
    </div></form></div></div></main>`);
  for (const file of ['public/assets/app.css', 'public/assets/sales/documents.css', 'public/assets/sales/date-range.css']) {
    await page.addStyleTag({ content: read(file) });
  }
  await page.addScriptTag({ content: read('public/assets/sales/date-range.js') });
  await page.evaluate(() => {
    window.savedQuery = null;
    document.querySelector('form').addEventListener('submit', event => {
      if (!event.defaultPrevented) window.savedQuery = Object.fromEntries(new FormData(event.target));
      event.preventDefault();
    });
  });
}

test('date filters preserve existing query fields and submit an ISO range', async ({ page }) => {
  await openFilters(page);
  await page.locator('[data-date-text="from"]').fill('24-11-2026');
  await page.locator('[data-date-text="to"]').fill('25-11-2026');
  await page.getByRole('button', { name: 'Search', exact: true }).click();
  expect(await page.evaluate(() => window.savedQuery)).toEqual({
    quote_number: 'SQ-001', customer_code: 'CUST-001', status: 'Submitted',
    date_range: '2026-11-24 to 2026-11-25',
  });
});

test('bookmarked ranges restore both visible dates, including legacy separators', async ({ page }) => {
  for (const range of ['2026-11-24 to 2026-11-25', '2026-11-24 - 2026-11-25', '2026-11-24 \u2013 2026-11-25', '2026-11-24 \u2014 2026-11-25']) {
    await openFilters(page, range);
    await expect(page.locator('[data-date-text="from"]')).toHaveValue('24-11-2026');
    await expect(page.locator('[data-date-text="to"]')).toHaveValue('25-11-2026');
  }
});

test('one date means that single day; clearing both removes the range', async ({ page }) => {
  for (const side of ['from', 'to']) {
    await openFilters(page);
    await page.locator(`[data-date-text="${side}"]`).fill('24-11-2026');
    await page.getByRole('button', { name: 'Search', exact: true }).click();
    expect(await page.evaluate(() => window.savedQuery.date_range)).toBe('2026-11-24 to 2026-11-24');
  }
  await openFilters(page, '2026-11-24 to 2026-11-25');
  await page.locator('[data-date-text="from"]').fill('');
  await page.locator('[data-date-text="to"]').fill('');
  await page.getByRole('button', { name: 'Search', exact: true }).click();
  expect(await page.evaluate(() => window.savedQuery.date_range)).toBe('');
});

test('invalid dates and reversed ranges cannot submit and can be corrected', async ({ page }) => {
  for (const [from, to] of [['31-02-2026', ''], ['2026-11-24', ''], ['25-11-2026', '24-11-2026']]) {
    await openFilters(page);
    await page.locator('[data-date-text="from"]').fill(from);
    await page.locator('[data-date-text="to"]').fill(to);
    await page.getByRole('button', { name: 'Search', exact: true }).click();
    expect(await page.evaluate(() => window.savedQuery)).toBeNull();
    await page.locator('[data-date-text="from"]').fill('24-11-2026');
    await page.locator('[data-date-text="to"]').fill('25-11-2026');
    await page.getByRole('button', { name: 'Search', exact: true }).click();
    expect(await page.evaluate(() => window.savedQuery.date_range)).toBe('2026-11-24 to 2026-11-25');
  }
});

test('calendar changes update the visible fields and submission', async ({ page }) => {
  await openFilters(page);
  await page.locator('[data-date-calendar="from"]').evaluate(input => {
    input.value = '2026-11-24'; input.dispatchEvent(new Event('change'));
  });
  await expect(page.locator('[data-date-text="from"]')).toHaveValue('24-11-2026');
  await page.getByRole('button', { name: 'Search', exact: true }).click();
  expect(await page.evaluate(() => window.savedQuery.date_range)).toBe('2026-11-24 to 2026-11-24');
});

test('calendar buttons are inside their fields on desktop and mobile', async ({ page }) => {
  for (const width of [1440, 390]) {
    await page.setViewportSize({ width, height: 900 });
    await openFilters(page);
    for (const side of ['from', 'to']) {
      const input = await page.locator(`[data-date-text="${side}"]`).boundingBox();
      const button = await page.locator(`[data-date-open="${side}"]`).boundingBox();
      expect(button.x).toBeGreaterThanOrEqual(input.x);
      expect(button.x + button.width).toBeLessThanOrEqual(input.x + input.width + 1);
      expect(button.y).toBeGreaterThanOrEqual(input.y);
      expect(button.y + button.height).toBeLessThanOrEqual(input.y + input.height + 1);
    }
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
  }
});
