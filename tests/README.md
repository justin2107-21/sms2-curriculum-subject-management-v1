# Foundation regression (CLI only)

## Academic FK portability regression

`academic_fk_compatibility.php` is part of the Docker gate. It requires an explicitly
confirmed disposable `csms_docker_test_*` schema and empty academic fixture tables.
It tests database constraints directly with artificial records and cleans only
captured IDs. The full foundation suite additionally tests prerequisite cycles,
RBAC, CSRF, audits and current module workflows over HTTP.

On Windows, run `powershell -ExecutionPolicy Bypass -File tests/local-fk-compatibility.ps1`
to test the actual XAMPP binary (default `C:\xampp\mysql\bin`). This initializes a
NEW temporary data directory with a random root secret, binds a random loopback
port, and runs fresh initialization plus a populated legacy upgrade. It never
starts/modifies the normal XAMPP instance or `sms_db`. Finally it drops its own
disposable schemas, stops its own process and removes its temporary directory.
`-MysqlBin` and `-Php` allow other installed binary locations.

The legacy fixture recreates reviewed pre-013 SQL (including the old cascading
keys), records its exact historical migration digests, and inserts prerequisite,
equivalency and compliance records before applying 013. Tests verify unchanged
rows, CHECK expressions, indexes, AUTO_INCREMENT values, journal evidence and
unrelated FKs. Unknown checksum tampering must still fail. Use a legacy-compatible
engine for this fixture; MariaDB 10.11 correctly refuses creation of the old shape.

## Other foundation checks

`docker-bootstrap-scenarios.ps1` is called by the full Docker workflow, not run
standalone. It creates a separate disposable MariaDB instance for production
startup tests. No-bootstrap/disabled flags must leave it empty; a wrong confirmation
or premature admin flag must exit before Apache. It tests missing roles, forced
role/audit-write rollback, weak secrets, real first-admin login, idempotent retries
with a different supplied password, non-admin collisions, second-admin refusal,
and normal startup after every bootstrap variable is removed. Module smoke creates
no academic/demo records—only the disposable admin and normal audit evidence.
Temporary secrets are generated at runtime and never written to source/files.
The existing foundation suite runs separately against its own disposable database.

HostForge/Docker build and runtime checks are in `docker-deployment.ps1`.
See [deployment guide](../docs/hostforge-deployment.md) for prerequisites and
the current validation limitation. `database_config.php` tests alias/port
validation without opening a database. `deployment_migrations.php` and
`deployment_admin.php` refuse unconfirmed or non-disposable database names;
they are never production provisioning tools and are excluded from the image.

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
