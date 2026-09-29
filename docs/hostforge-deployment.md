# HostForge Docker deployment

## Verification status (2026-09-29)

**Not deployment-verified yet.** Docker Desktop/CLI is absent on this machine;
WSL is not installed. No Docker build, container runtime, in-container Apache
security check or HostForge deployment is claimed. Do not push/deploy these
changes as verified until `tests/docker-deployment.ps1` passes on a Docker host.
A local preparation commit does not imply runtime validation. Push/deployment
is withheld while this gate remains blocked.

Fallback checks performed without Docker: 16 environment-configuration checks;
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

`docker/apache-entrypoint.sh` uses `set -eu`, validates `PORT`, and writes only
Apache's listener/virtual-host configuration at container startup. It then execs
the official entrypoint/foreground scripts; Apache becomes PID 1. CLI commands
bypass Apache startup and never trigger automatic schema initialization.

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

Provision the database in HostForge first, then use its authorized container/CLI
console or a one-off job with the SAME runtime database settings:

```sh
php database/deploy.php --status
php database/deploy.php --apply --confirm-database=YOUR_PROVISIONED_SCHEMA
php database/inspect.php
```

`YOUR_PROVISIONED_SCHEMA` is an operator-supplied schema name, not a password.
No CLI console/predeploy-job capability is assumed to exist; if unavailable,
stop and obtain a controlled maintenance connection. Never publish a web reset
script to compensate.

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
explicit authorized CLI account provisioning with a unique secret before making
the application public; no production account-provisioning workflow was invented
in this deployment task. The existing browser setup is blocked in Apache even if
someone accidentally enables its environment flags. Test-only account setup is
excluded from the image and refuses non-disposable database names.

`database/bootstrap.php` is still the legacy development empty-database helper;
it now refuses `APP_ENV=production`. Do not use it for HostForge initialization.
Applied migration SQL and domain/business rules were not edited.

## Repeatable Docker verification

On Windows with Docker Desktop using Linux containers:

```powershell
pwsh -File tests/docker-deployment.ps1
```

Windows PowerShell can also invoke the script. It builds
`docker build -t csms-hostforge-test .`, then uses a uniquely named private Docker
network and a temporary `mariadb:10.11` container. Passwords are generated at
runtime and passed by environment names, never embedded in source/build args.
The developer's `sms_db` is never a test target.

The test covers migration guards, both DB alias styles, actual host-visible
8080/9090 listeners, health/root/login/assets/favicon, mysqli/modules, app
regression, HTTPS cookie configuration, generic DB-failure responses, and
representative denied URLs. It mounts CLI tests read-only into test containers
only; production image does not contain them. It inspects PHP/Apache logs.
Finally it removes only this run's containers, anonymous DB volume and network;
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
