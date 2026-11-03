# CSMS-SMS2 Dashboard Redesign — Plan (Phase 1, analysis only)

Date: 2026-11-03 · Branch `ui-redesign-pilot` · Status: **awaiting approval**. Nothing in the application was changed for this plan.
Inputs: `UI_Decisions.md` (D1–D8), `UI_Audit.md`, `design-system/csms-sms2/MASTER.md`, `css/csms-tokens.css`, `ui-ux-pro-max` (`data/charts.csv`, `references/quick-reference.md`, read directly because Python is not installed).
Method: code and migration reading only. **No connection to `sms_db` and no queries were run.** Where real values matter (e.g. the label bug), a read-only diagnostic query is supplied for you to run yourself.

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
