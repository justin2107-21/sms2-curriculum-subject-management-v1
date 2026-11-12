# Grade Weighting Setup: slice 0 (read-only audit and plan)

Status: slice 0 (this audit) written 2026-11-12. **Slices 1 and 2 built 2026-11-12, see "Slices 1 and 2: done" below; sections 1-9 describe the module *before* them.** S3 (reference layer, migration 032) deferred, blocked on client answers. Branch `ui-redesign-pilot`. Module name is fixed: **Grade Weighting Setup**.
Evidence: code read (file:line below); `sms_db_uitest` read-only; `sms_db` counts only (one status query on `sms_db` also returned two subject codes and a version label; they are not recorded here). Not verified: anything in a browser; the AI provider; the source documents (the CHED/DepEd PDFs are not in the repository, section 8 uses only the summary given with the request).

## Slices 1 and 2: done (2026-11-12; no schema change, no migration, no permission change)

- **Page:** the shared Program / Curriculum / Version bar (the old own context card and the separate Subject picker are gone), same shell as Offering History / Equivalency, title with the `csms-info` text "Weights are configured by the institution. No CHED or DepEd weights are applied automatically. Totals must equal 100%.", subtitle "Configure assessment component weights per subject in a curriculum version.". The demo sentence is removed; "official BCP policy" appears nowhere.
- **Main view:** server-side paged table of the version's subjects (`overview`): Code, Title, Year & Semester, Components, Total %, status chip (Configured / Incomplete / Not configured; Incomplete only exists for legacy or cloned schemes), row menu (Edit weights or View weights, Remove configuration when allowed). Filters: search (300 ms, 2 characters), Status, Year level, Semester, rows 10/20/50/100 (capped at 100 server-side); removable chips and Clear all; filters, sort and page in the URL next to the shared `program_id` / `curriculum_id` / `version_id`; sortable plain headers with an arrow; empty states "No subjects in this version" vs "No subjects match these filters" and the shared "no version selected"; a page note when the role can edit weights but no editable version exists ("No editable version is available for your role.").
- **Dialog:** row click (or the menu) opens one compact dialog: subject and version in the header, components table with inline name and weight inputs, add and remove, running total bar with chip Incomplete / Over 100% / Complete, Save enabled only at exactly 100.00 and when something changed, unsaved-changes guard (the discard dialog; Escape and backdrop included), "Review with CIMF" in the footer (advice beside the dialog, only a fully valid draft is passed along, nothing is written into the fields), Remove configuration with an inline confirmation inside the dialog. Read-only variant when the server says the version cannot be edited: one-line message, no inputs, no Save, Remove or Add.
- **Server:** `context` (version labels, lifecycle, `editable` + `reason_code` + `reason_text`, terms, whole-version coverage, `editable_version_exists`), `overview`, `scheme_get` (now also returns the components and the edit state), `configuration_remove` (POST, CSRF, `grade_weighting.manage`, same editable rules as saving; one transaction deletes components and scheme; audit `GRADE_WEIGHTING_CONFIGURATION_REMOVE` with old names, weights and total). Reason codes: `role`, `archived`, `approved`, `under_review`, `reference`, `protected`. The edit policy still decides on save. The old endpoints `program_options`, `curriculum_options`, `version_options`, `placement_options` are retired (400).
- **Audit:** the save summary row now stores `old_components`, `new_components`, `old_total`, `new_total` (names and weights); per-component update rows carry the old name and weight; removals keep old values.
- **Scope fix:** `component_list` derives the version from the scheme and applies the read scope (a restricted reader gets 404 and no data for a scheme in a draft version); `scheme_get` and `overview` check the version too.
- **Un-placing:** after Remove configuration, `assignment_actions.php?action=remove` works for that subject again (tested).
- **Dead code retired:** the whole old inline editor (own context cascade, Subject picker, component modal, demo note) in `grade-weighting.js`, `grade-weighting.css` and the page; a few old `.grade-weighting-*` selectors remain in the shared files `css/csms-components.css`, `css/csms-typography.css` and `js/csms-live-states.js` (harmless; not touched).
- **Test:** `tests/grade-weighting-http.php` (85 checks, disposable schema and server): page wording, editable flag per version state, list filters, sort whitelist, page-size cap, status filter, read-only data, exact-100 (33.3 + 33.3 + 33.4 accepted, 33.3 x 3 refused), case-insensitive duplicates, CSRF and permissions, removal (audit with old values, blocked on protected versions, un-place afterwards), update audit old / new, `component_list` scope.
- **Scale (disposable clone, synthetic `ZZ-LOAD-` placements in one version, about half configured; the clone was dropped):** best of three, ms. 5,000 placements: default list 71, 100 rows 59, code search 100, status filters 94-115, sorts 49-87, deepest page 85-90, context + coverage 87. 20,000 placements: default list 159, code search 339, status filters 335-436, sorts 103-293, deepest page 280-289, context 349. EXPLAIN: every table is reached through an existing key (`uq_curriculum_version_subject`, `PRIMARY`, `uq_grade_weighting_scheme_placement`, `uq_grade_weighting_component_name`); the filesort over the version's placements is inherent. Real versions hold tens of placements. **No index and no migration are needed** (next free number stays 032).
- **S3 (reference layer, migration 033; 032 is now the Compliance rule library) is deferred**, blocked on the client answers listed in section 12.

## 1. The page today

**Files**
- Page `modules/curriculum/pages/grade_weighting_setup.php` (135 lines), script `assets/grade-weighting.js` (342), styles `assets/grade-weighting.css` (268).
- Endpoint `http/grade_weighting_actions.php:11-56`; `http/grade_weighting_cimf_actions.php` is a retired stub (always 410). Service `services/GradeWeightingService.php` (604 lines), repository `repositories/GradeWeightingRepository.php` (290), validator rules `DomainValidator.php:416-495`.
- Tables (migration `010_create_grade_weighting_setup.sql:9-50`): `grade_weighting_schemes` (one row per placement) and `grade_weighting_components`.

**Endpoints** (`grade_weighting_actions.php`): GET `program_options`, `curriculum_options`, `version_options`, `placement_options`, `scheme_get`, `component_list` (all `grade_weighting.view`); POST `configuration_save` (`grade_weighting.manage`, CSRF). The service also has `createScheme`, `updateScheme`, `removeScheme`, `createComponent`, `updateComponent`, `removeComponent` (`GradeWeightingService.php:111-361`) that **no route calls**: the only write path is `configuration_save`.

**Saved atomically:** yes. `saveConfiguration` (`GradeWeightingService.php:363-509`) runs one transaction: edit-policy check and lock, scheme create-or-lock, delete omitted components, rename through temporary names (so two names can be swapped), update / insert the rest, touch the scheme, audit rows, commit; any failure rolls everything back.

**Page structure (old):** header with a disabled "Review with CIMF" button (`:55-57`), then a context card with its own Program / Curriculum / Version / Subject autocompletes and a hint line (`:60-70`), then (hidden until a subject is chosen) a card "Assessment Components" with a table (Component, Weight, Actions), "Add Component", a running-total bar, a status badge, "Unsaved changes" and "Save Changes" (`:73-93`). Before a subject is chosen the page shows only the context card and the hint "Select a curriculum version and subject to view its weighting configuration." (blank first state).
- **Old components:** own context card (`mapping-filter-card grade-weighting-context-card`, ids `weightingProgramSearch`...), no `mapping-context-bar`, no `csms-empty`, no `csms-chip`, no `csms-info` (the demo note is a plain `<p>`, `:75`); `domain-card`, `status-badge`, `grade-weighting-*` classes, `curriculum-modal` dialogs; a total bar made of page CSS.
- **Shared pieces it does use:** `csms-page-table` / `csms-ctable` for the components table (one table only), `record-autocomplete` pickers, `button-muted` / `button-primary`.
- The working-context key `grade_weighting` already exists on the server (`WorkingContextService.php:27`), so the shared bar can be adopted.
- Wording to remove: the demo note "Demo data is not an official BCP grading policy" appears whenever the curriculum code or version label contains "demo" (`GradeWeightingService.php:85-87`, shown by `grade-weighting.js:149`).

## 2. Scope (what a scheme belongs to)

- **Per placement**, i.e. per (curriculum version, subject): `grade_weighting_schemes.curriculum_version_subject_id` is `UNIQUE` and a foreign key to `curriculum_version_subjects` (`010...sql:11,16-22`); a placement is itself unique per version and subject (`004...sql:107`).
- **Editing version A does not change version B.** Each version owns its placements, so its own schemes.
- **Cloning a version copies the weights.** `CurriculumRevisionService.php:56-59` copies every scheme and component of the source into the new version's placements (new ids, `created_by` = the actor); after that the two are independent.
- **Removing a subject from a version is blocked while a scheme exists:** `CurriculumVersionSubjectService.php:221-224` ("Remove the grade weighting configuration before removing this curriculum placement."). **Defect found:** no route and no UI can remove a scheme (the unrouted `removeScheme` also needs every component gone first, `GradeWeightingService.php:189-193`, and `configuration_save` refuses an empty or non-100% list). Once a subject is configured, its placement cannot be removed from the Builder through the application.

## 3. Validation (server-side)

All in `configuration_save` (`GradeWeightingService.php:363-368, 511-556`); the JavaScript repeats the rules for feedback only.
- **Total must be exactly 100.00:** weights are parsed from the text into integer hundredths (`decimalToHundredths`, `:589-593`) and summed as integers (`:366-367`), so 33.3 + 33.3 + 33.4 = 3330 + 3330 + 3340 = 10000 is accepted and 33.3 + 33.3 + 33.3 is refused; no floating point on the server. (The browser uses `Math.round(Number * 100)`, `grade-weighting.js:44-47`; the server decides.)
- **Weight:** text matching `^\d{1,3}(\.\d{1,2})?$` (at most two decimals, no exponent, no sign, surrounding spaces rejected), greater than 0 and at most 100 (`:532-538`; the table also has `CHECK weight > 0 AND <= 100`, `010...sql:42-43`, and `DECIMAL(5,2)`).
- **Names:** trimmed, required, at most 150 characters (`mb_strlen`, `:522-525`); **duplicates rejected case-insensitively** (`mb_strtolower`, `:526-530`) and the table has `UNIQUE (scheme_id, component_name)` under `utf8mb4_unicode_ci`, which is case-insensitive too.
- **Count:** 1 to 100 components (`:514-516`).
- **An incomplete scheme cannot be saved** through the routed action (total must be 10000). Incomplete schemes can only exist from legacy rows or clones of one (`CurriculumRevisionService.php:207` accepts totals up to 100% on clone). Today: 0 incomplete schemes in either database.
- Component ids must belong to the scheme (`:410-412`); a placement must belong to the stated version (`:378-380`).

## 4. Historical safety and audit

- **Edits are blocked on archived, approved, under-review and reference versions:** `assertChildEditable('placement', ...)` (`:373`) goes through `CurriculumVersionEditPolicy::assertEditable` (`CurriculumVersionEditPolicy.php:50-70, 113-131`): archived is never editable (the rule from commit 9ac49c6); otherwise only an unclassified version (not started, no baseline) or a proposed version in not_started / draft / returned. The refusal is a 409 with a clear message.
- **The page does not show this up front.** `scheme_get` returns no "editable" flag; Add Component and Save stay enabled and the user finds out on save (a toast). Needs a read-only state.
- **Every change is audited** with `audit_log_required` inside the transaction (`:388-393, 420-429, 459-481, 487-493`): scheme create, component create / update / remove, and one `GRADE_WEIGHTING_CONFIGURATION_SAVE` summary (count). **Old values are kept only for removals; an update records the new name and weight but not the old ones** (`:459-468`). One save writes many rows (noise noted in the 2026 audit).

## 5. Permissions and read scope

| Action | Permission | Roles with it (`sms_db_uitest`) |
|---|---|---|
| read (all GETs) | `grade_weighting.view` (GET) | acad_office, academic_curriculum_staff, admin, department_head_curriculum_reviewer, faculty_academic_viewer, legacy_admin, staff, super_admin, system_administrator |
| `configuration_save` | `grade_weighting.manage`, POST + CSRF | academic_curriculum_staff, legacy_admin, staff, super_admin, system_administrator |
| Acad Faculty | none | page 403 |

- **Read scope:** `curriculum_require_read` / `curriculum_require_mutation` call `CurriculumReadScope::enforceInput` (`support.php:15-28`), which checks version-like request keys (`curriculum_version_id`, `curriculum_version_subject_id`, `placement_id`, ...; `CurriculumReadScope.php:47-66`). So `placement_options` (version id), `scheme_get` (placement id) and `configuration_save` are scoped. **Gap (by code reading, not exercised):** `component_list` takes `scheme_id`, which is not in that list, so a restricted reader can request the components of any scheme id. `version_options`, `curriculum_options` and `program_options` list structure without version scope (same as the other modules).
- **Possible role conflict (by the permission table, not exercised):** `academic_curriculum_staff` has `manage` but not `curriculum.view_unapproved`; its read scope hides drafts, and approved versions are protected, so it may be unable to edit anything. Needs confirmation.

## 6. Consumers (does a weight change affect anything else?)

| Reader | What it does |
|---|---|
| Curriculum Export | optional `include_grade_weighting`: `CurriculumExportService.php:185, 404, 445`; `CurriculumExportRepository.php:187` |
| Version comparison (Builder / Offering History compare) | components compared by subject and exact name: `CurriculumHistoryComparison.php:70, 174`; snapshot reads `SubjectOfferingHistoryRepository.php:39-40` |
| Version clone | copies schemes (section 2) |
| Dashboard | "Needs attention" counts placements with an incomplete or missing scheme (`DashboardAnalyticsRepository.php:222-250`, `DashboardAnalyticsService.php:198-206`; "complete" means total = 100) and the audit feed |
| CIMF | stored or draft weights as facts (section 7) |
| Compliance Validator | **does not read weights** (no match in the compliance services) |
| Offering History page | indirect, via the comparison above |

A weight change changes nothing stored elsewhere; it changes what Export prints, what the comparison shows and the Dashboard's attention list.

## 7. CIMF (advice only)

- Profile `grade_weighting` (`CIMFProfileRegistry.php:20`), actions Explain, Review, Summarize; route `global_cimf_actions.php` (`grade_weighting.view`). The context builder (`CIMFGlobalContextBuilder.php:178-203`) validates the components (stored, or the user's unsaved draft passed by the page), computes the totals in PHP and states "No official grading policy is supplied. Do not change weights or approve the configuration."
- The prompt forbids setting or changing weights, approving, claiming regulatory or institutional compliance, or inventing official BCP policy (`CIMFPromptBuilder.php:47`); the response validator replaces any sentence with approved / compliant / official policy wording (`CIMFResponseValidator.php:29-40`), rejects "CHED/DepEd approved|compliant", and rejects any percentage not in the supplied components or totals (`:180-186`).
- **No path writes weights:** the only writes on that route are the `cimf_ai_logs` usage row. The retired `grade_weighting_cimf_actions.php` returns 410.

### CIMF control in all 10 modules (where it lives today; first state)

| Module | Control | First state |
|---|---|---|
| Curriculum Builder | floating CIMF circle and sidebar only | available |
| Subject Mapping | floating only | available |
| Pre-requisite Configuration | floating, plus a per-subject review link in the details dialog (`prerequisite-configuration.js:121`) | floating available; dialog link only after a row is opened |
| Electives Manager | floating only | available |
| Academic Strand/Course Assignment | page header button "CIMF Assignment Review" (`academic_program_assignment.php:33`) | **disabled** until a program is chosen (`program-assignment.js:136,141`) |
| Subject Offering History | dialog footer "Summarize History with CIMF" (`subject_history_view.php:105`) | only inside the open dialog |
| Subject Equivalency Tool | dialog footer "Review with CIMF" (`subject_equivalency.php:144`) | **disabled** until two subjects are compared |
| Grade Weighting Setup | page header "Review with CIMF" (`grade_weighting_setup.php:55`) | **disabled** until a subject is chosen **and** it has at least one component (`grade-weighting.js:138`) |
| Compliance Validator | floating only (page updates the context with the run id) | available |
| Curriculum Export | floating only | available |

No single standard: **page header 2, dialog 3 (one of them also a link), floating sidebar only 5.** The floating circle and its sidebar are not touched by this plan.

## 8. Data (counts only)

| | `sms_db_uitest` | `sms_db` |
|---|---|---|
| schemes | 2 | 2 |
| components | 6 | 6 |
| complete (= 100) / incomplete / empty schemes | 2 / 0 / 0 | 2 / 0 / 0 |
| audit rows `GRADE_WEIGHTING_*` | 50 | not counted |

Both databases hold the same three component names (PRELIM, MIDTERM, FINALS, 30 / 30 / 40) on two subjects of draft versions. They are generic test values, not a stated policy. The "20% PRELIM test component on BPM101" no longer exists in either database. Treat all six rows as demo data (**needs client input** before anything is shown as real).

## 9. Scale

- The subject picker is server-side (`placement_options`): placements of one version, `LIMIT` 20 per request (page size 10 / 20 / 50 only), search on code or title, ranked, no minimum length (`grade-weighting.js:304-305`, `CurriculumVersionSubjectRepository.php:57-100`). It lists the subjects **of one version**, never the Subject Master. The largest version in `sms_db_uitest` has 52 placements. The component list asks for 50 rows (`grade-weighting.js:184`) and a scheme is limited to 100.
- Nothing lists a version's subjects with their weighting status (no aggregate query exists): the new main view needs one (slice 1).

## 10. Sources: stated vs not stated

Only the summary given with the request is used; the documents themselves are not in the repository and were not read.

| Topic | STATED | NOT stated / not found |
|---|---|---|
| DM 074 s.2025 par. 7 | defines Written Work / Performance Tasks / Quarterly Assessment, with weights per SHS subject group | college weights; any rule for BCP |
| DO 015 s.2026 Table 10 / Annex D Table 2 | Source Review v2 treats them as the current SHS weights (**INTERPRETED**) | their values (not supplied; **do not invent**) |
| DO 015 reach | binding on public schools only; private schools "encouraged", may adapt with regional-office approval (**STATED** par. 4, 6) | whether BCP is a pilot / adapting school (**needs client input / may be NDA-restricted**) |
| College component weights | **NOT FOUND** in any project source: an institutional matter | the BCP policy itself (**needs client input / may be NDA-restricted**) |
| GPA rules (NSTP exclusion, PE inclusion: IRR Sec 6(c), CMO 39 Sec 6.4) | computation rules | out of scope for weight setup |
| Handoff Module 8 | valid weighting rules, total percentage validation, duplicate / conflicting configurations, curriculum / program / version scope, safe editing and historical consistency, **no arbitrary defaults** | -- |
| **DO NOT ACTIVATE** | DM 074 or DO 015 weights as defaults; the DepEd "unit system" values as credit units | -- |

**Source rule (client decision pending):** BCP-specific grading or weighting policy is **not confirmed** by the client. Nothing BCP-specific may be hardcoded, defaulted or worded as "official BCP policy". Only CHED and DepEd documents in the Project count as sources.

## 11. Proposed design (shared standard, no blank state)

**Page**
- Title "Grade Weighting Setup"; subtitle "Configure assessment component weights per subject in a curriculum version."; one `csms-info` next to the title: "Weights are configured by the institution. No CHED or DepEd weights are applied automatically." The demo note is removed.
- **Keep the shared Program > Curriculum > Version bar** (`mapping-context-bar`, working context key `grade_weighting`); weights are version-scoped. The own Subject picker and the own context card go away. Same shell as Subject Offering History (`mapping-workspace-shell` > `mapping-workspace-panel csms-panel`).
- **Main view:** a server-side paged table of the version's placements: Code (blue), Title, Components (count), Total %, Status chip (`csms-chip`: Configured = 100%, Incomplete, Not configured), row menu (Edit weights / View, Remove configuration for managers), `csms-filter-row` (search code or title, Status select, rows per page, "N records"), `csms-empty` states (no version selected, no subjects in the version, no match with Clear filters), pagination. A version badge shows "Protected version: read only" when the version cannot be edited. Counts of Configured / Incomplete / Not configured sit in the filter row summary or as filter chips.
- **Row click opens one compact dialog** (same shell as Equivalency / Offering History): subject (code and title as plain text), components table (add, edit and remove inline, drag-free: up / down buttons or the numeric order), running total bar with remaining or over-limit text, "Save" enabled only at exactly 100.00 and only when something changed, inline errors for name and weight, "Unsaved changes" guard on close, **Review with CIMF inside the dialog** (advice shown beside it, never copied into the fields; see decision D1). Read-only variant for non-managers and protected versions. Target: no scrolling at 1440x900 for up to about 6 components, internal scroll of the components table beyond that.
- Server work is the same `configuration_save`; the dialog adds no new validation rules.

**Slices** (next free migration is **033**; 030 Electives, 031 optional Equivalency list index, 032 Compliance rule library)

| Slice | Content | Schema? |
|---|---|---|
| **S1** (about 2 days) | Shared bar and shell; new read action `weighting_overview` (placements of a version with component count, total, status; LEFT JOIN aggregate, paged, search, status filter, indexed by existing keys); table, filters, chips and empty states; dialog editor reusing `configuration_save`; read-only state with an `editable` flag from the edit policy; wording and info text; scope check on `component_list` (resolve the scheme's version); audit: store the old name and weight on updates; HTTP regression test (permissions, CSRF, exact-100 and rounding cases such as 33.3 + 33.3 + 33.4, duplicate names in different case, over 100, protected / archived version refusal, read scope, audit rows, atomic rollback) | **None** |
| **S2** (about 0.5 day) | "Remove configuration" for managers: one atomic action (delete components and scheme, audited with old values), so a configured subject can be un-placed (fixes the trap in section 2); confirm dialog | **None** |
| **S3** (about 1.5 days, only if wanted) | Optional **reference layer**, user-initiated, data-driven (section 12): a "Use reference" button in the dialog that only fills the editable draft from a labelled reference template, which the user then reviews and saves through the same validated path | **Yes, migration 032** |
| S4 (optional) | Version-level view of the weighting coverage on Export or the Dashboard stays as it is; nothing planned | -- |

**Risks**
- The dialog replaces an editing flow people may know; keep the same save semantics (replace the whole configuration at once).
- Row counts per version are small (tens), so the overview needs no new index; recheck if a version ever holds thousands of placements.
- A reference layer invites a reading of "official". Mitigated by section 12: status labels, no default, no automatic application.
- `academic_curriculum_staff` may be unable to edit anything (section 5).
- Existing test rows (30 / 30 / 40) look like a policy; do not show them as one.

**Decisions for you (not changes)**
- **D1, CIMF placement:** keep the current page-header button, or move it into the editor dialog? With a table as the main view there is no selected subject on the page, so a header button would be permanently disabled (the profile needs a subject). Dialog placement matches Offering History, Equivalency and the Prerequisite details dialog (3 of the 5 modules with a control); header placement matches Assignment and today's Grade Weighting (2). The addendum says to keep today's header behaviour unless the other modules show a clearly different standard: **they do not show one standard** (2 header, 3 dialog, 5 floating only). Recommendation: dialog, because the subject only exists there; your call.
- **D2:** show the Dashboard's attention rule ("incomplete grade weighting") unchanged.

## 12. Extensibility: a future reference layer without code changes to validation

Goal: a labelled DO 015 template now, and a BCP policy later, as **data**, with source labels: issuance, section, **status** (`reference` / `adopted` / `pending confirmation`), applicability (level or subject group), components. Validation is unchanged because a reference never writes anything by itself: the "Use reference" action copies its numbers into the dialog's draft and the user saves through `configuration_save` (exact 100.00, same rules). Adding a BCP policy later is then a new row set with status `adopted`, not a code change.

**Can the current schema hold it? No.** `grade_weighting_schemes` has only `description` (free text) and `curriculum_version_subject_id`; there is no template, source or status field (`010...sql:9-26`). The closest existing structure is `compliance_references` (reference code, title, issuing body, source kind, effective date, verification status, URL; columns checked in `sms_db_uitest`) and `curriculum_version_references` (version-to-reference links with snapshots). Proposal for **migration 032** (both tables are new, so no legacy-baseline entry is needed, unless the optional column below is added):
- `grade_weighting_reference_templates` (id, `compliance_reference_id` NULL, issuance label, section label, applicability, title, `status` in reference / adopted / pending_confirmation, `created_by`, timestamps) and `grade_weighting_reference_template_components` (template id, component name, weight DECIMAL(5,2), sequence), with the same `CHECK` and uniqueness rules as the live table, plus a permission `grade_weighting.reference.manage`.
- Optional: `grade_weighting_schemes.source_template_id` (provenance; `grade_weighting_schemes` is a baseline-covered table, so this column needs a declared entry in `database/legacy_baseline.php`). Without it, provenance can still be recorded in the audit metadata.
- Templates are never defaults, never applied automatically, never counted as "configured", and the UI labels each with its source and status. **No template values are entered by us; you supply them** (values, issuance, section).

**What we need from the client** (each: needs client input / may be NDA-restricted)
1. BCP's grading and weighting policy for college subjects (component names, weights, any per-subject-type differences) and its approval status.
2. Whether BCP's Senior High program follows DO 015 Table 10 as published, adapts it with regional-office approval, or uses its own weights; which school year applies.
3. Whether weights are per subject, per subject type (lecture / laboratory / NSTP / PE) or per program, so the right scope is stored (today: per subject in a version).
4. Who may change weights and who must approve a change (today `manage` is held by staff-type roles; Acad Office is view-only).
5. Whether a historical version's weights must stay frozen once approved (today they are, by the edit policy).
6. Whether Export should print weights by default (today an option, off unless chosen).
7. Whether the existing 30 / 30 / 40 rows are test data to be removed.

## 13. Not verified here
The page was read, not opened in a browser; role behaviour is from the permission table and code, not exercised; `component_list` scope gap and the `academic_curriculum_staff` conflict are code-reading findings; the AI provider was not called; source documents were not available.
