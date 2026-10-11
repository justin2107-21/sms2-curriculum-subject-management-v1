# Phase 1 Task 4: reference-based curriculum revisions

Base: `da5ab279be49b8d6a8cf87368115b3339fd7088b` (Task 3.1).
Worktree: `C:\xampp\htdocs\sms-csms-task4`.
Branch: `feature/phase1-task4-curriculum-cloning`.

## Implemented workflow

In Curriculum Builder, open a protected reference under its existing program and
curriculum. An explicitly authorized user sees **Create Curriculum Revision**.
The compact form checks eligibility, shows copy counts and the stored documentary
title/code, kind, locator (including any recorded section fragment), and recorded
effective date. Missing dates remain “Not recorded”; no applicability is inferred.
Enter a revision label, optional existing metadata, and preservation confirmation.
Success opens the new version's existing workspace.

`version_actions.php` exposes `GET clone_preview` and `POST clone_reference`.
Both require `curriculum.version.clone`; POST also requires the existing session,
CSRF and method checks. Ordinary admins do not receive this capability by default.
Migration 023 adds only the permission; explicit RBAC-manager assignment uses the
existing grantable-permission mechanism. It changes no institutional role grants.

`CurriculumRevisionService::create()` accepts user metadata and source identity,
but owns `status=draft`, `version_role=proposed`, `review_status=not_started`, and
the exact source `baseline_version_id`. Client lifecycle overrides are ignored.

## Copy matrix

Observed populated fixture counts are per successful revision, not institutional
curriculum data. Every academic fixture is explicitly synthetic.

| Source table | Destination / decision | Remapping | Observed count |
|---|---|---|---:|
| curriculum_versions | Independent proposed row | New PK; same curriculum; exact reference baseline | 1 |
| curriculum_version_subjects | Copy all stored academic placement fields | Actual inserted placement IDs; shared subject IDs | 4 |
| curriculum_subject_requirements | Copy prerequisite and corequisite edges | New version + shared subject IDs; composite FKs resolve new placements | 4 |
| curriculum_elective_groups | Copy name, description, year, term, selection count, status | Actual inserted group IDs | 1 |
| curriculum_elective_group_subjects | Copy memberships | New group and version + shared subject IDs | 2 |
| grade_weighting_schemes | Copy description | New scheme + mapped placement | 1 |
| grade_weighting_components | Copy names, exact DECIMAL strings, order | New component + mapped scheme | 2 |
| curriculum_version_subject_snapshots | Independent creation-time capture_method=clone | New placement; exact validated source snapshot values/source_reference_id | 4 |
| curriculum_version_references | Retain original attachments, inherit via baseline read model | No duplicate links or verification | 0 new links |
| subjects, curricula, academic_programs | Reuse existing shared identities | No master inserts/updates | 0 changes |
| program_subject_assignments, subject_equivalencies, compliance_rules | Shared/global configuration; excluded from copying | Existing identities remain unchanged | 0 changes |
| personnel, review events, validation runs/results, CIMF logs, archive attribution/history | Excluded | No copied approval, personnel, certification or results | 0 new historical rows |
| audit_logs | New required creation/placement lineage evidence only | New version and new/source placement IDs | 1 creation + 4 placement events |

Prerequisites and memberships do **not** store placement IDs in this schema.
Their `(curriculum_version_id, subject_id)` FKs are remapped by changing version,
after the new placements exist. Subject-code matching and consecutive-ID assumptions
are never used. Weighting schemes do store placement IDs and use explicit maps.

Groups retain technical status, including inactive/archived status. Historical
archive actor/time are not copied. Incomplete weighting schemes are legitimate
existing incremental configurations: totals 0..100% are preserved exactly, never
completed or normalized silently. Totals above 100% are rejected.

## Integrity, provenance and locking

One mysqli transaction owns all reads, inserts and required audits. The service
checks that the audit connection is the same object before beginning a write.
It never invokes a public editing service that commits its own transaction.

Discovery reads identify candidate source/subject IDs only. Locks follow:

1. All attached documentary source rows in numeric ID order.
2. Shared subject rows in numeric ID order.
3. Existing Task 2 policy program, curriculum, and versions in ID order.
4. Current attachment/placement reads, source assessments, snapshots and version-owned structures.
5. New version, placements/snapshots/lineage audits, requirements, groups/memberships,
   schemes/components, required creation audit, then commit.

Links and placements are re-read as current locking reads after owner-lock waits;
changed discovery results produce a reload conflict. Evidence assessments are
current locking reads. Relationships, elective context and decimal totals use
current locked rows. No application-wide isolation change is introduced.

Eligibility requires a same-curriculum reference with no baseline, recognized
technical/review values, traceable curriculum-source attachment, matching latest
identity evidence, and complete source-linked snapshots. Institutional sources
also retain Task 3's separate declared-origin requirement. Every recorded latest
assessment must match current metadata. Withdrawn/inactive, stale, missing or
conflicting data is rejected without repair. Snapshot/master differences reject
cloning instead of fabricating new shared subjects.

Technical `archived` status remains independent of academic role, as in the
existing policy. A protected reference with that technical state remains an
eligible immutable source when every integrity check passes. This is tested.

Original source links, evidence events, snapshots, records and timestamps remain
unchanged. Proposal snapshots have a new capture timestamp and `clone` method;
each placement's required audit records its exact immutable source placement.
The workspace identifies inherited provenance through its baseline rather than
claiming a fresh attachment or assessment. “CHED sample curriculum” is derived
from stored source kind. Documentary title/code and locator come from stored
snapshots/evidence, not a hardcoded BSIT/CMO label. Institutional adoption/approval
always remains unverified. No institutional dataset is seeded.

Deadlocks remain possible with arbitrary external writers; rollback preserves
atomicity. Tests do not establish freedom from every possible lock interleaving.

## Acceptance evidence

`D` = tests/curriculum-revision.php; `H` = tests/curriculum-revision-http.php;
`C` = tests/curriculum-revision-concurrency.php; `U` = tests/curriculum-revision-ui.cjs.

| Criterion | Implementation | Observed verification |
|---|---|---|
| T4-01 | Dedicated endpoint permission, no default grants | D migration grants; H anonymous 401, ordinary admin 403, explicit synthetic grant succeeds; U permission gating |
| T4-02 | lockAndValidate same-curriculum reference eligibility | D/H missing/empty source, missing/incomplete snapshot, wrong curriculum, legacy and proposed/draft/returned/under-review/approved source rejection |
| T4-03 | Repository createVersion fixed lifecycle/baseline | D/H forged status/role/review/baseline ignored; exact baseline and neutral state asserted |
| T4-04 | Placement projection and inserted-ID map | D four placements over multiple years/terms, exact sequence/is_required/subject data, independent IDs and unchanged source digest |
| T4-05 | Composite FK remapping, PrerequisiteGraph and sequence validation | D multiple prerequisite/corequisite edges copied; cycles/bad sequence rejected; broken FK insert rejected |
| T4-06 | Group/scheme maps; exact decimal strings | D group metadata, selection, memberships, scheme ownership and 33.33/66.67 decimals/order; invalid selections/totals, single-member groups, blank names and mismatched placements rejected |
| T4-07 | Read-only source persistence; inherited references | D/H source/evidence/snapshot/shared/global digests unchanged after cloning and proposal edits |
| T4-08 | Single transaction with required audit | D component-insert and final-audit SIGNAL failures leave every table unchanged; C concurrent audit failures also fully roll back |
| T4-09 | Stored source kind/title/code/locator; separate revision label | H workspace stored title/sample/adoption labels; U actual source text/section locator, missing date, truthful empty state |
| T4-10 | Ordered ownership locks and authoritative current reads | C actual InnoDB waits, simultaneous successes, duplicate-label winner/conflict, changed source/master rejection after waits, guarded source edit serialization |
| T4-11 | Existing editing services operate on proposal | D/H mapping, prerequisite, elective, weighting edits succeed; protected source stays unchanged; H workspace/export/history reads succeed; U success navigation opens new workspace |
| T4-12 | Existing policy/security/history remain in use | Full isolated regression gate, Task 2/2.1 and Task 3/3.1 suites, frontend checks and syntax checks |

## Verification commands and environment

Focused suites were run first using the established runner:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File tests/version-protection-isolated.ps1 -OnlyTest curriculum-revision.php
powershell -NoProfile -ExecutionPolicy Bypass -File tests/version-protection-isolated.ps1 -OnlyTest curriculum-revision-http.php
powershell -NoProfile -ExecutionPolicy Bypass -File tests/version-protection-isolated.ps1 -OnlyTest curriculum-revision-concurrency.php
powershell -NoProfile -ExecutionPolicy Bypass -File tests/version-protection-isolated.ps1
```

Focused domain: 60 checks; HTTP: 60; concurrency: 11. Migration coverage includes
fresh deploy, populated 022-to-023 upgrade preservation, journaled second apply,
and zero default clone grants. Domain tests deliberately inject corruption only
in disposable fixtures to test stale/invalid-source rejection; application code
never updates historical evidence. Foreign keys remain enabled.

Final release run: **24/24 database suites and 4/4 supported frontend suites
passed**, exit 0, no skips. Independent MariaDB 10.4.32 instance, loopback port
58908, temporary datadir
`C:\Users\ESQUIT~1\AppData\Local\Temp\csms-task2-ad0d0f3b37c541468b9951905f4c00b6\data\`.
The runner confirmed foreign_key_checks=1 and shut down/removed its test server,
schemas and data. Raw final log: `%TEMP%\csms-task4-release-gate.log`.
Task 2 protection passed 260 domain and 458 HTTP checks; Task 2.1 concurrency
passed 11; Task 3/3.1 passed 18 schema, 42 domain, 82 HTTP and 4 concurrency checks.
The wider gate also passed deployment (31), lifecycle (70), prerequisite (73),
electives (64), mapping HTTP (199), electives HTTP (94), archives HTTP (21),
academic sequence (17), database search (19), fresh FK (40), legacy FK (396),
prerequisite HTTP (80), CIMF, and foundation (1800 checks plus 95 HTTP checks).

The runner independently checks VERSION, actual port, canonical datadir and
foreign_key_checks=1 **before** creating each newly generated disposable schema.
Schemas use `csms_docker_test_task2_run_<index>_<random>`; only those schemas are
migrated/written/dropped. The runner shuts down/removes its own server/data.
Concurrency workers explicitly use REPEATABLE READ for the reproduction cases;
this does not assert or change normal database-server isolation.

Syntax: PHP 8.0.30, 197 PHP files; Node 24.11.1, 39 JS/CJS/MJS files, all passed.
Supported frontend: frontend.cjs, phase2_ui.cjs, cimf-modal.cjs and the new revision
interaction suite passed. `git diff --check` passed.

Three unrelated legacy frontend suites fail identically on untouched Task 3.1
and Task 4: curriculum-cascade.cjs lacks a CustomEvent test double; search-ranking.cjs
expects 4 but receives 2; electives-workspace.cjs imports that failing ranking
suite. They were neither altered nor reported as passes. The supported gate has
no skips. Mock-DOM checks do not establish visual browser QA.

The concurrency harness uses engine wait records where exported. MariaDB 10.4
also repeatedly reported `Statistics` for blocked locking queries without a
matching engine wait-view entry. For those cases the harness checks the retained
blocking transaction/row locks, exact pending SQL, and absence of a completed
worker result before releasing the known blocker. The source-edit and second-clone
source queries have separate pending-query barriers while the first clone holds
the source. These observations are not mislabeled as engine-reported waits.
Final outcomes and preserved rows are checked after both workers finish.
Earlier gate attempts failed at the wait-view-only barrier; no success commit was
made from those failures. The observer was corrected without changing application
logic, dropping scenarios, retrying failures as passes, or removing outcome checks.

## Scope and changed files

- database/ProvisionalRoles.php
- database/migrations/023_curriculum_revision_clone_permission.sql
- modules/curriculum/assets/curriculum-builder.js
- modules/curriculum/http/version_actions.php
- modules/curriculum/pages/curriculum_builder.php
- modules/curriculum/repositories/ComplianceReferenceRepository.php
- modules/curriculum/repositories/CurriculumRevisionRepository.php
- modules/curriculum/services/CurriculumRevisionService.php
- modules/curriculum/services/CurriculumVersionReferenceService.php
- tests/curriculum-revision-fixture.php
- tests/curriculum-revision.php
- tests/curriculum-revision-http.php
- tests/curriculum-revision-concurrency.php
- tests/curriculum-revision-ui.cjs
- tests/deployment_migrations.php
- tests/version-protection-isolated.ps1
- docs/phase1-task4-verification.md

Historical migrations 001–022 and older worktree source files remain untouched.
No normal XAMPP/academic/production DB access, push, merge or deployment occurred.
No new official module, academic approval workflow, AI decision, outcomes mapping,
assessment planning, proposed-from-proposed cloning, or later-phase comparison
implementation is included. Actual institutional source completeness, applicability
and adoption still require genuine institutional evidence. Unknown optional source
metadata is disclosed rather than inferred. Independent review is required before
any merge, push or deployment.
