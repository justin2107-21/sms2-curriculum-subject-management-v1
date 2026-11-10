# Academic Strand/Course Assignment — Plan (Slice 0, read-only)

## Status: MODULE COMPLETE (updated 2026-11-10)

Slices 0 to 4 are done. What remains is data cleanup and the manual browser checks listed in `SESSION_HANDOFF.md`; no further code is planned. Open product decision carried over from slice 0: whether placement should require or create an assignment (not built; "Assign placed subjects" is the manual reconcile).

| Slice | State |
|---|---|
| 0 Findings | Done (commit 232d4ca). |
| 1 Fix the dead Assign | Done (commit b1fda9c). Disabled or blocked Assign explains why (csms-info tooltip beside the button, toast when attempted); inactive records are tagged in the program picker; the assign picker already excluded inactive and assigned subjects (server `available=1`), now covered by a test. Select height: the generic form-field rule in csms-components.css (specificity 0,2,1) beat `.csms-filter-row .csms-select` (0,2,0), so selects were 36px while inputs were 40px; the filter-row rule now uses `select.csms-select` and wins. |
| 2 Program-first page | Done in this slice, covering what section 3 called slices 2 and 3: no tabs, program picker kept in `?program_id=`, one coverage strip, union listing with server-side paging, "Placed in" column, "Where used" dialog replacing the Subject-centric tab, row menu, bulk assign dialog, read-only role. No schema change. |
| 2b Shared context bar | Done. The Program / Strand picker card is replaced by the shared Program / Curriculum / Version context bar (same markup, cascade engine and `js/curriculum-context.js` store as Electives Manager; new working-context module key `assignment`, permission `program_assignment.view`). The selection is shared both ways with Subject Mapping, Pre-requisite and Electives; a fresh load uses the saved selection, else the same default (last edited, then newest active version). Assign/Unassign stay program-level. The version scopes only the Placed column and the Placed counts: rows = assigned to the program UNION placed in the selected version; with no version selected nothing counts as placed. Row menu fixed in the shared `csms-menu.js` (menu moved to `<body>`, clamped to the viewport), disabled items show their reason. No schema change. |
| 3 Rules and reconcile | Done (2026-11-10). "Assign N placed subjects" in the coverage strip (manage only; disabled with a reason when no version, count 0, inactive program, or only inactive subjects remain). Preview dialog (shared `csms-review.js` lists: "Will be assigned", "Skipped, inactive"), then one server action in a single transaction, idempotent, program-level only: it never writes to `curriculum_version_subjects` or any version. Audit: one `PROGRAM_SUBJECT_ASSIGN` per assignment (`source: reconcile`) plus one `PROGRAM_SUBJECT_RECONCILE` summary (none when nothing was assigned). Unassign keeps its server rule; the error now names the blocking curricula, versions and terms and the client pre-check is gone. |
| 4 Polish and CIMF review | Done. Four equal-height count tiles, the reconcile button under the program type text, one scope sentence only (the Assignment tooltip), and the cut-off info tooltip fixed (a `white-space: nowrap` header leaked into the shared `csms-info__tip`; the shared rule now wraps, and the table keeps room for the tip). CIMF Assignment Review is deterministic first: the server computes five findings from stored records (placed not assigned active / inactive, assigned not placed in the selected version, inactive placed in the version, assigned but placed in no version) with counts and codes; the AI only explains and prioritizes a compact payload (exact counts, at most 30 example subjects). With the AI off or failing, the panel shows the findings alone with a notice. Read only. |
| 3 Drawer | Not built. Where used is the existing details dialog (a shared csms drawer does not exist). Revisit only if the dialog proves too small. |
| 4 Backfill / consistency | Open. Needs a product decision (see risks). Nothing was backfilled. |

Facts recorded while building slice 2: the Acad Faculty role has no `program_assignment.view`, so it gets 403 on this page; Staff and Acad Office see it read-only; placements shown to any user without `curriculum.view_unapproved` are limited by `CurriculumReadScope`, while the unassign block still checks all placements.

---

Scope of this slice: investigation only. No code and no database changed. Counts come from `sms_db_uitest` (SELECT only). `sms_db` was not touched.

## 1. Findings

### 1.1 Where things live

| Layer | Program-centric | Subject-centric |
|---|---|---|
| Page | `pages/program_centric_assignment.php` | `pages/subject_centric_assignment.php` |
| Shared template | `pages/academic_program_assignment.php` (both pages set `$programAssignmentView` and include it) | same |
| JS | `assets/program-assignment.js` (one file, branches on `data-view`) | same |
| CSS | `assets/program-assignment.css` | same |
| Endpoint | `http/program_assignment_actions.php` (`search`, `context`, `list`, `details`, `review`, `assign`, `remove`) | same |
| Service / repo | `ProgramSubjectAssignmentService` / `ProgramSubjectAssignmentRepository` | same |
| Tab strip | `pages/_module_views.php`, group `assignment` | same |

Dead code: `AcademicProgramAssignmentService`, `AcademicProgramAssignmentRepository` and `http/assignment_actions.php` are the older placement-based implementation. The live page does not use them.

### 1.2 Assignment vs placement

- **Program-subject assignment:** `program_subject_assignments` (migration 017). Columns: `academic_program_id`, `subject_id`, `status` (`active` or `archived`), unique on (program, subject). Archiving is the soft delete.
- **Curriculum placement:** `curriculum_version_subjects` (year level, semester, sequence). Its parent chain is `curriculum_versions` → `curricula.academic_program_id`.
- **What links them:** nothing in the database. There is no foreign key and no trigger. The only link is a logical join on `(curricula.academic_program_id, cvs.subject_id)`, used by `ProgramSubjectAssignmentRepository::placements()` and `list()`.

### 1.3 Why "+ Assign" does nothing (code trace, not a browser run)

- `assets/program-assignment.js:52` sets the header button to `disabled = !manage || state.context.status !== 'active'`. A non-active selected record therefore leaves the header "Assign to Program / Strand" button disabled.
- `assets/program-assignment.js:92` is the guard in `openAssign()`: `if (!manage || !state.context || state.context.status !== 'active') return;`. It returns without a toast, error or console message. The empty-state "+ Assign" button (`#emptyAssignButton`, bound at line 74) calls the same function, so it does nothing.
- The context picker (`program-assignment.js:18-20`) calls `search` without `available=1`, so inactive subjects can be picked. The server enforces the same rule: `ProgramSubjectAssignmentService.php:51` throws "Inactive or archived … cannot receive new assignments".
- In `sms_db_uitest`, 4 subjects are inactive (CC101, CC103, BPM101, CAP101). All 4 have no assignments, so they show the empty state, which is exactly the reported symptom.
- Second cause for users without permission: `program_assignment.manage` is held by Superadmin and Admin only (Roles_and_Permissions.md:104). For everyone else the header button is `hidden` (`academic_program_assignment.php:41`) and the empty-state button is not rendered (`program-assignment.js:29`).
- No console error is expected. This is a silent early return.
- Not verified in a browser. No browser tool was available in this session. This is a static trace plus DB data. Slice 1 should confirm it in a browser before changing code.

### 1.4 Counts (`sms_db_uitest`)

Active assignments vs distinct subjects placed, per program:

| Program | Status | Assigned (active) | Placed (distinct subjects) | Placed, not assigned | Assigned, not placed |
|---|---|---|---|---|---|
| BSIT | active | 1 | 52 | 51 | 0 |
| BSCRIM | active | 0 | 2 | 2 | 0 |
| UITEST-PA | active | 0 | 4 | 4 | 0 |
| UITEST-PB | active | 0 | 4 | 4 | 0 |
| All other programs (ASD, BSBA, BSHM, ICT, T-*) | mixed | 0 | 0 | 0 | 0 |

- Totals: 1 assignment row (active), 0 archived. 61 subjects placed but not assigned; 0 assigned but not placed.
- Subjects: 51 active, 4 inactive. 54 subjects have no active assignment.
- Meaning: the placement side is the real data and the assignment side is almost empty. Today the Assignment page shows "0 subjects" for programs that have full curricula.

### 1.5 Rules and guards

- **Does placement require an assignment?** No. `CurriculumVersionSubjectService::assign()` checks version editability, subject existence and duplicate placement. It never reads `program_subject_assignments`. The live DB confirms it with no FK and no trigger. The comment at `ProgramSubjectAssignmentService.php:84` ("FK-backed mapping inserts") is stale.
- **Is unassign blocked when the subject is placed?** Yes, in code only. `remove()` locks the subject row, then throws a conflict if any placement exists for (program, subject) (`ProgramSubjectAssignmentService.php:86-87`). The DB does not enforce it.
- **Permissions:** `curriculum_require_read('program_assignment.view')` for reads. `curriculum_require_mutation('program_assignment.manage')` for `assign` and `remove`, which also requires POST and a CSRF token. Both are enforced server-side. The UI hiding is only a convenience.
- **Audit logging:** `assign` writes `PROGRAM_SUBJECT_ASSIGN` and `remove` writes `PROGRAM_SUBJECT_ASSIGNMENT_ARCHIVE`, both through `audit_log_required` inside the transaction. The uitest `audit_logs` table has 2 `PROGRAM_SUBJECT_ASSIGN` rows and 0 archive rows. Re-assigning an archived row reuses it (`activateOrCreate`) and logs as ASSIGN.

### 1.6 Source documents (stated text only)

Read from scans by OCR, with Table 12 also checked visually. Locations: DO 017 s.2026 Annex B1 (printed p. 32); DM 048 s.2025 ¶14 (PDF p. 20); DM 036 s.2026 ¶9 (PDF p. 4).

- **DO 017 Table 12, "Mapping of SHS Track and Strands to the SSHS Academic Track Clusters of Electives":** each previous SHS strand is mapped to one or more SSHS Academic Track clusters of electives.
  - ABM maps to Business and Entrepreneurship.
  - General Academic maps to Arts, Social Sciences, and Humanities; Business and Entrepreneurship; and the cluster previously implemented under any track or strand.
  - HUMSS maps to Arts, Social Sciences, and Humanities.
  - STEM maps to Science, Technology, Engineering, and Mathematics.
  - Arts and Design (track) maps to Arts, Social Sciences, and Humanities.
  - Sports (track) maps to Sports, Health, and Wellness.
  - Any track/strand maps to Field Experience.
  - The text before the table says it shows equivalencies for planning, for School Year 2026-2027. It is a mapping aid, not a statement that clusters replace strands as records.
- **DM 048 ¶14:** clusters of electives are organized groups of elective subjects. They are "not the same as strands" because clusters are flexible groupings students can choose from, while strands are "rigid, pre-defined sets of subjects that limit student choice to a specific specialization".
- **DM 036 ¶9:** the SSHS curriculum "replaces strands with track-independent elective clusters". Schools are not mandated to offer every subject in a cluster, and learners may enroll in any elective across any cluster the school makes available. (¶13 of DM 036 adds that a school may offer a cluster only if it holds a permit or recognition for the corresponding track or strand. Table 2 is referred to as the mapping there.)
- **What the documents do not state:** they do not say how a college or institution should store strands, clusters or assignments. Nothing here dictates the schema. The "Program / Strand" label on this page is a CSMS naming choice. SHS-specific modelling of clusters stays with the Electives Manager (see Electives_Plan.md), not this page.

## 2. One-page program-first design

Principle: pick a Program / Strand and see everything about its subjects on one page. The Subject-centric view stops being a separate tab and becomes a drawer opened from a subject row.

```
Academic Strand/Course Assignment                         [ + Assign Subjects ] [ CIMF Review ]
Program / Strand: [ BSIT — Bachelor of Science in IT  v ]      status badge
+--------------------------------------------------------------------------------------+
| Coverage strip:  Placed 52 | Assigned 52 | Placed, not assigned 0 | Assigned, not placed 0 |
+--------------------------------------------------------------------------------------+
| [search] [Coverage: All v] [Status: All v] [20 rows v]                       52 records |
| Subject Code | Title | Units | Assignment | Placed in                       |   ...   |
| CC101        | ...   | 3     | Assigned   | BSIT-2024 v1 · Y1 S1            |   ...   |
| CC102        | ...   | 3     | Not assigned (placed) | BSIT-2024 v1 · Y1 S1 | Assign |
+--------------------------------------------------------------------------------------+
Row click -> subject drawer: all Program / Strand assignments for this subject,
placements per curriculum version, status, link to Subject Mapping, Assign / Remove.
```

- **Coverage strip:** four numbers using `csms-stat-row` / `csms-stat`. "Placed, not assigned" and "Assigned, not placed" are clickable and set the Coverage filter. Numbers come from one query that joins placements to assignments, per program. This directly exposes the data gap in 1.4.
- **Rows:** the list is the union of active assignments and placed subjects for the program. A placed-but-unassigned subject appears with an "Assign" action. This fixes today's state, where BSIT shows 1 row but has 52 placed subjects.
- **"Placed in" column:** curriculum code / version label / year-semester, replacing the current "Curriculum Usage: Used in N Curriculums" text. It stays informational. Placement editing remains in Subject Mapping.
- **Subject drawer replaces the Subject-centric tab:** opened by row click. It lists the subject's assignments across programs and its placements, with Assign to another program and Remove. The "Subject-centric view" tab is removed from `_module_views.php`. `subject_centric_assignment.php` redirects to the program page, so existing links and sidebar entries keep working.
- **Disabled-button fix:** when an action is unavailable, show the reason. The reasons are inactive subject or program, no `program_assignment.manage` permission, and placed in a curriculum (for Remove). Use a visible helper line or tooltip, not a silent disabled state or silent return.
- **Shared csms components only:** `csms-filter-row` / `csms-page-bar`, `csms-select`, `csms-search`, `csms-ctable` with `csms-row-click`, `csms-menu` row menu, `csms-stat-row` / `csms-stat`, `csms-chip`, `csms-state`, `status-badge`, and the existing `curriculum-modal` dialogs. No new one-off page styles beyond what exists in `program-assignment.css`.
- **Gap:** there is no shared drawer component in the repo (no `csms-drawer`). Slice 3 either adds one as a shared csms component (documented in UI_Decisions.md) or uses the existing details dialog. Decide before building. Do not invent a page-local drawer.

## 3. Slices

1. **Fix and clarify (small).**
   - Confirm 1.3 in a browser.
   - Make `openAssign` and the header button explain why they are unavailable.
   - Stop listing inactive subjects and programs in the context picker, or label them "inactive".
   - Correct the stale FK comment.
   - No schema change. Verify with `sms_db_uitest` users `uitest.admin` (can manage) and `uitest.acadoffice` (view only).
2. **Coverage data (read-only backend).** Add a `coverage` action returning the four counts per program, and make `list` return the union of assigned and placed rows with `placed_in`. Add tests (before/after) for the BSIT case: 52 placed, 1 assigned.
3. **Program-first UI.** Add the coverage strip, the Coverage filter and the "Placed in" column. Add the "Assign" row action for placed-not-assigned rows. Add the subject drawer (shared component decision from section 2) and remove the Subject-centric tab, with a redirect from the old URL. Check at 390px width.
4. **Backfill and consistency (needs approval).** Decide the rule for the 61 placed-but-unassigned subjects, either through a one-time backfill (on `sms_db_uitest` first) or by making placement create the assignment. Decide whether the DB should enforce placement-implies-assignment. Update UI_Decisions.md and SESSION_HANDOFF.md.

## 4. Risks

- **Placement does not require assignment today.** Any UI that assumes both agree will show the gap everywhere (61 rows in uitest). Slice 4 needs a product decision. Backfilling or changing placement rules touches curriculum data and must not run on `sms_db` without a backup (run one DB job at a time).
- **Enforcement is in code only.** The "cannot unassign a placed subject" rule has no DB constraint. A direct DB write or a future code path can break it.
- **Removing the Subject-centric tab** changes navigation. Sidebar items, `activeNav` ids (`program-assignment-subject`), bookmarks and CIMF context (`CSMSPageCIMFContext` uses `subject_id`) must be migrated, not dropped.
- **No drawer component exists** (see section 2). Building one inline would break the "shared csms components only" rule.
- **Union listing changes semantics** of `list` and of `summary.assigned_count`. Other callers (CIMF review facts, Dashboard counts) read `program_subject_assignments`; check them in Slice 2.
- **Dead code** (`AcademicProgramAssignment*`, `assignment_actions.php`) can mislead. Confirm it is unused and remove it in a separate commit.
- **Regulatory wording:** DO 017, DM 048 and DM 036 concern SHS electives. Do not present strands as clusters or imply compliance on this page. The documents were read by OCR from scans, so re-check quotes against the originals before citing them in any user-facing text.
- **Single seeded assignment row:** testing results on `sms_db_uitest` will look much emptier than the BSIT placement data. Seed fixtures for the coverage states (assigned and placed, placed only, assigned only) before Slice 3.
