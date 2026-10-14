# Task 10: Outcomes workflow stabilization

## Repository and scope

- Worktree: C:\xampp\htdocs\sms-csms-task10
- Branch: feature/phase1-task10-outcomes-ui-stabilization
- Exact parent: 7018129c07b7c449ed04d2235b72cf81fb7170eb
- Local commit: the commit containing this report; obtain its exact ID with git rev-parse HEAD.
- Task 9 worktree and preview retained unchanged. No push, merge, deployment, migration-file changes, source-catalog changes, normal XAMPP data access, or permission grants.
- Browser runtime was initialized and discovery returned no available browsers. No rendered-page inspection, screenshots, pixel verification, or actual browser overflow measurement was possible.

## Findings and foundation inspection

Inspected the active Subject Mapping PHP/controller and final CSS (Curriculum Map, Subject Trace, mapping review, placement details and confirmation modals), Curriculum Builder and Academic Programs, shared curriculum controls, global dashboard/typography styles, and Curriculum History comparison. The Task 7/8 outcome and planning blocks used broad ad-hoc expanded forms; they did not use the foundation's field and detail hierarchy consistently.

Reused page-title, mapping-review-header, section-heading, eyebrow, domain-card, program-summary-grid, record-code, status-badge, record-detail-grid, nested-section, list-toolbar, search-control, form-field, form-grid, domain-form, modal-actions, button-primary, button-muted, button-danger, reference-action-details, and card-header-actions. Existing CurriculumUI handles escaping elsewhere, cascading selectors, submission feedback, form errors and toasts. The new presentation module only renders the existing API response; it adds no framework or authorization policy. Native details/summary provides keyboard-operable disclosures. Subject placement uses a labeled search filter plus the native foundation-styled select, retaining normal keyboard selection.

Two actual integration defects were confirmed:

1. Builder's Graduate Outcomes link included program/curriculum/version IDs but omitted the old outcomes=1 flag. The controller unconditionally selected Map, and only switched after async initialization when that legacy flag was present. The baseline controller is executed in the new test to reproduce this exact path.
2. CurriculumCloAssessmentRepository::placement selected lifecycle role and review status but omitted baseline_version_id. The existing policy requires a baseline for proposed revisions, so the workspace incorrectly returned editable=false. The preview editor already has independent mapping, CLO and plan capabilities. Returning the existing baseline field corrects the read response; write authorization and lifecycle rules are unchanged. Protected references still report read-only, and viewers still lack management capabilities.

## Changes

The Builder link now carries tab=outcomes. PHP selects an allowlisted tab in the initial HTML; the controller uses the same allowed states before async context loading. Tab changes update history; popstate restores and revalidates the owner/version chain. Legacy outcomes=1 remains supported. Ordinary sidebar and Manage Subject Mapping links retain Map behavior. Ownership mismatches, missing records and denied requests remain visible errors without loading an unrelated outcome workspace. Older async context, outcome and CLO responses are ignored or cleared.

Graduate Outcomes now has a compact context header, recorded-count overview, search and coverage filter, a full-text compact list, and one focused View/Manage detail panel. Overview states come from server coverage assessments; links never imply assessed coverage or attainment. Documentary title, issuer, source key, sections, individual outcome page locations, order and fingerprint remain in an expandable source section. Accuracy, applicability and institutional-adoption limitations remain visible. Mapping details separate links, rationale, inherited origin and coverage notes. Forms are disclosed only when allowed; removal requires confirmation. API payloads and academic mutation semantics remain unchanged.

CLO records separate code/statement, manual or inherited origin, review state, alignments/rationale, and assessment plans. Task and evidence are both displayed, alongside indicators, optional rubric and missing fields. Create/Edit/Withdraw actions use existing endpoints and are gated independently for CLO and plan permissions, lifecycle and active record state. Withdrawn records remain visible without unusable child edit controls. Submit buttons use foundation submitting feedback and errors remain in their forms.

Builder provenance headers now remain visible despite the foundation's broad hidden-header rule. Compare revision with reference sits with the protection badge in the reference header. A scoped hidden rule prevents the foundation button display rule from exposing it on ineligible versions. Metadata subtext displays on separate lines; source cells wrap, facts group vertically, and long tables scroll inside their container. Revision guidance precedes the year tabs so it no longer separates year navigation from semester lists.

The shared synthetic warning has neutral informational styling and remains guarded by CSMS_DISPOSABLE_PREVIEW=1. It is not added to normal production pages.

## Safe preview

Task 10 URL: http://127.0.0.1:55049/auth/signin.php

Separate MariaDB: loopback port 55048; verified MariaDB 10.4.32, foreign_key_checks=1, dedicated data directory under %LOCALAPPDATA%\CSMS-SMS2\task10-preview\data. The manifest contains a dedicated csms_docker_test_task7_run_10_* schema and confirms this worktree. Task 9 at port 49792 remains separate.

Read generated credentials locally (do not commit them):

~~~powershell
Get-Content -LiteralPath "$env:LOCALAPPDATA\CSMS-SMS2\task10-preview\login.txt"
~~~

Editor: task10_preview. Viewer: task10_viewer. All fixture content is synthetic; institutional adoption/approval is unverified. The preview intentionally mirrors the established synthetic scenario, including its fixture source labels; it contains no real BCP academic records.

From the Task 10 worktree:

~~~powershell
powershell -NoProfile -ExecutionPolicy Bypass -File tools/task10-preview.ps1 -Action Status
powershell -NoProfile -ExecutionPolicy Bypass -File tools/task10-preview.ps1 -Action Stop
powershell -NoProfile -ExecutionPolicy Bypass -File tools/task10-preview.ps1 -Action Start
& C:\xampp\php\php.exe tools/task10-preview-check.php "$env:LOCALAPPDATA\CSMS-SMS2\task10-preview"
~~~

Stop preserves this preview's data. Reset is separate and requires the exact manifest marker. Do not run Task 9 preview scripts from the Task 10 worktree. Do not point these tools at a normal academic database.

Synthetic direct links after sign-in:

- Builder: http://127.0.0.1:55049/modules/curriculum/pages/curricula.php?program_id=1&curriculum_id=1&version_id=2
- Outcomes: http://127.0.0.1:55049/modules/curriculum/pages/subject_mapping.php?program_id=1&curriculum_id=1&version_id=2&tab=outcomes
- Protected reference outcomes: same URL with version_id=1.

## Verification

Verified on 2026-10-14:

- 71 focused executable controller/presentation checks passed (tests/outcomes-ui.cjs), including execution of the baseline bug and actual Builder link generation.
- All 13 supported standalone frontend suites passed; no browser-rendering claim.
- Full guarded academic gate passed: 32/32 isolated database/HTTP/domain/concurrency suites, followed by 9/9 frontend suites. Gate exit code 0; temporary MariaDB server/data removed by its verified cleanup.
- Runtime integration HTTP: 165 checks passed, including initial HTML tab visibility, forged-tab fallback, permission flags and proposed/reference CLO lifecycle states.
- CLO domain suite: 21 checks passed, including the baseline/read-only regression.
- Separate Task 10 preview: all 8 API/analytics requests and all 12 authenticated page routes returned HTTP 200; 13 graduate outcomes and the synthetic inherited CLO were returned.
- PHP lint: 221 files; JavaScript syntax: 45 files; zero failures. git diff --check passed.
- Regression log retained outside the repository at %TEMP%\csms-task10-regression-final.log.

Full final gate inventory:

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
| subject-mapping-workspace.cjs | 0 (pass) |
| curriculum-cascade.cjs | 0 (pass) |

Two pre-existing frontend harness defects were reproduced on untouched Task 9 and corrected only in Task 10: stale searchRank assertions expected 4 instead of the existing word-boundary tier 2 / contains tier 3, and the cascade mock omitted CustomEvent, dispatchEvent and closest. No shared search implementation changed.

## Acceptance outcomes

| ID | Result and evidence |
| --- | --- |
| U10-01 | Automated pass: actual Builder handler emits outcomes tab and exact context; baseline bug reproduced. Real-browser click pending. |
| U10-02 | Automated pass: independent page loads, immediate visibility, reload-equivalent initialization, history events, new page instances, tab switching and forged/missing/denied contexts. Browser Back/Forward and hard refresh pending. |
| U10-03 | Foundation classes and typography reused; scoped CSS only. Rendered visual consistency pending. |
| U10-04 | Automated search/filter/full-text list/detail checks pass. Human browsing/scroll usability pending. |
| U10-05 | Escaped title, issuer, sections, page locations and full fingerprint preserved in source disclosure; limitations retained. Disclosure visual QA pending. |
| U10-06 | Controller submission, coverage state, confirmation cancellation and success/error feedback pass; isolated mapping/RBAC/CSRF suites pass. Browser form interaction pending. |
| U10-07 | Controller and presentation tests cover selected-placement CLOs, origins, alignments, rationale, edit dispatch and stale responses. Visual QA pending. |
| U10-08 | Planned task, evidence, indicator, rubric and missing-state rendering tested. Separate plan edit dispatch tested; no attainment claim. Visual QA pending. |
| U10-09 | Proposed baseline read defect fixed and regression tested; protected reference and independent view/mapping/CLO/plan gates preserved. |
| U10-10 | Builder version handler regression passes; source wrapping, visible reference header, grouped comparison action and guidance placement implemented. Visual QA pending. |
| U10-11 | Preview flag and styled notice verified in authenticated HTML; dedicated preview identity checked. Rendered notice pending. |
| U10-12 | Pass: all 32 isolated academic suites, all 13 standalone frontend suites, PHP/JS syntax and diff checks. Task 9 worktree and preview retained. |

## Manual browser acceptance (pending)

1. Open the Task 10 URL and sign in with the generated editor credentials. Confirm the synthetic warning is visible, styled, and explicitly denies verified adoption. Confirm the URL port is 55049, not the Task 9 port.
2. Open the synthetic working revision in Builder. Click Graduate Outcomes. Confirm Program 1 / Curriculum 1 / Version 2 and the Graduate Outcomes tab appear directly with IT01-IT13. Confirm Map does not flash during loading.
3. Hard refresh; paste the outcome URL into a new tab. Switch to Map, Trace, Review and Outcomes using mouse and arrow/Home/End keys. Use Back and Forward. Check the address and selected tab/context each time. Open ordinary Subject Mapping and Manage Subject Mapping separately and confirm they still default to Map.
4. Change program_id to 2 while retaining curriculum_id=1 and version_id=2. Confirm a context inconsistency, no populated version and no unrelated outcome data. Test version_id=999999 and a logged-out session. Restore the valid link.
5. Search IT01 and a description phrase, then clear search. Filter each coverage state. Confirm full descriptions are readable and only the selected outcome opens editing details. Open documentary source details; confirm long title, issuer, source key, section, printed/PDF pages and SHA-256 wrap and can be read/copied.
6. On the synthetic editable revision only, record a mapping with rationale, mark coverage complete/incomplete, and inspect summary counts. Cancel a removal, then confirm a removal. Confirm coverage invalidates as before, errors remain visible, and no duplicate submission occurs while saving. No real academic data is involved.
7. Filter/select a synthetic placement. Inspect inherited CLO, review state, alignment rationale, assessment method, task, evidence and missing optional rubric. Expand Edit and cancel by collapsing without submission; exercise Create/Edit/Withdraw on disposable records if desired. Confirm method/task-or-evidence/indicator completeness remains distinct from measured attainment.
8. Open the protected reference version. Confirm mapping, CLO and plan mutation actions are absent. Sign in as task10_viewer and confirm all three areas are read-only. Independent CLO-only and plan-only authorization is covered by isolated HTTP tests; do not change preview role grants to simulate these cases.
9. In Builder, check the References & Baseline heading, spaced source values, long titles/locators, protection badge and comparison action. Confirm year/semester subject tables still work and revision guidance no longer splits that section. Confirm the comparison action is hidden for the protected reference and available on its eligible revision. Open Curriculum History comparison and return.
10. Inspect at 1440, 1024, 768, 430 and 360 CSS pixels. Confirm no page-level horizontal overflow; any wide reference table scrolls within its own container. Check long source values, outcome descriptions, native selectors, focus outlines and disclosure/confirmation keyboard behavior. These measurements have not been performed by Codex.

## Exact changed files

- css/dashboard.css
- docs/task10-outcomes-ui.md
- modules/curriculum/assets/curriculum-builder.js
- modules/curriculum/assets/curriculum.css
- modules/curriculum/assets/outcomes-presentation.js
- modules/curriculum/assets/subject-mapping-final.css
- modules/curriculum/assets/subject-mapping-final.js
- modules/curriculum/pages/curriculum_builder.php
- modules/curriculum/pages/subject_mapping.php
- modules/curriculum/repositories/CurriculumCloAssessmentRepository.php
- shared/topbar.php
- tests/curriculum-cascade.cjs
- tests/curriculum-clo-assessment.php
- tests/outcomes-ui-fixture.cjs
- tests/outcomes-ui.cjs
- tests/runtime-integration-http.php
- tests/search-ranking.cjs
- tests/subject-mapping-workspace.cjs
- tests/version-protection-isolated.ps1
- tools/task10-preview-check.php
- tools/task10-preview-fixture.php
- tools/task10-preview.php
- tools/task10-preview.ps1
