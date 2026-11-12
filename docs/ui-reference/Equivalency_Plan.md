# Subject Equivalency Tool: slice 0 (read-only audit and plan)

Status: slice 0 (this audit) written 2026-11-12. **Slice 1 built 2026-11-12, see "Slice 1: done" below; sections 1-7 describe the page *before* slice 1.** Slices 2-4 not started. Branch `ui-redesign-pilot`.
Module name is fixed: **Subject Equivalency Tool**. Evidence: code read; `sms_db_uitest` queried read-only; `sms_db` counts only.

## Slice 1: done (no schema change, no migration)

- **One page, no tabs.** `pages/subject_equivalency.php` is the page; `subject_comparison.php` and `equivalency_records.php` redirect (302) to it with their query string. The `_module_views.php` switcher entry is gone; sidebars point to `subject_equivalency.php` (nav key `subject-equivalency`). Title "Subject Equivalency Tool", one subtitle, `csms-info`: "Advisory record. A decision never changes placements or credits automatically." No Program > Curriculum > Version bar.
- **Records table is the main view:** `csms-filter-row` (search, Decision select with an "Archived" option, rows per page, "N records"), `csms-ctable` with Subject A, Subject B, Decision (`csms-chip`: Equivalent `--approved`, Not equivalent `--returned`, Needs review `--under_review`, Archived `--archived`), Reason (2-line clamp; "No reason recorded" muted), Reviewed by, Reviewed at; `csms-menu` row actions View / Edit decision / Archive, or Restore on archived rows (manage only). `csms-empty` for loading, no records (with Record decision for managers), no match and load error. No blank rows.
- **One dialog** (the same `curriculum-modal` / `modal-dialog` shell as Assignment and Offering History) for record, edit and view: two `record-autocomplete` pickers, an evidence table that always shows every row (code, title, description, total / lecture / lab units, technical status, prerequisites, placements, programs, curricula, versions; "Not recorded" when empty), a "Same" / "Differs" chip per row (no score), Decision and a **required Reason** (counter, inline error). A pair that already has a record opens that record (edit, or read-only with Restore if archived). Row click opens the dialog read-only; non-managers only ever get read-only. "Review with CIMF" is inside the dialog: the CIMF panel opens beside it (above it at 1024 px and narrower) and nothing is copied into the reason.
- **Server:** reason required on create and update (trimmed, 10-4,000 characters; `DomainValidator::EQUIVALENCY_REASON_MIN/MAX`); old rows without a reason stay readable, editing them needs one. New `restore` action (POST, CSRF, `equivalency.manage`, audit `SUBJECT_EQUIVALENCY_RESTORE`). Update audit now stores `old_decision_status`, `decision_status` and `remarks_changed` (never the reason text). Duplicate pair message says "Edit that record" or, for an archived pair, "Restore it". `list` accepts `decision_status=archived`.
- **Read scope:** `compare` (and therefore CIMF `stored_usage`) applies `CurriculumReadScope::sql('versions')` to usage and prerequisites, like Assignment / Electives. A role without `curriculum.view_unapproved` sees approved-version usage only and a note "N draft or unapproved placements are hidden for your role".
- **Removed:** the dashboard "Pending equivalency decisions" list and `pendingEquivalencies()` (the DB CHECK still allows `pending`); the old two-view CSS/JS (`equivalency-selector-card`, summary / usage cards, existing-record block, `mapping-fixed-context`, hand-built header actions, page-specific badges). `equivalency_cimf_actions.php` stays a 410 stub.
- **Test:** `tests/equivalency-http.php` (53 checks, disposable schema and server).

## 1. The page today

**Files**
- `pages/subject_comparison.php` and `pages/equivalency_records.php` are 3-line stubs that set `$subjectEquivalencyView` to `comparison` / `records` and include `pages/subject_equivalency.php` (the real page, about 110 lines of markup plus three dialogs).
- `subject_equivalency.php` opened directly (no view set) **redirects (302)** to `subject_comparison.php`, or to `equivalency_records.php` when `?view=records`, keeping the other query parameters. So three addresses, two real pages.
- `assets/subject-equivalency.js` (470 lines), `assets/subject-equivalency.css` (148 lines), `pages/_module_views.php` (the two-link switcher, rendered as `csms-segmented` tabs).
- `http/equivalency_actions.php` -> `SubjectEquivalencyService` -> `SubjectEquivalencyRepository`; `DomainValidator::subjectEquivalency*`.
- `http/equivalency_cimf_actions.php` is a retired stub (always 410).

**Tables:** `subject_equivalencies` (the only one). Reads also use `subjects`, `curriculum_version_subjects`, `curriculum_versions`, `curricula`, `academic_programs`, `program_subject_assignments`, `users`; audit goes to `audit_logs`.

**Endpoints (`equivalency_actions.php`):** `list`, `get`, `subject_search`, `compare` (GET, `equivalency.view`); `create`, `update`, `archive` (POST + CSRF, `equivalency.manage`).

**Which part is which**
- *Comparison tab* (title "Subject Comparison"): Subject A / Subject B pickers, "Compare Subjects", comparison table (Stored Field, A, B, Objective Result), two usage cards, a "similar usage is not proof" note, header buttons **Record Decision** and **Review with CIMF** (both disabled until two subjects are compared; Record Decision exists only with `equivalency.manage`).
- *Records tab* (title "Equivalency Records"): search, decision select, "N records", table (Subject A, Subject B, Decision, Reviewed by, Reviewed at, row menu), pagination, plus the details dialog, the record dialog and the archive dialog.
- The two views have **different page titles and descriptions** but one switcher; the first state of the Comparison tab is a blank table row ("No subjects selected for comparison").

**Old vs shared components**
- Shared (since Run A): `csms-segmented` tabs, `csms-filter-row`, `csms-select`, `csms-ctable` / `csms-page-table`, `csms-menu` row menu, `csms-info`, `csms-detail-list` in the details dialog, `record-autocomplete` pickers.
- Old / page-specific: `equivalency-selector-card`, `equivalency-summary-card`, `equivalency-usage-card/grid`, `equivalency-existing-record`, `curriculum-modal` + `modal-dialog` + `domain-form` (the older dialog shell, not the dialog used on Assignment / Offering History), `mapping-fixed-context`, hand-built `.equivalency-header-actions`.
- No `csms-empty` state on the Comparison tab; the empty state is a table row.

**What Run A changed (commit ee098af, 2026-11-09):** tabs became standard `csms-segmented` links; Records rows became clickable with a `csms-menu` (Edit decision, Archive); the details dialog became a `csms-detail-list`; the comparison shows Code and Title as plain values, **hides rows whose value is "Not available" for either subject** (a count note says how many), and compacted the summary and usage. Checked at 1440 px as Super Admin only.

**Why the comparison looks like 4 fields:** the API returns 7 fields (code, title, description, total units, lecture units, laboratory units, technical status). In `sms_db_uitest`, 0 of 55 subjects have a description and 2 of 55 have lecture units, so those rows are hidden by the "Not available" rule and you see code, title, units, status.

## 2. Data model (schema only)

`subject_equivalencies` (migrations 009, 018):

| Column | Notes |
|---|---|
| `subject_id`, `equivalent_subject_id` | FK to `subjects`. **`CHECK (subject_id < equivalent_subject_id)`** and `UNIQUE (subject_id, equivalent_subject_id)` |
| `decision_status` | `CHECK IN ('pending','equivalent','not_equivalent','needs_review')`, default `pending` |
| `remarks` | TEXT NULL |
| `reviewed_by`, `reviewed_at`, `created_by`, `created_at`, `updated_at` | users FKs, `ON DELETE SET NULL` |
| `archived_at`, `archived_by` | soft archive (018) |

Answers:
- **Unordered pair, not directional.** The service sorts the two ids (`canonicalPair`) and the CHECK enforces it. A and B in the records table mean "lower id" and "higher id", **not** the order the user picked them. The UI therefore cannot say "A is credited as B".
- **Many-to-one:** not expressible as one decision. Only separate pair rows (A-C and B-C), with no link between them and no "A + B together replace C".
- **One-way / direction:** not expressible. **Credit outcome (full / partial / none):** no column; the only values are equivalent / not equivalent / needs review (and a hidden `pending`). **Scope** (any curriculum vs old version to new version): no column; every decision is global between two Subject Master records.
- **Decision values in use:** `equivalent`, `not_equivalent`, `needs_review` (validator). `pending` is in the CHECK but cannot be created: `create()` hard-codes `isFinal = true`, so the list excludes `pending` and the dashboard's "Pending equivalency decisions" list (`DashboardAnalyticsRepository::pendingEquivalencies`) can never show anything. Dead path.
- **Reason required server-side? No.** `subjectEquivalencyReview` uses `optionalString` for `remarks` (max 4,000). The dialog's "Review Notes" is optional. This is why the details dialog shows empty notes: both stored records have NULL remarks.
- **Who may do what:** `equivalency.view` reads; `equivalency.manage` creates, edits (update) and archives. `manage` is held by Super Admin, Acad Office, Department Head / Curriculum Reviewer, System Administrator, legacy_admin (`sms_db_uitest`). Staff, Academic Curriculum Staff and Faculty / Academic Viewer have `view` only; Acad Faculty has neither.
- **Audit:** yes, `audit_log_required` inside the transaction for create (`SUBJECT_EQUIVALENCY_CREATE`), update (`..._UPDATE`) and archive (`..._ARCHIVE`), with both subject ids and (create / update) the decision. The audit does **not** store the old decision or the remarks. An archived record can never be restored or replaced: `UNIQUE` still holds the pair, so `create` for an archived pair fails with "already exists" and `update` refuses archived rows. There is no un-archive.

## 3. Consumers

- **Reads of `subject_equivalencies`:** only (a) the dashboards (`CurriculumDashboardRepository` total count including archived; `DashboardAnalyticsRepository` active count and the dead "pending" list) and (b) CIMF (`CIMFGlobalContextBuilder`, section 5).
- **No other module reads it:** Pre-requisite checks, Compliance Validator, Curriculum Export, Offering History, Builder and the version comparison (`CurriculumHistoryComparison` states "no equivalency inferred") do not. **An equivalency change does not change any other module's result**; it is an advisory record only. (Consistent with the 2026 audit: "no placement edits or automatic credit".)
- **Junk test data?** `sms_db_uitest`: 2 rows, both active, both NULL remarks: `DM102 Financial Management` vs `SA101 System Administration And Maintenance` = not_equivalent; `CC101 Introduction to Computing` vs `CC104 Data Structures And Algorithms` = **equivalent** (not academically plausible, so it is test data) , created 2026-10-05; 18 audit rows exist for 2 records (create / update churn). `sms_db`: 2 rows (1 archived, 1 equivalent, 1 not_equivalent), both without remarks, same pattern. Counts only were read on `sms_db`. Treat all four as demo/test data; **needs client input** before any are shown as real decisions.

## 4. What the comparison could show without new tables

| Evidence | Lives in | Cost to include |
|---|---|---|
| Description | `subjects.description` (returned today, hidden when empty; 0/55 filled in the test data) | none; show the row with "Not recorded" instead of hiding it |
| Lecture / lab units | `subjects.lecture_units`, `laboratory_units` (returned today, 2/55 filled) | none, same |
| Prerequisites / corequisites | `curriculum_subject_requirements` (per version placement: `subject_id`, `required_subject_id`, type) | small query, but it is per curriculum version, so show "in <version>" or the union across versions; label as such |
| Placement usage | already shown (placements / programs / curricula / versions, first 10 contexts) | exists; needs the read-scope fix in section 6 |
| Active program assignments | `program_subject_assignments` | exists |
| CLOs | `curriculum_version_course_learning_outcomes` (placement-scoped, user-authored) | medium: one query per subject, choose latest or all versions; wording must say "planning records" |
| Outcome mappings | `curriculum_version_subject_outcomes` (+ catalog) | medium, same |
| Grade weighting | `grade_weighting_schemes` / `grade_weighting_components` by subject | small query |
| Reported offerings | `reported_offerings` (Offering API) | not relevant to equivalency; skip |

Content comparison (CLO text) is a human judgement; show side by side, never a similarity score.

## 5. CIMF

- Advice-only, confirmed. Route: the page's CIMF button -> `global_cimf_actions.php` (`operation` analyze / context) with profile `subject_equivalency`, permission `equivalency.view`, CSRF. `CIMFGlobalContextBuilder` calls `SubjectEquivalencyService::compare()` (read-only) and passes the stored human decision as "Stored Human Decision" with the sentence "only an authorized human can decide equivalency". `CIMFPromptBuilder` forbids declaring equivalent / not equivalent; `CIMFResponseValidator` rejects responses containing those words or percentages for this profile.
- The only write on that path is the `cimf_ai_logs` usage row (user, action, token counts). **No CIMF path calls `create`, `update` or `archive`.** `equivalency_cimf_actions.php` is a 410 stub. The record dialog's remarks are typed by a human; nothing prefills them from CIMF.

## 6. Permissions and read scope (server side)

| Action | Check |
|---|---|
| list, get, subject_search, compare | `curriculum_require_read('equivalency.view')` (GET, then `CurriculumReadScope::enforceInput`) |
| create, update, archive | `curriculum_require_mutation('equivalency.manage')` (POST, CSRF, permission, enforceInput) |
| Page | `require_permission('equivalency.view')`; Record Decision / edit / archive controls only with `manage` |

**Read scope is effectively not applied.** `enforceInput` only inspects version-like request keys (`curriculum_version_id`, `version_id`, ...). Equivalency requests carry only subject ids, so nothing is checked. Consequences, for roles that have `equivalency.view` but not `curriculum.view_unapproved` (Academic Curriculum Staff, Faculty / Academic Viewer, Dept Head, System Administrator, legacy_admin):
- `compare` -> `usageContext()` lists program, curriculum, version label and **version status** of every placement including draft and unapproved versions, and the same data goes to CIMF as `stored_usage`. Subject Master records themselves are global, so `subject_search` is not a leak.
The Assignment and Electives scoping (versions limited to approved ones for restricted users) is **not** used here. Fix in slice 1.

## 7. Sources

Files available in the project: `docs/source-data/ched-cmo25-2015-bsit.json` (+ README) and the project's own notes of what was read from DO 017 / DM 036 / DM 048 / CMO 25 (`Offering_History_Plan.md`, `Strand_Assignment_Plan.md`, `Dashboard_Plan.md`). **The original DO 017, CMO 20 and CMO 25 documents are not in the repository.**

| Question | STATED in project files | NOT stated / not available |
|---|---|---|
| DO 017 Table 1 | Nothing. Only Table 4 (phased implementation) and Table 12 (strand to cluster mapping), Annex B1 and D.2 are quoted in the notes | Table 1 content unknown here |
| DO 017 Annex F.1 and F.2 | Nothing | Unknown here |
| CMO 20 | One fact: from AY 2018-2019 GE falls from 54 to a minimum of 36 units (as cited inside CMO 25 Section 23); the balance may be replaced by professional/domain courses | Nothing about equivalency or crediting |
| CMO 25 | The BSIT dataset: unit totals, required and recommended elective lists (with cross-table conflicts). `grep` for credit / transfer / equivalen in the JSON finds only elective wording | Nothing about equivalency, crediting or transfer |
| Many-to-one crediting; direction; "credited as elective"; credit decision fields; college credit transfer | **Not stated anywhere in the files above** | All five points are open. **Do not claim them.** Needs the actual DO 017 Table 1 / Annex F.1-F.2 text, and the client's registrar policy; may be NDA-restricted |

## 8. Proposed design (one page, no tabs)

**Layout (shared standards: Assignment, Offering History, Export)**
- Title "Subject Equivalency Tool" (one title, one subtitle), `csms-info` icon explaining "advisory record, no automatic credit".
- No tabs. The page is the **records table**: `csms-filter-row` (search, Decision `csms-select`, "N records"), `csms-ctable` with columns **Subject A, Subject B, Direction, Decision / credit, Reason, Reviewed by / at, status chip** (`csms-chip`: Equivalent / Not equivalent / Needs review / Archived), `csms-menu` row actions (View, Edit decision, Archive), pagination, `csms-empty` when none ("No equivalency decisions recorded yet" with a Record decision button for managers).
- **Record decision** button in the page header (managers only) opens a dialog (shared dialog shell, not `curriculum-modal`): two subject pickers (`record-autocomplete`), then a side-by-side comparison with **evidence rows** (code, title, description, total / lecture / lab units, status, prerequisites, usage counts, optionally CLOs), decision select, **required Reason** (minimum length, server-enforced), optional "Review with CIMF" button inside the dialog (advice shown beside the form, never prefilled). If the pair already has a record, the dialog opens it in edit mode with a notice.
- **Row click** opens the same dialog read-only (non-managers see only this). No separate details dialog.
- **Program > Curriculum > Version bar: does not belong.** Equivalency relates two Subject Master records and is global, not version-scoped; the 2026 audit already reached the same conclusion. The one exception is a future *scope* field (slice 3, "applies to: any curriculum / from version X to version Y"), which would be an optional field inside the dialog, never a page-level bar.
- No blank state: the table, empty state or loading state is always visible; the old "No subjects selected for comparison" row disappears with the Comparison tab.

**Slices**

| Slice | Content | Schema? |
|---|---|---|
| **S1** (about 1.5 days) | One page, no tabs, records table with the columns above (Direction shows "Mutual (either way)" until S3; Credit column hidden), shared dialog with pickers + side-by-side evidence (description, lecture / lab, units, status, usage), row click read-only, redirect `subject_comparison.php`, `equivalency_records.php`, `subject_equivalency.php` to the one page, **required reason** (validator change only), apply `CurriculumReadScope` to `compare`/usage for restricted roles, show "Not recorded" rows instead of hiding them, remove the dead "pending" path from the dashboard list, HTTP regression test for the module (permissions, CSRF, audit, reason required, scope) | **No schema change** |
| **S2** (about 1 day) | More evidence read-only: prerequisites, grade weighting, optionally CLOs / outcome mappings, each labelled with its source and version | No schema change |
| **S3** (about 2-3 days, needs answers) | Direction (mutual / A to B / B to A), credit outcome (full / partial / none), scope (any curriculum, or from version X to version Y), many-to-one (a decision with several source subjects). New table(s) or columns in **migration 031** (030 is reserved for Electives). Drop the `subject_id < equivalent_subject_id` rule or move it to a canonical key for mutual records; keep the existing rows as "mutual, any scope". Audit to store old and new values | **Yes, migration 031** |
| S4 (optional) | Let other modules read equivalencies (e.g. a Pre-requisite or Compliance hint "satisfied by an equivalent subject") | No new schema if S3 exists; **only with client approval** |

**Risks**
- Over-reading regulation: no source in the project supports crediting rules; the page must say "advisory record" until sources are provided.
- Changing the unordered-pair design touches 4 audit consumers and 2 existing rows; keep rows migratable.
- Required reason will make the 2 existing rows (no remarks) edit-invalid until a reason is added; the table shows an empty Reason, no data loss.
- Archived pairs are permanent (UNIQUE blocks a re-create). Decide whether archive should allow restore.
- Demo data (CC101 equivalent to CC104) should not appear in a client demo as a real decision.

**Questions**
1. *Needs client input / may be NDA-restricted:* the text of DO 017 Table 1 and Annex F.1 / F.2, and CMO 20, as they relate to equivalency and crediting. Are they the rules BCP follows?
2. *Needs client input:* does BCP credit many-to-one (several subjects replace one) and is crediting one-way (old to new) or mutual?
3. *Needs client input:* is "credited as elective" a decision BCP records, and what are the credit outcomes (full / partial / none, units credited)?
4. *Needs client input / may be NDA-restricted:* is college credit transfer from other institutions in scope for this tool, or only equivalency between BCP subjects across curriculum versions?
5. Who approves a decision: the same user who records it (today), or does it need a review step (the 'pending' state exists in the CHECK but is unused)?
6. Should restricted roles (view only) see decisions about subjects they cannot see in any approved curriculum?
7. May the 4 demo rows be deleted or marked, and should archive be reversible?

## 9. Not verified here
Anything in a browser (the page was read, not opened); `sms_db` rows beyond counts; the CIMF provider response (AI was not called); original regulatory documents (not in the repository).
