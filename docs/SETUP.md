# Setup, development and verification

This is the single setup guide for the shared foundation: remote deployment, local development, PHP/JavaScript/browser tests and migration recovery. The foundation includes company-aware authentication, user/permission Settings, Smarty views, an audited note example and the [API-first bearer framework](./api-development.md). ERP domain workflows remain graduate work; the organizer prepares the peer adapter/fixture pack. Read [coding conventions](./coding-conventions.md) and [permission boundaries](./permissions.md) before adding module code.

**Web server:** Apache. **Application filesystem/web root:** `<checkout>/public/`; the checkout can have any name and live in any approved deployment directory.

**Example Application URL:** https://www-p308.intacct.com/users/pinaki.das/mini-erp/public/
**Your possible application URL:** https://www-p308.intacct.com/users/<YOUR_USERNAME>/<YOUR_CHECKOUT_NAME>/public/

**Repository:** `https://github.com/pinaki-das-sage/mini-erp.git`.

Examples below use `~/public_html/mini-erp/` and your existing URL. Substitute your actual checkout name/location and URL. Run Composer and console commands from the checkout, not its `public/` directory.

## 1. Clone the repository on the remote server

Login to the remote server as the deployment user.

```bash
cd ~/public_html
git clone https://github.com/pinaki-das-sage/mini-erp.git mini-erp
cd mini-erp
```

## 2. Pre-checks and configuration

- CLI and Apache's PHP handler must both use **PHP 8.4**. Database-backed pages and Oracle tests also require OCI8 and a compatible Oracle client; confirm the Oracle/client/driver combination with the DBA.
- Configure remote toolkit so that you can ssh to and sftp files into the remote server.
- Fill in the values for config.ini and upload to the server.

```ini
[oracle]
host = YOUR_ORACLE_HOST
port = 1521
service_name = YOUR_SERVICE_NAME
username = YOUR_LAB_SCHEMA
password = "YOUR_PRIVATE_PASSWORD"
```
- Make sure the oracle sql client is set up to connect to the db.

Create a private `.env` from `.env.example` only if one does not already exist. Set the effective `APP_ENV=production` on the remote HTTPS application; otherwise session cookies will not have `Secure`. Check inherited Apache/PHP environment overrides if editing `.env` does not take effect. Keep local HTTP fixture servers in `APP_ENV=local`.

Completed `config.ini` and `.env` are ignored by Git, not access-controlled by Git ignore. Keep them outside `public/` and never put credentials in commands, screenshots or logs. `ORACLE_CONFIG_FILE` can select a private INI file outside the checkout. `ORACLE_DB_HOST`, `ORACLE_DB_PORT`, `ORACLE_DB_SERVICE`, `ORACLE_DB_USER` and `ORACLE_DB_PASSWORD` override INI values; `ORACLE_CONNECTION` can provide a server-defined connection descriptor.

### Apache configuration and application URL

- Expose only `<checkout>/public/`. The checkout name/location and application URL prefix are not fixed.
- Enable `mod_rewrite` and have the hosting administrator permit the required overrides for this checkout. The checkout `.htaccess` needs `AuthConfig`; the public directory must permit its authorization, options, directory-index and rewrite directives (for example, `AllowOverride All` for that dedicated directory).
- Deploy both supplied `.htaccess` files. The parent denies private checkout files; the public file permits public requests, disables directory listing and routes non-file/non-directory requests to `index.php`.
- Leave `APP_BASE_PATH` blank/unset to detect Apache's `index.php` directory automatically. An explicit override must be a URL prefix, never a filesystem path; `/` forces a hostname-root deployment.
- Verify explicit private files under `src/`, `vendor/`, `views/` and `var/` are denied, not merely directory listings. Make host configuration changes only through the authorized maintainer.

## 3. Install PHP dependencies

Install development dependencies so PHPUnit is available:

```bash
composer install --no-interaction --prefer-dist
composer validate --strict
composer dump-autoload --optimize --strict-psr
composer check-platform-reqs
composer lint
composer test
php bin/console.php doctor --offline
```

To run the phpunit tests on the remote server, you can use the command 
```bash
php vendor/bin/phpunit --testsuite unit
```

Smarty 5 is now a runtime dependency. When deploying this view refactor, upload/pull the changed PHP files, the complete `views/` template tree, and both Composer manifests, then run `composer install` on the remote checkout. Remove the obsolete PHP layout if your deployment copies files rather than synchronizing deletions. No migration is needed for this refactor.

Create the private template runtime directories:

```bash
mkdir -p var/smarty/compile var/smarty/cache && chmod -R 0777 var/smarty/
```

Ensure Apache/PHP's runtime user can write these directories using the host's approved owner/group or ACL configuration; do not use world-writable permissions. Keep `views/`, `vendor/` and `var/` outside `public/` and retain the existing private-checkout protection. Templates compile automatically; rendered HTML caching is disabled. See [Smarty view conventions](./coding-conventions.md#views-smarty-and-responsibility-boundaries).

**Note:** It is advisable for each team member to have their own sandbox so they are testing against un-changed lab data and not affecting other team members' work.

## 4. Verify Oracle connectivity without changing data

```bash
php bin/console.php doctor
```

Expected: `Oracle connection OK`

If it fails, check private configuration, OCI8 loading, client libraries, listener/service, network access and schema authentication with the administrator. Do not expose credentials or raw diagnostic dumps.

The adapter supports independent connections for two-session tests and bound `SELECT ... FOR UPDATE`. Business mutations require an active caller-owned transaction, without automatic commits or a silent row limit. Typed Oracle errors support controlled conflict handling; domain services do not own commit/rollback. `/health` explicitly does not check Oracle; `/ready` checks the foundation's tables, migration status/checksums and essential seed data, not complete ERP acceptance.

## 5. Apply foundation migrations to the approved lab schema

Make sure `config.ini` points to the approved lab schema. The command checks Oracle connectivity and the connected schema before starting migrations; a failed check exits with an error and performs no migration writes:

```bash
php bin/console.php migrate
```

Expected first run: `Oracle connection OK. Schema: ...`, then `Applied: 001_foundation.php, 002_company_permissions.php, 003_multi_tenancy.php, 004_api_tokens.php`. Repeating unchanged completed migrations reports no pending shared migrations.

### Include Sales migrations

The default `migrate` command applies Shared migrations only. To prepare the Customer API, deploy the Sales migration files and run:

```bash
php bin/console.php migrate --module=sales --confirm-schema=YOUR_TEST_SCHEMA
```

Replace `YOUR_TEST_SCHEMA` with the intended configured Oracle schema. The command verifies that target, applies pending Shared migrations first, then runs `database/migrations/sales/` in filename order: `001_oe_permissions.php`, then `002_oe_customers.php`. Sales does not start if Shared migration validation or execution fails. `--module=shared` explicitly selects the default behavior; other module names are rejected.

Each component is tracked separately in `ME_MIGRATIONS`. Repeating the command skips unchanged completed migrations and reports `No pending shared migrations.` / `No pending sales migrations.` Changed, removed or incomplete migrations stop execution and require the recovery procedure below. Do not run migrations concurrently. This command prepares schema and permission registrations; it does not prove that the Customer API is deployed or passes Oracle acceptance.

If the company already exists, you should get a message confirming the same.

Before upgrading an existing installation, back up data, stop application traffic and apply the pending tenant migration `003_multi_tenancy.php`. Sign in again afterward. On any failed/incomplete migration, stop and follow [migration failure guidance](#migration-failure), not a blind retry.

For the API framework update, also apply pending `004_api_tokens.php` in each approved team/integration schema. It creates timezone-aware token storage, login-throttle counters and user-API permission registration; `/ready` now checks these tables and the new checksum. Preserve private configuration and optionally set `API_TOKEN_TTL_SECONDS=3600` (60-86400 allowed). Existing browser login remains cookie/CSRF-based; new clients use `/api/v1` and HTTPS. Install locked dependencies as usual; this update adds no package dependency. Run unit checks and the opted-in disposable-schema Oracle suite before claiming live API acceptance.

### Migration failure

- Stop traffic and contact the authorized maintainer. Retain the error and inspect the migration ledger/status/checksum and actual schema.
- Oracle DDL may already have committed. Do not blindly retry, rewrite an applied migration or mark an incomplete migration as applied.
- Use an approved lab backup restore or an explicitly reviewed forward-recovery procedure. Verify the schema/ledger and `/ready` before reopening traffic.

Use a separate Oracle schema/credentials for each team, plus a maintainer-controlled integration schema. A company is a data tenant, not protection against another team executing DDL in the same schema. Use approved DDL rights during migration and restrict runtime privileges afterward; do not grant blanket cross-team or production access. The optional `--confirm-schema=NAME` adds an explicit target check without changing configured connection precedence.

## 6. Bootstrap company and training user accounts

The migration automatically creates company `SIA-MINI-ERP`. It also creates its default internal-lab administrator and training accounts:

```bash
php bin/console.php bootstrap
```

Sign in with company `SIA-MINI-ERP`, username **admin** and password **admin**. Password change is optional through **Change password** in the UI. Re-running bootstrap never overwrites an existing active admin's password.

Administrators can create further users and assign permissions in **Settings → Users & permissions**. See [the permission framework](./permissions.md).

Accounts: `buyer`, `approver`, `receiver`, `inventory`, `sales`, `dispatcher`, `driver1`, `driver2`, `warehouse`, `supervisor`. Each has its own business role and the neutral-example `trainee` role.

Each account's initial password is identical to its username, for example `buyer` / `buyer` and `driver1` / `driver1`. These are non-admin accounts with neutral-note read/update permissions; assign business-module permissions through Settings as modules are registered. Password changes are available in the UI.

## 7. Additional companies (CLI-only)

```bash
php bin/addcompany.php "Second company"
```

This atomically creates the company, its administrator, all ten training users with their roles/neutral-note grants, and its own welcome note. Sign in using company `SECOND-COMPANY` and `admin` / `admin`, or the private bootstrap credential overrides. Training passwords match usernames, for example `buyer` / `buyer`.
Custom bootstrap admin usernames must differ from the ten training usernames; a conflict rolls back creation rather than replacing a training account.

The single argument is the display name. Its uppercase form, with whitespace replaced by hyphens, becomes the login company code printed by the command. Use names yielding a unique 1-50-character code containing letters, digits, underscores or hyphens; quote names containing spaces. Missing/invalid names fail explicitly.

For a separate code and display name, `php bin/console.php company:create SECOND "Second company"` remains supported and creates the same accounts. Admins automatically receive all supported registered permissions within their company; training users receive only neutral-note read/update grants until module permissions are assigned. Duplicate companies fail without changing existing accounts. Company/user/grant creation rolls back together on failure.

## 8. Check the deployed application

In a browser:

1. Open https://www-p308.intacct.com/users/pinaki.das/mini-erp/public/; confirm company, username and password inputs.
2. Sign in with company `SIA-MINI-ERP` and `admin` / `admin` (or your configured bootstrap credentials). Check all four module entries, Settings and user creation/permission editing. Alternatively, use an optional seeded `buyer` account; open **Neutral example**.
3. Save a training note and confirm the version increases.
4. Sign out, sign in as another role and verify session behavior.
5. Confirm invalid CSRF/unauthenticated writes are rejected using the provided tests.
6. Confirm sibling paths such as `/users/pinaki.das/mini-erp/.env`, `/users/pinaki.das/mini-erp/config.ini`, `/users/pinaki.das/mini-erp/src/`, `/users/pinaki.das/mini-erp/vendor/` and `/users/pinaki.das/mini-erp/.git/` are denied. Also check explicit files inside source/dependency folders, not just directory listings.

`/ready` is not proof of full ERP functionality, all role accounts or all Day 0 checks. Actual remote login/write behavior must be exercised.

## 9. Install npm dependencies and run browser checks

This is optional on a runtime-only server. There is **no frontend build step**: CSS and JavaScript are served directly. npm dependencies are test tooling.

Run these commands on your **local laptop/test machine**, not the remote PHP server. Remote tests need Node 22+, npm and Chromium; they do not start PHP or require local Oracle/OCI8:

```bash
node --version
npm --version
npm ci
npm run check:js
npx playwright install chromium
```

Add the following to your **local private `.env`** without replacing existing configuration:

```dotenv
BROWSER_TEST_BASE_URL=https://www-p308.intacct.com/users/pinaki.das/mini-erp/public/
BROWSER_TEST_COMPANY=SIA-MINI-ERP
BROWSER_TEST_USERNAME=admin
BROWSER_TEST_PASSWORD=admin
```

Then run:

```bash
npm run test:browser -- --headed
```

For certificate errors, prefer installing the approved corporate CA for Chromium and providing its PEM bundle to Node through `NODE_EXTRA_CA_CERTS` before npm starts. On supported Node versions, `NODE_USE_SYSTEM_CA=1` can use an already trusted system CA.

For a **trusted training server only**, you may temporarily add:
```dotenv
BROWSER_TEST_IGNORE_HTTPS_ERRORS=true
```

to your local private `.env` file and rerun the tests. This opt-in flag bypasses certificate verification for both browser and API checks, not globally for Node or the application. It removes server identity verification: avoid sensitive credentials/data, never use it for production/security acceptance, and restore `false` after repairing CA trust. Only the exact strings `true` and `false` are accepted.

**Note:** The first test is expected to fail if the `APP_ENV=production` is not set on the remote.

Use your actual deployed directory URL; `.env.example`, private `.env` and process environment settings are loaded in that precedence order. A missing target fails rather than silently testing localhost. Credentials are optional, but must be supplied together: leaving all three blank explicitly skips real sign-in/sign-out; a temporary-password account cannot complete this smoke flow without a separate password change.

Remote checks use real pages/responses and do not create users, edit grants or save notes. Traces, screenshots and video are disabled. Retain the manual write/role checks above and the separate Oracle integration suite.

