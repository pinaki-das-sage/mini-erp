'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const collection = path.resolve(__dirname, '../../examples/bruno');
const read = name => fs.readFileSync(path.join(collection, name), 'utf8');
const block = (source, name) => {
  const match = source.match(new RegExp(`^${name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')} \\{\\n([\\s\\S]*?)^\\}`, 'm'));
  assert.ok(match, `Missing ${name} block`);
  return match[1];
};

test('login clears stale credentials and retains only a valid token in runtime variables', () => {
  const source = read('login.bru');
  const variables = new Map([['access_token', 'stale-token']]);
  const bru = {
    deleteVar: name => variables.delete(name),
    setVar: (name, value) => variables.set(name, value),
  };
  vm.runInNewContext(block(source, 'script:pre-request'), { bru });
  assert.equal(variables.has('access_token'), false);
  vm.runInNewContext(block(source, 'script:post-response'), {
    bru, res: { getStatus: () => 401, getBody: () => ({ success: false, result: null }) },
  });
  assert.equal(variables.has('access_token'), false);
  const token = 'a'.repeat(64);
  vm.runInNewContext(block(source, 'script:post-response'), {
    bru, res: { getStatus: () => 200, getBody: () => ({ success: true, result: { access_token: token } }) },
  });
  assert.equal(variables.get('access_token'), token);
  assert.throws(() => vm.runInNewContext(block(source, 'script:post-response'), {
    bru, res: { getStatus: () => 200, getBody: () => ({ success: true, result: { access_token: 'invalid' } }) },
  }), /valid bearer token/);
});

test('user requests match implemented routes and use runtime authentication and explicit versions', () => {
  const cases = [
    ['users-list.bru', 'get', '/users?page=1&page_size=25'],
    ['users-create.bru', 'post', '/users'],
    ['users-update.bru', 'patch', '/users/{{target_user_id}}'],
    ['users-permissions.bru', 'post', '/users/{{target_user_id}}/permissions'],
    ['users-reset-password.bru', 'post', '/users/{{target_user_id}}/reset-password'],
  ];
  for (const [file, method, route] of cases) {
    const source = read(file);
    assert.ok(block(source, method).includes(`url: {{base_url}}/api/v1${route}`), file);
    assert.ok(block(source, 'headers').includes('Authorization: Bearer {{access_token}}'), file);
    new vm.Script(block(source, 'tests'));
    assert.throws(() => vm.runInNewContext(block(source, 'script:pre-request'), {
      bru: { getVar: () => undefined, getEnvVar: () => undefined },
    }), /Run Login/);
    vm.runInNewContext(block(source, 'script:pre-request'), {
      bru: {
        getVar: name => name === 'access_token' ? 'a'.repeat(64) : 'fixture-user',
        getEnvVar: () => undefined,
        deleteVar: () => {},
      },
    });
    if (file !== 'users-list.bru' && file !== 'users-create.bru') {
      assert.throws(() => vm.runInNewContext(block(source, 'script:pre-request'), {
        bru: { getVar: name => name === 'access_token' ? 'a'.repeat(64) : undefined, getEnvVar: () => undefined },
      }), /explicitly set target_user_id/);
    }
    if (file === 'users-list.bru') continue;
    const body = JSON.parse(block(source, 'body:json').replaceAll('{{?Current user version}}', '2'));
    if (file !== 'users-create.bru') assert.equal(body.expected_version, 2, file);
    if (body.password !== undefined) assert.match(body.password, /^\{\{\?/, file);
    if (file === 'users-create.bru' || file === 'users-update.bru') assert.equal(body.is_admin, false, file);
    if (file === 'users-update.bru') assert.equal(body.active, true);
    if (file === 'users-permissions.bru') assert.deepEqual(body.grants, ['shared.note:read', 'shared.note:update']);
  }
});

test('create clears an old selection and selects the returned ID only after successful creation', () => {
  const source = read('users-create.bru');
  const script = block(source, 'script:post-response');
  const variables = new Map([['target_user_id', 'old-user'], ['access_token', 'a'.repeat(64)]]);
  const bru = {
    setVar: (name, value) => variables.set(name, value),
    getVar: name => variables.get(name),
    deleteVar: name => variables.delete(name),
  };
  vm.runInNewContext(block(source, 'script:pre-request'), { bru });
  vm.runInNewContext(script, { bru, res: { getStatus: () => 409, getBody: () => ({ success: false }) } });
  assert.equal(variables.has('target_user_id'), false);
  vm.runInNewContext(script, {
    bru, res: { getStatus: () => 201, getBody: () => ({ success: true, result: { id: 'fixture-user' } }) },
  });
  assert.equal(variables.get('target_user_id'), 'fixture-user');
});
