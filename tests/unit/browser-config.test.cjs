'use strict';

const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const { loadBrowserBasePath, loadRemoteBrowserSettings } = require('../browser/config.cjs');

function withFixture(check) {
  const root = fs.mkdtempSync(path.join(os.tmpdir(), 'mini-erp-browser-config-'));
  fs.copyFileSync(path.resolve(__dirname, '../../.env.example'), path.join(root, '.env.example'));
  try {
    check(root);
  } finally {
    for (const name of ['.env', '.env.example']) {
      const file = path.join(root, name);
      if (fs.existsSync(file)) fs.unlinkSync(file);
    }
    fs.rmdirSync(root);
  }
}

test('browser path defaults from the template without requiring a private .env', () => {
  withFixture((root) => {
    assert.equal(loadBrowserBasePath(root, {}), '/mini-erp/public');
  });
});

test('private browser setting and environment overrides preserve production path independence', () => {
  withFixture((root) => {
    fs.writeFileSync(path.join(root, '.env'), 'APP_BASE_PATH=/production\nBROWSER_TEST_BASE_PATH="/training/course/"\n');
    assert.equal(loadBrowserBasePath(root, {}), '/training/course');
    assert.equal(loadBrowserBasePath(root, { BROWSER_TEST_BASE_PATH: '/another/course/' }), '/another/course');
    fs.writeFileSync(path.join(root, '.env'), 'APP_BASE_PATH=/production\n');
    assert.equal(loadBrowserBasePath(root, {}), '/mini-erp/public');
  });
});

test('invalid and site-root browser settings fail explicitly', () => {
  withFixture((root) => {
    for (const value of ['~/public_html/app', '/', '', '//other-host', '/app?query=1', '/app/../other']) {
      assert.throws(() => loadBrowserBasePath(root, { BROWSER_TEST_BASE_PATH: value }), /BROWSER_TEST_BASE_PATH/);
    }
  });
});

test('remote URL is required instead of silently testing localhost', () => {
  withFixture((root) => {
    assert.throws(() => loadRemoteBrowserSettings(root, {}), /BROWSER_TEST_BASE_URL/);
  });
});

test('remote settings retain the deployed directory and load only browser credentials', () => {
  withFixture((root) => {
    fs.writeFileSync(path.join(root, '.env'),
      'BROWSER_TEST_BASE_URL=https://example.test/users/training/public\n'
      + 'BROWSER_TEST_COMPANY=SECOND\nBROWSER_TEST_USERNAME=buyer\nBROWSER_TEST_PASSWORD=fixture-only-password\n'
      + 'ORACLE_DB_PASSWORD=not-a-browser-setting\nAPP_BASE_PATH=/another/deployment\n');
    assert.deepEqual(loadRemoteBrowserSettings(root, {}), {
      baseURL: 'https://example.test/users/training/public/',
      company: 'SECOND', username: 'buyer', password: 'fixture-only-password',
      ignoreHTTPSErrors: false,
    });
    assert.equal(loadRemoteBrowserSettings(root, {
      BROWSER_TEST_BASE_URL: 'https://other.example.test/renamed/',
    }).baseURL, 'https://other.example.test/renamed/');
  });
});

test('remote URLs reject credentials, query strings, fragments and non-web protocols', () => {
  withFixture((root) => {
    for (const value of ['~/public_html/app', 'file:///app', 'https://user:password@example.test/app',
      'https://example.test/app?query=1', 'https://example.test/app#fragment', 'https://example.test/app\\other']) {
      assert.throws(() => loadRemoteBrowserSettings(root, { BROWSER_TEST_BASE_URL: value }), /BROWSER_TEST_BASE_URL/);
    }
    assert.throws(() => loadRemoteBrowserSettings(root, {
      BROWSER_TEST_BASE_URL: 'https://example.test/app/', BROWSER_TEST_USERNAME: 'buyer',
    }), /BROWSER_TEST_COMPANY.*BROWSER_TEST_USERNAME.*BROWSER_TEST_PASSWORD/);
  });
});

test('remote TLS bypass is opt-in, supports private overrides and rejects invalid flags', () => {
  withFixture((root) => {
    const environment = { BROWSER_TEST_BASE_URL: 'https://example.test/app/' };
    assert.equal(loadRemoteBrowserSettings(root, environment).ignoreHTTPSErrors, false);
    fs.writeFileSync(path.join(root, '.env'), 'BROWSER_TEST_IGNORE_HTTPS_ERRORS=true\n');
    assert.equal(loadRemoteBrowserSettings(root, environment).ignoreHTTPSErrors, true);
    assert.equal(loadRemoteBrowserSettings(root, {
      ...environment, BROWSER_TEST_IGNORE_HTTPS_ERRORS: 'false',
    }).ignoreHTTPSErrors, false);
    for (const value of ['', '1', 'yes', 'FALSE']) {
      assert.throws(() => loadRemoteBrowserSettings(root, {
        ...environment, BROWSER_TEST_IGNORE_HTTPS_ERRORS: value,
      }), /BROWSER_TEST_IGNORE_HTTPS_ERRORS/);
    }
  });
});

test('default Playwright config selects real remote tests without local PHP servers', () => {
  const values = {
    BROWSER_TEST_BASE_URL: 'https://example.test/users/training/public/',
    BROWSER_TEST_BASE_PATH: '/local/fixture',
    BROWSER_TEST_COMPANY: '', BROWSER_TEST_USERNAME: '', BROWSER_TEST_PASSWORD: '',
    BROWSER_TEST_IGNORE_HTTPS_ERRORS: 'false',
  };
  const previous = Object.fromEntries(Object.keys(values).map(key => [key, process.env[key]]));
  const configPath = require.resolve('../../playwright.config.js');
  try {
    Object.assign(process.env, values);
    delete require.cache[configPath];
    const config = require(configPath);
    assert.equal(config.use.baseURL, values.BROWSER_TEST_BASE_URL);
    assert.equal(config.webServer, undefined);
    assert.equal(config.testDir, './tests/browser/remote');
    assert.equal(config.use.ignoreHTTPSErrors, false);
    process.env.BROWSER_TEST_IGNORE_HTTPS_ERRORS = 'true';
    delete require.cache[configPath];
    assert.equal(require(configPath).use.ignoreHTTPSErrors, true);
  } finally {
    delete require.cache[configPath];
    for (const [key, value] of Object.entries(previous)) {
      if (value === undefined) delete process.env[key];
      else process.env[key] = value;
    }
  }
});
