# CSMS Core Functional Foundation v1 — verification report

Date: 2026-09-29. Actual repository: `C:\xampp\htdocs\sms-csms` (the request's
`sms-temp` directory no longer exists). Scope: Phase A stabilization, followed
by Phase B synthetic development tooling. No later phase was started.

**Release addendum (2026-09-29): a separate clean-history release repository
has been prepared. See Historical Credential Remediation below for its scope,
verification and publication hold. Sections A-AT preserve the earlier report
about the original repository; they do not describe the new release history.**
Browser/screenshot QA was unavailable; manual visual review is still required.

## A. Initial Git status

`main`, 33 commits ahead of the existing `origin/main` tracking ref. Nothing
staged. Three modified files and one untracked recovered SQL backup.

## B. Existing changes found

- `css/csms-typography.css`: sidebar padding/icon sizes, section weight and a
  duplicated (identical) min-height declaration.
- `css/dashboard.css`: increased child-menu top margin.
- `modules/curriculum/pages/subjects.php`: unit input steps changed from 0.01 to 1.
- `sms_db_full_backup_20260928_recovered.sql`: local recovery dump containing
  private data, not source.

These were real content differences, not line-ending-only changes. `.agents`
was empty. No pre-existing test helpers were found in the empty tests directory.

## C. Resolution of existing changes

The user explicitly confirmed all three UI changes were intentional and should
be committed. They were preserved as supplied and included in the Phase A commit.
The local dump was not changed, staged or committed; it is now ignored and
blocked from HTTP download. No broad restore/reset/clean operation was used.

## D. Secret/config scan

No live credentials, cookies, password hashes, API keys or SQL backups were found
in the current tracked source or the outgoing implementation commits. The
recovered local dump is sensitive and intentionally untracked/ignored.

The deeper all-history scan found a seeded administrator password hash in
`students.sql` at baseline commit `31a6112`. That commit is also an ancestor of
the existing origin/main tracking ref. No secret value is reproduced here.
This finding stops the push under the user's explicit safety rule. No history
rewrite, credential change or forced push was attempted. A separate approved
credential/history remediation decision is required.

## E. Migration inventory

Baseline: `students.sql` (users/students, including 25 legacy template samples).
Ordered migrations:

1. 001 — normalized RBAC tables.
2. 002 — temporary admin/student role/permission mappings and user backfill.
3. 003 — audit logs and audit.view.
4. 004 — five curriculum foundation tables and initial capabilities.
5. 005 — version-scoped prerequisite/corequisite requirements.
6. 006 — elective groups and memberships.
7. 007 — program-assignment permissions.
8. 008 — subject-history permission.
9. 009 — canonical human-reviewed equivalency records.
10. 010 — weighting schemes/components.
11. 011 — compliance references/rules/runs/results.
12. 012 — curriculum-export permission.

002 has repeatable upserts/backfill. Treat every other migration as one-time and
ordered. Applied migrations were not edited. No new migration was required.

## F. Fresh-database migration

Passed on isolated test databases with the new CLI-only bootstrap. Result:
22 tables, 41 foreign keys, 34 permissions, admin 34 permission mappings,
student 3. A second bootstrap against a non-empty database was rejected.
All 22 SHOW CREATE TABLE definitions matched the working database after ignoring
AUTO_INCREMENT values. No hidden manual SQL step was needed.

## G. Current database integrity

MySQL and Apache were initially stopped; normal startup succeeded. MariaDB
reported recovery mode 0. No repair, recovery-mode setting or original-data
replacement was performed. A private pre-test backup was created outside the
web root. Table checks and all 41 foreign-key anti-join checks passed.

The backup was imported only into a separate private comparison database.
All pre-existing rows in all 22 tables—including earlier audit rows—were
compared and matched. That comparison database was then dropped.

Observed working counts after cleanup:

| Table(s) | Count |
| --- | ---: |
| academic_programs | 9 |
| subjects | 2 |
| curricula | 1 |
| users / user_roles | 2 / 2 |
| students | 25 |
| roles / permissions / role_permissions | 2 / 34 / 37 |
| audit_logs | 740 (430 before this task) |
| curriculum_versions / curriculum_version_subjects | 0 / 0 |
| curriculum_subject_requirements | 0 |
| curriculum_elective_groups / curriculum_elective_group_subjects | 0 / 0 |
| subject_equivalencies | 0 |
| grade_weighting_schemes / grade_weighting_components | 0 / 0 |
| compliance_references / compliance_rules | 0 / 0 |
| compliance_validation_runs / compliance_validation_results | 0 / 0 |

Audit evidence from testing is retained. Counter gaps were not reset.

## H. Admin authentication

Valid existing admin login passed; invalid credentials returned 401. Session
cookies retained HttpOnly/SameSite=Lax locally. Protected pages were accessible
after login. Logout succeeded; the old cookie jar subsequently received API 401
and protected-page redirects. No credentials were changed.

## I. Unauthorized/student RBAC

Disposable public registration with forged role=admin still created only the
student compatibility role with no domain permissions. Restricted navigation
was absent; all 18 protected domain child pages and Users/Permissions/Audit Logs
returned 403. Representative protected reads and mutations also returned 403
with a valid student CSRF token. Self-service Settings remained accessible.

## J. CSRF

Missing/invalid CSRF returned 403 across representative create/update/remove
operations in the modules. GET mutation requests returned 405. Authorized valid
POSTs succeeded. The existing single session-based CSRF mechanism is unchanged.

## K. Audit

Each successful tested mutation generated exactly one expected event. Duplicate,
validation, CSRF and authorization failures generated no misleading success
events; reads generated no new audit events. Existing audit metadata was checked
for secret-bearing keys and tested password/token values. Audit page access and
high-page clamping passed. It has no implemented search feature.

Early test-harness development attempted logout after disposable-user deletion,
producing logged best-effort audit FK failures. The harness was corrected to
logout first. The final passing run produced no new PHP application errors.
The genuine CSV runtime failure was logged, fixed and retested; logs were not
deleted or suppressed.

## L. Curriculum Builder

Programs create/edit/duplicate rejection, program lookup, curricula
create/edit/invalid-parent rejection, version create/duplicate rejection, and
master/version detail reads passed. Search, status filtering and pagination
were exercised with disposable records.

## M. Subject Master

Create/edit/duplicate rejection, negative-unit rejection, decimal backend unit
handling, code/title search, inactive filter and two-page pagination passed.
The user-approved whole-step input UI remains unchanged; backend decimal rules
remain authoritative and were not narrowed.

## N. Subject Mapping

Assign/edit/remove, duplicate rejection, program/curriculum/version context,
year/term filters, search and pagination passed. Removal preserved Subject
Master. Prerequisite, elective and weighting dependencies blocked removal.

## O. Pre-requisite Configuration

Prerequisite/corequisite creation and removal passed. Self-reference,
duplicates, wrong-version membership and two-/three-edge cycles were rejected.
Corequisites did not become prerequisite-cycle edges. No enrollment logic added.

## P. Electives Manager

Group create/edit, duplicate checks, membership add/remove, subject search,
non-empty group protection and placement dependency checks passed. Selected
group/reset behavior was inspected in source; not claimed as browser interaction.

## Q. Academic Strand/Course Assignment

Both program- and subject-centric page/read paths passed. Shared subject usage
across two programs, assignment/update/removal, filtering/pagination and
dependency protection reused the existing placement relationship/service.

## R. Subject Offering History

Both pages passed. Version comparison produced Added, Removed, Placement Changed
and Unchanged; cross-curriculum comparisons were rejected. Reads stayed
audit-free. History remains stored curriculum usage—not live classes.
Physical placement deletion still prevents immutable historical reconstruction.

## S. Subject Equivalency

Objective field comparisons, self-pair rejection, canonical reverse-pair
duplicate prevention, Pending/Equivalent/Not Equivalent updates and required
remarks validation passed. No automatic credit or equivalency decision added.

## T. Grade Weighting

Scheme/component create/update/remove, duplicate rejection and dependency checks
passed. Below 100 was Incomplete, exactly 100.00 Complete, and above 100 rejected.
No student-grade computation or grading policy was introduced.

## U. Compliance Validator

Only clearly artificial references/rules were used. All five existing rule
types were exercised. Draft/inactive sources were excluded; verified synthetic
source evaluation produced PASS/FAIL/NOT_EVALUATED. History/source snapshots
remained unchanged after source edits; semantic rule changes after history were
blocked. Curriculum technical status was unchanged.

## V. Curriculum Export

Context validation, preview/default subject section, optional requirements,
electives, weighting and compliance sections, CSV generation, permission checks
and print-view HTML passed. Generic not-official-approval wording remained.
Actual browser Print / Save as PDF is a manual check.

## W. Dashboard

Admin/student HTTP access, permission-aware summaries/actions and source-backed
database counts were inspected. Repository queries use real table counts and
technical version states, not fabricated enrollment statistics. Clock tests
verified seconds, midnight/noon and AM/PM behavior. No dashboard redesign.

## X. Sidebar/navigation/mobile

All 18 child pages rendered HTTP 200 for admin, with favicon tags and unique DOM
IDs. Independent menu state, active-child override, focusability, scroll state
and hamburger/drawer logic passed mock-DOM tests at 1440/1024/768/430/390/360.
Responsive CSS, compact modals, table overflow and accepted sidebar source were
inspected. These are source/runtime logic checks, **not visual screenshots**.

## Y. Search/filter/pagination

All tested domain list families accepted 10/20/50, rejected invalid page sizes
and malformed page/search input, and safely clamped high pages. Program/subject
fixtures proved two non-overlapping pages. Literal LIKE wildcard searches and
SQL-looking strings stayed safe. Existing Audit Logs uses safe fixed-size
clamping rather than the domain pagination API.

## Z. Destructive/dependency protection

Placement removal did not delete subjects; prerequisite/elective/weighting
dependencies were rejected. Non-empty groups/schemes were protected. Existing
confirmation markup/handlers were retained. No master-record delete feature or
cascading academic delete was added.

## AA. Static validation

Passed: 109 PHP lints, 17 JS/CJS syntax checks, 18 CSS structural checks,
git diff --check, Apache configuration syntax, CSV escaping/CRLF/formula tests,
and deterministic frontend tests. CSS checks are structural, not a full browser
standards/conformance test.

## AB. Verified defects fixed

1. Apache served the local SQL backup and Git metadata without authentication.
   Root .htaccess now denies SQL/runtime/config/test artifacts and repository
   internals. Backup, .git/HEAD, seed and test URLs returned 403; login/favicon
   remained 200. Backups are also ignored by Git.
2. XAMPP PHP 8.0 rejected the exporter's sixth fputcsv argument. A version-safe
   fallback preserves escaping and only converts the record terminator to CRLF.
   The PHP manual documents the sixth EOL parameter as added in 8.1:
   [official fputcsv documentation](https://www.php.net/manual/en/function.fputcsv.php).
   Live CSV and round-trip regression then passed.

No institutional business rule, schema, permission mapping, session mechanism or
CSRF implementation was changed.

## AC. Regression cleanup

All DEV-REGTEST fixtures and disposable accounts were removed by recorded IDs.
No original users/students/domain rows changed. No counters reset. The final
live suite passed 1,559 checks. The recovered root dump was preserved unchanged;
the additional private pre-test backup is outside the web root.

## AD. Synthetic seed files

- database/seeds/development_seed.sql
- database/seeds/development_cleanup.sql
- database/seeds/development.php (mandatory CLI transaction/provenance wrapper)
- database/seeds/README.md

## AE. Exact synthetic contents

42 domain rows: 2 programs, 6 subjects, 2 curricula, 3 versions, 11 placements,
3 requirements, 1 elective group, 2 members, 3 review pairs, 1 weighting scheme,
2 components, 1 artificial reference and 5 artificial rules. Details and exact
manual commands are in [the seed guide](../database/seeds/README.md).

## AF. Seed safety/idempotency

Explicit database/actor/development confirmation required. Existing active
manifest or DEV-DEMO identifier collisions cause clear refusal, not overwrite.
Aggregate audit provenance and all inserts share one transaction. A forced
mid-seed failure rolled back earlier inserts. No seed account/password,
permanent helper table, table truncation or counter reset exists.

## AG. Cleanup safety

Exact IDs and row fingerprints come from append-only seed provenance. Changed
rows cause refusal. External dependencies roll back the whole cleanup. Only
fully owned synthetic validation snapshots are additionally removable.
Unrelated data and audit records survive. Repeat cleanup fails harmlessly.

## AH. Isolated seed test

40 checks passed on a separate freshly migrated database, including forced
rollback, collisions, reruns, changed-record refusal, external-dependency
rollback, all-table unrelated-row comparison and reload/cleanup. Isolated test
actors/sentinels were removed. All temporary validation databases were dropped
after inspection; no temporary schema remains.

## AI. Demo workflow

All 14 requested demonstration areas were checked through their existing
services, including 1 Added / 1 Removed / 1 Changed / 3 Unchanged, shared usage,
three review decisions, 100.00 weights, artificial 3 PASS / 1 FAIL /
1 NOT_EVALUATED, and in-memory export. Full interactive demo visualization
remains manual. No seed data was loaded into the working sms_db.

## AJ. Files created

Root .htaccess; database/bootstrap.php; database/inspect.php; the four seed files;
tests/FoundationHttpClient.php; tests/foundation.php; tests/csv_export.php;
tests/frontend.cjs; tests/development_seed.php; tests/README.md; this report.

## AK. Files modified

.gitignore; database/README.md; CurriculumExportService.php; the three
user-approved existing UI edits (csms-typography.css, dashboard.css, subjects.php).
No applied migration, authentication handler, permission mapping or module
business rule was rewritten.

## AL. Local commits

- a8555b6 — chore: stabilize CSMS core foundation
- 0fd475d — feat: add synthetic development seed data
- This report is recorded in a separate documentation commit.

## AM. Final Git state

Implementation working tree was clean after both scoped commits. The report is
committed separately. Local SQL recovery dump remains ignored, not tracked.
No temporary cookie jar, generated report/export, credential file or throwaway
web testing endpoint was committed. Maintained CLI tests are intentional source.

## AN. Milestone tag

Not created. The historical-credential safety gate is unresolved; the formal
milestone is not complete. No existing tag was overwritten.

## AO. Remote

Configured origin matches the expected CSMS repository:
`https://github.com/justin2107-21/sms2-curriculum-subject-management.git`.
No remote was changed.

## AP. Main push

Not attempted, as required by the historical credential finding. No force push.

## AQ. Tag push

Not attempted; no milestone tag created.

## AR. Manual visual checks remaining

Responsive appearance at the six requested widths, modal focus/close/return,
autocomplete positioning/selection, elective selected-group interactions,
sidebar animation/scroll appearance and actual browser Print/PDF flow.
No browser automation execution tool was available; no screenshot QA is claimed.

## AS. Client-validation dependencies

Final roles, approval/status semantics, academic classifications, version/year/
term conventions, waiver/cross-program rules, elective quotas, equivalency
criteria, grading policies, real regulatory references/rules, Registrar
integration and official export formats remain unconfirmed. None was invented.

## AT. Final status

The CSMS core passed the reported automated functional checks and the repository
contains a controlled synthetic development dataset. Synthetic records do not
represent official Bestlink College of the Philippines data, curricula,
policies, or processes.

The formal **CSMS Core Functional Foundation v1 release is not yet complete**:
historical credential review/remediation must be approved and resolved before
the milestone tag and authorized pushes. Manual visual confirmation remains
explicitly outstanding. No CSV import, JWT, CIMF/OpenAI or later phase started.

## Historical Credential Remediation

### Finding and remote exposure

A fresh `git fetch origin` confirmed that baseline commit
`31a6112ad774fb68dc25c69f8882b94a6b354937` is an ancestor of `origin/main` in
the original repository. Both original local `main` and fetched `origin/main`
pointed to `b73fbdb29a8f76c113bca37941181ec636118ce4`.

The baseline contains a reusable template administrator credential in
`students.sql` (password hash and password text) and `shared/setup.php`
(the same password text). No credential value is reproduced here.
The current versions of both files no longer contain that credential.

The recovered template password was verified against its historical hash,
then tested privately against both current `sms_db` user password hashes:
neither matched. No current account retains the historical hash. The user
confirmed that the template password was never reused outside this local
template. No working account password was changed.

The source-history scan covered all 42 reachable commits and 384 unique blobs,
including the local checkpoint ref. It checked the known password/hash,
additional password hashes, common provider tokens, private keys, JWT values,
credential literals/URLs, session cookies and environment-file paths. Only
the above two files at the baseline commit were flagged. This is a targeted
pattern scan, not a guarantee against every possible encoded/unknown secret.

### Selected remediation

Because remote-history dependencies were uncertain, the user selected a clean
new repository/history rather than rewriting the existing GitHub repository.
The release copy is:

`C:\Users\esquitajustin\Documents\csms-core-foundation-v1-clean`

Only tracked source from the original HEAD was exported with `git archive`.
No existing `.git` directory, checkpoint/backup ref, ignored SQL dump, local
configuration, credential file or session file was copied. A new `main` branch
starts with one independent root commit; it has no parent from the old history.
Source differences from the exported snapshot are this report and removal
of pre-existing extra blank lines at EOF in `.github/workflows/php-lint.yml`,
`shared/csrf.php` and `shared/http.php`, found by the initial-commit whitespace
check. No executable statement changed. No remote is configured on the new
repository.

`students.sql` remains required by `database/bootstrap.php`: it creates the
baseline users/students tables and legacy sample students. It seeds no user
credential. Administrator provisioning uses the existing guarded, local-only
development setup and an operator-supplied password. No bootstrap/authentication
code or applied migration needed changing.

The original repository, its refs, its ignored recovery dump and the existing
GitHub remote were left unchanged. No local history rewrite or backup ref was
needed. No reflog/object pruning or claim of physical secure deletion is made.
The OLD remote/history still contains the exposed obsolete template credential;
creating this new history does not erase clones, caches or old GitHub objects.

### Verification

- Fresh isolated bootstrap: baseline and all 12 migrations succeeded; 22
  tables, 41 foreign-key integrity checks, 34 permissions, admin 34 permissions,
  student 3 permissions. The fresh schema contained zero users/password hashes.
- Seed safety: all 40 checks passed, including partial-write rollback,
  collision/rerun refusal, changed-record/dependency refusal, ownership-based
  cleanup and preservation of unrelated rows. Synthetic dataset contains no
  account credentials or official institutional curriculum/regulatory content.
- Clean-copy HTTP regression: all 1,559 maintained checks passed using a
  temporary loopback-only PHP server and the isolated database. Coverage
  includes admin login, invalid-password rejection, HttpOnly/SameSite sessions,
  logout invalidation, 401/403/405 responses, valid/missing/invalid CSRF,
  database RBAC, Audit Logs access, mutation audit counts and fixture cleanup.
- Fresh setup was additionally exercised with a generated, in-memory temporary
  administrator password: valid CSRF succeeded, missing/invalid CSRF failed,
  and the admin RBAC mapping was created. Setup was disabled after provisioning
  and returned 404. No working development credential was replaced or exposed.
- The temporary server was stopped; no PHP/application error entries were
  emitted. These HTTP checks exercise PHP application behavior, not Apache
  deployment rules or screenshot/manual-browser QA.
- Static checks: 109 PHP lint checks, 17 JavaScript/CJS syntax checks, 18 CSS
  structural checks, mock sidebar/clock tests, CSV compatibility tests and
  `git diff --check` passed. CSS structural checks are not full visual QA.
- Temporary database `sms_db_foundation_test_credential_20260929` was inspected
  after fixture cleanup, then dropped explicitly. It contained only baseline
  rows, the disposable setup account and disposable test audit evidence. No
  `sms_db_foundation_test_*` schema remains. The working `sms_db` was not mutated;
  final counts include 2 users, 9 programs, 2 subjects, 1 curriculum and 742 audit
  records, with no foreign-key/table-integrity issues.
- The new committed history is scanned again before tagging. Only the new
  root commit and its annotated milestone are release-bound; no old ancestor
  or credential-bearing bootstrap revision may be reachable from those refs.

### Final release status and publication boundary

The clean repository is the release candidate, not the original repository.
After successful final committed-history/static verification and a clean
working tree, its local annotated milestone is `csms-core-foundation-v1`,
with message `CSMS Core Functional Foundation v1`.

No push is authorized/performed in this remediation path. A normal push to
the existing `origin/main` cannot remove its exposed history and would not
fast-forward from this new independent root. No force push, remote replacement
or new GitHub repository creation was performed.

Safest next action: approve and create/select a NEW EMPTY GitHub repository,
review the clean local milestone, then explicitly authorize publishing this
new `main` and milestone there. Do not fetch/merge/import the old history into
the clean repository or mirror/push all refs from the original repository.
Any retirement/remediation of the old remote must be coordinated separately
with its users. Manual visual review remains a separate outstanding check.
