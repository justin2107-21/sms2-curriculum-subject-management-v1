# Deploying the demo on Hostforge (Docker)

Release: branch `release/demo-2026-11-13`, tag `demo-2026-11-13`. `main` was not changed by this release, so `main` itself is the older code if you need it (there is no `pre-demo-main` tag).

Status: the Dockerfile has passed local Docker runs before (see `hostforge-deployment.md`), but **this release has not been built or run on Hostforge yet**. Treat the first deploy as the test, and do the smoke test below.

## 1. What runs

| Item | Value |
|---|---|
| Base image | `php:8.2-apache-bookworm` (PHP 8.2, Apache, mod_rewrite) |
| Document root | `/var/www/html` (only application source and compiled UI; see "What is in the image") |
| Port | `8080` inside the container (`PORT`), published by Hostforge on your domain |
| PHP extensions | mysqli (mysqlnd), mbstring, zip (AES-256 protected exports), curl, dom, fileinfo, json, session, ctype, filter. The build fails if one is missing. |
| Database | MariaDB 10.4 or newer, a **separate** service (tested on 10.4.32 and 10.11). CHECK constraints, window functions and InnoDB are used. |
| Configuration | **Container environment variables only.** There is no `.env` loader. `.env.example` lists every variable. |
| Health check | `GET /health.php` returns `{"status":"ok"}`; it does not test the database. The image also has its own HEALTHCHECK. |
| PHP settings | `display_errors` off, errors to stderr (visible in the Hostforge log), `expose_php` off, session cookies HttpOnly + SameSite=Lax + Secure, sessions in `/var/lib/php/csms-sessions`. Timezone is not set (UTC); the app stores UTC timestamps. |

## 2. Hostforge settings

1. **Git repository:** `justin2107-21/sms2-curriculum-subject-management-v1` (the `-v1` repository). **Branch:** `release/demo-2026-11-13`. **Build:** Dockerfile at the repository root.
2. **Port:** 8080. **Domain:** your domain, **HTTPS on** (Hostforge terminates TLS and forwards plain HTTP to the container; that is expected, see section 4).
3. **Health check path:** `/health.php`.
4. **Persistent volumes:** the application container needs **none** (it writes no uploads, exports, logs or caches; logs go to stderr; protected-export temp files live in `/tmp` and are deleted at once). **The MariaDB service must have a persistent volume** and its own backups.
   - Sessions are files in `/var/lib/php/csms-sessions` inside the container. They are lost when the container is recreated (each redeploy): everyone is signed out. A restart without recreation keeps them. This is acceptable for the demo. Mounting a volume there is possible, but it must be owned by `www-data` with mode 700, which a fresh volume is not.
5. **Environment variables** (set in the panel, names only; meaning and placeholders are in `.env.example`):
   `APP_ENV`, `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`, `SMS_SESSION_COOKIE_SECURE`, `PORT`, `SMS_ALLOW_SETUP`, `CSMS_FACULTY_READ_SCOPE`, `CSMS_DASHBOARD_SHOW_PENDING_CHARTS` (optional), `API_KEY_PEPPER`, `API_RATE_LIMIT_PER_MIN` (optional), `CIMF_AI_ENABLED`, `CIMF_AI_PROVIDER`, `CIMF_AI_MODEL`, `GROQ_API_KEY` / `OPENAI_API_KEY` (only the chosen provider), `CIMF_GROQ_MODEL` / `CIMF_OPENAI_MODEL` (optional).
   - `APP_ENV=production`, `SMS_SESSION_COOKIE_SECURE=1` and `SMS_ALLOW_SETUP=0` are already the Dockerfile defaults. Keep them.
   - With `APP_ENV=production` the app refuses to connect when the database host is localhost, the user is root, the password is empty or the database name is missing (message: "Production database configuration is required."). Without `APP_ENV=production` it would silently fall back to local defaults, so never unset it.
   - `DB_HOST` is the **name of the MariaDB service** as Hostforge shows it for the internal network (not `localhost`). Use a dedicated database user that owns only `DB_NAME`.
   - Set `API_KEY_PEPPER` to the **same value as local** (at least 32 characters) if API client keys were created locally; otherwise they stop working. Without it the Offering API cannot create or verify keys.
   - `CIMF_AI_ENABLED=false` keeps AI advice off. If you turn it on, only the key of the chosen provider is needed.

## 3. Steps, in order

1. **Upload the code:** point Hostforge at branch `release/demo-2026-11-13` (tag `demo-2026-11-13` is the same commit). Do not deploy `main` or `ui-redesign-pilot`.
2. **Create the database and user:** a MariaDB service, an empty database (`DB_NAME`, `utf8mb4` / `utf8mb4_unicode_ci`) and a dedicated user with privileges on that database only.
3. **Import the final local export (structure and data):** make it on your machine with `mysqldump --single-transaction --routines --triggers sms_db > sms_db_final.sql` (do not commit it; keep it outside the repository). Import with the Hostforge database tool, or from a shell that can reach the service:
   `mysql -h <DB_HOST> -P <DB_PORT> -u <DB_USER> -p <DB_NAME> < sms_db_final.sql`
   Check afterwards that the user and migration tables have rows.
4. **Run pending migrations (manual, only if needed).** The container does not run them by default. In the Hostforge console of the app container:
   - `php /var/www/html/database/deploy.php --status` lists applied and pending migrations (read-only);
   - `php /var/www/html/database/deploy.php --apply --confirm-database=<DB_NAME>` applies the pending ones, in order (baseline from `students.sql`, then `001` to `032`; `028` and `030` are intentionally absent);
   - `php /var/www/html/database/inspect.php` checks the schema.
   A dump of the current local database already holds every migration (up to 032), so normally nothing is pending. Alternative at container start: set `CSMS_RUN_MIGRATIONS=1` and `CSMS_MIGRATION_CONFIRM_DATABASE=<DB_NAME>`, deploy once, then **remove both**.
5. **Passwords and keys:** user passwords use plain `password_hash`, so the imported hashes validate on any host. Only `API_KEY_PEPPER` must equal the local value (API client keys). Set the variables in section 2 in the panel, never in the repository.
6. **Directory permissions:** nothing to set by hand; the image sets directories 755 and files 644, and the session directory belongs to `www-data` (700).
7. **Force HTTPS:** enable HTTPS and the HTTP-to-HTTPS redirect in the Hostforge domain settings. The Offering API refuses non-HTTPS requests in production.
8. **Change every demo/seed account password** to a strong one before anyone else uses the site: sign in as an administrator, open the users page and reset each account (a temporary password is generated and the person must change it at first sign-in), and change your own password in Account Settings. Remove accounts you do not need.
9. **Smoke test** (below).

## 4. HTTPS behind the Hostforge proxy

- Session cookies: `SMS_SESSION_COOKIE_SECURE=1` forces the Secure flag even though the container sees plain HTTP. Check in the browser that the session cookie shows Secure and HttpOnly.
- The web pages decide HTTPS only from `HTTPS` / port 443; the Offering API also accepts `X-Forwarded-Proto: https`.
- **Client IP:** the audit log stores `REMOTE_ADDR` only, so behind the proxy it will show the proxy address, not the person's. This does not break anything; it only weakens the audit trail. Minimal fix (not done in this release): enable Apache `mod_remoteip` with the proxy's address range trusted. It needs the proxy range from Hostforge, so it is left as an open item.

## 5. Smoke test checklist

Sign in once per role (five roles: Super Admin, Admin, Staff, Academic Office, Academic Faculty) and confirm the menu matches the role. Then, as a user with the right permission:

- [ ] `/health.php` answers `{"status":"ok"}`; the site loads with its styles (if the pages look unstyled, the image lacks `assets/ui/dist`).
- [ ] Dashboard loads for each role.
- [ ] Curriculum Builder: BSIT 2026 opens (52 placements) and can be viewed.
- [ ] Subject Mapping: four tabs (Curriculum Map, Mapping Review, Graduate Outcomes, Course outcomes); open an outcome dialog.
- [ ] Prerequisites: rules list loads.
- [ ] Electives Manager: opens; the empty state reads "No elective groups yet" for BSIT 2026 (the service refuses pools of one subject, so no slot groups exist).
- [ ] Compliance Validator: run a validation and see the stored result (Met / Not met / Cannot determine; nothing says "compliant" or "approved").
- [ ] Curriculum Export: a protected export downloads (this needs the zip extension; "Encrypted export is not available on this server" means it is missing).
- [ ] CIMF advice panel opens (with AI off it says so; with AI on it answers).
- [ ] Idle-timeout warning appears after inactivity and the session ends.
- [ ] Sign out works and the protected pages then redirect to sign-in.
- [ ] Not reachable (expect 403 or 404): `/students.sql`, `/database/`, `/docs/`, `/tests/`, `/docker/`, `/.git/`, `/shared/setup.php`.
- [ ] Container log shows no PHP errors and no environment values.

## 6. Rollback

- **Code:** in Hostforge, redeploy the previous image/build, or switch the branch to an earlier release branch or tag of this repository. Do not move `demo-2026-11-13`.
- **Database:** restore from the dump taken before the import or the last backup: drop and recreate the empty database, then import the dump as in step 3. Take a new dump before every change on the host.
- Sessions are lost on any redeploy; people sign in again.

## 7. What is intentionally empty or incomplete

- **Equivalency** and **Offering History**: no records (no sourced data).
- **Grade Weighting:** component weights are unsourced and left unset.
- **Electives:** no elective groups for BSIT 2026 (see smoke test); professional-elective compliance rule shows "Cannot determine".
- Compliance rules: six library rules only; nothing is described as compliant, approved or an official BCP policy.
- The dataset is the team's BSIT 2026 data following CHED CMO No. 25, s. 2015; BCP approval is not verified.

## 8. Must fix or know before deploy

- The image has **not been built here** (the Docker daemon was not running when this release was prepared). Build once and watch the log; the new `zip` extension and the widened `.dockerignore` (`assets/ui/dist`, `api/`) are the two changes since the last verified build.
- Audit client IP shows the proxy (section 4).
- The PHP timezone is not set (UTC); the app stores UTC timestamps. Confirm that is acceptable for the demo.
- `API_KEY_PEPPER` must match local if API keys exist.
