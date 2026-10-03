# Shared academic sequence validation

Implemented 2026-10-03. No database migration or schema change.

## Files added

- `modules/curriculum/services/AcademicSequenceValidator.php`: shared position resolution and structured incoming/outgoing conflict validation.
- `tests/academic-sequence.php`: pure domain regressions.
- `docs/academic-sequence-validation.md`: implementation and verification record.

## Existing files updated

- `modules/curriculum/services/CurriculumVersionSubjectService.php`: placement write validation, preview, version locking, and audit metadata.
- `modules/curriculum/services/CurriculumSubjectRequirementService.php`: authoritative prerequisite create/edit validation and preview; removes the confirmation override.
- `modules/curriculum/services/PrerequisiteGraph.php`: Validation Review uses the shared validator, including equal periods and unknown labels.
- `modules/curriculum/validators/DomainExceptions.php`: structured sequence exception.
- `modules/curriculum/http/assignment_actions.php`: permission-protected placement preview.
- `modules/curriculum/http/requirement_actions.php`: permission-protected prerequisite preview.
- `modules/curriculum/http/support.php`: structured 422 responses and best-effort blocked-attempt audit through the existing audit helper.
- `modules/curriculum/pages/subject_mapping.php`: inline preview panel and explicit read-only prerequisite label.
- `modules/curriculum/pages/prerequisite_configuration.php`: blocking sequence panel replaces confirmation checkbox.
- `modules/curriculum/assets/subject-mapping-final.js`: server-backed preview, disabled invalid Save, preserved modal values, and subject-specific deep links.
- `modules/curriculum/assets/prerequisite-configuration.js`: server-backed preview, Sequencing Conflicts review, explanatory messages, and contextual Fix Placement links.
- `tests/prerequisite-service.php`: updated synthetic fixtures, strict validation, incoming/outgoing placement changes, chain validation, and audit rollback.
- `tests/prerequisite-http.php`: ordered synthetic placements for existing authenticated regressions.
- `tests/subject-mapping-http.php`: ordered synthetic placements plus preview RBAC, direct invalid placement/rule requests, and override-bypass regressions.
- `tests/prerequisite-workspace.cjs`: server preview mock and blocking/review terminology checks.
- `tests/subject-mapping-workspace.cjs`: preview, disabled Save, retained modal/values, dependency warning, and existing workspace behavior checks.

## Validation and transactions

The prerequisite position must be strictly less than its target position. College year labels remain flexible; recognized year/semester numeric and ordinal labels are normalized. Grade 11/12 and semesters 1/2 normalize to four SHS periods. Unknown year or term labels, including extra terms, are unresolved and block affected relationships with an explanation. A placement with no prerequisite relationships may retain an unknown label.

Placement changes validate every direct incoming and outgoing stored prerequisite edge. This preserves chain ordering without inventing dependencies from codes. Prerequisite create/edit retain existing self, duplicate, and cycle checks, then validate actual placements in the chosen version. Corequisite behavior remains unchanged.

Placement and prerequisite writes acquire the same curriculum-version row lock before reading the graph. Placement lookup for lock scope occurs before its transaction; placement and graph data are reloaded after acquiring the lock to avoid using an earlier repeatable-read snapshot. Placement assignments/removals also acquire this lock. Successful writes and required audit records commit together; validation or audit failures roll back. The HTTP layer records blocked sequence attempts with the existing best-effort audit helper.

Both UI previews call the PHP validator. Write services repeat validation authoritatively regardless of JavaScript or preview state. No automatic placement or prerequisite corrections occur.

## Verification

Passed:

- `php tests/academic-sequence.php`: 17 checks.
- `php tests/prerequisite-service.php`: 73 checks using connection-local temporary tables; permanent data fingerprints unchanged.
- `php tests/subject-mapping-http.php`: 127 checks using a disposable synthetic database and loopback HTTP server.
- `php tests/prerequisite-http.php`: 56 checks using a disposable synthetic database and loopback HTTP server.
- `php tests/subject-mapping-reads.php`: 440 read-only checks.
- `node tests/prerequisite-workspace.cjs`.
- `node tests/subject-mapping-workspace.cjs`.
- `node tests/subject-mapping-graph.cjs`.
- `node tests/curriculum-cascade.cjs`.
- PHP lint on all ten modified/new production PHP files and JavaScript syntax checks on both modified assets.

UI tests use a mock DOM. Live browser visual verification was unavailable because browser discovery returned no connected browsers. A repository-wide whitespace check also reported pre-existing trailing whitespace in `modules/curriculum/pages/subjects.php:129`, which this task did not modify.

## Existing data found by read-only inspection

Version 42 contains three same-period conflicts:

| Prerequisite | Target | Both placements |
| --- | --- | --- |
| GE3 | GE1 | 1st Year / 1st Semester |
| CCS1102/CC102 | CC101 | 1st Year / 1st Semester |
| BPM101 | CAP101 | 4th Year / 1st Semester |

No cycles, duplicate prerequisite pairs, or missing placements were found in that scan. These are observations of local data, not assumptions or fixtures. All records were left unchanged and are surfaced through Validation Review.

## Preserved scope

Existing sidebar, navbar, branding, global styles, authentication, RBAC, CSRF, repository architecture, and unrelated user edits remain intact. There is no new framework, inferred academic policy, inferred prerequisite, migration, or automatic data correction. Subject Mapping remains read-only for prerequisite information; all rule editing remains in Pre-requisite Configuration.
