# Task 10.1 - Foundation UI correction report

## Repository and scope

- Worktree: C:\xampp\htdocs\sms-csms-task10-1
- Branch: feature/phase1-task10-1-foundation-ui-corrections
- Exact baseline: 0d0bc95f830857bf75790550660f0df1a263c475
- Final local commit: the commit containing this report; obtain its exact hash with git rev-parse HEAD.
- Baseline and worktree inventory were verified before coding. All prior worktrees were preserved.
- No push, merge, rebase, deployment, permission grants, services/repositories/HTTP endpoint changes, academic model changes, source record changes, or changes to migrations 001-025.
- Test data exists only in independently guarded disposable environments. Normal XAMPP academic data and the Task 10 preview database were not selected.

## Foundation inventory inspected before implementation

| UI element | Actual reference files | Components reused |
| --- | --- | --- |
| Centered View dialog | modules/curriculum/pages/subjects.php; modules/curriculum/pages/curriculum_builder.php (programDetailsModal) | curriculum-modal, record-details-modal, modal-backdrop, modal-dialog-large, modal-dialog-scroll, modal-header, modal-context, modal-close-button, modal-body, modal-footer, data-modal-close |
| Modal lifecycle | modules/curriculum/assets/curriculum-common.js; modules/curriculum/assets/subjects-redesign.js | CurriculumUI.createModal(): fixed dialog/backdrop, body scroll lock, Escape, focus trap and focus restoration |
| Warning notice | modules/curriculum/pages/subject_equivalency.php; modules/curriculum/assets/curriculum.css | curriculum-notice and fa-circle-info; existing soft-yellow #fff8e8 background, #f2d58c border and #73510d text |
| Status badges | modules/curriculum/assets/curriculum.css; modules/curriculum/assets/curriculum-common.js | status-badge, status-active, status-draft, status-inactive, status-archived, informational read-only-pill, statusBadge() |
| Open-version content layout | modules/curriculum/assets/curriculum.css (final open-version rules); modules/curriculum/pages/curriculum_builder.php | Existing 54px desktop gutters, 28px at <=1100px, 16px at <=700px; domain-card, version-workspace-header, year-tabs, semester-panel, table-scroll |
| Forms and picker | modules/curriculum/pages/subjects.php; modules/curriculum/pages/subject_mapping.php; modules/curriculum/assets/curriculum-common.js | domain-form, form-grid, form-field, record-autocomplete, record-autocomplete-options, createAutocomplete(), setFormSubmitting(), showFormError() |
| Compact record details | modules/curriculum/pages/subjects.php; modules/curriculum/pages/prerequisite_configuration.php | record-detail-grid, subject-description-section, subject-usage-list, subject-usage-context, domain-table, row-actions |
| Buttons | Academic Programs/Subjects/Builder markup | view-button, row-action-button, secondary-action-button, button-primary, button-muted, button-danger; existing Font Awesome icons |
| Permitted inline editing | Builder reference actions; Subject Equivalency/Grade Weighting forms | reference-action-details with existing domain-form and form controls; no nested form dialog |

Also inspected Subject Equivalency's view/review/archive dialogs, Prerequisite Configuration's details/editor/remove dialogs, Grade Weighting's component/removal/discard dialogs, and global typography and responsive rules. Grade Weighting supplied only component references, not an assessment data model.

The small compatible extensions are scoped layout rules, a nested academic-notice modifier for the existing direct-child notice styling, additional human-readable status labels using the existing palette, and an optional restoreFocus callback on createModal when a refreshed record list detaches the original trigger. Existing callers retain their behavior. No framework, font import, theme or competing component system was added.

## Corrections

### One academic notice

The two separate Graduate Outcomes notices are now one curriculum-notice academic-notice container headed Important Academic Notice. It retains the CHED CMO No. 25, s. 2015, Section 6.3 citation; fingerprint and independent-registry/current-applicability/adoption limitations; and the distinction between planning/mapping and attainment, compliance or approval. The same limitation covers CLO/assessment planning, so the page no longer repeats another disclaimer box below it. Detailed source limitations remain available inside the source dialog. The independent synthetic-preview identification remains intact.

### Documentary Source Details

View Source Details uses the foundation secondary action button and file icon. It opens sourceDetailsModal using CurriculumUI.createModal(). Its body has Document Information, Source Reference, Document Integrity, Source Verification and Applicability, and Detailed Outcome Locations sections. The recorded title, issuer, source key, complete SHA-256 and individual IT01-IT13 printed/PDF page locations remain accessible; locations are table rows rather than a concatenated paragraph. Fingerprint text wraps in the existing Consolas/monospace convention. No inline expanding documentary section remains.

### Graduate Outcome View

Every outcome row has a foundation View button. Clicking it looks up the ID in the current version response, updates the heading/status/content synchronously and opens outcomeViewModal. No detached bottom-page detail section remains. Details include exact description and compact citation, coverage status/link count/note, recorded mappings with rationale and inherited mapping identity, and authorized mapping/coverage forms. The forms remain collapsed until selected. Removal retains its existing confirmation and endpoint behavior.

Unknown IDs cannot display prior content. Context/tab changes close dialogs; version changes clear the picker and details. Responses from older requests remain ignored. Search/filter values survive closing. Only one academic dialog is opened at a time. After refreshed rows detach a trigger, Close restores focus to the stable search control through the optional foundation-helper callback.

### Curricula layout and badges

The provenance panel and revision guidance now join the same responsive gutter rules as the year selector and subject placement tables. The source table also has internal card spacing. Header actions use existing hierarchy/icons and wrap with existing action-group behavior.

Required, Editable, Mapped, Planned and actual Approved states use status-active; Draft, Under review, Awaiting academic review and Incomplete use status-draft; Protected, Read only, Optional, Not assessed and Unmapped use neutral status-inactive; Archived/withdrawn history uses status-archived; Reference uses the existing informational read-only-pill style. Visible labels preserve the distinction between Required, Editable and Approved. Proposed revision, Not started and Curriculum source replace raw values where appropriate. Editability still comes from the backend response and actual capabilities, not the Draft label.

### Compact CLO and assessment workspace

The separate search field/select blocks are replaced with the foundation searchable placement picker. A compact selected-subject header shows subject, version/year/semester and the actual available editing state. CLOs use a compact table with statement, active outcome alignment codes, active/incomplete/withdrawn assessment counts, origin/review badges and View/Edit controls.

View opens cloViewModal. It contains the full statement, origin/lineage, record/review state, exact aligned outcomes/rationale, and each plan's method, task, evidence, indicator, optional rubric and inherited origin. Missing fields and withdrawn records remain visible. Larger Create/Edit/alignment/plan forms are revealed only by explicit actions. Withdrawn CLO details remain read-only even when their parent version is editable. Independent CLO and assessment permissions, placement ownership and lifecycle restrictions are preserved.

## Safe separate preview

URL: http://127.0.0.1:59098/auth/signin.php

The server serves this Task 10.1 worktree, using its own MariaDB 10.4.32 on loopback port 59097, foreign_key_checks=1, data directory %LOCALAPPDATA%\CSMS-SMS2\task10-1-preview\data, and dedicated csms_docker_test_task7_run_101_* schema. Its manifest, root, process identity, port and datadir are checked independently. Task 10 remains on its earlier URL and database.

The initial preview attempt used a schema name rejected by the unchanged catalog-loader guard. Only that newly created Task 10.1 environment was reset through its own verified marker, then recreated with the existing allowed naming convention. The immutable source loader and its safeguards were preserved.

Generated local credentials:

~~~powershell
Get-Content -LiteralPath "$env:LOCALAPPDATA\CSMS-SMS2\task10-1-preview\login.txt"
~~~

Editor username: task10_1_preview. Viewer username: task10_1_viewer. Do not commit or share the generated passwords. All curriculum/subject/planning fixtures are synthetic; institutional adoption/approval remains unverified.

From C:\xampp\htdocs\sms-csms-task10-1:

~~~powershell
powershell -NoProfile -ExecutionPolicy Bypass -File tools/task10-1-preview.ps1 -Action Status
powershell -NoProfile -ExecutionPolicy Bypass -File tools/task10-1-preview.ps1 -Action Start
powershell -NoProfile -ExecutionPolicy Bypass -File tools/task10-1-preview.ps1 -Action Stop
& C:\xampp\php\php.exe tools/task10-1-preview-check.php "$env:LOCALAPPDATA\CSMS-SMS2\task10-1-preview"
~~~

Stop retains this preview's data. Start resumes it. Reset is a separate action requiring the exact manifest marker. Use only these Task 10.1 scripts for this preview.

After signing in:

- Outcomes: http://127.0.0.1:59098/modules/curriculum/pages/subject_mapping.php?program_id=1&curriculum_id=1&version_id=2&tab=outcomes
- Builder revision: http://127.0.0.1:59098/modules/curriculum/pages/curricula.php?program_id=1&curriculum_id=1&version_id=2
- Protected reference: use version_id=1 in the outcome URL.

## Automated verification

Verified on 2026-10-14:

- tests/foundation-outcomes-ui.cjs: 113 passing checks, executing the shipped controller and real CSMS createModal helper with deterministic DOM/network doubles.
- tests/outcomes-ui.cjs: 72 passing existing Task 10 behavior/presentation checks, adapted to modal event ownership and strengthened with compact-list assertions.
- All 14 supported standalone frontend suites passed. The helper fixture is excluded as a non-runner.
- Full guarded academic gate: 32/32 isolated database/domain/HTTP/concurrency suites and 10/10 frontend suites, exit 0. Its temporary MariaDB process/data were removed by verified cleanup.
- Authenticated runtime HTTP: 194 passing checks, including exactly one academic notice, retained limitations, actual modal markup/body ownership, standard close controls, initial hidden state, searchable picker, permission flags and deep links.
- Separate Task 10.1 preview: all 8 API/analytics requests and all 12 authenticated pages returned HTTP 200; 13 recorded graduate outcomes and the inherited synthetic CLO were returned.
- PHP lint: 224 files. JavaScript syntax: 46 files. Zero failures. git diff --check passed.
- Full final gate log retained outside the repository: %TEMP%\csms-task10-1-regression-final.log.

Final gate inventory:

| Suite | Exit |
| --- | --- |
| deployment_migrations.php | 0 (pass) |
| runtime-integration-http.php | 0 (pass) |
| curriculum_lifecycle_schema.php | 0 (pass) |
| curriculum-reference-schema.php | 0 (pass) |
| curriculum-reference-baseline.php | 0 (pass) |
| curriculum-reference-http.php | 0 (pass) |
| curriculum-reference-concurrency.php | 0 (pass) |
| curriculum-revision.php | 0 (pass) |
| curriculum-revision-http.php | 0 (pass) |
| curriculum-revision-concurrency.php | 0 (pass) |
| curriculum-comparison.php | 0 (pass) |
| curriculum-comparison-http.php | 0 (pass) |
| curriculum-comparison-concurrency.php | 0 (pass) |
| curriculum-outcome-mapping.php | 0 (pass) |
| curriculum-clo-assessment.php | 0 (pass) |
| curriculum-clo-assessment-http.php | 0 (pass) |
| curriculum-outcome-mapping-http.php | 0 (pass) |
| version-protection.php | 0 (pass) |
| version-protection-http.php | 0 (pass) |
| curriculum-concurrency.php | 0 (pass) |
| prerequisite-service.php | 0 (pass) |
| electives-service.php | 0 (pass) |
| subject-mapping-http.php | 0 (pass) |
| electives-http.php | 0 (pass) |
| archives-http.php | 0 (pass) |
| academic-sequence.php | 0 (pass) |
| search-ranking.php | 0 (pass) |
| academic_fk_compatibility.php | 0 (pass) |
| legacy-fk | 0 (pass) |
| prerequisite-http.php | 0 (pass) |
| cimf.php | 0 (pass) |
| electives-foundation | 0 (pass) |
| frontend.cjs | 0 (pass) |
| phase2_ui.cjs | 0 (pass) |
| cimf-modal.cjs | 0 (pass) |
| curriculum-revision-ui.cjs | 0 (pass) |
| curriculum-comparison-ui.cjs | 0 (pass) |
| runtime-integration-ui.cjs | 0 (pass) |
| outcomes-ui.cjs | 0 (pass) |
| foundation-outcomes-ui.cjs | 0 (pass) |
| subject-mapping-workspace.cjs | 0 (pass) |
| curriculum-cascade.cjs | 0 (pass) |

## F10 acceptance matrix

| ID | Result | Evidence / remaining acceptance |
| --- | --- | --- |
| F10-01 | Automated HTML pass; rendered QA pending | Exactly one academic notice in authenticated DOM, required source/limitation text retained, existing yellow styling reused. |
| F10-02 | Automated interaction/HTML pass; rendered QA pending | Button opens the real helper's source dialog with grouped full provenance and location rows; inline source disclosure absent. |
| F10-03 | Automated interaction/HTML pass; rendered QA pending | All 13 View actions immediately open the outcome dialog; body belongs to dialog, not the list container. |
| F10-04 | Automated pass | Distinct record titles/descriptions, unknown-ID rejection, form-record identity, version-context clearing and existing stale-response guards. |
| F10-05 | Automated helper pass; browser keyboard/layout QA pending | Real helper executes Close, Escape, backdrop, forward/reverse Tab, body lock and focus return; authenticated markup has standard scroll/body/footer classes. |
| F10-06 | Automated pass; browser form QA pending | Rendered mapping fields dispatch existing actions/IDs, complete/incomplete coverage and removal work; duplicate saves suppressed; protected/view-only controls absent. Existing authenticated RBAC/CSRF/audit suites retained. |
| F10-07 | Implemented; rendered QA pending | Existing 54/28/16px gutter rules now include provenance and guidance; source table has internal spacing. Actual pixel/overflow measurement awaits a browser. |
| F10-08 | Automated semantics pass; rendered QA pending | Existing palette classes and human-readable labels tested; actual stored meanings and edit-policy response preserved. |
| F10-09 | Automated interaction pass; rendered QA pending | Searchable placement picker, compact CLO list, View/Edit/Create dialogs, full nested fields, incomplete counts and independent permissions verified. |
| F10-10 | Source/component inventory verified | Actual foundation classes, structures and helpers listed above are used; no competing framework/theme/font. |
| F10-11 | Automated pass; browser navigation QA pending | Existing Task 10 Builder link, initial active tab, owner context, reload-equivalent and history scenarios remain in the executable suite. |
| F10-12 | Automated pass: 32 isolated suites, 10 frontend gate suites, all 14 standalone frontend suites, HTTP, syntax and diff checks. | Full guarded gate plus supported frontend, HTTP, syntax and diff verification. |

## Rendered visual QA and manual acceptance

PENDING. Browser discovery returned no available browser. The real CSMS source/markup/CSS was inspected, and the shipped modal helper was executed in deterministic tests. No screenshots, CSS pixel measurements or rendered comparison have been claimed. The eight screenshot issues were supplied as written correction requirements in this turn; no screenshot image attachments were available to inspect directly.

1. Sign in at the Task 10.1 URL. Confirm its port is 59098 and the separate synthetic identification is visible. Compare the screens against the existing Subjects and Academic Programs View dialogs on the same preview.
2. Open the synthetic working revision in Builder and click Graduate Outcomes. Confirm the intended program/curriculum/version and correct active tab without a Map flash. Refresh, open the URL in another tab, switch tabs, and use browser Back/Forward.
3. Confirm exactly one soft-yellow Important Academic Notice is visible in Outcomes, with all source and planning limitations readable. Confirm no repeated CLO disclaimer box below it.
4. Click View Source Details. Confirm the same centered blue-header dialog convention as Subjects, a constrained standard width, body scrolling, grouped provenance and a readable complete fingerprint. Inspect IT01-IT13 source rows and both printed/PDF pages. Close by icon, footer, Escape and backdrop; check focus and background scrolling.
5. Search/filter the outcome list, open IT01, then IT02 and IT13. Confirm immediate modal behavior, correct record contents and no bottom-page expansion. Close and verify search/filter values remain intact. Change version and verify stale details disappear.
6. On the editable synthetic revision only, record a mapping and rationale, complete/incomplete coverage, and test cancel/confirm removal. Verify status feedback and coverage invalidation follow existing rules. The isolated HTTP suites cover authentication, CSRF, authorization and audits; do not test against normal academic data.
7. Use the searchable placement picker. Confirm the compact CLO table shows statements, alignment codes, plan/incomplete counts and origin/review badges. Open View/Edit/Create and inspect full task/evidence/indicator/rubric fields only in the dialog. Check permission-gated mutations and retained history after withdrawal.
8. Sign in as task10_1_viewer and inspect both outcome and CLO dialogs. Open the protected reference as the editor. Confirm all unavailable mutation controls are absent and statuses read truthfully. Do not modify role grants to simulate additional capabilities.
9. Inspect Builder at 1440, 1024, 768, 430 and 360 CSS pixels. Confirm header, provenance card, year selector and semester cards share the foundation gutter grid, source cells have internal spacing, buttons wrap, and wide tables scroll inside their containers rather than widening the page. Compare Required/Optional/Draft/Editable/Reference/Protected badges and their visible labels.
10. Inspect modal centering and internal scrolling at those widths, keyboard focus, long titles/fingerprints, table readability and mobile overflow. Record remaining visual issues against F10-01 through F10-12. These rendered checks have not been performed by Codex.

## Exact changed files

- docs/task10-1-foundation-ui.md
- modules/curriculum/assets/curriculum-builder.js
- modules/curriculum/assets/curriculum-common.js
- modules/curriculum/assets/curriculum.css
- modules/curriculum/assets/outcomes-presentation.js
- modules/curriculum/assets/subject-mapping-final.css
- modules/curriculum/assets/subject-mapping-final.js
- modules/curriculum/pages/curriculum_builder.php
- modules/curriculum/pages/subject_mapping.php
- tests/foundation-outcomes-ui.cjs
- tests/outcomes-ui-fixture.cjs
- tests/outcomes-ui.cjs
- tests/runtime-integration-http.php
- tests/version-protection-isolated.ps1
- tools/task10-1-preview-check.php
- tools/task10-1-preview-fixture.php
- tools/task10-1-preview.php
- tools/task10-1-preview.ps1
