# Electives Manager implementation

This earlier implementation record is superseded by
[the final electives/search audit](search-electives-finalization.md), including
compatible candidate filtering, seven review categories, stored placement choices,
shared relevance ranking, and the passing foundation suite.

Implemented on 2026-10-03 in the existing PHP/MySQL CSMS. No framework, sidebar,
navbar, Subject Master, mapping, or prerequisite redesign was introduced.

## Behavior and integration

- Program/Strand → Curriculum → Version uses the shared cascading autocomplete.
  Its existing three-row context dropdown styling is reused. Changing a parent
  clears descendants, results, and open group dialogs; stale responses are ignored.
- Create is unavailable until a valid context has loaded. The page contains exactly
  Elective Groups and Validation Review tabs, with no redundant context summary.
- Search, year/grade, semester, and status filter responsive group cards. Centered
  dialogs support create, view, edit, and confirmed archive. Membership and required
  selections are saved together in one transaction.
- Candidates come exclusively from the selected version's Subject Mapping records.
  Selecting a candidate adds membership immediately. The module does not modify
  subject identity, placement, Required/Optional indicators, or prerequisite rules.
- College defaults, SHS Grades 11/12, and flexible existing placement values are
  supported. Valid placement must match every member before saving. Mismatches
  display a warning and a context-preserving Subject Mapping link.
- Member details include units and stored, read-only prerequisite information.
  Links use the existing Subject Mapping and Pre-requisite Configuration URL
  contracts (`program_id`, `curriculum_id`, `version_id`, `subject_id`). Subject
  Master receives `subject_id` and opens its existing detail flow. The destination
  modules retain their own permissions. Their existing deep-link implementations
  are reused; no changes to those modules were needed in the final implementation.
- Review derives six counters and issue rows from the same database records:
  empty groups, invalid selections, duplicate members, placement mismatches,
  unmapped elective members, and inactive subjects in active groups. Archived
  groups remain reviewable. Inactive-member checks apply only to active groups.
  A clean review displays the requested success state.

## Security and data

- All endpoints require authentication and the existing elective permissions.
  Mutations require POST, valid CSRF, and `elective.manage`; reads require
  `elective.view`. Elective context suggestions do not require unrelated management
  permissions.
- The server verifies the complete program/curriculum/version ownership chain,
  group ownership, member placement, unique membership, and integer selections
  from 1 through member count. Active groups reject inactive members.
- Prepared statements and escaped UI output are used. Context/group/placement rows
  are locked while saving. Required audits and the mutation commit or roll back
  together, including membership additions/removals.
- Existing uppercase audit conventions are retained:
  `ELECTIVE_GROUP_CREATE`, `ELECTIVE_GROUP_UPDATE`, `ELECTIVE_GROUP_ARCHIVE`,
  `ELECTIVE_GROUP_SUBJECT_ADD`, `ELECTIVE_GROUP_SUBJECT_REMOVE`.
- Hard deletion through the group API is disabled. Legacy standalone membership
  mutation endpoints reject writes so they cannot bypass selection/placement checks.
  Legacy list reads remain available for compatibility.

## Migration

New `014_finalize_electives_manager.sql` extends `curriculum_elective_groups` with
`year_level`, `semester`, `required_selections`, `status`, and a version/status
index. Existing tables, foreign keys, unique membership constraint, descriptions,
and member references are preserved. Legacy placement remains NULL rather than
being inferred; review exposes unresolved placement. No academic records are seeded.

Migration 014 was applied to local `sms_db` on MariaDB 10.4.32 after checking the
exact legacy group shape. A before-change schema/data backup is at
`C:\Users\esquitajustin\AppData\Local\Temp\csm34.tmp`.
The local schema is untracked, so it was not adopted into a migration ledger.
For tracked deployments, the normal `database/deploy.php` runner discovers 014.
Fresh installation, legacy upgrade, replay protection, and existing FK preservation
were tested on a separate MariaDB instance.

## Files changed

Application:
- `modules/curriculum/pages/electives_manager.php`
- `modules/curriculum/assets/electives-manager.js`
- `modules/curriculum/assets/electives-manager.css`
- `modules/curriculum/http/elective_group_actions.php`
- `modules/curriculum/http/elective_membership_actions.php`
- `modules/curriculum/repositories/ElectivesWorkspaceRepository.php` (new)
- `modules/curriculum/services/ElectivesWorkspaceService.php` (new)
- `database/migrations/014_finalize_electives_manager.sql` (new)

Tests/documentation:
- `tests/electives-service.php` (new)
- `tests/electives-http.php` (new)
- `tests/electives-workspace.cjs` (new)
- `tests/foundation.php` (electives workflow/audit/fixture cleanup contract)
- `tests/deployment_migrations.php`, `tests/bootstrap_assertions.php`
  (15 journal entries: baseline plus 14 migrations)
- `tests/academic_fk_compatibility.php` (assert the added index while checking all
  old indexes remain intact)
- `tests/README.md`, `docs/hostforge-deployment.md`, and this report

## Verification results

Passed:
- All 140 PHP files linted; all 30 JS/CJS files passed syntax checks.
- `tests/electives-service.php`: 52 checks, including College, SHS 11/12, context
  isolation, invalid counts, duplicate membership at service/DB level, mismatch,
  unmapped and inactive detection, archive preservation, and audit-failure rollback.
  Temporary fixtures leave permanent academic/audit tables unchanged.
- `tests/electives-http.php`: 94 checks against a disposable database and HTTP
  server, including authenticated page rendering, view/manage RBAC, CSRF, method
  guards, rejected forged input, create/edit/archive, and audit events.
- `tests/electives-workspace.cjs`: mock-DOM interaction checks for selection,
  filters, escaping, stale response rejection, warnings, navigation, modal data,
  save validation, and archive confirmation.
- Existing cascade, Subject Mapping workspace/graph, prerequisite workspace, and
  frontend mock-DOM/CSS structural suites.
- Existing prerequisite service (73), prerequisite HTTP (56), mapping/prerequisite
  HTTP (199), read-only mapping (440), academic sequence (17), and CSV tests.
- Isolated local FK harness: migration deployment (29), fresh FK compatibility
  (32), legacy upgrade (93), development seed (40), BSIT seed safety (27), and
  BSIT service/read/export (181) checks. The temporary server and schemas were removed.
- Read-only live electives workspace requests across all five local versions;
  local versions currently contain zero elective groups. Nonempty/defective group
  behavior was exercised with isolated fixtures, not seeded into the user's data.

Broader existing-worktree failures, left outside this module's scope:
- `tests/electives-http.php --foundation` reaches the full foundation suite but
  stops at its `subjectLectureUnits` decimal-field assertion. The current Subject
  Master page omits that field. It stops before the electives section.
- `tests/phase2_ui.cjs` fails its Curriculum Builder `placementModal?.open()`
  source-shape assertion (expects one occurrence, finds zero).
- Whole-worktree `git diff --check` reports existing whitespace at
  `modules/curriculum/pages/subjects.php:129`. Electives changes pass a scoped check.

## Still requires browser verification

No browser was connected; visual or manual-browser completion is not claimed.
The user explicitly authorized continuing without a browser.

Verify desktop two-column cards and narrow-screen stacking; long subject names and
large membership lists; actual dropdown height/scrolling and keyboard interaction;
modal centering, scrolling, focus, and Escape behavior; create/view/edit/archive
click-through; warning navigation into mapping; prerequisite/subject detail links;
and rendered review counts/issues and success state. All underlying API/service
flows and the listed JavaScript interactions have non-browser coverage.
