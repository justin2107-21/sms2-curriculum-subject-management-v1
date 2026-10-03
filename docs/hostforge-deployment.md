# HostForge Docker deployment

## Verification status (2026-09-30)

**Local Docker workflow passed; HostForge itself has not been deployed/tested.**
The PHP 8.2 Apache Bookworm image builds, all application PHP files lint
inside the image, and all required extensions including mysqli pass. The entire
workflow passes on MariaDB 10.11.19: 14 baseline/migration journal entries,
41 foreign keys, ports 8080/9090, both database-variable conventions, minimal
HTTP 200 health, login/dashboard/module/logout regression, CSRF/RBAC/audit,
sensitive URL denial, generic invalid-DB responses, and container log checks.
The 1,560-check foundation suite runs once for EACH DB naming convention.

The previous error 1901 was a CHECK/cascading-FK incompatibility in migrations
005, 009 and 011. Seven explicitly approved identity-reference FKs now use
ON UPDATE RESTRICT; DELETE RESTRICT, CHECKs, UNIQUEs and attribution FKs remain.
Migration 013 upgrades old definitions using temporary restrictive guards while
preserving original names. See [database compatibility guidance](../database/README.md#academic-identity-fk-portability-migration-013)
and the [upstream MariaDB constraint issue](https://jira.mariadb.org/browse/MDEV-25437).

Fresh installs and populated historical-schema upgrades also pass with the actual
XAMPP MariaDB 10.4.32 binary in a new temporary data directory and non-default
loopback port. Its regular server was unavailable, so no claim is made that an
existing persistent journal was inspected. Exact pinned historical digests support
such journals without bypassing checksum validation or rewriting their evidence.
Oracle MySQL was not tested; XAMPP's bundled engine here is MariaDB.

All temporary containers, anonymous volumes, networks, local test schemas and the
isolated local server/data directory were removed. Local `sms_db` and the normal
XAMPP data directory were not touched. No visual/browser QA or HostForge account
configuration is claimed. Provisioned credentials, console access, DB TLS and
initial administrator provisioning still require the deployment operator.

Harness fixes replace Windows PowerShell's corrupted inline `php -r` expression
with `tests/docker-runtime-check.php`, mounted read-only outside DocumentRoot.
They also handle native stderr using exit codes, avoid a case-insensitive `$Name`
variable collision, preserve original failures during cleanup, and give apps a
separate localhost-published frontend bridge while MariaDB stays internal-only.
Tests/docs remain excluded from the production image.

Historical fallback checks performed on 2026-09-29 without Docker:
16 environment-configuration checks;
29 database migration checks plus an actual forced partial-DDL failure/retry;
41 foreign-key integrity checks; and 1,560 HTTP foundation checks for EACH naming
style against a disposable local MariaDB schema and loopback PHP server. Root,
health, assets/favicon and generic DB-failure responses also passed. These checks
do not substitute for Apache/extension/build validation in the Docker image.
The existing 40-check development seed regression also passed using a third
fresh disposable schema. Static checks passed for 118 PHP files, 17 JS/CJS files,
18 CSS structures, shell/PowerShell syntax, CSV compatibility and Git whitespace.
No test statement targeted the developer's `sms_db`. All three explicitly
created temporary schemas were removed after verification.

Work only in the sanitized repository:
`https://github.com/justin2107-21/sms2-curriculum-subject-management-v1`.
Local path: `C:\Users\esquitajustin\Documents\csms-core-foundation-v1-clean`.
Never merge/push the old historical repository into this replacement.

## HostForge application settings

After local/container verification and explicit deployment approval:

1. Connect the sanitized GitHub repository above; select branch `main`.
2. Choose **Use my own Dockerfile**, build context repository root (`.`),
   Dockerfile path `Dockerfile`. Keep the image entrypoint/default start command.
3. Provision a managed MySQL/MariaDB database. Use the credentials and internal
   endpoint shown in HostForge's database/environment screen, not XAMPP defaults.
4. Set runtime environment variables below. Never enter secrets as build args,
   commit an `.env`, or upload a local database backup with credentials.
5. Use public HTTPS and health path `/health.php`. The service listens on the
   injected `PORT` (default 8080). Configure HostForge's routing to that actual
   internal port. `EXPOSE` is documentation, not listener configuration.
6. Keep public access restricted until controlled schema initialization and
   an authorized administrator-provisioning procedure are complete.

These settings follow the supplied tutorial. Platform-specific console access,
private networking, database TLS requirements and port routing must be confirmed
in the actual HostForge account; they were not exercised from this workstation.

## Runtime variables

| Purpose | Preferred CSMS name | Compatible alternative | Local fallback |
| --- | --- | --- | --- |
| Database server | `DB_HOST` | `SMS_DB_HOST` | localhost |
| Database port | `DB_PORT` | `SMS_DB_PORT` | 3306 |
| Provisioned schema | `DB_NAME` | `DB_DATABASE`, then `SMS_DB_NAME` | sms_db |
| Database account | `DB_USER` | `DB_USERNAME`, then `SMS_DB_USER` | root |
| Database password | `DB_PASS` | `DB_PASSWORD`, then `SMS_DB_PASS` | empty |
| Apache listener | `PORT` | none | 8080 |

Use one naming convention consistently. The first nonempty value wins; string
`0` is not treated as missing. Ports must be integers in 1-65535. Preferred
`DB_*` values take precedence over HostForge aliases and legacy `SMS_DB_*`.
Both HTTP and CLI tools use `shared/database_config.php`.

The image defaults to `APP_ENV=production`, `SMS_ALLOW_SETUP=0`, and
`SMS_SESSION_COOKIE_SECURE=1`. Production rejects localhost/loopback, root or
empty-password connection fallbacks and requires an explicitly configured
schema. Those defaults remain available to local XAMPP development only.

Use the provider's private network. The current connection does not add a
new database TLS-certificate configuration; if HostForge requires DB TLS,
confirm its requirements before deployment rather than disabling verification.

## Image and Apache

Base: official `php:8.2-apache-bookworm`, not `latest`. The Dockerfile compiles
mysqli. mysqlnd, curl, DOM, fileinfo, JSON, session, ctype and filter are checked
at build time; they are supplied by the official base. No application dependency
on pdo_mysql, GD, intl or zip was found. mbstring is already supplied by the base,
but the application does not require an additional installation.

`docker/apache-entrypoint.sh` uses `set -eu`, validates `PORT`, and configures
Apache's listener/virtual host. Normal startup does not initialize the database.
Only the explicit temporary flags below enable CLI migrations/admin provisioning
before the official foreground scripts make Apache PID 1. Bootstrap failure exits
nonzero before Apache starts. CLI commands bypass these startup gates.

DocumentRoot is `/var/www/html`; rewrite is enabled and `AllowOverride All`
preserves the root `.htaccess`. Directory indexes are disabled. Server config
additionally denies internal directories, metadata, SQL, local configuration
and web setup. `.dockerignore` excludes development seeds, test helpers, docs,
Git/IDE metadata, credentials and local artifacts. Baseline SQL and migration
files remain available internally to CLI, but are denied over HTTP.

Source remains root-owned/read-only to Apache. Only the private session directory
`/var/lib/php/csms-sessions` is owned by www-data (0700); PHP exports stream via
temporary memory/files, and errors use container stderr. No 0777 permissions.
No durable upload/export directory was identified. Start with **one app replica**:
file sessions are container-local and redeployment logs users out. Shared sessions
or load-balancer affinity require a separate design before multiple replicas.

## Liveness and HTTPS

`/health.php` is unauthenticated and returns only `{"status":"ok"}`. It does
not connect to the database or initialize sessions. Healthy web runtime does
not prove database readiness or completed migrations. The Docker health check
uses the actual injected port. `/` redirects to the existing sign-in route.

Existing HttpOnly and SameSite=Lax settings remain. Explicit
`SMS_SESSION_COOKIE_SECURE=1` makes cookies Secure behind HostForge's HTTPS
termination without trusting client-supplied forwarding headers. Keep this value
in production. Set it to 0 **only** for isolated plain-HTTP local tests.
Do not globally trust `X-Forwarded-Proto` or disable CSRF to accommodate a proxy.

## Controlled database initialization

### First Production Database Bootstrap

Use this explicit, temporary startup mechanism when HostForge has no shell/exec
and its SQL console is read-only. It is not a public setup endpoint. Keep the app
restricted during initial setup. A healthy `/health.php` says only that PHP/Apache
work: it does not mean tables/users exist. An empty database causes login to fail.

1. Keep the existing managed `DB_*` variables unchanged. Both preferred and
   alternative aliases still use `shared/database_config.php`; `DATABASE_URL`
   is not required. Keep `APP_ENV=production`, `SMS_SESSION_COOKIE_SECURE=1`,
   `SMS_ALLOW_SETUP=0`, port 8080 and health path `/health.php`.
2. Add these **temporary runtime environment variables** in HostForge's secret/
   environment settings (never Git, Docker build arguments or a public form):

   ```text
   CSMS_RUN_MIGRATIONS=1
   CSMS_MIGRATION_CONFIRM_DATABASE=<exact attached database name>
   CSMS_BOOTSTRAP_ADMIN=1
   CSMS_ADMIN_USERNAME=<chosen administrator username>
   CSMS_ADMIN_PASSWORD=<unique strong secret>
   CSMS_ADMIN_EMAIL=<real administrator email>
   CSMS_ADMIN_FIRST_NAME=<administrator first name>
   CSMS_ADMIN_LAST_NAME=<administrator last name>
   ```

   All eight are listed deliberately: the existing user schema requires email,
   first name and last name. No default identity is invented. Username is limited
   to 60 characters, email to 150, and each name to 100. Supply a unique randomly
   generated password (at least 12, at most 72 UTF-8 bytes, no NUL); the upper bound
   prevents silent bcrypt truncation. No password value is shown in logs/audits.
3. Manually redeploy. Only literal `1` enables either gate; missing, empty, `0`,
   `false`, `true`, `01`, or other values do not. The migration gate checks exact
   agreement with both configured DB name and `SELECT DATABASE()`, then executes
   existing `deploy.php --status`, `deploy.php --apply --confirm-database=...`, and
   `inspect.php`. It never creates/drops the database or loads development data.
4. Verify successful startup messages, 23 tables (22 application + journal),
   16 applied journal entries (baseline + 15 migrations), and admin login using
   your chosen credentials. Check dashboard, curriculum pages and logout. Expected
   safe messages include `Startup migrations: completed and verified.` and
   `First administrator provisioned; disable and remove bootstrap variables now.`
5. **REMOVE ALL EIGHT temporary variables above**, including the password and
   personal details—not merely setting flags to zero. Do not remove managed DB
   credentials or the permanent production/session settings.
6. Redeploy normally. Verify healthy status, working admin login and no startup
   migration/provisioning messages. Existing schema, account and password remain.

**DO NOT LEAVE BOOTSTRAP VARIABLES ENABLED.** The entrypoint removes them from
Apache's inherited environment after successful startup, but Docker/HostForge
still retain their configured values until you remove them in HostForge.

`database/provision_admin.php` is CLI-only and uses PASSWORD_DEFAULT, prepared
statements, the current `admin` role, an advisory lock and a transaction covering
user + user_roles + `ADMIN_BOOTSTRAP_CREATE`. The audit actor is NULL (CLI/system),
with only the new entity ID and non-sensitive source context. Failed role/audit
writes roll back account creation. No authenticated session is fabricated.

Retries are safe: completed migrations are not replayed; an existing matching
administrator is left unchanged without resetting its password or adding another
mapping/audit event. A matching non-admin username is rejected (never promoted).
If another administrator exists, creating an additional bootstrap admin is refused.
Missing schema, pending/invalid journal, missing admin role, invalid details and
DB confirmation mismatch fail closed. Admin-only provisioning can be explicitly
enabled later, but still requires the already-complete verified migration journal.

If bootstrap fails, Apache does not start. Read the safe failure stage in container
logs, correct configuration, and redeploy. A partial DDL failure needs controlled
journal/schema recovery; never bypass checksums, delete a running marker blindly,
or expose setup.php. Do not share passwords or raw environment dumps in support.

### Optional operator CLI route

Provision the database in HostForge first, then use its authorized container/CLI
console or a one-off job with the SAME runtime database settings:

```sh
php database/deploy.php --status
php database/deploy.php --apply --confirm-database=YOUR_PROVISIONED_SCHEMA
php database/inspect.php
```

`YOUR_PROVISIONED_SCHEMA` is an operator-supplied schema name, not a password.
If no console/predeploy job exists, use the explicit temporary startup gates above.
Never publish a web reset script to compensate.

The runner is CLI-only. It:

- Requires an explicit schema and exact confirmation before writes.
- Connects to an existing database; never creates/drops the database.
- Creates a `schema_migrations` infrastructure journal (not a domain table).
- Extracts only users/students schema from `students.sql`; never loads its
  sample students, administrator credentials or development seed dataset.
- Applies the baseline plus numbered migrations in order, only when unapplied.
- Uses a database advisory lock and normalized SHA-256 checksums.
- Records `running` BEFORE DDL and `applied` after success. MySQL DDL is not
  atomic; an interrupted/failed migration stays visibly incomplete and blocks
  reruns rather than silently replaying partial DDL.
- Rejects modified/missing/out-of-order migration history and nonempty schemas
  that have no journal. It does not guess how to adopt an existing installation.
- Logs safe failure categories and does not display SQL or connection values.

Back up before every schema change. On failure inspect the journal and actual
schema with an authorized DBA; do not simply delete the `running` marker.
There is no automatic rollback of committed DDL. Restoring a reviewed backup or
an explicitly reviewed repair is an operator decision, not container startup.

Fresh production initialization creates 22 application tables plus the journal,
34 permissions and the existing compatibility mappings, but **zero users and
zero student/domain sample records**. No default administrator exists. Arrange
explicit first-admin provisioning above with a unique secret before making
the application public. The existing browser setup is blocked in Apache even if
someone accidentally enables its environment flags. Test-only account setup is
excluded from the image and refuses non-disposable database names.

`database/bootstrap.php` is still the legacy development empty-database helper;
it now refuses `APP_ENV=production`. Do not use it for HostForge initialization.
Only the approved identity-FK portability revisions and forward migration changed
schema definitions; no module workflows or institutional policies changed.

## Repeatable Docker verification

On Windows with Docker Desktop using Linux containers:

```powershell
powershell -ExecutionPolicy Bypass -File .\tests\docker-deployment.ps1
```

PowerShell 7 can also invoke the script. It builds
`docker build -t csms-hostforge-test .`, then uses a uniquely named private Docker
database network and a temporary `mariadb:10.11` container. Apps also join a
separate frontend bridge with ports published only on 127.0.0.1; MariaDB has no
published ports and remains on the internal network alone. This follows Docker's
[frontend/backend network pattern](https://docs.docker.com/engine/network/).
Passwords are generated at
runtime and passed by environment names, never embedded in source/build args.
The developer's `sms_db` is never a test target.

The test covers migration guards, both DB alias styles, actual host-visible
8080/9090 listeners, health/root/login/assets/favicon, mysqli/modules, app
regression, HTTPS cookie configuration, generic DB-failure responses, and
representative denied URLs. It mounts CLI tests read-only into test containers
only; production image does not contain them. It inspects PHP/Apache logs.
Finally it removes only this run's containers, anonymous DB volume and networks;
test images remain available for inspection. It does not prune unrelated Docker
resources, push Git commits, or deploy to HostForge.

It also runs a targeted pre-build credential-pattern scan and checks that the
image omits metadata, environment files, documentation, tests and dev seeds.
These checks complement review; they are not a guarantee against every possible
unknown/encoded secret.

If any test fails, do not call the deployment verified. Inspect failures privately
without pasting environment variables/container secrets into reports. Browser
visual QA is separate from HTTP/source tests; no UI was redesigned here.

## Sources

- [Official PHP Docker image](https://hub.docker.com/_/php)
- [Official PHP 8.2 Apache image build](https://github.com/docker-library/php/blob/master/8.2/bookworm/apache/Dockerfile)
- [Docker build context exclusions](https://docs.docker.com/build/concepts/context/#dockerignore-files)
- [MariaDB container healthcheck](https://mariadb.com/docs/server/server-management/automated-mariadb-deployment-and-administration/docker-and-mariadb/using-healthcheck-sh)
