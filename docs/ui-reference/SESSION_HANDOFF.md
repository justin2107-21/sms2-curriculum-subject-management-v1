# Session handoff

Updated 2026-11-06 (first written 2026-11-05) for whoever (or whichever session) continues this work. Read this first, then `Roles_and_Permissions.md`, `Dashboard_Plan.md`, `UI_Decisions.md` and `UI_Testing.md`.

## Where things stand
- Project: `C:\xampp\htdocs\sms-csms` (CSMS-SMS2, Bestlink College). PHP 8.0.30 (`C:\xampp\php\php.exe`, not on PATH), MariaDB 10.4, database `sms_db`.
- Branch: `ui-redesign-pilot`. Nothing has been pushed; `main` is the PR target.
- Recent commits, newest first (use `git log` for the rest):
  - `4b666fd` Builder tables: Code column text in the Subject Mapping blue (`#2163c5`)
  - `f592332` Builder slice 5: plain sidebar, view switchers, Curricula list retired
  - `369f66b` Builder slice 4: new version / new curriculum from the selector, More menu, renames
  - `2084287` Builder slice 3: plan editing (add, move, remove), subject table layout
  - `b70ceda` UI polish pass and selector / table / conflict refinements
  - `3c6b34a` Builder workspace (slice 2), `5de99cf` Academic Programs (slice 1)
  - Earlier (dashboards, baseline fingerprint fix, handoffs):
  - Dashboard verification tools: count verifier, synthetic volume (uitest only), timing
  - Finalize role dashboards: donuts, top-10, colours, export status, footer, data checks
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
| Role dashboards | **Finished.** One profile per role chosen from permissions on the server, only that role's cards in the payload. Counted-version rule: approved, then active, then draft with placements. Final chart rules: donut only for part-of-whole with at most six categories, bars with a value label at every end (zero included), top 10 with "View all", colour-blind-safe palette, "—" for averages without data, each fact once per dashboard, the CIMF launcher overlap is accepted for now (D8; the button will be redesigned later), page margins are equal. Program filter placement follows D10: page-level for Admin, Staff, Acad Office and Faculty; inside the pipeline card for Super Admin |
| Official exports | The export audit entry now stores `review_status`, `version_label` and the version id. The Admin card "Official exports" lists only exports stored as approved; older entries are not listed |
| Footer | "Bestlink College of the Philippines · CSMS-SMS2 © 2026" on every page that had the old text |
| Dashboard data checks | `tools/verify-dashboard-counts.php` compares every card, chart total and list with its own read-only SELECT: 109 checks on `sms_db`, 116 on `sms_db_uitest` with synthetic volume, 0 mismatches. Timing with 500 versions, 20,000 placements and 50,000 audit rows: every role under 210 ms, so no index migration is proposed |
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

## Curriculum Builder: finished (2026-11-06)
All five slices are committed. What it is now:
- **Pages:** `curriculum_builder.php` is the Builder (`builder_workspace.php` renders it); the same file with `$curriculumWorkspacePage='programs'` renders Academic Programs. `curricula.php` only redirects (302) with `program_id`, `curriculum_id` (or `id`), `version_id`. A partial address opens the newest active version under it (D4).
- **Builder:** shared Phase 1 / Phase 2 picker (footers "+ New curriculum" for Super Admin and Admin, "+ New version"), version header with More menu (rename version, rename curriculum, compare versions), tabs Plan / Details and references / Review history, editable Plan (add, move, remove per term, server refusals shown, conflict dialog), Code column blue. "Create revision" is the header button for references only.
- **Sidebar:** Main, Records, Curriculum (ten plain links), Setup (D12). Multi-view modules link to their first view and show a view switcher under the title (`pages/_module_views.php`). System Settings stays in Setup.
- **Tests (sms_db_uitest, one at a time):** `tests/curriculum-builder-http.php` (126 checks), `academic-programs-http.php` (86), `rbac-safety-http.php` (110), `rbac-final-roles.php`, `ui-consistency-static.cjs`. Probes in `tools/uitest/screens.cjs`: `builder` (-BuilderFlow), `planedit`, `versions`, `nav`, `ctxlayout`.
- **Not run:** `curriculum-revision-http.php`, `curriculum-reference-http.php`, `curriculum-comparison-http.php` were repointed to the Builder page but need the isolated Docker database.
- **Unused code left:** `curriculum-builder.js` still has Curricula-page branches (Academic Programs uses the file). Remove them in a later clean-up.
- `tools/uitest/setup-db.ps1` works again (the fingerprint blocker is fixed). After a rebuild run `DB_NAME=sms_db_uitest php tools/uitest/builder-fixtures.php`. Browser probes that create rows (-BuilderFlow, planedit, versions) leave rows behind; rebuild before the next module.

## Blocker found on 2026-11-05: deploy.php refuses sms_db after migration 027
**Fixed 2026-11-05: declared per-migration changes in `database/legacy_baseline.php`; the stored record is unchanged. The owner confirmed on `sms_db`: `deploy.php --status` shows 28 applied, none pending; the drift report shows no differences. Migrations 028 and later are now possible. `deploy.php --apply` refuses, before changing anything, a pending migration that changes covered state without a declared entry (`database/README.md`). The text below describes the problem as found and no longer applies.**

`php database\deploy.php --status` on the real `sms_db` now stops with **"Legacy baseline adoption record is invalid; manual review required."** The adoption record stores a fingerprint of the legacy `roles`, `permissions` and `role_permissions` tables (`csms_legacy_baseline_fingerprint()` in `database/legacy_baseline.php`), and 027 changed all three (new columns, the legacy admin role renamed, new roles, permissions and grants). Earlier migrations were kept out of the fingerprint by listing mutated tables and future permissions; 027 was not. Consequences:
- **No new migration (028 or later) can be applied to `sms_db` until this is resolved.** Nothing is broken at runtime; the site works.
- `tools/uitest/setup-db.ps1` fails for the same reason, because it runs `deploy.php` on a copy of `sms_db`. Workaround used on 2026-11-05: after the failed rebuild, run `DB_NAME=sms_db_uitest php tools/uitest/seed-users.php` (it also seeds the scenario fixtures); the copy is already imported by then.
- The guard was deliberately **not** changed. It needs the owner's decision: either make the fingerprint cover only the pre-027 legacy rows and columns (excluding what 027 added or renamed), or re-adopt the baseline with a reviewed new fingerprint. Either way: backup first, test on a `sms_db_uitest` copy, and do not weaken the check.

## Open decisions
| Item | Owner of the decision | Notes |
|---|---|---|
| BSIT unit target | The owner's adviser | CMO 25 s.2015 Section 8.1 (p.10) gives 146 minimum units; Section 23 (p.48) says GE falls from 54 to a minimum of 36 and the 18-unit balance may be replaced by professional courses "as long as the minimum total number of units is satisfied as articulated in Section 8.1". No target is loaded: a Super Admin creates a `MIN_TOTAL_UNITS` compliance rule once the figure is confirmed. Until then the dashboard says "No target set" |
| Rename demo data | The owner, on the app screens | `modules/curriculum/pages/curriculum_builder.php` and the two year-label code paths no longer depend on names. Remaining name mentions are in seed manifests and historical docs |
| Load the outcome catalog on `sms_db` | The owner | Command: `php database\seeds\load_ched_bsit_outcomes.php --confirm-database=sms_db` after a backup. It is already loaded on `sms_db_uitest` (13 outcomes) |
| Drop `sms_db_reconcile_test_20261102_retry01` | The owner | A 2 Nov baseline-reconciliation test copy with 7 user rows; nothing references it. `sms_db_archive_test` is required by `tests/archives.php` and is kept |
| `students` table | Decided | Kept for SMS2 integration, synthetic data, not used by CSMS; only Super Admin holds `student.*` |

## Curriculum Builder redesign
Plan: `Curriculum_Builder_Plan.md` (section 11 is the build log). **Finished**, see the section above.

## Later tasks recorded in the docs
**Right after Subject Mapping:** split `curriculum.update` so Admin can't edit placements.

Also later: version archive (own design); summer term support (pending BCP answer).

Drop `users.role`; faculty-subject link; switch the Faculty read scope to `approved`; update `tests/original_post_migration_readonly.php`, which is out of date: it still expects 5 migration history rows and 37 tables, and has not been changed since before migrations 026 and 027.

## Subject Mapping slices 1-3 (2026-11-06)
- Decisions: subjects map to program outcomes; placements stay in the Builder. Check mark now, no level, no migration. Rationale stays required: the slice 3 dialog prefills the last rationale, shown selected, and needs an explicit Enter or confirm (no silent save). Catalog is BSIT only; other programs show "No outcome catalog for this program yet." Graduate Outcomes tab stays. `--csms-code-blue: #2163c5` added and used by the Builder.
- **Removed with the old Map tab (accepted):** the placement grid, year pills, Place Subject and its add/edit/remove placement dialogs, and the prerequisite connections view. Prerequisites remain in Pre-requisite Configuration and the Builder conflict dialog.
- **Dependency:** notation (check, L/P/O or I/P/D) still awaits BCP. `mapCell()` in `subject-mapping-matrix.js` is the only place that draws a mark; a level (migration 028, nullable `contribution_level`) is not built.
- Built: shared `csms-tabs/panel/card/empty/search-field/term/ctable` in `css/csms-components.css` (Builder keeps its `builder-*` names); matrix in `modules/curriculum/assets/subject-mapping-matrix.js` (sticky Code and name columns, sticky header and footer, footer counts from visible rows, filters: search, year, semester, mapped status).
- Tests: `tests/subject-mapping-workspace.cjs` rewritten for the read-only map; `ui-consistency-static.cjs` updated. The old placement edit/remove tests went with the features. No HTTP test was added or run yet (planned with slice 3). `sms_db_uitest` now has the BSIT catalog (13) and 32 fixture mappings on version 42 (uitest only).

- **Slice 3 (editing):** matrix cells are buttons only with `curriculum.outcome_mapping.manage` on an editable version (otherwise read-only with a note). Checking a cell opens a rationale dialog (prefilled with the last rationale, selected, Enter or Save confirms, nothing saved silently); clearing a cell asks for confirmation and shows the server refusal when CLO alignments depend on it. Existing endpoints only; no schema change or migration.
- **Layout:** no nested vertical scroll. The page scrolls; the matrix card clips (`overflow: clip`), so the header and footer rows stick to the page (header below the 52px top bar). Only when the matrix is wider than its card does JS add `is-scrollable` (horizontal scroll, sticky Code and name columns); the sticky header then no longer follows the page scroll (known limit). Outcome codes use a slashed-zero monospace so IT01 and ITO1 differ (the data is IT01-IT13).
- **Tests:** `tests/subject-mapping-map-http.php` (new, runs only on `sms_db_uitest` with its own PHP server on port 8091: read, RBAC 403, required rationale, add, duplicate, dependent-CLO refusal message, remove, non-BSIT refusal, reference version 409; restores what it changes) and the extended `tests/subject-mapping-workspace.cjs`.

- **Slice 4 (Subject Trace and Mapping Review, 2026-11-06):** Trace results are a compact Builder-style table (Code in the Subject Mapping blue | Program | Curriculum | Version | Year / Semester | Required/Optional chip | "Open in Builder" link; the old "Open Placement" button is gone). The version status chip in the plan was not used: `mapping_list` does not return it and no server change was made. Mapping Review keeps the four structural checks and adds **Outcomes with no mapped subject** and **Subjects mapped to no outcome**, computed in the browser by `SubjectMappingData.outcomeReview()` from the existing outcomes `workspace` response (no new endpoint, no schema change). Each check is a compact row with a count and an expandable list (several can be open). Programs with no catalog show "No outcome catalog for this program yet." in place of the two checks (the structural checks still apply). "Outcomes never reaching D" is not built (needs levels). Review rows still open the placement details modal ("View details"); that modal and the old trace/review CSS are slice 5 cleanup.
- Tests: `tests/subject-mapping-workspace.cjs` (outcomeReview unit cases; both checks, expand/collapse, empty and all-mapped states, no-catalog state, Trace link) and `ui-consistency-static.cjs` pass. Browser probe `-Probe mappingtabs` in `tools/uitest/screens.cjs` (Super Admin, 1440 and 390, no overflow or console issues; on `sms_db_uitest` version 42: 11 outcomes unmapped, 36 subjects unmapped). `sms_db` .ibd sizes identical before and after.

## Pre-requisite redesign, slices 1-2 (2026-11-06; plan in `Prerequisite_Plan.md`, approved with answers)
- Decisions: existing same-term rules show as conflicts only, never changed; clones keep copying rules (a broken rule is flagged, not removed); `subjects.status` is varchar(20) (values seen: active, inactive); any non-active status will count as "not active" in the slice 4 check and the row shows the real status; Unlocks shows the full downstream chain as columns (slice 3); SHS has no special behaviour, only the limitation note; `create` endpoint kept.
- **Slice 1 (shared standard, in `css/csms-components.css`):** tab focus box only on keyboard focus; one clear X (32 px, 44 on small/touch screens, centred, native search cancel hidden) for every `.record-autocomplete`; `.csms-filter-row` and `.csms-select` (40 px, no clipped text); `.csms-row-click` table rows. Prerequisite page uses `.csms-tabs`, the filter row, a read-only badge ("Read-only for your role", by permission only: the workspace does not say whether a version is editable, the server refuses saves) and the limitation note. The two page-specific `top:7px` clear-button overrides were removed.
- **Slice 2 (Rules tab):** `workspace.placements` now carry `period_position` (additive, from `AcademicSequenceValidator::position`, null when year or semester cannot be verified). Rules tab = tables grouped by Year/Semester (Code blue | Subject | Requires | warning icon opening a modal with the server's messages | row menu: Edit/View, Remove, View in chain). Row click or Enter opens the edit dialog (managers) or the details dialog (read-only roles). Filter row has Search (Escape clears), Year, Semester, Has rules / No rules, and a summary. The required-subject picker lists only earlier-term subjects (client uses `period_position`), with hints for a first-term or unverifiable subject. The edit dialog shows Requires and Unlocks.
- **Not done yet:** slice 3 (Chain Viewer: double X on the chain picker is fixed by slice 1's shared X, but the label/arrow overlap and Requires | Unlocks are not built), slice 4 (shared review component, "rule uses an inactive subject", `subject_status` on placements), slice 5 (dead `pr-*` card CSS, `UI_Decisions.md`).
- Tests: `tests/prerequisite-workspace.cjs` (table, summary, row click, menu, warning modal, earlier-term picker), `tests/prerequisite-http.php` (period_position present and null for an unknown term; 84 checks, run with `DB_NAME=sms_db_uitest`, it uses its own disposable schema), `ui-consistency-static.cjs`. Browser probe `-Probe prereq [-BuilderFlow]` in `tools/uitest/screens.cjs`. On `sms_db_uitest` version 42 the page shows 5 subjects with rules, two flagged same-term conflicts. Full-page screenshots from `run.ps1` show the sidebar open over the content (the sidebar check leaves it open); use the probe's viewport shots for layout.

## Next tasks, in order
0. **Subject Mapping redesign (in progress; plan in `Subject_Mapping_Plan.md`).** Slices 1-4 are built (see "Subject Mapping slices 1-3" below). Slice 4 is built. Next: slice 5 (Graduate Outcomes restyle, dead code cleanup, docs). **Next after slice 3 (not built, only if needed):** a per-subject mapping dialog: one dialog per subject, checkboxes for all outcomes, one shared rationale. Consider it if matrix entry proves too slow for BSIT (52 subjects x 13 outcomes = up to 676 cells); it is also a good panel demo.
1. **Password reset for Admin and Super Admin:** `KENNETH123` still needs the reset the owner planned from the Users page as `csms_admin`. Review the reset flow and its audit entry before relying on it.
2. **Module page redesigns, starting with Pre-requisite Configuration.** Follow `UI_Audit.md`, `UI_Decisions.md` and the shared header, picker and state components. Then the other module pages in the sidebar order.
3. **Subject category field:** a migration adding a category to `subjects` (list to be agreed), the Subject Master form field and import support, then enable the "Units by subject category" card (currently a pending card).
4. **Outcome I/P/D level:** a contribution level on subject-to-outcome mappings under `curriculum.outcome_mapping.manage`, with an inheritance rule for cloned versions. It brings back an I/P/D chart.
5. **Faculty-subject link:** a table linking faculty users to subjects, an admin screen, and the permission it needs (needs the owner's approval). Then give Acad Faculty CLO management of their own subjects, and fill "My subjects" on their dashboard.

## How to run things
- Rebuild the test database and users: `powershell -ExecutionPolicy Bypass -File tools\uitest\setup-db.ps1`
- One role, both widths: `powershell -ExecutionPolicy Bypass -File tools\uitest\run.ps1 -Role staff -Widths 1440,390 -Urls /dashboard/dashboard.php`
- Scenario then checker: `-CtxScenario`, then `node tools\uitest\check-scenario.cjs <folder>`
- Dashboard correctness, read-only, any database: `php tools\verify-dashboard-counts.php sms_db` (the connection is opened READ ONLY)
- Dashboard load test on `sms_db_uitest` only: `DB_NAME=sms_db_uitest php tools/uitest/synthetic-volume.php` (rows prefixed `SYN-`; `--remove` deletes them, or rebuild the test database), then `DB_NAME=sms_db_uitest php tools/uitest/time-dashboard.php 15`
- Baseline tests: `php tests/legacy-baseline-declarations.php` (no database), `DB_NAME=sms_db_uitest php tests/legacy-baseline-fingerprint.php`
- Grants against the approved matrix, read-only, warns only: `DB_NAME=<database> php tools/rbac-drift-report.php`
- Authorization tests: `DB_NAME=sms_db_uitest php tests/rbac-final-roles.php`, `tests/rbac-safety-http.php`, `tests/dashboard-role-payload.php`; node tests: `tests/*.cjs`
- Git Bash loses backslashes in `node -e` and heredocs; write scripts with the Write tool. Files have mixed LF/CRLF, so edit helpers must match `\r?\n`.

## Notes from the dashboard finalization
- On `sms_db` the Staff and Faculty dashboards default to the counted version, which is the 3-unit active BSIT "123 v1", not PREORAL-2026 (157 units). That follows the approved counted-version rule; choosing a version in the filter changes it.
- `sms_db` has no Staff account (the old staff account became Acad Office in 027), so the Staff dashboard was checked there with the Staff permission set and an existing account.
- Screenshots taken right after load can catch charts mid-animation (blank bars at 390 px once); a second run was complete.
