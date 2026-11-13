# Subject Mapping redesign: plan

Status: **plan only, awaiting approval. Nothing is built.** Written 2026-11-06.
Visual standard: the Curriculum Builder (DM Sans / Newsreader, plain text tabs with a blue underline, compact tables, shared buttons, the Code column in blue). No invented data.

## 0. What the page is for, and what the code has today
- **Purpose (decided):** Subject Mapping maps subjects to program/graduate outcomes: the OBE curriculum map (CHED Handbook on Typology, OBE and ISA; "Preparing a Curriculum Map"; Table 7). Placements stay in the Curriculum Builder.
- **Today:** `subject_mapping.php` has four tabs: Map (placement grid, year pills, "Place Subject", edit and remove placement dialogs, prerequisite connections), Subject Trace, Mapping Review (structural checks), Graduate Outcomes (outcome list, coverage assessment, CLO and assessment alignment). Scripts: `subject-mapping-final.js` (852 lines), `subject-mapping-final.css`, `subject-mapping.css`.
- **Data that exists (no schema change needed):**
  - `outcome_mapping_actions.php`: `workspace` (read, `curriculum.view`) returns `context` (with `editable`), `outcomes` (`outcome_code`, `outcome_text`, `source_order`, `mapping_count`, coverage), `placements` (year, semester, subject), `mappings` (placement x outcome). `add`, `remove`, `assess` need `curriculum.outcome_mapping.manage` and an editable version.
  - Table `curriculum_version_subject_outcomes`: one row per placement and outcome (`uq_placement_outcome`), with `rationale`, `mapping_origin` (manual or inherited), `inherited_from_mapping_id`.
- **Three facts that shape the design:**
  1. The outcome catalog is **CHED CMO 25 for BSIT only**; the service refuses other programs. The map tab must say so plainly for other programs and must not show an empty or invented matrix.
  2. **`add` requires a written rationale** (1 to 3000 bytes). A plain check cannot be a one-click toggle on the server as it stands. See open question 1.
  3. **`remove` is refused** while active CLO alignments depend on the mapping. The server's message must be shown.

## 1. Curriculum Map tab (replaces the Map tab)
- **Layout:** one table. Rows = placed subjects grouped by Year, then Semester, in the same order as the Builder (`placement-groups.js`: `group()` and `yearLabel()`). A group header row per term: "1st Year · 1st Semester · n subjects" (same as the Builder's term header). Columns = Code | Subject name | one column per outcome | (read-only: nothing else).
- **Outcome columns:** header is `outcome_code`; the full `outcome_text` is the tooltip and also the accessible name (`aria-label`, and a visually hidden text), so keyboard and touch users can read it. Order = `source_order`.
- **Many outcomes:** the table scrolls horizontally inside its card; the Code and Subject name columns are sticky on the left; the header row is sticky at the top. No page-level horizontal scroll (checked at 390 px).
- **Cell:** one small renderer, `mapCell(mapping)` returning the cell's inner mark. Today it returns a check icon for "mapped" and an en dash for "not mapped". It is the only place that knows what a mark looks like, so a level (I/P/D, L/P/O, or something else) can replace the check later without touching the page. The cell is a button only in edit mode (`aria-pressed`, `aria-label` "Map {code} to {outcome}" or "Remove mapping of {code} to {outcome}"); in read-only mode it is plain text with a visually hidden "mapped" or "not mapped".
- **Footer row:** per-outcome count of mapped subjects, computed from the same rows (not from `mapping_count`, so it always matches the visible matrix). A second line is not added.
- **Legend line** under the table: "✓ mapped · – not mapped. Hover or focus a column heading for the full outcome. Mapping does not establish attainment, compliance or institutional approval." (the last sentence is the existing server limitation text).
- **Edit rules:** editable only when the user holds `curriculum.outcome_mapping.manage` **and** `context.editable` is true (unclassified, or proposed and not under review or approved). Otherwise read-only, with the Builder's "Read-only for your role / version" badge in the page header. No client-side rule beyond hiding controls; the server decides and its message is shown.
- **Empty states:** no version chosen; version with no placements ("No subjects placed yet. Place subjects in the Curriculum Builder." + link); program without an outcome catalog ("No graduate outcomes are loaded for this program."). Never a blank grid.
- **Link:** a text link "Edit placements in Builder" in the tab header, going to `curriculum_builder.php?program_id=&curriculum_id=&version_id=`.
- **Removed:** the placement grid, year pills, "Place Subject" and its dialogs (add, edit, remove placement), the prerequisite-connections toggle and its modal (prerequisites are shown in the Builder's conflict dialog and the Pre-requisite module).

## 2. Subject Trace (REMOVED 2026-11-13: it showed placement only)
> **Update 2026-11-13:** the tab is gone; Subject Mapping now has four tabs: Curriculum Map, Mapping Review, Graduate Outcomes, Course outcomes. `?tab=trace` falls back to the Curriculum Map. Where a subject is placed is shown in the Subjects details dialog ("Used in": program, curriculum, version, year, semester, Required/Elective, Open in Builder) and in the Builder. The text below is kept as history.
- Same search-and-results behaviour; restyled with the Builder's card, table and chip classes. Results table: Code (blue) | Program | Curriculum | Version | Year / Semester | Status chip.
- "Open Placement" becomes **"Open in Builder"**, linking to the Builder with that placement's program, curriculum and version. The placement details modal goes with the placement grid, so each result row links out instead.
- Read-only for every role (as today).

## 3. Mapping Review
- Keep the existing structural checks and their cards.
- Add two checks computed from the outcomes workspace (real data only): **Outcomes with no subject** (outcomes with zero mappings) and **Subjects mapped to no outcome** (placements with zero mappings). Each card has a count and opens the same affected-records list as the others.
- Not added now: "outcomes never reaching D" (needs levels).
- Programs without a catalog: the two new cards are hidden, not zero.

## 4. Graduate Outcomes tab
- Restyle only: tabs, header, notice, summary cards, outcome list, coverage controls, CLO and assessment section move to Builder classes and spacing. No behaviour, endpoint or permission change. It keeps the assessment-state controls and the rationale-based add and remove it has today; the Curriculum Map tab is the quick view and edit surface, this tab stays the detail and CLO surface.

## 5. Filters
- Remove the year pill row. One compact row above the map: **Search** (code or title, Enter to run, Escape clears, as in the Builder's "Find a subject") | **Year** | **Semester** | **Mapped status** (All, Mapped to at least one outcome, Not mapped to any). Filters apply to rows; outcome columns always stay.
- Same controls and spacing as the Builder; the context picker above is unchanged (shared Phase 1 and 2 picker).

## 6. Shared classes and tokens to reuse (and what is new)
- **Reuse:** `.builder-tabs` (tab strip, plain text with blue underline; the markup uses `role="tab"`); `.builder-panel`, `.builder-card`; `.builder-term` header and `.builder-subjects` table rules (compact density, Code column `#2163c5`, right-aligned units); `.builder-plan-search` (search box); `.primary-action-button`, `.secondary-action-button`; `.csms-chip--*` chips; `.builder-empty` empty state; `.builder-conflict-btn`-style icon buttons for any row action; `.curriculum-modal` dialogs; `csms-tokens.css` tokens (`--csms-radius-surface`, border, muted surface); `csms-fonts.css`.
- **The Builder CSS file is loaded only on the Builder.** To share without copying, move the generic rules (tabs, term card, compact table, empty state, search box) from `builder-workspace.css` into `csms-components.css` under neutral names, in the first slice, and have the Builder use them. No visual change to the Builder (verified by the same screenshots).
- **New (small):** `.map-table` for the matrix (sticky columns and header, narrow check cells, rotated or abbreviated headers are NOT used; the code is short); `.map-cell` and `.map-cell--on`; `.map-legend`.
- **Code blue:** there is no token for `#2163c5` today (Subject Mapping and the Builder both use the literal). Proposal: add `--csms-code-blue: #2163c5` to `csms-tokens.css` (the same colour, not a new one) and use it in both. Open question 3.

## 7. Test plan (sms_db_uitest only, never sms_db)
- **Setup:** rebuild `sms_db_uitest` (`tools/uitest/setup-db.ps1`), `builder-fixtures.php`, and load the BSIT outcome catalog there (it is already loaded there; 13 outcomes). Fixture: a BSIT revision with placements and a few mappings, one mapping with a dependent CLO alignment, one outcome with no subject, one subject with no outcome. Added to `tools/uitest/` as a small fixture script; no change to `sms_db`.
- **HTTP tests** (new `tests/subject-mapping-map-http.php`, plus the existing `outcome` tests unchanged): workspace read per role; add and remove allowed only with `curriculum.outcome_mapping.manage` on an editable version; refused on a reference and on a version under review (409); rationale required (422); remove refused with dependent CLO alignment (409, message shown); non-BSIT program returns the catalog refusal; Faculty reads only versions in its scope. The page markup: no "Place Subject", no year pills, the Builder link present, flags per role.
- **Static and node tests:** `ui-consistency-static.cjs` extended for the Subject Mapping page (shared classes, no hidden duplicate year filter); a small node test for `mapCell`, grouping order and the footer counts, using the real grouping helper.
- **Logged in, every role** (Super Admin, Admin, Staff, Acad Office, Acad Faculty), at **1440 and 390 px**: all four tabs, the map with many outcomes (horizontal scroll inside the card, sticky columns, no page overflow), an editor flow (map, refused remove, remove) for Staff, read-only views for Acad Office and Faculty, empty states (no version, non-BSIT program, no placements), console issues, the contrast of the check mark and the dash. Screenshots per role and width.
- **Safety:** before and after every browser run, the `sms_db` `.ibd` file sizes are recorded and compared; one MySQL job at a time.
- **Run only what the slice touches**; the full suites run once before the last commit.

## 8. Slices (each commits only after approval; explicit paths; no push)
1. **Shell and shared styles.** Move generic rules to `csms-components.css`; Subject Mapping tabs, headers, buttons and filters in Builder style; year pills removed; "Place Subject" and the placement add, edit and remove dialogs removed; the Map tab shows the existing placements as a read-only compact Builder-style table with "Edit placements in Builder"; Code blue. Trace and Review and Outcomes keep working unrestyled except for tabs and header.
2. **Curriculum Map, read-only.** New tab replacing the placement table: matrix, sticky columns, footer counts, legend, empty states, filters, `mapCell`.
3. **Curriculum Map, editing.** Cell toggle with a rationale dialog (per the answer to question 1), remove with confirmation, server refusals shown, focus handling.
4. **Subject Trace and Mapping Review.** Restyle; "Open in Builder"; the two new checks.
5. **Graduate Outcomes restyle, cleanup, docs.** Restyle; delete the dead placement code in `subject-mapping-final.js` and CSS; update docs and the handoff.
- **Not in any slice:** the contribution level. Optional future step, described only: a nullable `contribution_level` column on `curriculum_version_subject_outcomes`, as **migration 028** with its `legacy_baseline` declaration (`database/README.md`), tested on `sms_db_uitest` first, existing rows shown as "mapped / unclassified", the notation chosen by BCP. Nothing of this is built or prepared except the single `mapCell` function.

## 9. Open questions for you
1. **Rationale.** The server requires a rationale for every mapping. For a check-mark matrix, do you want (a) a small dialog asking for the rationale each time a cell is checked (needs no server change, slower for bulk work), or (b) a change so a rationale is optional (a server and test change, and it loosens a rule the earlier work required)? Recommended: (a) now.
2. **Other programs.** The catalog exists for BSIT only (CHED CMO 25). Is a map for other programs expected soon? Each needs its own source-traceable outcome list; none will be invented.
3. **Code blue token** (section 6): add `--csms-code-blue` with the same value, or keep the literal?
4. **Graduate Outcomes tab overlap.** It already lists each outcome with its mapped subjects. Keep both views (map for the grid, tab for detail and CLO), or fold the outcome list into the map later?
5. **Prerequisite connections** on the old Map tab are removed with the placement grid. Is that acceptable, given they remain in Pre-requisite Configuration and the Builder's conflict dialog?
6. **Notation.** Still waiting for BCP's answer (plain check, L/P/O or I/P/D). Until then the check mark is used and the level is not built.

### Graduate Outcomes tab redesign and the Course outcomes tab (2026-11-13)
- **Status meanings (from the code, `CurriculumOutcomeMappingService::workspace`):** *Not assessed* = no coverage assessment recorded for the outcome (the default, whatever the links); *Incomplete* = assessed as incomplete, which a link added or removed after an assessment also sets; *Mapped* = assessed complete and at least one subject linked; *Unmapped* = assessed complete and no subject linked. "Subjects linked" is the number of recorded links, whatever the status. The (i) next to "Source details" lists these.
- **Graduate Outcomes tab:** one description line ("Official outcomes from CHED CMO No. 25, s. 2015, Sec. 6.3. Links show curriculum design, not student attainment or compliance.") with "Source details" and the (i); one toolbar row with clickable status chips (All, Mapped, Incomplete, Unmapped, Not assessed, each with a count) and the search box; one compact table (Code, Official outcome, Status, Subjects linked, Action "View links"; 48 px rows, text clamped to two lines with the full text in the tooltip, row click opens the same details dialog). Removed: the right-aligned context text, the eyebrow, the repeated title, the yellow banner (its full text now opens the Source details dialog), the six stat cards, the Coverage dropdown and the standalone source button. When every outcome has 0 links one line above the table says "No subjects linked yet" with "Open Curriculum Map". Chip counts are taken over the rows that match the search, so the active chip's number always equals the rows shown.
- **Measured at 1440 x 900 (clone, all-zero state):** the first outcome row moved from 562 px below the tabs to 247 px (315 px saved); fully visible rows went from 0 to 8; the outcomes panel went from 1,911 px to 872 px (it no longer carries the CLO section). 1024 px shows 5 rows (the context bar wraps).
- **Course outcomes tab (5th tab, `?tab=course`):** the "Course Learning Outcomes & Planned Assessment" part moved here unchanged in what it stores. The picker is searchable, grouped by year and semester, and starts empty ("Select a subject to view or add its course learning outcomes"); a placement without CLOs shows "No course learning outcomes recorded for this placement" and, for a user who may manage CLOs on an editable version, the Create CLO button. Read-only versions and missing permissions behave as before.
- **Create CLO dialog:** its own dialog in the shared shell (Cancel and Create CLO in the footer, no inner collapsible); sequence pre-filled with the next free number; the CLO code has no stored pattern (no CLO exists in `sms_db`), so it stays empty with the placeholder "e.g. CLO 1"; server messages show inline; focus goes to the first empty field.
- **Code font:** `.csms-code` (shared, `csms-components.css`): monospace stack with a slashed zero, so "IT01" is never read as "ITO1".
- **Tests:** `subject-mapping-workspace.cjs` (chips equal rows for every chip and with a search, zero-state, picker empty and grouped, Create CLO prefill, read-only, URL state `tab=course`), `subject-mapping-http` 201 checks (five tabs; no banner, stat cards or coverage dropdown).
- **Not changed:** the other three tabs, the mapping data model, the audit trail, permissions. `outcomes-ui.cjs` and `foundation-outcomes-ui.cjs` were already failing before this work (stale fixtures) and still do.

## 9. Duplicate workflows removed (2026-11-13)
- **Subject Trace tab removed** (see section 2). It had no endpoint of its own: it used `assignment.mapping_list` (also used by the map) and `subject.list`, which stay.
- **Graduate Outcomes dialog:** the "Record Mapping" form is gone (links are added only in the Curriculum Map matrix). Order now: outcome statement and source reference; coverage summary; **Linked subjects** (code, title, year/semester, reason; "Remove link" kept for editable versions); **Assess coverage** as a plain section. The button **"Map subjects in Curriculum Map"** closes the dialog, shows the Curriculum Map tab and marks that outcome's column (also in the empty state, "No subjects linked yet"). Permissions, version immutability, audit entries and server validation are unchanged (the server `add` action stays; the matrix uses it).
- **Next step, not built:** a per-subject mapping dialog (one dialog per subject, 13 checkboxes for IT01-IT13, one shared reason).
