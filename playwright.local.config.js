'use strict';

const { defineConfig } = require('@playwright/test');
const { loadBrowserBasePath } = require('./tests/browser/config.cjs');

const browserBasePath = loadBrowserBasePath();
process.env.BROWSER_TEST_BASE_PATH = browserBasePath;

module.exports = defineConfig({
  testDir: './tests/browser',
  testIgnore: '**/remote/**',
  workers: 1,
  use: { baseURL: 'http://127.0.0.1:8097', browserName: 'chromium' },
  webServer: [{
    command: `${process.env.PHP_BINARY || 'php'} -S 127.0.0.1:8097 -t public public/router.php`,
    url: 'http://127.0.0.1:8097/health',
    reuseExistingServer: false,
    env: { APP_ENV: 'local', APP_MODE: 'standalone', APP_BASE_PATH: '/' },
  }, {
    command: `${process.env.PHP_BINARY || 'php'} -S 127.0.0.1:8098 -t public tests/browser/subdirectory-router.php`,
    port: 8098,
    reuseExistingServer: false,
    env: { APP_ENV: 'local', APP_MODE: 'standalone', APP_BASE_PATH: '', BROWSER_TEST_BASE_PATH: browserBasePath },
  }],
});
