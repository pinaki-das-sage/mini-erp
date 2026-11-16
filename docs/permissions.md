# Users, company and permissions

## Foundation ownership

This is shared infrastructure, not one graduate team's extra project. Every team registers its business areas and uses these checks. One Oracle schema supports multiple companies; the default training company is **SIA-MINI-ERP**.

Login requires the manually entered company identifier, username and password. Company/username lookup is normalized to uppercase/lowercase respectively. Usernames are unique within a company, not across the schema. Session company identity comes from the authenticated database record, never a posted `company_id`.

## Tenant boundary

`ME_COMPANY.ID` identifies a company. Mandatory `COMPANY_ID` columns scope `ME_USERS`, `ME_USER_ROLES`, `ME_NOTES`, `ME_ACTIONS`, `ME_AUDIT`, `ME_USER_PERMISSIONS` and `ME_SECURITY_AUDIT`. Compound foreign keys reject company/user/action mismatches; note IDs and action references may repeat in different companies.

The migration ledger and shared role/object/action definitions (`ME_MIGRATIONS`, `ME_ROLES`, `ME_OBJECTS`, `ME_OBJECT_ACTIONS`) remain global metadata. They intentionally have no company column. `ME_API_LOGIN_LIMITS` holds global operational throttle counters with hashed keys, not business data. `ME_API_TOKENS` belongs to its company/user and enforces that association through a compound foreign key.

All module business tables must carry `COMPANY_ID`; scope every read/write and business uniqueness rule using `$actor->companyId`, including joins, references and replay. Admin permissions never authorize another company's rows. This is application-level isolation in a shared schema, not isolation from someone holding the schema's database credentials.

## Users and Settings

There are two user types: admin and non-admin.

- Admins access their company's Settings and all supported permissions on every active registered object. Newly registered operations are included automatically; no static admin grant snapshot is needed.
- Non-admins start with no business permissions.
- Settings cannot be delegated through an ordinary object grant.
- Settings includes company information, user list/search/filter, creation, account editing, activation/deactivation, password reset and per-user permission editing.
- Company information is read-only in the UI. Company creation is CLI-only; there is no cross-company user-management UI.
- Users are deactivated, not deleted; the last active administrator cannot be deactivated/demoted.
- New/reset temporary passwords must be changed at first login, except the requested internal-lab bootstrap default `admin` / `admin`. That account can optionally change its password through the UI. Users change their own password using their current password.
- Account edits, permission changes and password changes increment the security version. Existing sessions fail on their next request; no login is kept alive with stale access.
- Security changes are audited without passwords or password hashes.

## Registration: one object row for CRUD

Add a new reviewed migration in your module's migration directory:

```php
<?php
declare(strict_types=1);

return [
    "INSERT INTO ME_OBJECTS
        (CODE, NAME, MODULE_CODE, ALLOW_CREATE, ALLOW_READ, ALLOW_UPDATE, ALLOW_DELETE)
     VALUES ('inventory.products', 'Products', 'inventory', 1, 1, 1, 1)",
];
```

Use lowercase, stable codes. CRUD flags default to zero, so list only supported operations. `ACTIVE` defaults to one. Adding the row automatically exposes those operations in the admin's permission editor; existing non-admin users do not automatically gain access.

The shared CLI currently applies shared migrations. Module teams should use `MigrationRunner::apply()` with their reviewed module directory and the same migration ledger; component directory names must be distinct. Agree module migration ordering with the foundation maintainer.

For an additional business action, add a row:

```sql
INSERT INTO ME_OBJECT_ACTIONS (OBJECT_CODE, ACTION_CODE, NAME)
VALUES ('purchasing.orders', 'approve', 'Approve');
```

The referenced object must exist first. Named actions are not aliases for CRUD: `update` does not imply `approve`, `post`, `allocate`, `dispatch` or `close`.

Registration is metadata, not code generation. It does not create endpoints/screens or automatically protect existing services.

## Enforce authorization

```php
use MiniErp\shared\security\Authorization;

$authorization = new Authorization($connection);
$authorization->requirePermission($actor, 'inventory.products', 'create');
// Validate business state and perform the operation using the shared transaction.
```

Derive/reload `$actor` on the server from the authenticated browser session or verified API bearer token; never deserialize admin/role values from the request.

Unknown/inactive objects, unsupported actions and absent grants fail closed. Admins bypass the grant lookup, not registration or business validation. Permission checks do not replace no-self-approval, driver ownership, quantity limits, record versions or immutable posting rules.

The neutral note demonstrates shared checks: UI access uses `shared.note:read`; mutation uses `shared.note:update`. Previously seeded `trainee` accounts receive these two grants through the upgrade migration; regular bootstrap also provides them to newly created training accounts. Ordinary users created through Settings receive no note/business grant until an admin assigns it.

## UI and REST

Use the **same business permissions and application services** for both channels. Do not define duplicate UI-vs-REST CRUD grants.

The original handlers below retain browser session authentication and CSRF:

- `GET /api/settings/users`: admin-only, excludes password/hash fields.
- `POST /api/settings/users`: admin-only and CSRF-protected. Supports `create`, `update`, `permissions`, `reset_password`; browser forms use this endpoint.
- `POST /api/note`: authenticated, CSRF-protected, registered update permission.

User edits/resets/permission updates include `expected_version`; invalid or stale input fails explicitly. No deletion operation exists for users.

The [versioned API framework](./api-development.md) adds `/api/v1/auth/login`, identity, logout and password change, plus bearer note/user endpoints. It does not authenticate from browser cookies. Tokens are short-lived opaque credentials stored only as hashes, not permanent tokens. Every request reloads the active user/company/security version and applies current permissions. Permission edits, account changes and password changes/resets invalidate existing tokens; logout revokes the current one. Temporary-password tokens cannot perform business operations.

`shared.user` and its `permissions`/`reset_password` actions register versioned administration endpoints. UserService still requires admin status, even if a non-admin receives those grants. No separate token scopes are implemented in this baseline; future narrower scopes must intersect with current user permissions, never replace them.

## Bootstrap and upgrade

Run `php bin/console.php migrate` to apply through `004_api_tokens.php`; never edit previously applied migrations. Back up data and stop application traffic during this upgrade. The existing `003_multi_tenancy.php` retains older tenant rows under company ID `1` (`SIA-MINI-ERP`) and refuses unexpected action foreign keys rather than silently removing module links. The new migration adds token/throttle storage and user-API permission registration without rewriting existing domain data.

The migration creates the company and permission structures. Run `php bin/console.php bootstrap` using the approved schema in `config.ini`; connectivity/schema checks happen before writes. It defaults to username `admin` and password `admin`, without a mandatory first-login change. Passwords are still hashed by PHP, never embedded in migrations. Change this publicly known default before broader access.

Optional private `BOOTSTRAP_ADMIN_USER`/`BOOTSTRAP_ADMIN_PASSWORD` overrides remain supported; custom passwords require 12-72 bytes and a first-login change.

Bootstrap creates the first administrator and the neutral note. Repeating it with the same active admin preserves that account/password. It rejects conflicting inactive/non-admin names or creation of an additional bootstrap admin when one already exists. Further administrators are created through Settings.

Regular CLI bootstrap also creates missing training users (`buyer`, `approver`, `receiver`, `inventory`, `sales`, `dispatcher`, `driver1`, `driver2`, `warehouse`, `supervisor`) with passwords matching their usernames, **only in SIA-MINI-ERP**. They are non-admins with neutral-note grants, not automatic access to business modules. No seed-related environment settings are required. Existing users/passwords/grants are preserved on rerun; UI-created users still follow temporary-password and default-deny rules.

Create another company from the configured, approved schema:

```bash
php bin/addcompany.php "Second company"
```

The company, first administrator, all ten training users with their roles/neutral-note grants, and welcome note are created atomically. Default admin credentials are `admin` / `admin`, or the private bootstrap overrides above; custom admin usernames must differ from the training usernames. Training passwords match usernames. The admin has all supported registered permissions within that company. Training users do not automatically gain business-module permissions.

The name becomes an uppercase login code with whitespace replaced by hyphens (`SECOND-COMPANY` here), printed by the command. The derived code must be unique and contain 1-50 letters, digits, underscores or hyphens. `php bin/console.php company:create SECOND "Second company"` remains available for an explicit code/name and seeds the same users. Duplicate company codes fail without changing existing accounts; any user/grant failure rolls back the entire creation transaction. New tenant users have independent IDs; existing SIA-MINI-ERP fixture IDs are preserved.

All existing sessions must sign in again after upgrade. The legacy shared-password seed command remains supported.

Actual Oracle migration/bootstrap/security-session behavior still needs verification on the configured lab server. Local tests do not establish live Oracle compatibility.
