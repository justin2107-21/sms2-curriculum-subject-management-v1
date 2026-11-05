# CSMS-SMS2 Dashboard Redesign — Plan (V2 section awaiting approval)

Date: 2026-11-03 · Branch `ui-redesign-pilot` · Status: **awaiting approval**. Nothing in the application was changed for this plan.
Inputs: `UI_Decisions.md` (D1–D8), `UI_Audit.md`, `design-system/csms-sms2/MASTER.md`, `css/csms-tokens.css`, `ui-ux-pro-max` (`data/charts.csv`, `references/quick-reference.md`, read directly because Python is not installed).
Method: code and migration reading only. **No connection to `sms_db` and no queries were run.** Where real values matter (e.g. the label bug), a read-only diagnostic query is supplied for you to run yourself.

---

## V2: role dashboards and chart corrections (plan, awaiting approval, 2026-11-05)
This section replaces the card lists, chart plan, role variants and representative-version rule in sections 4 and 8 below. Everything below it is kept as history. Data facts come from read-only queries on `sms_db`, whose test copy `sms_db_uitest` holds the same curriculum data.

### V2.1 What the data shows today
| Program | Curriculum | Version | Status / review | Subjects | Units |
|---|---|---|---|---|---:|
| BSIT | 123 | 1 | active / not started | 1 | 3 |
| BSIT | 123 | 2 | draft / not started | 1 | 2 |
| BSIT | BSIT-DEMO-2026 | PREORAL-2026 | draft / not started | 52 | 157 |
| BSIT | BSIT-DEMO-2026 | FINAL | draft / not started | 0 | 0 |
| BSCRIM | CRIMINALISM | ROTC | active / not started | 2 | 8 |
| ICT | COMP11 | COMSHOP | active / not started | 0 | 0 |
| BSBA, BSHM, ASD (inactive) | none | | | | |

Also: 0 review events, 0 compliance rules and runs, 0 outcome catalog rows, 8 prerequisite rules, audit history from 2026-08-19.

Consequences:
1. **BSIT has two curricula, so "one version per curriculum" gives BSIT two counted versions.** The old chart added them under one label. V2 shows one row per curriculum and never adds them.
2. **The rule as written counts FINAL (0 subjects), not PREORAL-2026 (52 subjects),** because FINAL is the newer draft. See decision 1.
3. **PREORAL-2026 is 157 units,** not about 158.
4. **146 is not in the repository.** `docs/source-data/ched-cmo25-2015-bsit.json` holds a partial 81 units and says `known_units_are_not_a_program_total`. It must be confirmed against the CMO before it is stored.
5. **Three versions are active but never reviewed.** The five pipeline buckets have no place for them; calling them Draft would be false. See decision 2.
6. **`curriculum_version_personnel` is empty and nothing fills it,** so today's "Awaiting my review" query always returns nothing, even with versions under review.
7. **Faculty charts have no data on the real site:** with no outcome catalog, Outcome coverage and "Outcomes covered" stay unavailable until `database/seeds/load_ched_bsit_outcomes.php` is run on `sms_db`.

### V2.2 Counted-version rule
Per curriculum, ignoring archived versions and anything outside the user's read scope:
1. Approved (`review_status='approved'`), latest `updated_at`.
2. Otherwise active (`status='active'`), latest `updated_at`.
3. Otherwise draft: those with at least one placement first (proposed, decision 1), then latest `updated_at`.

Ties go to the higher id. It replaces `DashboardShaper::representativeVersions()` and `VERSION_RULE_NOTE`. Every chart that uses it shows the version in its label or tooltip, plus the note: "Each curriculum counts one version: approved, otherwise active, otherwise the latest draft with subjects."

Result today: curriculum 123 counts version 1 (active, 3 units), and BSIT-DEMO-2026 counts PREORAL-2026 (157 units) until FINAL gets subjects or a version is approved.

### V2.3 Required-units target
There is no dedicated target field, but none needs adding. `compliance_rules` already has `rule_type='MIN_TOTAL_UNITS'` with `numeric_value`, scoped by program or curriculum, tied to a reference document, and used by the validator.

**Proposal: no migration.** The target is the active `MIN_TOTAL_UNITS` rule for that curriculum, otherwise for its program. A Super Admin creates it on the Compliance Rules page once the figure is confirmed. No active rule shows "No target set". If you prefer a field that doesn't affect validation, the alternative is a new `curriculum_unit_targets` table (migration 028).

### V2.4 Replacement charts
All queries are read-only and prepared, and use the existing snapshot transaction. `P` means the Program filter applies.

| Key | Type | Source and query | Empty or unavailable |
|---|---|---|---|
| `completeness` | Horizontal bar with target marker | Counted versions (V2.2) × `curriculum_version_subjects` × `subjects.units` (reuses `placementTotals`); target from `compliance_rules`; one row per curriculum, label "BSIT · BSIT-DEMO-2026 · PREORAL-2026". P | Row "No target set"; programs with no curricula listed under the chart as "No curriculum yet" |
| `pipeline` | Stacked horizontal bar | All versions per program, each in one bucket, checked in order: Archived (`status`), Approved, In review, Returned, Active (not reviewed, decision 2), Draft. P | Program with no versions gets "No curriculum yet" |
| `prerequisite_health` | Overall: table-style bars. Single program: histogram | Counted versions; `curriculum_subject_requirements` and placements through `PrerequisiteGraph::review()` for later-term and circular conflicts; % = Year 2–4 placements with at least one prerequisite, by `AcademicSequenceValidator::position()`. P | SHS versions use "Grade 12" for the later years if `position()` allows, otherwise "Not applicable"; no rules gives a compact empty state |
| `audit_weekly` | Bars | `audit_logs` per week, 12 weeks | Compact empty state |
| `review_outcomes` | Stacked bars | `curriculum_version_review_events` per month, or per week when the first event is under 3 months old; submit, approve, return. P | "No reviews yet" |
| `accounts_by_role` | Bars | `users` (active) × `user_roles` × active `roles` | Never empty |
| `programs_by_level` | Bars | `academic_programs`, College or SHS using the existing `program_type` test | Compact empty state |
| `term_load` | Grouped bars (existing) | Staff: working-context version. Faculty: counted version in read scope | "Choose a version" |
| `outcome_coverage` | Radar, per your table (BSIT has 13 axes) | Existing query | Unavailable until the catalog is loaded |
| `compliance_under_review` | Stacked bars | Latest run for `review_status='under_review'` versions | "No validation results for versions under review" |
| `units_by_category` | Pending card | None | Unavailable; respects `dashboard.show_pending_charts` |

Empty cards use one line and a link, at compact height. The "Active curricula" list becomes labelled rows: "Curriculum 123 · Version 1 · Effective 2100", or "Effective date not set".

### V2.5 Role and server rule
The server picks one profile from permissions, first match wins:
`rbac.manage` Super Admin → `user.manage` Admin → `curriculum.review.decide` Acad Office → `curriculum.review.submit` Staff → `curriculum.view` Acad Faculty → none (a short "No dashboard items" message).

Only that profile's queries run, and only its cards go in the JSON. Every card also keeps its own permission check, so a query can't run for a role without the permission. No permission changes. The filter list still needs `program.view`. Account and audit cards aren't program data, so they ignore the filter and say "All programs".

"Awaiting my review" means all versions `under_review` that this user did not submit, not `curriculum_version_personnel`. "Returned to me" and "my versions" use the last `submit` event's actor, otherwise `created_by`.

### V2.6 Cards per role
**Super Admin**
| Card | Source | Permission | Empty |
|---|---|---|---|
| Active accounts | `users.is_active` | `user.manage` | Never |
| Need password change | `users.must_change_password`, active | `user.manage` | 0 |
| Audit events, 7 days | `audit_logs` | `audit.view` | 0 |
| Awaiting review | `review_status='under_review'` | `curriculum.view` | 0 |
| Accounts by role, Audit events per week, Pipeline | V2.4 | `user.manage`, `audit.view`, `curriculum.view` | V2.4 |
| Recent role/permission changes | `audit_logs` `RBAC_ROLE_ASSIGNMENT`, `RBAC_PERMISSION_CHANGE` | `rbac.manage` | "No recent changes" |
| Accounts needing password change | `users` | `user.manage` | "None" |

```
┌ Dashboard ─────────────────────────── [Program: All ▾] ┐
│ Active accts │ Need password │ Audit 7d │ Awaiting rev. │
│ ┌ Accounts by role ──┐ ┌ Audit events per week ───────┐ │
│ ┌ Curriculum pipeline by program ─────────────────────┐ │
│ ┌ Role/permission changes ┐ ┌ Password change needed ┐ │
```

**Admin**
| Card | Source | Permission | Empty |
|---|---|---|---|
| Active programs | `academic_programs` | `program.view` | 0 |
| Curricula | `curricula` | `curriculum.view` | 0 |
| Approved versions | `review_status='approved'` | `curriculum.view` | 0 |
| Awaiting review (count only) | `under_review` | `curriculum.view` | 0 |
| Pipeline, Completeness, Programs by level | V2.4 | `curriculum.view`, `program.view` | V2.4 |
| Recently approved | `approve` events | `curriculum.view` | "None yet" |
| Official export log | `CURRICULUM_EXPORT_GENERATE`, approved versions only | `audit.view` and `curriculum_export.official` | "No official exports yet" (see V2.8) |
| Programs with no curriculum | `academic_programs` | `program.view` | "All have one" |

```
┌ Dashboard ─────────────────────────── [Program: All ▾] ┐
│ Programs │ Curricula │ Approved │ Awaiting review       │
│ ┌ Pipeline ──────────┐ ┌ Completeness ────────────────┐ │
│ ┌ Programs by level ─┐ ┌ Recently approved ───────────┐ │
│ ┌ Official exports ──┐ ┌ Programs with no curriculum ─┐ │
```

**Staff**
| Card | Source | Permission | Empty |
|---|---|---|---|
| My drafts | `created_by`, draft | `curriculum.update` | 0 |
| Returned to me | `returned`, last submitter | `curriculum.review.submit` | 0 |
| Awaiting decision | `under_review`, last submitter | `curriculum.review.submit` | 0 |
| Failing checks | Latest runs of my versions, `failed_count>0` | `compliance.view` | 0 |
| Term load, Prerequisite health, Units by category | V2.4 | `curriculum.view`, `prerequisite.view` | "Choose a version"; pending |
| Returned for revision, with reason | Latest `return` event | `curriculum.review.submit` | "Nothing returned" |
| My drafts, next step | Existing | `curriculum.update` | "No drafts" |
| Validator failures | Existing | `compliance.view` | "No failures" |

```
┌ Dashboard ─────────────────────────── [Program: All ▾] ┐
│ My drafts │ Returned to me │ Awaiting decision │ Failing │
│ ┌ Returned for revision: version · reason · Open ─────┐ │
│ ┌ Term load (working version) ┐ ┌ Prerequisite health ┐ │
│ ┌ Units by category (pending) ┐ ┌ My drafts ──────────┐ │
│ ┌ Validator failures ─────────────────────────────────┐ │
```

**Acad Office**
| Card | Source | Permission | Empty |
|---|---|---|---|
| Awaiting my review | V2.5 | `curriculum.review.decide` | 0 |
| Average days in review | Each `approve` or `return` minus its preceding `submit`, last 90 days | `curriculum.review.decide` | "No decisions yet" |
| Approved this month | Events | `curriculum.review.decide` | 0 |
| Returned this month | Events | `curriculum.review.decide` | 0 |
| Review outcomes, Pipeline, Compliance under review | V2.4 | `curriculum.view`, `compliance.view` | V2.4 |
| Awaiting my review, submitter and date | Events × `users` | `curriculum.review.decide` | "Nothing waiting" |
| Pending equivalency decisions | `decision_status='pending'` | `equivalency.manage` | "None pending" |
| My recent decisions | Events, this actor | `curriculum.review.decide` | "None yet" |

```
┌ Dashboard ─────────────────────────── [Program: All ▾] ┐
│ Awaiting me │ Avg days │ Approved mo. │ Returned mo.    │
│ ┌ Awaiting my review: version · submitter · date ─────┐ │
│ ┌ Review outcomes ───┐ ┌ Pipeline ────────────────────┐ │
│ ┌ Compliance under review ┐ ┌ Pending equivalencies ──┐ │
│ ┌ My recent decisions ────────────────────────────────┐ │
```

**Acad Faculty**
| Card | Source | Permission | Empty |
|---|---|---|---|
| Approved curricula | `approved` versions | `curriculum.view` | 0 |
| Outcomes covered | Outcomes with a mapped subject in the counted version | `curriculum.view` | Unavailable without a catalog |
| My subjects (pending) | None | `curriculum.view` | Unavailable; respects the flag |
| Outcome coverage, Term load | V2.4, in read scope | `curriculum.view` | V2.4 |
| Approved curricula list | Approved | `curriculum.view` | "None approved yet" |
| Recently approved | `approve` events, approved versions only | `curriculum.view` | "None yet" |

```
┌ Dashboard ─────────────────────────── [Program: All ▾] ┐
│ Approved │ Outcomes covered │ My subjects (pending)     │
│ ┌ Outcome coverage (radar) ┐ ┌ Term load ─────────────┐ │
│ ┌ Approved curricula ──────┐ ┌ Recently approved ─────┐ │
```

### V2.7 Build notes
- `DashboardAnalyticsService::build()` becomes a profile-to-cards map. `js/csms-dashboard.js` renders the payload's card order, and existing IDs stay.
- The "Curriculum activity" chart isn't on any role in the approved table. Its fix (discrete bars, no smoothing, weekly under 3 months) applies to `audit_weekly` and `review_outcomes`.
- `analytics.php` is unchanged.

### V2.8 What the data can't fully support
| Item | Limit | Plan |
|---|---|---|
| BSIT target of 146 | Not in the repository | Needs your confirmation, then a rule |
| Official export log | The audit entry stores no review state at export time, so "approved" means approved now | Label it "exports of versions now approved"; storing the state at export is a later task |
| Average days in review | No real events yet | "No decisions yet" until there are |
| Outcome coverage | No catalog on `sms_db` and no faculty link | Loading the catalog is a data step for you to approve; "My subjects" stays pending |
| Units by category | No category field | Pending card |
| Compliance cards | 0 rules and runs on `sms_db` | Compact empty state |

### V2.9 Decisions needed
1. **Prefer drafts with subjects in the counted-version rule?** Recommended: yes. Otherwise BSIT-DEMO-2026 counts the empty FINAL and shows 0 units.
2. **Add a sixth pipeline bucket, "Active (not reviewed)"?** Recommended: yes. Otherwise the three active versions must be mislabelled.
3. **Use `MIN_TOTAL_UNITS` rules for targets?** Recommended: yes, no migration.
4. **Confirm 146 and load it?** You or I would check it against the CMO in `C:\xampp\csms-reference-documents`. Until a rule exists, BSIT shows "No target set".
5. **Which charts stay removed?** Your table drops Required vs optional, Grade weighting and the I/P/D chart from every dashboard. Recommended: drop them; the data remains in their modules.

---

## 1. What the dashboard does today

### Files
| File | Role |
|---|---|
| `dashboard/dashboard.php` | Page. `require_permission('dashboard.view')`; builds stat cards and program list server-side; renders the context panel and empty containers for JS. |
| `dashboard/CurriculumDashboardService.php` / `CurriculumDashboardRepository.php` | Global counts (8 `COUNT(*)`), program list with curriculum counts, version status counts (computed but **not displayed**), recent audit activity (8 rows). |
| `dashboard/analytics.php` | JSON endpoint, GET only, `dashboard.view` + `curriculum.view`, read-only snapshot transaction. Takes one `version_id` (+ optional program/curriculum ids that must match). Returns placements by year and term, units, required/optional, requirement counts, elective group count, grade-weighting status. |
| `js/curriculum-dashboard.js` | Three-level cascading autocomplete (program → curriculum → version) using `curriculum-common.js`; draws the version charts as HTML/CSS bars. |
| `css/curriculum-dashboard.css` | Styles. |
| Chart library | **None.** All "charts" are `<div>` bars and one CSS conic-gradient donut. |

### Existing cards and charts (all kept in the redesign)
| # | Existing item | Data | Where it goes |
|---|---|---|---|
| E1 | Stat cards: Academic Programs, Curricula, Curriculum Versions, Subject Placements, Prerequisite/Corequisite, Elective Groups, Equivalency Records (7, permission-filtered; Subjects excluded) | `summaryCounts()` | 4 scorecards + the rest folded into charts/links (finding 9) |
| E2 | Academic Programs / Strands list (all records, clickable into the context) | `visiblePrograms()` | Becomes the Program filter + compact top-5 card (finding 7) |
| E3 | Selected Curriculum Context (3 autocompletes) | program/curriculum/version APIs | Replaced by header Program filter + optional Version filter (finding 8). Same APIs. |
| E4 | Current Curriculum snapshot chips (mapped subjects, units, year levels) | `analytics.php` | Removed as duplicates (finding 6); numbers shown once in the stat row |
| E5 | Metric cards: Mapped Subjects, Total Units, Year Levels, Prerequisite Relationships, Elective Groups, Grade Weighting Status | `analytics.php` | Program-view stat row + chart cards |
| E6 | Subjects by Year Level (bars) | `analytics.php` `years[].subjects` | Chart 3 (grouped bar, normalized labels) |
| E7 | Units by Year Level (bars) | `years[].units` | Chart 3 (second series / toggle) |
| E8 | Subjects by Semester (bars) | `terms` | Chart 3 (by year × semester) |
| E9 | Placement Distribution donut (Required vs Optional) | `required`, `optional` | Kept as donut in program view (Chart 8a) |
| E10 | Prerequisite Relationships (with/without bars) | `subjects_with_prerequisites` | Merged into Chart 4 (finding 5) |
| E11 | Recent Activity (8 rows, `audit.view` only) | `recentCurriculumActivity()` | Human wording, 5 rows (finding 10) |
| — | `version_statuses` (draft/active/archived) | `versionStatusCounts()` | Already computed, never shown → Chart 1 |

### Permissions the dashboard uses
`dashboard.view` (page), `curriculum.view` (context + analytics), `program.view`, `prerequisite.view`, `elective.view`, `grade_weighting.view`, `audit.view`, plus per-card permissions in the stat definitions. The redesign uses **only these existing permissions** (+ `compliance.view`, `subject.view`, `curriculum.version.create`, `rbac.manage`, which already exist). No new permission is added or granted.

---

## 2. Data available for analytics (from migrations 001–025)

| Area | Tables / columns | Usable for |
|---|---|---|
| Programs / strands | `academic_programs(program_code, program_name, program_type, status)`; SHS detected by `program_type` LIKE "senior high" (existing logic in `AcademicProgramRepository`) | Filter list, "College vs SHS" split |
| Curricula | `curricula(academic_program_id, status, created_at)` | Counts per program |
| Versions | `curriculum_versions(curriculum_id, version_label, effective_academic_year, status ∈ draft/active/archived, version_role ∈ unclassified/reference/proposed, review_status ∈ not_started/draft/under_review/returned/approved, baseline_version_id, created_by, created_at)` | Lifecycle chart, activity over time, "my drafts" |
| Placements | `curriculum_version_subjects(curriculum_version_id, subject_id, year_level VARCHAR, semester VARCHAR, sequence_order, is_required, created_at)` | Load by year/term, required/optional |
| Subjects | `subjects(subject_code, subject_title, units, lecture_units, laboratory_units, status)` | Units distribution, lecture vs lab |
| Requirements | `curriculum_subject_requirements(curriculum_version_id, subject_id, required_subject_id, requirement_type)` | Prerequisite count per subject |
| Electives | `curriculum_elective_groups(curriculum_version_id, year_level, semester, required_selections, status)`, `curriculum_elective_group_subjects` | Elective group counts |
| Equivalencies | `subject_equivalencies(subject_id, equivalent_subject_id, archived_at)` | Count only |
| Grade weighting | `grade_weighting_schemes(curriculum_version_subject_id)`, `grade_weighting_components(scheme_id, weight_percentage)` | Configured / complete per version (existing logic) |
| Compliance | `compliance_validation_runs(curriculum_version_id, rules_evaluated, passed_count, failed_count, not_evaluated_count, created_at)`, `compliance_validation_results(result_status ∈ PASS/FAIL/NOT_EVALUATED)` | Latest run results |
| Outcomes | `curriculum_outcome_catalog(outcome_code, outcome_text, source_key)`, `curriculum_version_subject_outcomes(curriculum_version_subject_id, outcome_id, mapping_origin)`, `curriculum_version_outcome_coverage(curriculum_version_id, outcome_id, assessment_state ∈ incomplete/complete)` | Subjects mapped per outcome, coverage state |
| CLOs | `curriculum_version_course_learning_outcomes`, `…_clo_outcome_alignments`, `…_clo_assessment_plans` (record_state, review_state) | Optional later |
| Review workflow | `curriculum_version_review_events(action ∈ submit/return/approve/comment)`, `curriculum_version_personnel(responsibility ∈ preparer/reviewer/approver)` | **See gaps** |
| Users / roles | `users`, `roles` (`academic_curriculum_staff`, `department_head_curriculum_reviewer`, `faculty_academic_viewer`, admin), `role_permissions` | Role variants (by permission, not role name) |
| Audit | `audit_logs(user_id, action, entity_type, entity_id, description, created_at)` | Recent activity, activity over time |

### Gaps that change the proposed charts (important)
1. **No subject category.** `subjects` has no GE / core / professional / elective / PE / NSTP field anywhere in the schema. Chart 2 ("units by subject category") **cannot be built from real data.** Proposal below replaces it; adding a category column would be a schema change (out of scope; needs your decision and a migration).
2. **No I/P/D level on outcome mappings.** `curriculum_version_subject_outcomes` stores *that* a placement maps to an outcome, not *how* (Introduced/Practised/Demonstrated). Chart 5 as a weighted radar **cannot be built.** Proposal: count of mapped subjects per outcome + recorded coverage state.
3. **Review workflow is not implemented in code.** `review_status` exists, but no application code moves it beyond `not_started` (clones are created `not_started`); no code writes `curriculum_version_review_events` or `curriculum_version_personnel`. So "Draft / In review / Approved", "Awaiting my review" and "submitted/approved per month" would always show zero. They are real zeros, but a chart of them would mislead. Proposal: use the technical `status` (draft/active/archived) that **is** in use, show `review_status` only if any version is past `not_started`, and hide review-queue lists until the workflow exists.
4. **No faculty–subject assignment.** Nothing links a faculty user to subjects, so "Approved curricula for my subjects" **cannot be built.** Proposal: "Active curricula" list for read-only users.
5. **No "approved" state in use** — the stat "Curriculum versions (Approved count)" becomes "Curriculum versions (Active count)" using `status='active'`.

---

## 3. Label bug (finding 4) — cause and fix

**Cause (from code):** `curriculum_version_subjects.year_level` and `.semester` are free-text `VARCHAR(50)`; `DomainValidator` accepts any string ≤ 50 characters (`DomainValidator.php:215-248`). The Curriculum Builder form, the BSIT seeds and the preview fixtures write `1st Year` / `1st Semester`, but the Subject Mapping editor also offers **any value already stored** (`subject-mapping-final.js:112,133,565`), and API callers can send short forms. `analytics.php:23` then groups by the raw string, so `1st` and `1st Semester` become two categories.

**Stored-data inconsistency (to confirm):** at least one stored placement uses a short form (`1st`) — that is what your screenshot shows. I did not query the database. To see exactly which rows, run this **read-only** query yourself (phpMyAdmin or CLI):
```sql
SELECT year_level, semester, COUNT(*) AS placements, COUNT(DISTINCT curriculum_version_id) AS versions
FROM curriculum_version_subjects
GROUP BY year_level, semester
ORDER BY year_level, semester;
```

**Fix (display/query layer only, stored data unchanged):** reuse the existing parser `AcademicSequenceValidator::position()` (`modules/curriculum/services/AcademicSequenceValidator.php:6-20`, already covered by `tests/academic-sequence.php`), which already reads `1st`, `1st Year`, `Year 1`, `1`, `Grade 11`, `1st Semester`, `Semester 1`. The new data layer groups by the parsed position and labels it canonically (`1st Year`, `1st Semester`, `Grade 11`). Values it cannot parse keep their raw text and are listed under "Unrecognised" so nothing is hidden. Tooltip shows the raw stored values that were merged. A DB-free unit test covers the mapping.

---

## 4. Chart plan (validated against the data)

**Common rules for every chart:** real data only, through new read-only repository methods (prepared statements), scoped by the Program filter (and Version filter where noted), gated by the listed permission; title, one-line description, axis labels, legend, tooltip, direct value labels; colors from `csms-tokens.css` and never color alone (labels/patterns); "Show data table" toggle (screen-reader text summary always present); loading, empty and error states; "View in module" link carrying the same filter.

**"Representative version" rule** (used when the overall view needs one version per curriculum): the most recently updated version with `status='active'`; if none, the most recently updated non-archived version; archived-only curricula are excluded. Shown in each card's description so users know which version is counted.

| # | Title | Type | Question | Source (tables / method) | Overall view | Program view | Permission | Empty state |
|---|---|---|---|---|---|---|---|---|
| 1 | **Curriculum versions by status** | Donut + legend with counts | How many versions are draft, active, archived? | `curriculum_versions.status` joined to `curricula.academic_program_id` (extends existing `versionStatusCounts()` with program filter). `review_status` breakdown added only when any value ≠ `not_started` | All programs | That program | `curriculum.view` | "No curriculum versions yet" + link to Curricula |
| 2 | **Units and subjects per program** *(replaces "units by category")* | Horizontal stacked bar (Required / Optional units) | How large is each program's curriculum, and how much is optional? | `curriculum_version_subjects` × `subjects.units`, `is_required`, representative version per curriculum | One bar per program | One bar per version of that program | `curriculum.view` + `program.view` | "No placements yet" + link to Subject Mapping |
| 3 | **Curriculum load by year and semester** *(keeps E6, E7, E8)* | Grouped bar: x = year level, bars = semesters; toggle Units / Subjects | Is the load balanced across terms? | `curriculum_version_subjects.year_level, semester` (normalized, §3) × `subjects.units` | **Hidden** — "Select a program to see term load" (averages across programs would hide differences) | Selected version (Version filter; defaults to representative version) | `curriculum.view` | "This version has no placements" |
| 4 | **Prerequisites per subject** *(keeps E10)* | Histogram: bins 0 / 1 / 2 / 3+ prerequisites; plus top-5 list of subjects with most prerequisites | Which subjects carry many prerequisites? How many have none? | `curriculum_subject_requirements` (`requirement_type='prerequisite'`) grouped by `subject_id` per version | Representative versions of all programs | Selected version | `prerequisite.view` | "No prerequisite rules stored" + link to Pre-requisite Configuration (compact, finding 5) |
| 5 | **Graduate-outcome coverage** *(replaces I/P/D radar)* | Horizontal bar: subjects mapped per outcome; badge per outcome for recorded coverage `complete`/`incomplete`/not assessed | Which outcomes are well or weakly covered by mapped subjects? | `curriculum_version_subject_outcomes` → `curriculum_outcome_catalog`; `curriculum_version_outcome_coverage.assessment_state` | **Not shown** — "Select a program" (outcomes differ by program) | Selected version | `curriculum.view` | "No outcome mappings for this version" + link |
| 6 | **Curriculum activity over time** | Line (monthly, last 12 months): versions created, placements added, compliance runs | Is curriculum work progressing? | `curriculum_versions.created_at`, `curriculum_version_subjects.created_at`, `compliance_validation_runs.created_at` (all filterable by program). **Not** submitted/approved (gap 3) | All programs | That program | `curriculum.view` (compliance series only with `compliance.view`) | "No activity in the last 12 months" |
| 7 | **Compliance results (latest run)** | Stacked horizontal bar: Pass / Fail / Not evaluated | Which curricula fail rules? | Latest `compliance_validation_runs` per version: `passed_count`, `failed_count`, `not_evaluated_count` | One bar per program (representative version); "Not yet validated" label for versions with no run | Each version of that program | `compliance.view` | "No validation runs yet" + link to Validate Curriculum |
| 8a | **Required vs optional placements** *(keeps E9)* | Donut | How much of the version is optional? | existing `analytics.php` fields | Hidden | Selected version | `curriculum.view` | as today |
| 8b | **Grade weighting status** *(keeps E5)* | Scorecard with progress ("12 of 52 complete") | Are weighting schemes complete? | existing `analytics.php` grade-weighting query | Hidden | Selected version | `grade_weighting.view` | "Not configured" in neutral style (finding 11) |
| 8c | **Elective groups** *(keeps E5)* | Count in stat row / link | — | existing count | Overall count | Version count | `elective.view` | 0 with link |

Dropped / not built: weighted I/P/D radar (gap 2), category stacked bar (gap 1), "submitted/approved per month" (gap 3). Radar is also discouraged by the skill's chart data for > 8 axes (BSIT has 13 outcomes); a sorted bar is easier to read and compare.

### Stat row (max 4, finding 9)
| View | Scorecards (each from the data layer, each linking to its module) |
|---|---|
| Overall | **Programs / strands** (`program.view`) · **Curriculum versions** with "N active" (`curriculum.view`) · **Subjects** (`subject.view`) · **Needs attention**: versions whose latest compliance run has failures + active-program curricula with no active version (`compliance.view` / `curriculum.view`) |
| Program selected | **Curricula / versions** · **Placed subjects** (representative/selected version) · **Total units** · **Needs attention** (same rule, scoped) |
Subject placements, prerequisite rules, elective groups and equivalencies move out of the stat row into their charts / the "Shortcuts" card, so each number appears once (finding 6).

### Lists (finding 12) — by existing permission, never by role name
| List | Who sees it (permission test) | Source | Notes |
|---|---|---|---|
| **My draft versions** — label, curriculum, last updated, next step ("Add placements" if 0, "Run compliance" if never validated, else "Open") | `curriculum.version.create` | `curriculum_versions.created_by = me AND status='draft'` | Real; next step derived from real counts |
| **Compliance failures** — latest run with `failed_count>0`, 5 rows | `compliance.view` | `compliance_validation_runs` | Actionable for reviewers |
| **Awaiting my review** | — | needs `curriculum_version_personnel` + workflow | **Not built** until the review workflow exists (gap 3). Shown to nobody. |
| **Active curricula** (top 5 + View all) — replaces the full program list (finding 7) | `curriculum.view` | `status='active'` versions | For read-only users this is the main list (gap 4) |
| **Recent activity** — 5 rows, human wording, link to record, "View audit logs" | `audit.view` | `audit_logs` (existing method, limit 5) | "Created curriculum version PREORAL-2026" built from `action` + `description`/`entity_type` map; internal `curriculum_version #47` removed (finding 10) |

### Role variants (resulting from the permission tests above, with today's role grants)
- **Admin** (`rbac.manage`, `audit.view`, everything): stat row, all charts, Compliance failures, Recent activity.
- **Academic / Curriculum Staff** (`curriculum.version.create`, …): stat row, charts, **My draft versions** first, Compliance failures if granted.
- **Department Head / Reviewer** (view + `compliance.view`): stat row, charts, **Compliance failures** first, Active curricula.
- **Faculty / Academic Viewer** (view only): stat row (what they may see), charts, **Active curricula**.
Each card disappears when its permission is missing.

---

## 5. Filter and layout (findings 1, 8, 9, 11)

- Header: title "Dashboard", one-line subtitle, right side **Program / Strand** select: "All programs" (default) + each program (code — name), grouped College / SHS when `program_type` shows SHS. Choice persisted in the URL (`?program=ID`) and `sessionStorage` (same pattern as the existing curriculum context script). This is the documented dashboard exception to D1.
- When a program is selected: optional **Version** select (defaults to the representative version) used only by charts 3, 4, 5, 8a, 8b.
- Changing the filter refetches one JSON endpoint (`dashboard/analytics.php`, extended with `?scope=overall|program` — the existing `version_id` contract stays backward compatible) inside the same read-only snapshot transaction.
- Headings use the standard card header (sentence case, `--csms-text-lg`, navy), no all-caps, no two blues, no "→" text arrows (Font Awesome solid `fa-arrow-up-right-from-square` with `aria-label`). "Not Configured" becomes a neutral badge, not a large coloured value.
- Card titles: **sans-serif.** Your brief says "serif title", but D5 ("one sans font, no serif headings") is an approved decision; I kept D5. Tell me if you want to override it.
- Grid: 2 columns of chart cards ≥ 1024px, 1 column below; stat row 4 → 2 → 1.
- D8: `.content` gets bottom padding for the CIMF launcher; list rows keep a right gutter. D7: sidebar "Academic Strand/Course Assignment" wrap fixed in the same step (UI-polish batch 6 item, needed here for finding 13).

### Wireframes
**Overall (All programs) — Admin**
```
┌ Dashboard ─────────────────────────────────────── [Program / Strand: All programs ▾] ┐
│ Monitor curricula across all programs and strands.                                    │
├───────────────┬────────────────┬────────────────┬─────────────────────────────────────┤
│ Programs  12  │ Versions 31    │ Subjects 418   │ Needs attention 3                   │
│               │ 9 active       │                │ 2 failing compliance · 1 no active  │
├───────────────┴────────────────┴────────────────┴─────────────────────────────────────┤
│ ┌ 1 Versions by status ──────────┐ ┌ 2 Units & subjects per program ────────────────┐ │
│ │   (donut) draft 18             │ │ BSIT ████████████░░  158 u                     │ │
│ │           active 9 archived 4  │ │ BSCS ██████████░     142 u   ■ req □ opt       │ │
│ └────────────[Table] [Open ↗]────┘ └──────────────────────────[Table] [Open ↗]──────┘ │
│ ┌ 7 Compliance (latest run) ─────┐ ┌ 6 Activity, last 12 months ────────────────────┐ │
│ │ BSIT ██████▓▓░  P 21 F 3 N 2   │ │  line: versions · placements · runs            │ │
│ └────────────────────────────────┘ └────────────────────────────────────────────────┘ │
│ ┌ 4 Prerequisites per subject ───┐ ┌ Term load / Outcome coverage ──────────────────┐ │
│ │ 0 ███████ 1 ████ 2 ██ 3+ █     │ │ "Select a program to see term load and         │ │
│ │ Top: CC105 (4) …               │ │  outcome coverage."                            │ │
│ └────────────────────────────────┘ └────────────────────────────────────────────────┘ │
├───────────────────────────────────────────┬───────────────────────────────────────────┤
│ Compliance failures (5)        View all ↗  │ Recent activity (5)       View audit logs ↗│
└───────────────────────────────────────────┴───────────────────────────────────────────┘
```
**Program selected — Academic / Curriculum Staff**
```
┌ Dashboard ───────────── [Program / Strand: BSIT ▾] [Version: PREORAL-2026 (active) ▾] ┐
├───────────────┬────────────────┬────────────────┬─────────────────────────────────────┤
│ Curricula 2   │ Placed 52      │ Units 158      │ Needs attention 1                   │
│ Versions 5    │ subjects       │                │ never validated                     │
├───────────────┴────────────────┴────────────────┴─────────────────────────────────────┤
│ ┌ 3 Load by year & semester  [Units|Subjects] ┐ ┌ 5 Outcome coverage ─────────────┐ │
│ │ 1st Yr ▇▇ ▇▇  2nd Yr ▇▇ ▇▇ … (1st/2nd sem) │ │ PO-a ██████ 8  ✓complete        │ │
│ └─────────────────────────────────────────────┘ └─────────────────────────────────┘ │
│ ┌ 8a Required vs optional ┐ ┌ 4 Prerequisites ┐ ┌ 8b Grade weighting 12/52 complete ┐ │
│ ┌ 1 Versions by status (BSIT) ┐ ┌ 2 Units per version ┐ ┌ 7 Compliance per version ┐  │
├────────────────────────────────────────────────────────────────────────────────────────┤
│ My draft versions: PREORAL-2027 · 0 placements · Next: Add placements  [Open ↗]        │
└────────────────────────────────────────────────────────────────────────────────────────┘
```
**Department Head / Reviewer** — same as above; lists: *Compliance failures* first, then *Active curricula*; no My drafts (no `curriculum.version.create`).
**Faculty / Academic Viewer** — stat row limited to what they may view; charts 1–6, 8a (7 only if `compliance.view`); list: *Active curricula (top 5)*; no activity, no drafts.

---

## 6. Chart library recommendation

The dashboard uses **no chart library** today (HTML/CSS bars only), which cannot provide axes, grouped/stacked bars, line, histogram or tooltips accessibly. Recommendation: **Chart.js 4.x** (current npm release 4.6.1), installed with the existing dev-only npm tooling (`npm install --save-dev chart.js`), copied by `tools/ui/copy-vendor.cjs` to a local file (e.g. `assets/ui/dist/js/chart.umd.min.js`, ~200 KB) and loaded only on the dashboard. **No CDN.** It is a plain script (no framework), canvas-based, so each chart also gets a hidden-by-default data table and a text summary for screen readers.

**Question for you:** may I install `chart.js` (dev dependency, vendored locally)? Alternative without a package: hand-built inline SVG charts (more code, fewer interactions).

---

## 7. Build steps after approval (each reported, wait for "next")
1. **Data layer:** read-only methods in `CurriculumDashboardRepository` (prepared statements, program filter, representative-version rule, label normalization via `AcademicSequenceValidator`), shaping in `CurriculumDashboardService`; extend `dashboard/analytics.php` (backward compatible). DB-free tests for shaping + normalization.
2. **Filter and layout:** header filter, URL/session persistence, stat row, card grid, role variants; existing IDs kept (`curriculumDashboard`, `dashboardError`, `dashboardEmpty`, `dashboardResults`, `analyticsGrid`, `metricGrid`, data-* attributes).
3. **Charts** (Chart.js), existing first: 3 (E6–E8), 8a, 4 (E10), 8b → then 1, 2 → then 5, 6, 7.
4. **Lists and links:** My drafts, Compliance failures, Active curricula, Recent activity rewording.
5. **Final pass:** responsive, accessibility, `php -l`, DB-free tests, browser checklist. DB-bound checks only on a disposable database after backup, never `sms_db`.

## 8. Decisions recorded (2026-11-03)
1. **Build all planned charts now**, including those without backend data. Each chart reads a service method with a fixed envelope `['status' => 'ok'|'empty'|'unavailable', 'reason' => string, 'data' => array]`. `unavailable` renders a faint chart outline with the reason and a module link; `empty` renders "No data yet for this selection." Neither renders zeros or bars. No fake, sample or hard-coded values.
2. Config flag `dashboard.show_pending_charts` (default `true`; `dashboard/dashboard_config.php`, override with env `CSMS_DASHBOARD_SHOW_PENDING_CHARTS=0`) hides every `unavailable` card when false.
3. Chart.js 4.6.1 as a dev dependency, vendored to `assets/ui/dist/js/`. No CDN.
4. Fonts: DM Sans (interface) + Newsreader (page and card titles), self-hosted with OFL licences under `assets/ui/dist/fonts/`, dashboard only for now (D5 override recorded in `UI_Decisions.md`).
5. Representative-version rule approved; shown as a one-line note under the overall charts (`DashboardAnalyticsService::VERSION_RULE_NOTE`).

## 9. Backend contract (charts and lists waiting for backend data)
All envelopes come from `dashboard/DashboardAnalyticsService.php` via `dashboard/dashboard_data.php`. Chart/list key → what it needs → the return shape once data exists → the backend task that supplies it. Until then each returns `status: 'unavailable'` with the reason shown on the card.

### 9.1 `charts.units_by_category` — Units by subject category
- **Needs:** a category per subject, e.g. `subjects.subject_category VARCHAR(30) NOT NULL DEFAULT 'unclassified'` with a CHECK over an agreed list (`general_education`, `core`, `professional`, `elective`, `pe`, `nstp`, `unclassified`) — or a lookup table `subject_categories(id, code, label)` + `subjects.subject_category_id`. Optional per-placement override `curriculum_version_subjects.category_override` if a subject can count differently per curriculum.
- **Query:** units per category per representative version (overall: grouped by program; program view: by version), from `curriculum_version_subjects` × `subjects.units` × category.
- **Return shape (`ok`):** `{ categories: [{key, label}], bars: [{label, values: {<category_key>: units}, total_units}] }`.
- **Backend task:** "Subject category" — migration adding the column (no backfill of guesses; existing rows `unclassified`), Subject Master form field, import/export support, `subject.update` permission reused. Dashboard switches from `unavailable` to querying when the column exists.

### 9.2 `charts.outcome_ipd` — Outcome levels (Introduced / Practised / Demonstrated)
- **Needs:** a level on each subject-to-outcome mapping: `curriculum_version_subject_outcomes.contribution_level ENUM('I','P','D') NULL` (NULL = not yet classified), maintained in the Outcome Mapping workspace under the existing `curriculum.outcome_mapping.manage` permission.
- **Query:** per outcome of the version's catalog source: count of mappings at I, P, D (+ unclassified).
- **Return shape (`ok`):** `{ outcomes: [{code, text, I, P, D, unclassified}] }` → radar (≤ 8 outcomes) or grouped bar (> 8; BSIT has 13).
- **Backend task:** "Outcome contribution level" — migration + UI in the mapping workspace + inheritance rule for cloned versions.
- **Related:** `charts.outcome_coverage` is already live for **BSIT** (catalog `CHED-CMO-25-2015-BSIT`); other programs return `unavailable` until their outcome catalogs are loaded (backend task: "Load graduate outcomes for <program>" via the existing catalog loader pattern in `database/seeds/load_ched_bsit_outcomes.php`).

### 9.3 `charts.review_trend` and `lists.review_queue` — Review workflow
- **Needs (tables already exist, migration 021):** application code that writes `curriculum_version_review_events (curriculum_version_id, actor_user_id, action ∈ submit/return/approve/comment, previous_status, new_status, comments, created_at)`, moves `curriculum_versions.review_status` through `draft → under_review → returned/approved`, and assigns `curriculum_version_personnel (curriculum_version_id, user_id, responsibility ∈ preparer/reviewer/approver, is_active)`. New permissions (e.g. `curriculum.review.submit`, `curriculum.review.decide`) need your approval; none is added or granted by the dashboard.
- **Detection (already coded):** the dashboard returns `unavailable` while there are no review events, no versions past `not_started` and no active personnel rows; as soon as the workflow writes any of these, both turn live with no dashboard change.
- **Return shapes (`ok`):** trend `{ months: ['YYYY-MM'×12], series: [{label: 'Submitted'|'Approved'|'Returned', values: [int×12]}] }`; queue `{ items: [{id, label, context, responsibility, updated_at}] }` (max 5, oldest first).
- **Backend task:** "Curriculum review workflow" — service + endpoints + UI for submit/return/approve and reviewer assignment, with audit entries.

### 9.4 `lists.my_subjects` — Approved curricula for my subjects (faculty)
- **Needs:** a faculty–subject link, e.g. `faculty_subject_assignments (id, user_id, subject_id, academic_year, term, status, assigned_by, assigned_at)` with a unique key on (user, subject, year, term).
- **Query:** active/approved versions whose placements include the user's assigned subjects.
- **Return shape (`ok`):** `{ items: [{subject_code, subject_title, curricula: [{program_code, curriculum_code, version_label, year_level, semester}]}] }`.
- **Backend task:** "Faculty-subject assignment" — migration, admin UI, permission (e.g. `faculty_assignment.manage`, needs approval). Shown only to users with `curriculum.view` and without `curriculum.update` / `curriculum.version.create`.

## 10. Decisions still open
None for step 1. Optional later: the backend tasks in §9.

## (Original) Decisions needed
1. Approve the substitutions for gaps 1–4 (chart 2 → units per program; chart 5 → mapped-subject counts per outcome; no review-queue list; Active curricula for faculty).
2. Install Chart.js 4.6.1 as a dev dependency, vendored locally? (yes / use SVG instead)
3. Card titles: keep sans-serif per D5, or serif as in the brief?
4. Representative-version rule (§4) acceptable?
5. Optional, later: add a subject category column (needs a migration) so the "units by category" chart becomes possible.

## V2 decisions recorded (2026-11-05)
Approved: drafts with placements count first; sixth pipeline bucket "Active (not reviewed)"; targets only from `MIN_TOTAL_UNITS` rules (none loaded, so "No target set"); Required vs optional, Grade weighting and I/P/D charts removed. Staff "Needs attention" lists validator failures and placements with incomplete grade weighting in their versions.

CMO 25 s.2015 figures for the adviser: Section 8.1, p.10, minimum total 146 units (GE 54, Common 18, Professional 48, Electives 12, Math 0, PE 8, NSTP 6); COE minimum 134. Section 23, p.48: from AY 2018-2019 GE falls from 54 to a minimum of 36 under CMO 20 s.2013; the balance of 18 "may be replaced by professional/domain courses... as long as the minimum total number of units is satisfied as articulated in Section 8.1".

## V3 finalization (2026-11-05)
Chart rules applied everywhere: donut only for part-of-whole with at most six fixed categories (centre total, legend with count and share); bars for comparison, integer ticks, a value label at the end of every bar including zero; long lists sort meaningfully and show the top 10 with "View all"; Okabe-Ito colours with white separators and counts on segments; averages without data show "—" and a reason. The program filter narrows program-scoped cards only; account and audit cards say nothing about programs. "No curriculum yet" appears once per dashboard (Admin: only in the Programs with no curriculum list).

Official exports: the export audit entry now stores `review_status`, `version_label` and the version id. The Admin card lists only exports stored as approved; older entries without the field are not listed.

### Correctness
`php tools/verify-dashboard-counts.php [database]` builds each role's dashboard read-only (session set READ ONLY) and compares every stat, chart total and list with a separate SELECT, for all programs and for one program. Result on `sms_db`: 109 checks, 0 mismatches. On `sms_db_uitest` with synthetic volume: 116 checks, 0 mismatches.

### Scale (sms_db_uitest only, synthetic rows prefixed SYN-)
60 programs, 300 curricula, 500 versions, 20,000 placements, 5,000 prerequisite rules, 2,000 review events, 50,160 audit rows. `php tools/uitest/time-dashboard.php` over HTTP, 15 runs after a warm-up, median ms: Super Admin 77 / 65 (all programs / one program), Admin 98 / 45, Staff 186 / 63, Acad Office 94 / 50, Acad Faculty 50 / 41. Slowest single request 205 ms. Every role is far under the 1 s target, so **no index migration 028 is proposed**. The synthetic rows were removed by rebuilding the test database.
