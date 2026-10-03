# Electives and search audit — 2026-10-03

## Result

The existing PHP repositories, services, endpoints, shared autocomplete, RBAC,
CSRF, transaction and audit conventions are retained. No framework, AI dependency,
academic policy, destructive migration, or automatic mapping repair was added.

Elective candidates are active Subject Master records mapped to the selected
version and exact stored year/term, excluding selected members. Server validation
independently enforces context ownership, placement, active status, uniqueness,
and positive selections no greater than member count. Required/Optional placement
is not elective membership. Changes to placement remain visible as review issues.

The compact editor uses “Subjects Available for This Elective Group” and one
helper line. Year/term choices come from stored placements/groups, not fixed
college/SHS defaults. Prerequisites are read-only metadata; management links appear
once at group detail level. Archive uses the confirmation dialog and retains
members/history. Seven real review categories cover empty groups, invalid rules,
duplicates, mismatches, unmapped members, inactive subjects (including archived
groups), and missing context. Inconsistent membership version references are
joined through group ownership so they are not silently dropped from review.

## Search behavior and defects fixed

- Searches previously used alphabetical, insertion, sequence, or update order
  before relevance, including before pagination.
- Subject Master’s current client list also searched descriptions, contrary to
  its code/title label. Program cards searched unrelated context labels and made
  ineffective extra subject requests. These now have explicit entity fields.
- Elective candidate lookup previously searched every placement in the version,
  regardless of selected year/term or active status.
- The elective version selector omitted effective academic year.
- Autocomplete had no shared relevance/filtering step; some local searches capped
  candidates before ranking. All active autocomplete uses the shared helper.
- Reports contained a decorative search box with no handler; it was removed.

Both helpers lowercase, trim, and collapse whitespace without altering punctuation.
Rank is exact field match, field prefix, token prefix, then substring. Nonmatches
are excluded. Equal ranks prefer the primary code/name field, then normalized
code/title and the existing stable ID/order tie-breaker. SQL uses prepared values,
escaped literal LIKE wildcards (`=`, `%`, `_`), and a quoted regex query. Ranking
is applied before LIMIT/OFFSET; count queries retain the same predicates. Empty
SQL searches preserve existing list ordering. Client collections sort stably.

`BPM101 — Business Process Management in IT` legitimately contains `ge` in
“Management.” For GE it ranks below GE code prefixes; a description-only match is
excluded. Tests assert this distinction rather than special-casing subject codes.

Autocomplete uses explicit entity fields, never display metadata. Context
suggestions are capped at three, subjects at five, after ranking. Existing stored
IDs, upstream invalidation, request sequence guards, keyboard behavior and empty
states remain in the shared component.

## Complete active search inventory

Aliases such as academic_programs/curricula and subject_history_view/
curriculum_history_view render the same underlying pages below.

| Page / controls | Fields / implementation and scope |
| --- | --- |
| Curriculum Builder: `programSearch`, `curriculumProgramSearch`, `curriculumProgramInput` | Program code/name; AcademicProgramRepository, parent ownership preserved |
| Builder context program/curriculum/version selectors where rendered | Program code/name, curriculum code/name, version label/effective year; their repositories; dependent IDs |
| Builder: `assignmentSubjectInput` | Subject code/title; SubjectRepository; explicitly “Search all subjects” for adding a placement |
| Subject Master: `subjectCardSearch` | Program code/name; shared client ranking; label now describes programs/strands |
| Subject Master: `subjectSearch` | Subject code/title only; SubjectRepository or selected-version client records; year/term/status filters preserved |
| Subject Master: `subjectAssignmentProgramInput`, `subjectAssignmentCurriculumInput`, `subjectAssignmentVersionInput` | Shared cascade; program → curriculum → version; descendant IDs cleared |
| Subject Mapping: `mappingProgramInput`, `mappingCurriculumInput`, `mappingVersionInput` | Shared cascade, scoped repositories |
| Subject Mapping: `mappingSubjectSearch` | Code/title within selected version; shared client rank within year/semester sections |
| Subject Mapping: `mappingTraceSearch` | Subject code/title; selected program/curriculum/version scope, or global without context |
| Subject Mapping: `mappingSubjectSelectorSearch` | Explicit global Subject Master insertion lookup; already placed subjects excluded by existing endpoint |
| Prerequisites: `programSearch`, `curriculumSearch`, `versionSearch` | Shared cascade, scoped repositories |
| Prerequisites: `ruleSearch`, `focusSearch`, `targetSearch`, `requiredSearch` | Subject code/title in selected-version placements; shared rank; existing sequence/cycle validation remains authoritative |
| Electives: context searches, `groupSearch`, `subjectSearch` | Scoped context; group name only; compatible active placement code/title, respectively |
| Program/Subject Assignment: `programAssignmentSearch`, `subjectUsageSearch` | Subject code/title; AcademicProgramAssignmentRepository; existing selected context predicates retained |
| Assignment: `programAssignmentSubjectSearch` | Explicit global Subject Master insertion lookup; existing placement exclusion retained |
| Offering History: `historySubjectSearch` | Subject code/title; SubjectOfferingHistoryRepository; program/curriculum/version/year/term filters retained |
| History: `historyVersionSearch`, `compareVersionASearch`, `compareVersionBSearch` | Version label/effective academic year; curriculum-scoped; comparisons exclude other chosen version |
| Equivalency: `subjectASearch`, `subjectBSearch`, `equivalencySearch` | Subject code/title; global cross-subject lookup is intentional; record list searches either subject's code/title |
| Weighting: `weightingPlacementSearch`, `weightingComponentSearch` | Selected-version subject code/title; selected-scheme component name |
| Compliance: `ruleSearch` | Rule name/reference code/reference title; ComplianceRuleRepository |
| Compliance: `referenceSearch`, `complianceRuleReferenceInput` | Reference code/title/issuing body; ComplianceReferenceRepository; shared autocomplete uses those exact fields |
| Compliance: `historySearch` | Program code/name, curriculum code/name, version label; ComplianceValidationRepository |
| Compliance: `complianceRuleScopeInput` | Explicit chosen program or curriculum scope entity |
| Compliance: `complianceRuleSubjectInput` | Explicit global Subject Master code/title lookup for reusable rules |
| Export: `exportProgramSearch`, `exportCurriculumSearch`, `exportVersionSearch` | Shared cascade, same ranked repositories and scoped IDs |
| Users / Permissions / Audit Logs | Inspected: no search inputs or search endpoints; existing lists/pagination unchanged |
| Dashboard / Account / Home / auth / Reports / shared navigation | No active search after removing Reports' disconnected placeholder |

Existing non-searchable native select filters retain their existing dependencies.
Legacy `subjects.js`, `subject-mapping.js`, `subject-mapping-workspace.js`, and
`subject-mapping-graph.js` are not loaded by current pages. Their repository/shared
autocomplete calls inherit server/helper fixes; dormant graph code was not
redesigned. Its highlight operation is not a result-list ranking surface.

## Files and schema

Core changes: `RepositoryQuery.php`, the 15 existing search repositories,
`ElectivesWorkspaceRepository.php`, `ElectivesWorkspaceService.php`,
`curriculum-common.js`, `electives-manager.js`, `prerequisite-configuration.js`,
`subject-mapping-final.js`, `subjects-redesign.js`; related page labels and the
Reports placeholder. Tests and this report document the behavior.

The already-present forward migration `014_finalize_electives_manager.sql` is
reused. It adds placement, required selections, status and a version/status index
to the existing groups table. No additional migration or duplicate table was
introduced, and this task did not apply schema changes to the normal database.
Deployments missing 014 must use the existing migration runner. Search SQL uses
MariaDB-supported REGEXP_REPLACE; no search index/schema changes were needed.

## Security and validation evidence

Mutations retain `elective.manage`, POST, CSRF, server validation, row locks,
transactions and required uppercase audit events (CREATE, UPDATE, ARCHIVE,
SUBJECT_ADD, SUBJECT_REMOVE). Reads retain `elective.view`. Hard delete and legacy
membership write bypasses remain rejected. An audit failure rolls back membership
and group changes. Search does not mutate authoritative academic records.

Passing verification:

- `php tests/electives-http.php --foundation`: **1,655 foundation checks + 95
  isolated HTTP checks**. Disposable schema/server removed afterward.
- `php tests/electives-service.php`: **56 checks**, temporary fixtures,
  permanent tables fingerprinted unchanged. Includes choose 1 of 3, impossible
  counts, duplicate/inactive/unmapped/mismatched members, context isolation,
  missing placement, archive preservation and audit rollback.
- `php tests/search-ranking.php`: **19 checks**, temporary fixtures; GE/BPM/CAP/
  CC101, exact title, title-prefix tie, extra spaces/case, hidden descriptions,
  literal wildcards/injection text, pagination, context and effective-year lookup.
- `node tests/search-ranking.cjs`, `node tests/curriculum-cascade.cjs`: equivalent
  client matrix, actual shared autocomplete rendering, caps, no-match states,
  upstream invalidation and stale responses.
- `node tests/electives-workspace.cjs`, `node tests/prerequisite-workspace.cjs`,
  `node tests/subject-mapping-workspace.cjs`, `node tests/phase2_ui.cjs`,
  `node tests/frontend.cjs`: passing nonvisual regressions.
- `php tests/prerequisite-service.php`: **73 checks**;
  `php tests/subject-mapping-reads.php`: **440 read-only checks**;
  `php tests/academic-sequence.php`: **17 checks**.
- **120 PHP files** linted; **31 JavaScript files** syntax checked.

Legacy foundation fixtures were corrected to use the current Units-only modal
and genuinely ordered prerequisite placements. History expectations were adjusted
to reflect those changed fixtures. No production validation was relaxed.

## Manual checks remaining

Browser skill bootstrap reported “No browser is available”; discovery returned
an empty list. No visual QA is claimed. Check desktop/mobile card wrapping, long
names, modal scroll/overflow, three/five-result dropdown layout, keyboard focus,
and read-only/edit/archive interactions in a real browser. SQL and HTTP tests do
not substitute for these checks. Normalization wraps searchable columns, so large
deployments should measure query plans before adding any index optimization.
