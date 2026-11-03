# Logged-in UI testing

Every UI task ends with a logged-in check: real sign-in, each role, 1440 px and 390 px, console errors and failed requests.

## Safety rules
- Tests use **`sms_db_uitest` only**. `sms_db` is only read (a `mysqldump --single-transaction` backup); it is never dropped or written.
- `tools/uitest/guard.php` rejects any database name that is not `sms_db_*` or that is `sms_db`; `setup-db.ps1` additionally hard-codes `sms_db_uitest` as the only database it may drop or create. `tests/uitest-guard.php` covers the guard (DB-free).
- No application code changes and no login bypass: the app already reads `DB_NAME` (`shared/database_config.php`), which is set **only** for the test server process. XAMPP keeps using `sms_db`.
- Test users exist only in `sms_db_uitest`. Credentials live in `.env.uitest` (ignored by the `.env.*` rule; check with `git check-ignore -v .env.uitest`). Passwords are never printed or committed.

## Test users
| Key | Username | Role |
|---|---|---|
| admin | `uitest.admin` | `system_administrator` |
| staff | `uitest.staff` | `academic_curriculum_staff` |
| reviewer | `uitest.reviewer` | `department_head_curriculum_reviewer` |
| viewer | `uitest.viewer` | `faculty_academic_viewer` |
| legacyadmin | `uitest.legacyadmin` | legacy `admin` (compatibility role; created only if it exists) |

Roles are assigned through `user_roles`; no direct permission grants. Seeding stops if a role is missing (no fallback).

## Set up or reset
```powershell
powershell -ExecutionPolicy Bypass -File tools\uitest\setup-db.ps1
```
Takes a fresh backup into `C:\xampp\csms-backups\`, prints the database it will drop, rebuilds `sms_db_uitest`, seeds the users and rewrites `.env.uitest` with new random passwords.

## Run
```powershell
powershell -ExecutionPolicy Bypass -File tools\uitest\run.ps1 -Role all -Urls "/dashboard/dashboard.php,/dashboard/dashboard.php?program=109"
```
- Starts `php -S 127.0.0.1:8090 -t C:\xampp\htdocs` (URLs keep `/sms-csms/`, e.g. `http://127.0.0.1:8090/sms-csms/dashboard/dashboard.php`), bound to 127.0.0.1 only, and always stops it.
- `tools/uitest/screens.cjs` drives headless Edge over the DevTools protocol (no extra packages), signs in through the real form, and saves full-page screenshots.
- Options: `-Role admin|staff|reviewer|viewer|legacyadmin|all`, `-Widths 1440,390`, `-IdleSeconds 300` (idle sign-out check), `-Out <folder>` (default `%TEMP%\csms-uitest\<timestamp>`).

## Reading the output
`report-<role>.json` per role: `login.ok`, and per page `finalPath` (a redirect to sign-in or another page shows here), `horizontalOverflow`, and `issues` (`console`, `exception`, `log`, `http` ≥ 400, `network`). The idle check records the warning (`warnings`), `loggedOutAt` and the server's answer to a request with the expired session.

Note: full-page screenshots show the fixed sidebar only for the first viewport height; that is a capture artefact, not a layout bug.

## Checks that run on every visited page
- **Sidebar burger:** a real mouse click on the burger must flip the sidebar's `collapsed` state and a second action must restore it. On narrow screens (≤ 900 px) the open sidebar covers the burger on every page, so the return step is a tap on the dimmed overlay; `report-<role>.json` records `closedVia: "burger" | "overlay"`. The run exits non-zero if any page fails (`failures` in the report). Pages with neither burger nor sidebar report `n/a`; having only one of them fails.

## Idle-timeout and warning checks
```powershell
# No input: warning at 240 s, sign-out at 300 s
powershell -ExecutionPolicy Bypass -File tools\uitest\run.ps1 -Role staff -Widths 1440 -IdleSeconds 300
# Click "Stay signed in" 10 s after the warning opens, then idle again
powershell -ExecutionPolicy Bypass -File tools\uitest\run.ps1 -Role viewer -Widths 390 -IdleSeconds 300 -Stay
# JavaScript off: server-side sign-out only
powershell -ExecutionPolicy Bypass -File tools\uitest\run.ps1 -Role staff -IdleSeconds 300 -NoJs
```
Runs can go in parallel if each gets its own `-ServerPort` and `-CdpPort` (and a different role). `report.idle` lists `warnings` (time opened, countdown, text, buttons, focus, modal), `afterStay`, `aliveAfterOriginalTimeout`, `loggedOutAt` and `serverAfterIdle`. Note: the alive check makes one authenticated request, which refreshes the server's clock; it does not affect the client's timer.
