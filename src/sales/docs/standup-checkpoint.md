# Invoice and Payment checkpoint — 25 November 2026

Implemented: Invoice create/list/detail/update/submit/return/delete, Payment
recording/history, generated Customer Codes, exact monetary capacity checks,
company-scoped permissions, optimistic versions, transaction/audit/replay handling,
and Swagger templates/examples. Local migration source files 007–011 are included
as dependencies; no database migration was executed by this checkpoint work.

The requester has verified remote API startup and Invoice creation/listing and has
reported a successful Draft update. Payment recording/history, remaining Invoice
lifecycle cases, source-linked flows and real simultaneous-session Oracle races
remain acceptance work. This commit is a reviewable checkpoint, not final sign-off.

Verification:

- Sales plus MigrationTest: 310 tests, 1,465 assertions passed.
- Oracle payment acceptance: skipped before connection (no explicitly enabled
  disposable `_TEST` schema).
- Swagger contract checks: five passed; two existing mismatches remain: login's
  company enum restricts SIA-MINI-ERP while the test expects unrestricted company
  input, and Quote submit documentation names update while the test expects submit.
  These Auth/Quote contract decisions were left unchanged for the owning team.
- The earlier broader PHP run exposed shared readiness code calling missing
  MigrationRunner::directories(), and a Windows command-line subprocess failure in
  ApiFrameworkTest. No clean full-suite result is claimed.

Applied migration checksums in the shared lab schema differ from some manually
copied local migration files. Obtain the exact applied originals from the migration
owner before future schema upgrades; never update migration metadata to suppress
the mismatch. Current feature code does not require rerunning applied migrations.

Secrets (`.env`, config.ini), generated test artifacts, and unrelated staged
final-newline changes in bin/console.php and ConsoleConnection.php are excluded
from this feature checkpoint.
