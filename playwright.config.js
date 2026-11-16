'use strict';

const { defineConfig } = require('@playwright/test');
const { loadRemoteBrowserSettings } = require('./tests/browser/config.cjs');

const settings = loadRemoteBrowserSettings();
process.env.BROWSER_TEST_COMPANY = settings.company;
process.env.BROWSER_TEST_USERNAME = settings.username;
process.env.BROWSER_TEST_PASSWORD = settings.password;

if (settings.ignoreHTTPSErrors) {
  console.warn('WARNING: BROWSER_TEST_IGNORE_HTTPS_ERRORS=true disables certificate verification for remote browser/API tests. Use only on a trusted training server; avoid sensitive credentials.');
}

module.exports = defineConfig({
  testDir: './tests/browser/remote',
  workers: 1,
  use: {
    baseURL: settings.baseURL,
    ignoreHTTPSErrors: settings.ignoreHTTPSErrors,
    browserName: 'chromium',
    trace: 'off',
    screenshot: 'off',
    video: 'off',
  },
});
