'use strict';

const fs = require('node:fs');
const path = require('node:path');
const { parseEnv } = require('node:util');

function readBrowserSettings(root, environment) {
  const defaults = parseEnv(fs.readFileSync(path.join(root, '.env.example'), 'utf8'));
  const privateFile = path.join(root, '.env');
  const settings = { ...defaults, ...(fs.existsSync(privateFile) ? parseEnv(fs.readFileSync(privateFile, 'utf8')) : {}) };
  for (const [key, value] of Object.entries(environment)) {
    if (value !== undefined) settings[key] = value;
  }
  return settings;
}

function loadBrowserBasePath(root = path.resolve(__dirname, '../..'), environment = process.env) {
  const value = readBrowserSettings(root, environment).BROWSER_TEST_BASE_PATH;
  if (typeof value !== 'string' || !value.startsWith('/') || value === '/' || value.includes('//')
      || /[\\\x00-\x20\x7f?#]/.test(value) || /(^|\/)\.\.?($|\/)/.test(value)) {
    throw new Error('BROWSER_TEST_BASE_PATH must be a non-root URL path without a host, query or traversal segments.');
  }
  return value.replace(/\/$/, '');
}

function loadRemoteBrowserSettings(root = path.resolve(__dirname, '../..'), environment = process.env) {
  const settings = readBrowserSettings(root, environment);
  const value = settings.BROWSER_TEST_BASE_URL;
  const message = 'Set BROWSER_TEST_BASE_URL to the deployed application HTTP(S) URL, including its directory, without credentials, query or fragment.';
  if (typeof value !== 'string' || !/^https?:\/\//i.test(value) || /[\\\x00-\x20\x7f?#]/.test(value)) {
    throw new Error(message);
  }
  let url;
  try {
    url = new URL(value);
  } catch (error) {
    if (!(error instanceof TypeError)) throw error;
    throw new Error(message);
  }
  if (url.username || url.password) throw new Error(message);
  url.pathname = url.pathname.replace(/\/?$/, '/');
  const company = settings.BROWSER_TEST_COMPANY ?? '';
  const username = settings.BROWSER_TEST_USERNAME ?? '';
  const password = settings.BROWSER_TEST_PASSWORD ?? '';
  if ([company, username, password].some(Boolean) && ![company, username, password].every(Boolean)) {
    throw new Error('Set BROWSER_TEST_COMPANY, BROWSER_TEST_USERNAME and BROWSER_TEST_PASSWORD together, or leave all three blank for anonymous smoke tests.');
  }
  const ignoreHTTPS = settings.BROWSER_TEST_IGNORE_HTTPS_ERRORS ?? 'false';
  if (ignoreHTTPS !== 'true' && ignoreHTTPS !== 'false') {
    throw new Error('BROWSER_TEST_IGNORE_HTTPS_ERRORS must be exactly true or false.');
  }
  return { baseURL: url.href, company, username, password, ignoreHTTPSErrors: ignoreHTTPS === 'true' };
}

module.exports = { loadBrowserBasePath, loadRemoteBrowserSettings };
