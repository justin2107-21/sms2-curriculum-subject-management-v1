# Foundation regression (CLI only)

These are maintained opt-in tests, not public testing/setup endpoints. Root
Apache rules deny this directory; each PHP test also rejects non-CLI requests.
Do not run against production. Stop interactive editing while tests run.

## Read-only/static checks

```powershell
php database/inspect.php
php tests/csv_export.php
node tests/frontend.cjs
rg --files -g '*.php' | ForEach-Object { php -l $_ }
rg --files -g '*.js' -g '*.cjs' | ForEach-Object { node --check $_ }
git diff --check
```

The frontend test uses a mock DOM for deterministic sidebar/clock behavior at
1440, 1024, 768, 430, 390 and 360 pixels and checks CSS strings/comments/braces.
It is **not** a CSS standards validator, rendered-browser test, screenshot test,
or substitute for manual responsive/modal/autocomplete/print review.

## Database-backed HTTP regression

Start local Apache/MySQL, back up the database outside the web root, and verify
the URL and SQL connection refer to the **same** development installation.
The existing SMS_DB_* variables select the SQL connection. Use the existing
authorized admin credential; never put a password in source or committed SQL.

```powershell
$credential = Get-Credential -UserName admin -Message 'Local CSMS regression account'
$env:SMS_TEST_BASE_URL = 'http://localhost/sms-csms'
$env:SMS_TEST_CONFIRM_DATABASE = 'sms_db'
$env:SMS_TEST_ADMIN_USER = $credential.UserName
$env:SMS_TEST_ADMIN_PASSWORD = $credential.GetNetworkCredential().Password
try { php tests/foundation.php } finally { Remove-Item Env:SMS_TEST_ADMIN_PASSWORD }
```

The test keeps cookie jars and tokens in memory, creates uniquely prefixed
DEV-REGTEST fixtures, records returned primary keys, and deletes only those IDs
in reverse dependency order with FK checks still on. It never resets counters.
Its finally block preserves append-only audit evidence. On cleanup failure it
prints only fixture IDs/prefix, stops, and leaves the transaction rolled back for
operator inspection. Do not replace this with broad prefix deletion.

Coverage includes admin/unauthorized pages, registration role forgery,
RBAC/CSRF/method rejection, domain CRUD/configuration, prerequisites, dependency
guards, equivalency, weights, synthetic compliance, export, pagination and logout.
Successful mutations must produce exactly one expected audit event; rejected
mutations and ordinary reads produce none. Local row counts must return to their
starting values except audit logs. Existing template users/students are not test
fixtures.

Run-time validation against HTTP does not imply that every interactive browser
control was visually tested. Browser review is still needed for focus trapping,
autocomplete popup placement, animations and Print / Save as PDF.

## Isolated seed safety regression

See [the seed guide](../database/seeds/README.md). `development_seed.php` only
accepts a fresh `sms_db_foundation_test_*` target; it checks atomic rollback,
collision/rerun refusal, ownership-based cleanup, unrelated data preservation and
demo service reads. It does not create users in the normal development database.
