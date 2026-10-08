# Phase 1 Task 2 implementation and verification

Date: 2026-10-08 (Asia/Manila).
Recommendation: **FAILED overall regression gate; Task 2 protection suites pass.**
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

## Actual validation

PHP: **8.0.30**, mysqli/mysqlnd enabled.
Database: **MariaDB 10.4.32**, separate processes initialized with fresh random
credentials, new temporary data directories, no-defaults and loopback ports.
Primary test port: 63622. The reusable runner independently verified another new
process on port 57986. Before tests, server port, canonical data directory and
enabled FK checks were explicitly validated. No existing credentials were used.
Docker was unavailable from Task 1; MariaDB 10.11/MySQL were not tested.

PHP lint: **183 files, zero errors**. Final edited policy/test were rechecked.
Normal `git diff --check` passed. A check with autocrlf disabled produced
Windows CRLF-only whitespace noise; repository-default checks passed and no
unrelated files were changed.

| Executed test | Final result |
|---|---|
| deployment_migrations.php | 29 passed |
| curriculum_lifecycle_schema.php | 70 passed |
| version-protection.php | 260 passed |
| version-protection-http.php | 458 passed |
| prerequisite-service.php | 73 passed |
| electives-service.php | 64 passed |
| subject-mapping-http.php | 199 passed |
| electives-http.php | 94 passed |
| archives-http.php | 21 passed |
| academic-sequence.php | 17 passed |
| search-ranking.php | 19 passed |

The 11 passing suites contain **1,304 checks** using final affected-suite results.
The reusable isolation runner completed all 11 suites successfully (before the
additional one-check DECIMAL no-op test); final domain, HTTP and prerequisite
suites were rerun after that small policy correction.

Run the reusable passing coverage with:
`powershell -File tests/version-protection-isolated.ps1`.
It creates and verifies its own disposable server, uses fresh databases for each
suite, restores its temporary environment setting and removes only its own server
data. It is a selected-suite runner, not a claim that the full historical suite is
green. The following broader tests were separately executed and remain failures.

| Existing test attempted | Unresolved result |
|---|---|
| prerequisite-http.php | Stops on the old cimfModal/CONFIG_DISABLED page assertion; current unchanged page uses the shared CIMF panel. |
| electives-http.php --foundation (foundation.php) | 338 successful checks including cleanup, then overall failure: obsolete program_assignment/update action expects 403 but the unchanged route returns 400 for unknown actions. Later foundation cases did not execute. |
| academic_fk_compatibility.php | Fails its fixed expectation of 41 FKs against the schema through 021; the fixture cleanup executes. |
| cimf.php | Fails the initial Disabled check: unchanged config now returns AI_DISABLED, while the test expects CONFIG_DISABLED. No external provider was called. |

The failing assertions and relevant application contracts are unchanged from the
Task 1 base. They appear to be pre-existing test-contract drift; this report does
not claim a separate pristine-base execution of all four failures. No failing
assertions were weakened, removed or disabled. Safest next step is separately
reviewing/updating those historical test contracts, then rerunning the full gate.
That repair was not expanded into this Task 2 implementation.

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

## Complete changed-file list

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
The implementation is committed locally only for review; the overall regression
gate is still red for the separately reported historical test failures.
