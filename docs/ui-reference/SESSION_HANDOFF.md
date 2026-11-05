# Session handoff

Written 2026-11-05 for whoever (or whichever session) continues this work. Read this first, then `Roles_and_Permissions.md`, `Dashboard_Plan.md`, `UI_Decisions.md` and `UI_Testing.md`.

## Where things stand
- Project: `C:\xampp\htdocs\sms-csms` (CSMS-SMS2, Bestlink College). PHP 8.0.30 (`C:\xampp\php\php.exe`, not on PATH), MariaDB 10.4, database `sms_db`.
- Branch: `ui-redesign-pilot`. Nothing has been pushed; `main` is the PR target.
- Recent commits, newest first. The last two are the name fixes and this file, so use `git log` for their hashes:
  - `6917a51` Role dashboards
  - `7735827` Curriculum review: submit, approve and return
  - `ec795cb` Final user types: migration 027
  - `489f9e7` Batch 6: sidebar
  - `9fb9b9d` Working context server store: migration 026
  - `d394748` Batch 5 + shared working context
  - `5a2a83b` Batch 4, `5918f70` Batch 3
  - `24043b5` Dashboard redesign, logged-in UI testing, 5-minute timeout
- Untracked on purpose, never stage them: `assets/ui/preview/`, `docs/ui-reference/csmsui.zip`, `task2-review.diff`. `.env.uitest` is git-ignored.

## What is done
| Area | State |
|---|---|
| Consistency batches 1 to 6 | Done: tokens, buttons and fields, icons, page headers, live states, sidebar alignment and active style, burger reachable on phones |
| Dashboard redesign | Done, then replaced by the role dashboards below |
| Working context and migration 026 | Done: shared Program / Curriculum / Version picker, server store in `user_preferences`, applied to `sms_db` by the owner |
| Final roles and migration 027 | Done and applied to `sms_db` by the owner: Super Admin, Admin, Staff, Acad Office, Acad Faculty. Old roles stay inactive. Rollback script in `database/rollbacks/`. `users.role` is deprecated and ignored |
| Review workflow | Done: submit, approve, return with a reason, review events, audit entries, nobody decides their own submission |
| Faculty read scope | One setting, `CurriculumReadScope::FACULTY_READ_SCOPE`: `active_or_approved` now, switch to `approved` once approvals exist |
| Role dashboards | Done: one profile per role chosen from permissions on the server, only that role's cards in the payload. Counted-version rule: approved, then active, then draft with placements |
| Scenario fixtures | Done: the UI scenario uses its own `UITEST-*` records, so real programs and curricula can be renamed freely |

## Standing rules (from the owner, still in force)
1. **Never write to `sms_db` except through commands the owner runs.** I may read it. Backups and applies are given as commands for the owner.
2. **One job at a time.** Never run backups, rebuilds and tests together, and never run parallel jobs against MySQL. Check that `sms_db` `.ibd` file sizes are unchanged before and after every job.
3. **Test on `sms_db_uitest` only.** `tools/uitest/setup-db.ps1` rebuilds it from a fresh read-only backup of `sms_db`, applies pending migrations, seeds the test users and the scenario fixtures. Test users are `uitest.superadmin/.admin/.staff/.acadoffice/.acadfaculty`; credentials are in `.env.uitest`, never printed or committed.
4. **Commits use explicit paths. No `git add -A` or `git add .`. No push.** Commit only when the owner approves.
5. Do not weaken auth, authorization, CSRF, curriculum lifecycle rules or advisory-only CIMF. No PHP auth, session or database changes as part of UI work.
6. Reference PDFs stay in `C:\xampp\csms-reference-documents`, never copied into htdocs. Never print or commit API keys.
7. Every UI task ends with a logged-in check through `tools/uitest/run.ps1`, one role at a time, at 1440 and 390 px.
8. Defender: the exclusion for `C:\xampp\mysql\data` is in place (added by the owner on 2026-11-04). Do not change Defender settings.
9. **The 15:32 incident on 2026-11-04 is still unexplained.** `sms_db/grade_weighting_components.ibd` was emptied to 0 bytes at 15:32:27 and MySQL crashed at 15:33:05 while a backup read it; a second crash followed at 16:23:32 with no file empty. The table was rebuilt from the 15:31 backup, all 38 tables passed `CHECK TABLE` and matched row counts, the disk is healthy, and MySQL has been stable since the exclusion. The cause was not found. If it repeats, stop and report; the next suspect is the XAMPP MariaDB install itself.

## Open decisions
| Item | Owner of the decision | Notes |
|---|---|---|
| BSIT unit target | The owner's adviser | CMO 25 s.2015 Section 8.1 (p.10) gives 146 minimum units; Section 23 (p.48) says GE falls from 54 to a minimum of 36 and the 18-unit balance may be replaced by professional courses "as long as the minimum total number of units is satisfied as articulated in Section 8.1". No target is loaded: a Super Admin creates a `MIN_TOTAL_UNITS` compliance rule once the figure is confirmed. Until then the dashboard says "No target set" |
| Rename demo data | The owner, on the app screens | `modules/curriculum/pages/curriculum_builder.php` and the two year-label code paths no longer depend on names. Remaining name mentions are in seed manifests and historical docs |
| Load the outcome catalog on `sms_db` | The owner | Command: `php database\seeds\load_ched_bsit_outcomes.php --confirm-database=sms_db` after a backup. It is already loaded on `sms_db_uitest` (13 outcomes) |
| Drop `sms_db_reconcile_test_20261102_retry01` | The owner | A 2 Nov baseline-reconciliation test copy with 7 user rows; nothing references it. `sms_db_archive_test` is required by `tests/archives.php` and is kept |
| `students` table | Decided | Kept for SMS2 integration, synthetic data, not used by CSMS; only Super Admin holds `student.*` |

## Later tasks recorded in the docs
Split `curriculum.update` so Admin cannot edit placements; drop `users.role`; faculty-subject link; switch the Faculty read scope to `approved`.

## Next tasks, in order
1. **Password reset for Admin and Super Admin:** `KENNETH123` still needs the reset the owner planned from the Users page as `csms_admin`. Review the reset flow and its audit entry before relying on it.
2. **Module page redesigns, starting with Pre-requisite Configuration.** Follow `UI_Audit.md`, `UI_Decisions.md` and the shared header, picker and state components. Then the other module pages in the sidebar order.
3. **Subject category field:** a migration adding a category to `subjects` (list to be agreed), the Subject Master form field and import support, then enable the "Units by subject category" card (currently a pending card).
4. **Outcome I/P/D level:** a contribution level on subject-to-outcome mappings under `curriculum.outcome_mapping.manage`, with an inheritance rule for cloned versions. It brings back an I/P/D chart.
5. **Faculty-subject link:** a table linking faculty users to subjects, an admin screen, and the permission it needs (needs the owner's approval). Then give Acad Faculty CLO management of their own subjects, and fill "My subjects" on their dashboard.

## How to run things
- Rebuild the test database and users: `powershell -ExecutionPolicy Bypass -File tools\uitest\setup-db.ps1`
- One role, both widths: `powershell -ExecutionPolicy Bypass -File tools\uitest\run.ps1 -Role staff -Widths 1440,390 -Urls /dashboard/dashboard.php`
- Scenario then checker: `-CtxScenario`, then `node tools\uitest\check-scenario.cjs <folder>`
- Authorization tests: `DB_NAME=sms_db_uitest php tests/rbac-final-roles.php`, `tests/rbac-safety-http.php`, `tests/dashboard-role-payload.php`; node tests: `tests/*.cjs`
- Git Bash loses backslashes in `node -e` and heredocs; write scripts with the Write tool. Files have mixed LF/CRLF, so edit helpers must match `\r?\n`.
