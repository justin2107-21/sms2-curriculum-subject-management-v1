# Phase 1 Task 2 implementation and verification

Date: 2026-10-08 (Asia/Manila).
Recommendation: **VERIFIED complete requested regression gate after narrow maintenance.**
Do not proceed to deployment or Task 3 from this report.

Starting commit: `fc8f7b2a2f4d3e462219c35b709d05b8bbd4e7f1`.
Worktree: `C:\xampp\htdocs\sms-csms-task2`.
Branch: `feature/phase1-task2-version-protection`.
Ending commit: the local Task 2 commit containing this report (its exact SHA is
reported in the delivery message). Original checkout remains at the starting
commit with a clean working tree. Main remains at
`20a4adf0a005d52c9f129cff41142bb3157d34ba`.
No stash commands were run; previously stashed changes were never accessed.

## Policy and coverage

The [pre-edit inventory](phase1-task2-mutation-inventory.md) contains the complete
module/action/resource matrix and existing route permissions, including alternate
legacy service paths. Archive restore is exposed through
`dashboard/archive_actions.php`: archive.restore plus the entity-specific
program.update, curriculum.update, subject.update or elective.manage permission.

`CurriculumVersionEditPolicy.php` is the shared domain gate. It reads authoritative
stored lifecycle state with locking reads, uses mysqli prepared statements, and
throws existing domain conflicts. No role or administrator bypass exists.

- Any reference version is locked.
- Any under_review or approved version is locked.
- unclassified/not_started with NULL baseline remains editable.
- proposed/not_started, proposed/draft and proposed/returned are editable with a
  valid baseline chain.
- Other/missing/inconsistent states fail closed. Technical active is never treated
  as approval.
- Child IDs resolve through stored placements, relationships, groups, memberships,
  schemes and components; a forged submitted version cannot choose the policy target.
- Material Subject Master, curriculum and program updates, archive and restore
  operations inspect every affected version. Identical material master values are
  allowed; DECIMAL padding is normalized for this comparison.
- Source-reference updates inspect direct lifecycle links and scoped compliance
  rules. Rule creation and updates protect their program/curriculum scope; updates
  guard old, proposed and locked-current scopes.
- Independent source/subject creation remains available. Program-subject
  associations and global equivalency decisions remain unchanged: they do not edit
  placements or award credit. Existing assignment removal already rejects any
  referenced placement.
- History, comparison, export and CIMF context reads remain available. Append-only
  validation runs/results and existing diagnostic/audit logging remain available.

Every integrated guard runs inside the service's existing transaction, before its
DML. Existing HTTP authentication, POST requirements, CSRF and normalized RBAC
remain in place. HTTP errors retain 403/404/409/422 behavior; archive restore now
maps the same domain exceptions to 404/409. No lifecycle or baseline HTTP endpoint
was added.

## Locks and baseline integrity

The policy never starts, commits or rolls back a transaction. Public policy helpers
require the caller to hold its transaction on the same connection; all integrated
services satisfy that contract. Version operations lock the owning program,
curriculum and versions in ID order. Child mutations then lock and recheck the
stored child relationship. Placement inserts and shared subject updates lock the
subject before inspecting version scope, preventing an unseen concurrent placement
insert while the shared subject is checked. Shared-reference edits retain the
source/link/rule locks while checking affected version scope.

The owning curriculum row is the serialization point for baseline validation;
all its versions are read with FOR UPDATE in ID order. Validation rejects missing
or foreign-curriculum baselines, self-links, repeated nodes/indirect cycles and
malformed existing chains. It never writes a baseline. A future assignment service
must retain these owner/chain locks until its assignment commits.

Actual two-connection/process tests show a concurrent lifecycle UPDATE waits,
a service waiting on an owner sees the newly committed protected state and rejects,
and two concurrent synthetic assignments cannot produce a baseline cycle.

These locks favor correctness over write concurrency: all versions in the owning
curriculum are locked, and program-owner locks can serialize unrelated writes in
the same program. Shared operations spanning multiple owners may still deadlock
with existing repository join locks. Existing catch/rollback behavior prevents
partial writes; there is no new automatic retry mechanism. Production-scale
contention and lock duration were not benchmarked.

## Snapshot behavior

No snapshot capture or rewriting was introduced. Protected-version mutations are
rejected before snapshot cleanup. A legacy unclassified placement with a snapshot
cannot be removed. Only an editable proposed placement may delete its exact stored
snapshot, after existing prerequisite/elective/grade dependencies pass, in the
same transaction as placement deletion and required audit logging.

Tests compare all table records after protected rejections, confirm the cleanup
audit, and inject an audit failure with a disposable trigger to verify both snapshot
and placement roll back. FK checks remain enabled throughout.

Snapshots are not comprehensive history: nullable source metadata, absent captures,
and mutable legacy Subject Master values still limit historical accuracy. Existing
reads generally use shared current master data; this task does not change them to
read snapshots or create missing snapshots. Shared protected masters cannot receive
material edits; unrelated and legacy masters remain editable.

## Final regression reconciliation (2026-10-08)

Maintenance starts at `8a7a94a2d690d3a072a6df577fd6e25f63e24007`.
Only the pre-existing assignment pagination defect and justified historical test
maintenance were authorized. Application changes are confined to
ProgramSubjectAssignmentRepository::list(); ProgramSubjectAssignmentService::list()
was reviewed and needs no change.

The repository now uses DomainValidator::listQuery() with its existing active /
archived status allowlist. Missing page and per_page return HTTP 200 with page 1
and size 20. Sizes 10, 20 and 50 work in both program and subject views.
Invalid sizes (0, 15, 100000, nonnumeric, fractional and array), invalid pages
(0, negative, nonnumeric, fractional and array), array search and invalid status
return HTTP 422, with unchanged academic records and audit count. The existing
high-page cap and empty result behavior are preserved. Prepared query bindings,
association semantics, authorization and CSRF are unchanged.

### Original failure evidence and comparison

The preceding reconciliation ran unchanged detached worktrees at exact Task 1
`fc8f7b2a2f4d3e462219c35b709d05b8bbd4e7f1` and Task 2 `8a7a94a`.
Both used PHP 8.0.30, Node v24.11.1, MariaDB 10.4.32 on the same disposable server
(port 57012), fresh schemas through 021, matching fixtures/environment and the
same commands. Full evidence was retained in the prior reconciliation report.

| Original command | Task 1 / Task 2 exit | Exact original failure | Classification and supported replacement |
|---|---|---|---|
| node tests/cimf-modal.cjs | 1 / 1 | line 31: cimfModal.hidden expected false, actual true | PRE-EXISTING STALE TEST: deprecated modal replaced by js/cimf-panel.js. Test navigation, advisory preservation, context invalidation, escaping, POST/CSRF/page binding and no unnecessary requests. |
| php tests/foundation.php | 1 / 1, 338 checks | Student program_assignment/update valid CSRF expected 403, received 400 | PRE-EXISTING STALE TEST: update action absent; supported global association writes are assign/remove. Test actual relationships and both list views; use assignment routes for version placements. |
| php tests/academic_fk_compatibility.php | 255 / 255 | line 127: All 41 foreign keys retained; actual 57 | PRE-EXISTING STALE TEST: fully migrated schema has later additive keys. Verify every complete FK definition against shipped DDL, not an unexplained count. |
| php tests/cimf.php | 255 / 255 | line 8 Disabled, RuntimeException line 6: expected CONFIG_DISABLED, actual AI_DISABLED | PRE-EXISTING STALE TEST: provider-aware config contract. Retain missing/unsupported configuration, canonical allowlist and prompt security cases. |

Replacement foundation coverage additionally exposed a pre-existing application
defect on BOTH unchanged commits: login 200, valid assign 201, explicit
per_page=10 list 200, omitted per_page list 500. Server evidence was
Undefined array key "per_page" at repository line 64 and Division by zero at
line 84. The conditional accepted default 20 but read the missing key in its
true branch. The preceding turn stopped; the user's subsequent narrow corrective
authorization permitted the DomainValidator fix above. No Task 2 regression or
new unrelated application defect was found.

### Historical integrity reconciliation

The FK test now validates all **57** final constraint definitions: names, owning
tables, ordered composite columns, referenced tables/columns, ON UPDATE and
ON DELETE actions. Its independent source inventory reads unchanged shipped SQL;
migration 016 omits ON UPDATE, correctly meaning RESTRICT on tested MariaDB.

Legacy mode starts with synthetic original schema through 012 and reviewed
historical checksums. It executes unchanged 013 alone, journals its real checksum,
and verifies every application row, CHECK expression, UNIQUE/ordinary index,
AUTO_INCREMENT value and constraint name/type is unchanged. Every original FK
definition remains identical except the seven intended UPDATE RESTRICT changes.
No permanent key is lost or added by 013.

Then the normal deployment runner applies 014-021. Original academic columns and
rows, original indexes/constraints and all original FK definitions are preserved;
014's added index and 018's documented decision CHECK extension are distinguished
from the original definitions. The complete final FK inventory must equal shipped
DDL. New security seed rows and lifecycle columns are legitimate additions; cleanup
compares the current schema excluding only owned fixture IDs. Rerun identity and
unknown historical checksum rejection remain tested. FK checks stay enabled.

Foundation also reconciles existing atomic grade configuration routes, retains
legacy domain intermediate-weight/removal validation and audits, tests current
equivalency decisions without granting credit, and preserves NULL/zero/fractional
lecture/lab checks through the existing export repository. Authentication, RBAC,
CSRF, HTTP method checks, valid relationships, duplicate/dependency rejection,
audit checks and fixture cleanup remain covered. No test was deleted or disabled.

### Actual final validation

PHP **8.0.30**, mysqli/mysqlnd; Node **v24.11.1**.
MariaDB **10.4.32-MariaDB**, two newly initialized disposable processes:

- Primary port **50224**, data directory
  `%TEMP%\csms-unblock-4588e543122e4a59b7494779d9de337b\data`.
- Reusable runner port **54933**, data directory
  `%TEMP%\csms-task2-3a17f4c613b04bcdb033e4740db16371\data`.

Both used --no-defaults, loopback binding, generated temporary credentials and
fresh csms_docker_test_* databases. Before tests, VERSION(), @@port, canonical
@@datadir and @@foreign_key_checks=1 were explicitly verified. Each suite received
its own schema and exact SMS_TEST_CONFIRM_DATABASE. No existing credentials or
academic databases were accessed. HTTP servers were synthetic loopback PHP
processes. CIMF_AI_ENABLED=false; no external provider was contacted.

| Actual command (PHP CLI unless marked Node) | Passed checks / result | Exit |
|---|---|---|
| tests/deployment_migrations.php | 29 | 0 |
| tests/curriculum_lifecycle_schema.php | 70 | 0 |
| tests/version-protection.php | 260, including actual concurrency and snapshot audit-failure rollback | 0 |
| tests/version-protection-http.php | 458 | 0 |
| tests/prerequisite-service.php | 73 | 0 |
| tests/electives-service.php | 64 | 0 |
| tests/subject-mapping-http.php | 199 | 0 |
| tests/electives-http.php | 94 | 0 |
| tests/archives-http.php | 21 | 0 |
| tests/academic-sequence.php | 17 | 0 |
| tests/search-ranking.php | 19 | 0 |
| tests/academic_fk_compatibility.php | 40, complete 57-key inventory | 0 |
| tests/academic_fk_compatibility.php --legacy | 396, original and additive upgrade integrity | 0 |
| tests/prerequisite-http.php | 80, including supported global CIMF and retired endpoint security | 0 |
| tests/foundation.php | 1,795 on fresh fully deployed schema with real FKs | 0 |
| tests/cimf.php | configuration / prompt / canonical allowlist assertions | 0 |
| tests/electives-http.php --foundation | 95 wrapper checks plus repeat of 1,795 foundation checks | 0 |
| node tests/cimf-modal.cjs | supported global panel assertions | 0 |
| php -l for every git ls-files '*.php' | 183 files, zero failures | 0 |
| node --check for every tracked '*.js' / '*.cjs' | 38 files, zero failures | 0 |
| git diff --check | no whitespace errors | 0 |

The 11 earlier suites retain **1,304** checks, including the unchanged **718**
Task 2 domain/HTTP protection checks. All listed suites actually ran; no required
test was skipped. Protected/unauthorized rejections retain whole-table row and
audit fingerprints in the unchanged Task 2 tests. Foundation rejected mutations
additionally compare 17 academic tables and exact audit counts. Legacy
unclassified operations and authorized reads passed.

Concurrency was repeated: a second connection times out updating locked state;
a waiting mutation sees a committed protected state and rejects; serialized
synthetic baseline assignments reject an indirect cycle. The disposable
audit-failure trigger proves snapshot/placement rollback. No protection test file
was modified by this maintenance.

Exact reproducible aggregate command, from the Task 2 worktree:

```powershell
powershell -File tests/version-protection-isolated.ps1
```

The expanded runner prints individual PHP commands/exit codes, runs the original
11 suites plus fresh/legacy FK, prerequisite HTTP, CIMF config and the elective
foundation wrapper, then the Node global panel test. Its 16 PHP invocations and
Node test passed, aggregate exit 0; its own server/data were removed. The
foundation wrapper uses its existing disposable fixture schema. Separately, the
primary run executed standalone foundation against actual migrated FK tables:
1,795 checks, exit 0. Neither fixture is an institutional academic database.

The primary external runner executed all 17 PHP invocations in the table with
fresh per-suite schemas; all exits 0 and failed=0. Syntax checks used the exact
PHP/Node binaries above. A sandbox ownership error initially prevented git file
enumeration; a command-scoped safe.directory option corrected the lint harness,
then all 183/38 files were actually checked. No global Git configuration changed.

Initial FK helper failures were test-authoring issues: DROP/ADD ordering for
replacement keys and migration 016's implicit ON UPDATE were corrected after
comparing actual/source inventories (57 actual versus 55 initially parsed).
The final fresh and legacy tests pass. A temporary edit helper failed before
writing due to PHP string interpolation; data-driven edits corrected it.
These failures did not alter application code, migrations or existing data.

Docker CLI exists but its daemon is unavailable; MariaDB 10.11 and MySQL were not
tested. Live browser rendering, external AI providers, production-scale lock
contention and deadlock retries remain outside this verification. Existing
snapshot history limitations described above remain. The prior legacy-mode
failure was not separately reproduced on Task 1; no baseline classification is
claimed for that mode. Its reconciled staged integrity checks were actually run.

## Narrow maintenance changed files

Relative to local Task 2 commit 8a7a94a, exactly these nine files changed:

- modules/curriculum/repositories/ProgramSubjectAssignmentRepository.php
- tests/academic_fk_compatibility.php
- tests/cimf-modal.cjs
- tests/cimf.php
- tests/electives-http.php
- tests/foundation.php
- tests/prerequisite-http.php
- tests/version-protection-isolated.ps1
- docs/phase1-task2-verification.md

The only application diff imports DomainValidator and replaces defective local
list-query coercion with its existing normalized validation. All other changes
are regression maintenance, runner coverage or evidence documentation. See the
local maintenance commit's exact diff for review. The existing mutation coverage
matrix remains applicable: this maintenance changes only a read query, adds no
mutation path, permission, lifecycle operation or baseline endpoint.


## Initial failures and corrections

New harness authoring errors were fixed: a function-scoped DB include was moved
after the global connection declaration; synthetic rules were explicitly active
so append-only validation would run; HTTP clients refreshed CSRF after login
rotation; an elective happy-path fixture received a unique group name. An embedded
PowerShell here-string quoting error prevented the first runner file write and was
corrected before running it. No academic database was targeted by these errors.

Existing HTTP role fixtures omitted required display_name. Fixture inserts in
mapping, prerequisite and elective HTTP tests now include that field; assertions
are unchanged. The mapping fixture also includes the snapshot table required by
the new removal policy. During code review the policy's no-op comparison was
corrected for mysqli DECIMAL strings versus validated numbers, with a regression.

Legacy unclassified/technical-active edits passed directly and over HTTP. All
guarded legacy service methods were exercised, including mapping, prerequisites,
both elective paths, every grade-weighting method, shared masters and compliance.
Proposed draft/returned edits passed; proposed/not_started rename passed.
Protected before/after comparisons, missing RBAC, CSRF, forged child-owner input,
archive/restore, shared masters, linked compliance, snapshots and concurrent locks
all passed in the new suites. Protected history/comparison/export/CIMF context
reads and append-only validation passed. Live AI/provider traffic was not tested.

## Original Task 2 implementation changed-file list

- dashboard/archive_actions.php
- modules/curriculum/services/AcademicProgramService.php
- modules/curriculum/services/ArchiveCenterService.php
- modules/curriculum/services/ComplianceReferenceService.php
- modules/curriculum/services/ComplianceRuleService.php
- modules/curriculum/services/CurriculumElectiveGroupService.php
- modules/curriculum/services/CurriculumElectiveGroupSubjectService.php
- modules/curriculum/services/CurriculumService.php
- modules/curriculum/services/CurriculumSubjectRequirementService.php
- modules/curriculum/services/CurriculumVersionEditPolicy.php
- modules/curriculum/services/CurriculumVersionService.php
- modules/curriculum/services/CurriculumVersionSubjectService.php
- modules/curriculum/services/ElectivesWorkspaceService.php
- modules/curriculum/services/GradeWeightingService.php
- modules/curriculum/services/SubjectService.php
- tests/electives-http.php
- tests/prerequisite-http.php
- tests/subject-mapping-http.php
- tests/version-protection-fixture.php
- tests/version-protection-http.php
- tests/version-protection-isolated.ps1
- tests/version-protection-lock-worker.php
- tests/version-protection.php
- docs/phase1-task2-mutation-inventory.md
- docs/phase1-task2-verification.md

No historical migration 001-021 changed. No role/permission grants, approval
workflow, cloning, reference attachment endpoint, outcome/assessment mapping,
Task 3, UI redesign or new migration was implemented. Main, HostForge, production,
the existing XAMPP process/data and sms_db were untouched. No push, merge, deploy
or startup migrations occurred. Temporary test processes and data were removed.
The original implementation and this verified maintenance are committed locally
only for review. The complete requested regression acceptance gate is now green.
The exact maintenance commit is reported in the delivery message.
