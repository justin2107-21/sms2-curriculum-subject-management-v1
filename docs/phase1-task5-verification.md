# Phase 1 Task 5 verification

Result: VERIFIED for the implemented comparison scope, 2026-10-12.

Workspace: `C:\xampp\htdocs\sms-csms-task5`.
Branch: `feature/phase1-task5-curriculum-comparison`.
Parent: `6c40d42c2bf82fa88557603c224bbeb2ac6f6129`.
The final commit SHA is reported after committing; this report is part of that commit.
Task 4 was clean at the exact expected branch/commit before creation. The Task 5
path and branch were unused. No serious Task 4 ownership/integration defect was
identified in the inspected cloning paths. No earlier source files were edited.

**Implementation and academic model**

`SubjectOfferingHistoryService::compareRevision()` supplies the integrated report
through `subject_history_actions.php?action=compare_revision`. It requires an
existing curriculum, a proposed version in that curriculum, and that proposal's
exact protected reference baseline. An omitted reference defaults to the baseline.
Invalid roles, self-pairs, unrelated references and cross-curriculum IDs fail.
The two versions share curriculum ownership, and therefore program ownership.
Archived records remain readable by explicit ID under existing History visibility.
Comparison never changes technical status, role, review state or baseline.

The existing `compare_versions` API remains an ordinary generic stored-history
compatibility read. It is not the Task 5 reference/revision report. Existing callers
and regression tests retain that behavior; the integrated page uses the strict API.

`SubjectOfferingHistoryRepository::comparisonState()` reads placements, snapshots,
shared subjects, requirements, elective groups/memberships, weighting schemes/
components, and recorded clone audit lineage. `CurriculumHistoryComparison`
calculates the report without database writes.

- Subjects match only by shared `subject_id`. Independent placement IDs are not
  equivalent. Similar titles never establish equivalency. Duplicate identities in
  defensive input are unresolved rather than merged.
- Year, semester, ordering and required/optional flags use each version's owned
  placement records. Reference records remain protected by unchanged Task 2 policy.
- Reference subject code, title, units, lecture/laboratory units and description
  come only from complete immutable snapshots linked to an attached curriculum
  source. Capture date and provenance are displayed. Incomplete/missing evidence
  yields `Historical subject details unavailable`, null historical attributes and
  unresolved coverage; present-day Subject Master never fills the gap.
- Revision subject attributes are explicitly present-day shared Subject Master
  data. Revision placements are current version-owned data. Clone snapshots are
  identified as creation-time evidence, not the revision's current values.
  Differences from reference subject snapshots are shown before/after and flagged
  unresolved as to revision-specific intent, rather than asserted intentional
  revision academic changes.
- Added/removed/moved/changed/unchanged/unresolved subject categories can overlap.
  Changed covers required/optional classification; moved covers year/term/order.
  `unique_changed_subjects` counts each confirmed added/removed/placement-changed
  subject once. Academic differences are separate shared-data observations.
  Subject categories exclude relationship changes, which are reported separately.
  Global summaries and relationship labels do not change when paginating subjects.
- Prerequisites/corequisites match shared target and required subject IDs plus
  type, ignoring relationship row IDs. A sole type replacement on the same pair
  is a type change; independent additions/removals remain separate. Missing owners
  and ambiguous placements are unresolved. No invalid relationship is repaired.
- Elective groups use the required Task 4 clone audit `group_id_map` where available,
  scoped to the exact source version. Otherwise exact group name is the disclosed
  business key. Members use shared subject IDs. Name, description, year/term,
  required selection count, technical status and membership differences are shown.
  Without lineage, simultaneous unmatched names leave rename equivalence partial.
- Weighting schemes use shared placement subject identity; components use exact
  component names. Description, decimal weight and ordering changes are shown.
  Added/removed names are visible in before/after component lists. Changed scheme
  subject association appears as removed/added schemes. Component rename identity
  is not inferred from unrelated component IDs.

No new schema, migration, permission, module, academic workflow or AI service was
introduced. Migrations 001–023 and mutation/security services are unchanged.

**Provenance and UI**

Curriculum Builder → selected proposed version → References & Baseline →
`Compare revision with reference` opens the existing `curriculum_history_view.php`
with curriculum/revision IDs. The reference defaults on the server. History's
existing program/curriculum/version autocomplete controls are reused; role-filtered
reference/revision suggestions replace arbitrary pairing in this page. Selecting
a new revision clears the old reference selection so the recorded baseline applies.

The existing History cards, tables, responsive scroll containers, details modal,
fonts and navigation display all subject categories, including unchanged records.
Before/after placement and subject attributes are visible directly in the table
and in details. Separate tables show requirements, electives and weighting.
Loading a new context hides the previous report; stale successful responses cannot
replace a newer selection, and stale request errors are ignored.

Reference/revision labels, role, technical status and review workflow are distinct.
Document code/title/issuer/kind/purpose, attachment-time locator/effective date and
current source identity/locator are taken from linked records. Supporting identity
event locators and identity/origin/applicability assessments are shown separately.
Task 3.1 stale assessment and changed-attachment warnings are reused. No section,
document title, source verification or academic applicability is invented.
CHED sample sources are labeled demonstration references; all reports display
`Institutional adoption/approval not verified`. Active/approved/confirmed values
never imply BCP adoption. Source strings and URLs are escaped; only HTTP(S)
locators become clickable links. Other locators remain text. No external AI call
is made by comparison; the existing CIMF button stays disabled for this report.

**Read-only security and concurrency**

Authentication and the existing `subject_history.view` permission protect the
GET-only endpoint. POST/PUT/DELETE fail with 405. No mutation permission or default
role grant was added. CSRF helpers and existing mutation endpoints are unchanged.
Every requested version is checked server-side; client lifecycle fields have no
effect. SQL values are bound through prepared statements. Domain/not-found errors
use existing 422/404 handling; internals are not exposed.

The service rejects an already active caller transaction. It sets REPEATABLE READ
for its next transaction only, then starts `WITH CONSISTENT SNAPSHOT, READ ONLY`.
All ownership, lifecycle, provenance, snapshot and structure queries use that same
transaction/connection. Commit/rollback releases the report view. There are no
FOR UPDATE reads, academic write locks, audit inserts or global/session isolation
changes. Existing Task 2.1 authoritative current reads for mutations remain intact.

The concurrency suite intercepts an actual statement boundary using a test-only
mysqli subclass, without a production test hook. After reference/provenance reads,
an independent connection atomically commits proposal placement, requirement,
elective and weighting edits before the report reads the proposal. The in-flight
report sees all old values; the next report sees all new values. The independent
writer completes without waiting on report row locks. MariaDB rejects a deliberately
attempted write inside the report transaction with error 1792. READ COMMITTED
session default remains unchanged after the report. Nested transaction rejection
leaves the caller's transaction active. Full-table digests prove comparison itself
creates no rows or audit events.

**Executed verification**

Focused commands (each through a newly initialized guarded disposable server):

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File tests/version-protection-isolated.ps1 -OnlyTest curriculum-comparison.php
powershell -NoProfile -ExecutionPolicy Bypass -File tests/version-protection-isolated.ps1 -OnlyTest curriculum-comparison-http.php
powershell -NoProfile -ExecutionPolicy Bypass -File tests/version-protection-isolated.ps1 -OnlyTest curriculum-comparison-concurrency.php
```

Final focused results: domain 44, HTTP 32, concurrency 11 checks; frontend 46 checks.
Earlier domain runs exposed test expectations for mysqli integer values, supported
pagination sizes and union cardinality. Those test expectations were corrected,
without weakening application assertions or changing unrelated legacy tests. No
success commit was made from a failed run.

After stabilization, the full gate ran once and exited 0:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File tests/version-protection-isolated.ps1
```

Full gate: **27/27 database suites; 5/5 supported frontend suites passed**.
Local output: `%TEMP%\csms-task5-release-gate.log`.

| Database suite | Observed result |
|---|---|
| deployment_migrations.php | PASS, 31 checks |
| curriculum_lifecycle_schema.php | PASS, 70 checks |
| curriculum-reference-schema.php | PASS, 18 checks |
| curriculum-reference-baseline.php | PASS, 42 checks |
| curriculum-reference-http.php | PASS, 82 checks |
| curriculum-reference-concurrency.php | PASS, 4 checks |
| curriculum-revision.php | PASS, 60 checks |
| curriculum-revision-http.php | PASS, 60 checks |
| curriculum-revision-concurrency.php | PASS, 11 checks |
| curriculum-comparison.php | PASS, 44 checks |
| curriculum-comparison-http.php | PASS, 32 checks |
| curriculum-comparison-concurrency.php | PASS, 11 checks |
| version-protection.php | PASS, 260 checks |
| version-protection-http.php | PASS, 458 checks |
| curriculum-concurrency.php | PASS, 11 checks |
| prerequisite-service.php | PASS, 73 checks |
| electives-service.php | PASS, 64 checks |
| subject-mapping-http.php | PASS, 199 checks |
| electives-http.php | PASS, 94 checks |
| archives-http.php | PASS, 21 checks |
| academic-sequence.php | PASS, 17 checks |
| search-ranking.php | PASS, 19 checks |
| academic_fk_compatibility.php | PASS, 40 checks, 61 FKs |
| legacy-fk | PASS, 396 checks, 61 FKs |
| prerequisite-http.php | PASS, 80 checks |
| cimf.php | PASS, exit 0 |
| electives-foundation | PASS, 1,800 foundation + 95 HTTP checks |

Supported frontend suites passed: `frontend.cjs`, `phase2_ui.cjs`, `cimf-modal.cjs`,
`curriculum-revision-ui.cjs`, `curriculum-comparison-ui.cjs`. These use deterministic
DOM doubles and do not claim visual browser QA. Three unrelated legacy frontend
failures documented by Task 4 (curriculum-cascade, search-ranking and its importing
electives-workspace suite) were not changed, rerun or represented as Task 5 passes.

PHP lint passed for all 201 PHP files with `C:\xampp\php\php.exe -l`.
Node syntax checks passed for all 41 JS/CJS/MJS files with
`C:\Program Files\nodejs\node.exe --check`. `git diff --check` passed.

Full-gate identity before test writes: MariaDB 10.4.32, loopback 127.0.0.1 port
52947; canonical datadir
`C:\Users\ESQUIT~1\AppData\Local\Temp\csms-task2-f6212e928d424dccbc1ef98b85ab96d7\data\`;
foreign_key_checks=1. New schemas used guarded names
`csms_docker_test_task2_run_<index>_<random hex>`, with matching explicit database
confirmation. For example comparison domain used
`csms_docker_test_task2_run_9_415c5b72`, HTTP `_10_42f0636a`, concurrency `_11_768bf875`.
The runner initialized the new server, verified identity before CREATE/migrations,
dropped its schemas, shut down that server and removed its temporary datadir.
PHP 8.0.30 and Node 24.11.1 were used. No normal XAMPP/academic/production server
was connected to. Synthetic fixture corruption simulates missing history/shared
drift only in disposable schemas; comparison never repairs or rewrites evidence.

**Acceptance evidence**

D = domain suite; H = HTTP suite; C = deterministic two-connection suite;
U = executed comparison frontend handlers/renderers; G = full isolated gate.

| Criterion | Result | Implementation and observed evidence |
|---|---|---|
| T5-01 | PASS | History service validates exact baseline/role/ownership; D/H missing IDs, invalid roles, wrong baseline and cross-curriculum forgery |
| T5-02 | PASS | Shared subject identity; D independent clone IDs, unrelated same-title subjects and defensive ambiguous duplicate input |
| T5-03 | PASS | Version placements plus captured/current academic values; D four placement fields, six academic fields and overlapping categories; U before/after |
| T5-04 | PASS | Complete source-linked reference snapshots only; D shared-master drift leaves reference values unchanged and clone-time evidence distinct |
| T5-05 | PASS | Shared subject-pair/type keys; D identical independent IDs and added/removed/type-changed relationships; U actual labels |
| T5-06 | PASS | Clone lineage/name keys; D rename, selections, memberships, removed/new groups and unresolved rename without lineage; U partial warning |
| T5-07 | PASS | Scheme subject ownership/exact component names; D decimal weights/order, component additions/removals and changed applicable association |
| T5-08 | PASS | Stored source identities/assessments/adoption separated; D not-assessed sample remains honest; U synthetic CMO metadata, locator, stale and unverified adoption labels |
| T5-09 | PASS | Missing/incomplete snapshots are unresolved, historical attributes null; D honest counts and pagination; U missing-history/partial notices |
| T5-10 | PASS | GET + authentication + subject_history.view, prepared ownership checks/read-only transaction; H 401/403/405/404/422 and no-write digests; C error 1792 |
| T5-11 | PASS | Existing Builder link and History controls/cards/tables/modal; H actual pages, U link/default pairing/deep-link logic and stale-response handling |
| T5-12 | PASS | G 27 DB + 5 supported frontend suites, PHP/JS lint, diff check; all historical migrations and mutation protections unchanged |

**Changed files and purpose**

- `modules/curriculum/services/SubjectOfferingHistoryService.php`: strict pairing and consistent read-only report transaction.
- `modules/curriculum/services/CurriculumHistoryComparison.php`: pure subject/relationship/elective/weighting calculation and completeness rules.
- `modules/curriculum/repositories/SubjectOfferingHistoryRepository.php`: consistent-read comparison state projections.
- `modules/curriculum/repositories/CurriculumVersionRepository.php`: lifecycle/baseline fields in existing version options.
- `modules/curriculum/http/subject_history_actions.php`: permission-protected GET comparison action.
- `modules/curriculum/pages/subject_offering_history.php`: existing History evidence/structure sections and comparison terminology.
- `modules/curriculum/assets/subject-history.js`: strict report loading, current context, counts and existing details modal integration.
- `modules/curriculum/assets/curriculum-history-comparison.js`: escaped before/after, source and structure renderers.
- `modules/curriculum/pages/curriculum_builder.php`: history-permission-gated comparison link.
- `modules/curriculum/assets/curriculum-builder.js`: selected proposal link to existing History.
- `tests/curriculum-comparison.php`: focused domain/integrity/identity/completeness tests.
- `tests/curriculum-comparison-http.php`: authentication, ownership, method, no-write and workflow tests.
- `tests/curriculum-comparison-concurrency.php`: deterministic consistent-view/read-only transaction tests.
- `tests/curriculum-comparison-ui.cjs`: executed rendering and navigation handler tests.
- `tests/version-protection-isolated.ps1`: includes new suites in established guarded gate.
- `docs/phase1-task5-verification.md`: this evidence report.

**Limits and preservation**

Shared subject properties have no revision-specific override schema; intent cannot
be reconstructed from shared-master drift. Missing historical snapshots cannot be
recovered. Group renames without lineage and component rename equivalence are not
guessed. Relationship comparisons cover current protected reference records versus
current proposed records, not an edit-by-edit timeline. Comparison loads version
structures before paginating; very large datasets were not performance benchmarked.
Visual browser/accessibility review and other database-engine versions were not
tested. No source applicability, institutional adoption or fixture academic
authenticity is certified. Future tasks remain unimplemented.

Older worktree commits/status were rechecked: Task 1 retained only its pre-existing
untracked `task2-review.diff`; Tasks 2, 2.1, 3, 3.1 and 4 stayed clean at their original
commits. No earlier worktree, existing commit, stash or migration was modified.
No academic/production database access, external AI, merge, push or deployment
occurred. Only the authorized Task 5 worktree, new branch/local commit, shared Git
metadata needed to create them, and guarded disposable test resources were changed.
