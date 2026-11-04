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
| superadmin | `uitest.superadmin` | `super_admin` |
| admin | `uitest.admin` | `admin` |
| staff | `uitest.staff` | `staff` |
| acadoffice | `uitest.acadoffice` | `acad_office` |
| acadfaculty | `uitest.acadfaculty` | `acad_faculty` |

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
- Options: `-Role superadmin|admin|staff|acadoffice|acadfaculty|all`, `-Widths 1440,390`, `-IdleSeconds 300` (idle sign-out check), `-Out <folder>` (default `%TEMP%\csms-uitest\<timestamp>`). Also `-Height <px>` (taller viewport, e.g. 1500, to see the whole sidebar in a screenshot) and `-ServerPort` / `-CdpPort` for a different port.

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
powershell -ExecutionPolicy Bypass -File tools\uitest\run.ps1 -Role acadfaculty -Widths 390 -IdleSeconds 300 -Stay
# JavaScript off: server-side sign-out only
powershell -ExecutionPolicy Bypass -File tools\uitest\run.ps1 -Role staff -IdleSeconds 300 -NoJs
```
Run one job at a time: never run backups, database rebuilds and tests together, and no parallel runs against MySQL. `report.idle` lists `warnings` (time opened, countdown, text, buttons, focus, modal), `afterStay`, `aliveAfterOriginalTimeout`, `loggedOutAt` and `serverAfterIdle`. Note: the alive check makes one authenticated request, which refreshes the server's clock; it does not affect the client's timer.

## Style probe (before/after a styling batch)
```powershell
powershell -ExecutionPolicy Bypass -File tools\uitest\run.ps1 -Role admin -Urls "/dashboard/dashboard.php,/admin/users.php" -Widths 1440 -Probe styles
```
Adds `styleProbe` to `report-<role>.json`: per page, the computed size, font, radius and colours of every button, form control, table header and cell and empty-state block, plus (`focus`) which focusable controls show no outline or shadow when focused after a Tab press. Static state only: modals and panels opened by a click are not included. Compare two runs to measure a change.

## More probes
- `-Probe headers`: per page, the h1 (font, size, weight), its header container (class, height, padding), subtitle, header actions and the left edge of the title and content blocks.
- `-Probe context`: which pages have a Program / Curriculum / Version selector, its kind and height.
- `-CtxFlow -Program BSCRIM` (add `-Prefer "BSIT-DEMO,PREORAL"` to choose by text at the Curriculum and Version steps): on each page in `-Urls`, looks at the picker, opens the Program panel, chooses a program through the real UI (auto-open and auto-pick), reloads from the address, then Reset. Screenshots: initial, open panel, chosen.
- `-CtxNav -Program BSIT -Prefer "DEMO,PREORAL"`: chooses a context on the first URL, then opens each following URL bare (like a sidebar click), then presses F5, recording the picker and the saved data each time.
- `-CtxScenario -Urls /dashboard/dashboard.php`: the shared working context end to end on the five picker pages, starting from an empty session: nothing saved, choose, carry to two more pages, change the version on one page and see it on the others, a link with other ids, three invalid links (fall back to the saved context), reload.
- `-Probe live`: how many error / status message elements each page has and how many are live regions; on the first page it also injects test messages and fires real error and success toasts.
- Every page check also records `contentOverflow` (pixels the content area is wider than itself); anything above 0 means content is being cut or scrolled sideways.
- `-Probe sidebar [-Height 1600]`: every sidebar label (lines, mid-word breaks, text start, icon width, active fill, radius and contrast over the real composited background), the section headings and, at 390 px, whether the burger is reachable with the drawer open.
- `node tools/uitest/check-scenario.cjs <folder>`: checks a `-CtxScenario` run. **Every expected step (A to I) must be present and error-free**, then each outcome is checked. A step that throws is recorded with its error and fails the run (it used to vanish, ending the scenario with the message only on stderr). `-FailStep I` injects a failure to prove this.
