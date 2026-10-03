# Subject Trace scope and Place Subject workflow

Implemented 2026-10-03. No schema change, deployment, data migration, prerequisite-rule change, or automatic placement change.

## Files changed for this task

- `modules/curriculum/assets/subject-mapping-final.js`
- `modules/curriculum/assets/subject-mapping-final.css`
- `modules/curriculum/pages/subject_mapping.php`
- `modules/curriculum/http/assignment_actions.php`
- `modules/curriculum/services/CurriculumVersionSubjectService.php`
- `modules/curriculum/repositories/SubjectRepository.php`
- `tests/subject-mapping-workspace.cjs`
- `tests/subject-mapping-http.php`
- `docs/subject-trace-scope-verification.md`

Existing working-tree changes were preserved. This list identifies files changed by this task, not all pending repository changes.

## Backend behavior

Trace suggestions reuse SubjectService/SubjectRepository's parameterized placement EXISTS query. Trace results reuse CurriculumVersionSubjectService/CurriculumVersionSubjectRepository's parameterized placement query. Both receive the identical selected scope:

| Context | Subject suggestions | Placement results |
| --- | --- | --- |
| None | Subject Master, including unplaced subjects | All placements of selected subject |
| Program | Subjects placed under program | Subject placements under program |
| Program + curriculum | Subjects placed under curriculum | Subject placements under curriculum |
| Full context | Subjects placed in exact version | Subject placement in exact version |

All supplied context constraints are combined with AND. An out-of-context subject ID returns no placements; contradictory scope never broadens results. Scope filtering happens in SQL rather than hiding global results in JavaScript.

Current visibility conventions are retained: existing unfiltered inspection APIs include inactive Subject Master records and archived curriculum versions. Explicit active-subject filtering still excludes inactive records. This task does not invent a new archival visibility policy.

The new `placement_subject_options` action uses the existing assignment route/service and SubjectRepository. A parameterized NOT EXISTS excludes subjects already placed in the fixed version. Both this action and `map_subject` require a matching program/curriculum/version chain. Other modules' existing `assign` action remains compatible.

Duplicate validation remains authoritative inside the existing version-lock transaction. Existing RBAC, CSRF, audit action names, and prerequisite-aware placement validation remain intact. Placement audit metadata now includes `placement.created`, `placement.updated`, and `placement.removed`. Ordinary reads are not audited.

## UI behavior

- Scope-specific trace helpers, result headings, and empty states.
- Context changes clear the trace selection/results and cancel stale autocomplete/result responses.
- Place Subject is visible only on Curriculum Map and disabled until its full context is loaded.
- Trace contains no placement-creation action.
- Open Placement opens the compact details modal directly, keeping the trace context intact. Its footer provides Close and authorized Edit Placement; prerequisite information is read-only.
- Place Subject fixes context in the modal and offers existing, not-yet-placed subjects. Stale duplicate submissions receive the requested Edit Placement message.
- Placement cards have explicit Program, Curriculum, Version, Year / Grade Level, Semester / Term, Placement, and Units labels.
- Existing responsive breakpoints are retained; local wrapping/min-width rules prevent long trace content from widening cards.
- Curriculum Map and version-scoped Mapping Review behavior are preserved.

## Tests

Passed:

- `php tests/subject-mapping-http.php`: 199 checks, including global/program/curriculum/version scopes, synthetic BPM101 usage in multiple contexts, unplaced subjects, injected subject IDs, contradictory contexts, existing inactive/archived visibility, selector exclusion, duplicate protection, complete creation context, audit metadata, RBAC, CSRF, editing, and prerequisite-aware rejection.
- `php tests/prerequisite-http.php`: 56 checks.
- `php tests/prerequisite-service.php`: 73 checks; permanent data fingerprints unchanged.
- `php tests/academic-sequence.php`: 17 checks.
- `php tests/subject-mapping-reads.php`: 440 read-only checks.
- `node tests/subject-mapping-workspace.cjs`: global/partial/full trace, matching suggestion/result scope, context clearing, stale result suppression, tab visibility, direct details opening, blocked Edit Placement, and existing map/review workflows.
- `node tests/prerequisite-workspace.cjs`.
- `node tests/subject-mapping-graph.cjs`.
- `node tests/curriculum-cascade.cjs`.
- PHP lint on all four changed production PHP files plus the changed HTTP test, and JavaScript syntax validation.

Total: 785 PHP checks plus four JavaScript suites. HTTP writes used disposable synthetic databases; production/local academic records were not rewritten. UI tests use a mock DOM; live visual verification remains unperformed.

The staged commit was also exported into a separate checkout: its 345 mutation/domain PHP checks and three mapping/prerequisite JavaScript suites passed independently of unstaged code. The pre-existing `curriculum-cascade.cjs` suite passed in the working tree but remains outside this commit because it also tests an unrelated, uncommitted Subject Master redesign.

## Commit scope

The repository already contained extensive uncommitted mapping and shared-component changes before this task. The user explicitly approved committing mapping and its existing shared dependencies. The commit therefore includes the existing mapping workspace, shared autocomplete/modal support, scoped Subject Master queries, and prerequisite-sequence protection from the preceding task, with their regression tests. Unrelated dashboard, navigation, compliance, and other module edits remain outside this commit. No deployment was performed.
