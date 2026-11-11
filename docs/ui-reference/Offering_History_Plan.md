# Subject Offering History — Plan (Slice 0, read-only)

Date: 2026-11-11 · Branch `ui-redesign-pilot` · Status: **findings and proposal, awaiting approval.** No code, schema or data was changed. `sms_db` was not touched; `sms_db_uitest` was queried read-only (SELECT only).

## 1. What the page does today

**Name problem first.** The module is called "Subject Offering History" (sidebar, `SMS 2 SUBSYSTEMS AND ITS MODULES.pdf` item 6 of our subsystem). What it actually shows is **where a subject is placed in stored curriculum versions**, not when a class was offered. The page's own tooltip says "technical history only, not official institutional approval or offering dates".

**Files**
- Pages: `modules/curriculum/pages/subject_history_view.php` and `curriculum_history_view.php` (both set `$subjectHistoryView` and include `subject_offering_history.php`; a bare `subject_offering_history.php` redirects to one of them). Switcher: `_module_views.php` (`history` group).
- Endpoint: `modules/curriculum/http/subject_history_actions.php` (GET only). Retired legacy modal endpoint: `history_cimf_actions.php` (returns 410).
- Service / repository: `SubjectOfferingHistoryService.php`, `SubjectOfferingHistoryRepository.php`, plus `CurriculumHistoryComparison.php` and `CurriculumVersionReferenceService.php` (comparison only).
- JS/CSS: `assets/subject-history.js`, `curriculum-history-comparison.js`, `subject-history.css`.
- Test coverage exists: `tests/curriculum-comparison*.php`, `version-protection.php`, `bsit_reads.php`, `curriculum-clo-assessment.php`.

**Tables read (all read-only):** `curriculum_version_subjects` (placements), `curriculum_version_subject_snapshots` (captured code/title/units/lecture/lab/description), `curriculum_versions`, `curricula`, `academic_programs`, `subjects`; comparison also reads `curriculum_subject_requirements`, `curriculum_elective_groups`/`_group_subjects`, `grade_weighting_*`, CLO/alignment/assessment-plan tables, `curriculum_outcome_catalog`, and `audit_logs` (`CURRICULUM_REVISION_CREATE` clone events). **There is no offering table and no manual-entry table.** Nothing on this page is typed in by a user.

**Endpoints (all `subject_history.view`):** `list`, `subject_options`, `details`, `program_options`, `curriculum_options`, `version_options`, `compare_versions` (older generic compare, kept), `compare_revision` (Task 5 compare).

**Tab 1: Subject history** (`subject_history_view.php`)
| Column | Source |
|---|---|
| Subject (code + title) | Snapshot `subject_code_snapshot` / `subject_title_snapshot` **only when `snapshot_complete=1`**; otherwise current Subject Master values (Run A change) |
| Program | `academic_programs` via curriculum |
| Curriculum / Version | `curricula`, `curriculum_versions.version_label` |
| Year & Semester | `curriculum_version_subjects.year_level` / `semester` (free text, e.g. "1st Year", "1st Semester") |
| Status | `curriculum_versions.status` badge (draft / active / archived) |
Filters: subject search, program, curriculum, then version, year level, term, version status. Row click opens a details dialog: historical subject, current Subject Master (labelled present-day), snapshot captured date/method, units, lecture/lab units, description, placement type, stored dates; "Unavailable" rows hidden. Search-first: no rows until a subject is searched.

**Tab 2: Curriculum version history** (`curriculum_history_view.php`): pick Program, Curriculum, protected reference (Version A) and proposed revision (Version B); the server compares in one read-only `REPEATABLE READ` snapshot transaction. Rows are added / removed / moved / placement-type-changed / changed per subject, with details dialog, reference provenance and coverage card, and secondary differences in requirements, electives, grade weighting and CLO/assessment plans. Only a `proposed` revision against its recorded baseline `reference` is accepted.

**"Summarize / Explain History with CIMF"** button uses the global CIMF endpoint (route-bound, not the retired legacy one).

**Where each row's data comes from:** 100% curriculum placements plus versions (and snapshots where captured). Effectivity is the free-text `curriculum_versions.effective_academic_year`; it is shown in the version picker but **not as a column**. No manual entries.

**What Run A already changed** (`SESSION_HANDOFF.md`, 2026-11-09; presentation only): standard csms tabs for the two views; `csms-filter-row`/`csms-select`/one clear X; `csms-ctable` with blue Code column; banner paragraphs moved into `csms-info` tooltips; row label = code + title; row click opens the dialog; "Unavailable" fields hidden; Graduate Outcomes link moved into the dialog. **Not done:** an Offering History change log; 390 px and row-dialog flows beyond Super Admin at 1440.

**State of the test data (`sms_db_uitest`, read-only counts):** 86 placements, **0 rows in `curriculum_version_subject_snapshots`**, so every row on the Subject tab falls back to current Subject Master values and every snapshot field reads "Unavailable". 17 versions: 8 active, 9 draft, **0 archived**. `effective_academic_year` is set on 4 of 17 versions and holds test junk ("2026", "2050", "2100", "21001"). Terms are strings ("1st Semester", one stray "1st"). Only reference/clone/mapping capture writes snapshots (`CurriculumRevisionRepository`, `CurriculumVersionReferenceRepository`); ordinary Subject Mapping placements do not.

## 2. What we can derive vs. what we cannot

**Can derive from our own data (curriculum subsystem)**
1. A subject appears in a curriculum version, at which year level and term, required or optional (placements).
2. Which versions exist for a curriculum, their status, `effective_academic_year` label, created/updated timestamps (technical dates, not approval dates).
3. Changes between two versions: added, removed, moved (year/term), required/optional, sequence; changes in code, title, units, lecture/lab units, description **only where both versions have complete snapshots**; prerequisite, elective, grade-weighting and CLO differences (already built for the revision compare).
4. "First seen / last seen" per subject across versions of one curriculum, ordered by version creation date or by `effective_academic_year` once that is cleaned up.
5. Group by academic year/term **of the curriculum's own plan** (year level + term of a version), and by effectivity label if the client confirms the format.

**Cannot derive; do not show, do not invent.** Per `SMS 2 SUBSYSTEMS AND ITS MODULES.pdf` these belong to other subsystems:
- Actual class offerings in a given school year/term, open/closed sections, section counts: Enrollment Management (Auto Section Assignment, Enrollment Dashboard) and Class Scheduling (Section Assignment Tool, Schedule Cloning Tool, Time Block Customizer).
- Enrollment counts, waiting lists: Enrollment Management (Waiting List Queue, Enrollment Dashboard).
- Instructors / teaching load: Faculty Management (Subject Load Tracker, Schedule Assignment, Teaching History).
- Which cohort/learners followed which curriculum version: Registrar SIS (Academic History) and Enrollment.
- Whether a removed placement used to exist: deleted placements are hard-deleted (`CurriculumVersionSubjectRepository::delete`), so "removed from this version" can only be shown by comparing two versions, never by looking at one.
- Official approval or offering dates. The existing wording is right; keep it.

Consequence: the honest name for this view is "Subject Placement History" or "Subject Curriculum History". Renaming a module is a client decision (see section 7). A future real "offered in AY/term" view would be a read-only integration from Class Scheduling / Enrollment, not data entered here.

## 3. History safety

- **Viewing never modifies curriculum data.** The repository and service contain only SELECTs; `subject_history_actions.php` accepts GET only (`require_http_method(['GET'])`); `compare_revision` runs in `START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY` and its code comment states "no lifecycle or audit writes". Page and JS make no POST. Confirmed by grep: no INSERT/UPDATE/DELETE in the history repository or service.
- **There is no editing of history.** The page has no edit action. (Placement removal lives in Subject Mapping and is audited as `CURRICULUM_SUBJECT_REMOVE`.) Any future "change log" must be derived, not hand-edited, or it becomes a second source of truth.
- **Archived versions visible where authorized:** yes. `list` has no default status filter, so archived (and draft) versions are included; `compareRevision` states "History has always allowed archived versions/owners by explicit ID". The version-status filter and badge identify them. Test data has no archived version, so this was verified by code reading only.
- **Current vs. historical distinguishable:** partly. The dialog separates "Historical subject" from "Current Subject Master (present-day context)". The **table does not**: when a snapshot is missing the row silently shows current Subject Master values under the same Subject heading (Run A choice), with nothing marking it as "current, no captured history". Today that is every row in the test data. Also, "current" curriculum vs. historical version is only the Status badge; nothing says "active = in effect now".
- **Gap: the list/details/compare reads do not apply the SQL read scope** (`CurriculumReadScope::sql`) that Assignment, Electives and Subject Mapping use. They rely on `enforceInput()`, which only checks version IDs named in the request. A user without `curriculum.view_unapproved` who calls `list` with no version filter would see placements of unapproved versions. **Not exploitable with the current role matrix:** `subject_history.view` is held by admin, staff, acad_office (all have `curriculum.view_unapproved`) and not by acad_faculty (migration 027). It becomes real the moment faculty are granted history access, which the Faculty read-scope work already anticipates.

## 4. Pilot vs. national cohorts by school year (stated text only)

Sources read: DO 017 s.2026 (OCR of the scan; **Table 4 and the paragraph below it were also checked visually, printed pp. 8-9, PDF pp. 10-11**), DM 036 s.2026 (OCR), CMO 25 s.2015 (scan, no text layer; the project's own transcription package was used).

**DO 017, Table 4 "Phased Implementation Schedule" (para 19):**
| School year | Pilot schools | All other schools |
|---|---|---|
| 2026-2027 | Grade 11-SSHS (Full Implementation); Grade 12-SSHS (Pilot Implementation) | Grade 11-SSHS (Full Implementation); Grade 12-2016 K to 12 SHS Curriculum |
| 2027-2028 | Grades 11 and 12-SSHS (Full Implementation) | Grades 11 and 12-SSHS (Full Implementation) |

Below the table: "For SY 2026-2027, all non-pilot schools of SSHS shall continue implementing the 2016 K to 12 SHS Curriculum for Grade 12, focusing on the MELCs, while the TVL Track shall follow the curriculum under the 2016 K to 12 Specializations." Para 8 (PDF p. 4, OCR) says the same in words: Grade 11 in SY 2026-2027 and Grade 12 in SY 2027-2028; the full implementation of both grades is SY 2027-2028.

**DM 036 s.2026:** is titled "Pilot Implementation of the SSHS Curriculum for Grade 12 in SY 2026-2027". Grade 12 SSHS in SY 2026-2027 is "strictly limited to schools that implemented the Grade 11 program in SY 2025-2026; no additional schools shall be included". The official list is Annex D (OCR reads "887 SSHS pilot schools"; **re-check the number**). It builds on DM 048 s.2025 (Grade 11 pilot, SY 2025-2026). Learners who completed Grade 11 SSHS in the pilot are eligible for the Grade 12 pilot. Grade 11 core subjects and academic electives were semestral in SY 2025-2026; Grade 12 electives are three-term in SY 2026-2027.

**CMO 25 s.2015 (BSIT):** states nothing about pilot or national cohorts or school years of implementation. Our transcription records "No conclusion about current applicability, amendments, or cohort coverage is made by this dataset."

**Not stated, so not to be guessed:** whether BCP is a pilot school; how a college/institution should record cohorts; whether the SSHS rules apply to BCP's own SHS offering beyond what the orders say; any rule linking a cohort to a curriculum version in our schema. Our one SHS program in test data is a CSMS record, not evidence of pilot status.

## 5. Permission and audit coverage (server-side)

- Every `subject_history_actions.php` action calls `curriculum_require_read('subject_history.view')` (`support.php`), which does `require_permission(..., true)` then `CurriculumReadScope::enforceInput`. The page itself calls `require_permission('subject_history.view')` and redirects unauthenticated users. UI hiding is only a convenience; the server enforces it.
- Roles with the permission (migration 027, `ProvisionalRoles.php`): super_admin, admin, staff, acad_office. **acad_faculty does not have it** (so cannot open the page or call the API). Tested in `tests/curriculum-comparison-http.php` (guest and denied users).
- Mutations: none exist on this module, so no CSRF/POST path to protect. Details and compare take a placement or version ID and are version-scoped by `enforceInput` for restricted users (see the gap in section 3).
- **Audit:** reads are not audited (matches every other view page, and the compare is explicitly "no audit writes"). Writes that change what history shows are audited elsewhere with `audit_log_required` in the same transaction: `CURRICULUM_SUBJECT_REMOVE` (and add/move) in `CurriculumVersionSubjectService`, `CURRICULUM_REVISION_CREATE` for clones. There is no audit of "who exported/viewed history". Open question whether the client wants view auditing for a restricted-reading role.
- Validators reject malformed IDs and unknown filters (`DomainValidator::subjectHistoryQuery` / `subjectHistoryComparisonQuery`).

## 6. Proposed one-page design

Principle: same as Electives, Assignment and Subject Mapping. One page, the **shared context bar** (Program / Curriculum / Version, from `shared/ui/context_bar.php` and `js/curriculum-context.js`, working-context module key `history`, permission `subject_history.view`), no blank state: a fresh load restores the saved selection, else the default (last edited, then newest active version). Selection is shared with the other modules and kept in the URL.

```
Subject Offering History                       [Summarize with CIMF]  [Read-only derived history]
Program ▸ Curriculum ▸ Version   BSIT / BSIT-2025 / v2 (Active)   Change
-------------------------------------------------------------------------------------------------
[ Where used / Changes between versions ]  (two standard tabs: "Subjects" | "Changes")
 Subjects tab (selected version):
   search [ code or title ]   Year [All]   Term [All]
   Code | Title | Year & term | Required | First seen | Last seen | Versions        (csms-ctable)
   row click -> dialog: timeline of every version of this curriculum that contains the subject
```
- **Version context strip** under the bar: version status chip (Draft / Active / **Archived**), label, effectivity label, "Stored placements, not class offerings".
- **Subjects tab** lists every subject placed in the selected version (never empty if the version has placements). Row dialog = **timeline across versions of the same curriculum** (oldest to newest, current marked "Current"), each entry: version, status, year/term, required/optional, and code/title/units **as captured** with a "Captured" or "Current Subject Master, no captured history" tag. Replaces the search-first empty table. Cross-program usage stays in Assignment's "Where used" dialog; link to it instead of duplicating.
- **Changes tab** = today's revision compare, but defaulting Version A/B from the selected version (its baseline), instead of four separate pickers. Same table, dialogs and coverage card.
- **Change log (new, derived):** per curriculum, an ordered list of version-to-version differences computed from stored placements and snapshots: added / removed / moved / code, title or units changed, with the version pair and the technical dates. Computed on read; nothing stored.
- **Current vs. historical** is explicit: Active version chip "Current", Archived "Archived (read-only)", Draft "Not in effect"; any value that is not a captured snapshot is tagged.
- Shared components only: context bar, `csms-tabs`, `csms-filter-row`, `csms-ctable`, `csms-detail-list`, `csms-chip`, `csms-info` tooltip, `csms-menu`. Page-level CIMF stays a button, not a module of its own.
- Not on the page: instructors, enrollment, sections, rooms, counts of students. No Add/Edit buttons.

## 7. Slices

| Slice | Content | Schema |
|---|---|---|
| 1 | Context-bar shell: replace the picker rows with the shared bar, no blank state, version status strip, Subjects tab listing the selected version; remove search-first emptiness. Same endpoints plus a version-scoped `list`. | none |
| 2 | Cross-version timeline in the row dialog (first/last seen, per-version entries, Captured vs Current tags) and table tag for rows without a snapshot. | none |
| 3 | Changes tab on the shared bar (defaults to selected version and its baseline); keep `compare_revision` contract and its read-only snapshot. | none |
| 4 | Derived change log per curriculum (code/title/units/placement changes across versions), read-only. | none |
| 5 | Security hardening: apply `CurriculumReadScope::sql` to `list`, `details`, `filter_values`, `compare_versions` so the module is safe when faculty are granted access; tests for restricted role. | none |
| 6 (only if the client asks) | Read-only "offered in" integration from Class Scheduling/Enrollment; or rename. Not buildable until those subsystems exist. | n/a |

Tests per slice on `sms_db_uitest` only; one MySQL job at a time.

## 8. Risks

- **Misleading name** leads users to read placements as real offerings. Mitigation: tooltip stays, strip says "stored placements"; rename decision below.
- **No snapshots for ordinary placements.** Any code/title/unit history is available only for cloned or reference-captured versions (test data: none). Slice 2 and 4 will look empty on most data until snapshot capture is extended (that is a write-path change, out of scope here and would need its own plan).
- **Hard-deleted placements** cannot be reconstructed; the change log can only compare versions that exist.
- **`effective_academic_year` is free text and unvalidated** (test data has "2100", "21001"); sorting or grouping by it needs cleanup or a format rule.
- **Read-scope gap** (section 3) once faculty get access.
- **Regulatory over-reading:** DO 017 / DM 036 concern SHS; do not present pilot/national status as a stored fact. OCR'd quotes should be re-checked against the originals before any user-facing text.
- Two tabs of history reading the same tables with different access paths (`compare_versions` legacy vs `compare_revision`) can drift; retire the legacy one after slice 3.

## 9. Open questions for the client (needs client input / may be NDA-restricted)

1. **Needs client input:** Should this module be about curriculum placement history only (what we can build), or must it show real class offerings per school year/term? If the latter, which subsystem is the source and when does it exist? (Class Scheduling / Enrollment data may be NDA-restricted.)
2. **Needs client input:** OK to rename it "Subject Placement History" (or similar) in the sidebar and the subsystem list, given it cannot show offerings?
3. **Needs client input / may be NDA-restricted:** Is BCP's SHS program a pilot school under DM 036 Annex D, and should the system record that per school year? DO 017 Table 4 defines pilot vs all other schools, but our sources do not say which BCP is.
4. **Needs client input:** For SHS, should a curriculum version be tied to a cohort (pilot SSHS vs 2016 K-12) and a school year? Our schema has only `effective_academic_year` (free text).
5. **Needs client input:** What format is an academic year / term label ("2026-2027", "AY 2026-2027 / 1st Semester")? The Strengthened SHS uses three terms while the college programs use two semesters (DO 017 para 20, private schools may keep two semesters).
6. **Needs client input:** Should faculty (acad_faculty) see this history, and only approved/active versions? (Today they cannot open the page.)
7. **Needs client input:** Is view auditing wanted for history reads, or only for changes?
8. **Needs client input:** Should archived and draft versions appear for every role that can open the page, or only for staff/office?
9. **Needs client input:** Is a read-only derived change log enough, or is a manual, official "reason for change" note required (that would need a new table and an audit trail)?
