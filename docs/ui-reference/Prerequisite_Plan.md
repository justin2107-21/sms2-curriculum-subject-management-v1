# Pre-requisite Configuration redesign: plan

Status: **plan only, awaiting approval. Nothing is built.** Written 2026-11-06.
Visual standard: the Curriculum Builder (DM Sans / Newsreader, plain text tabs with a blue underline, compact tables, shared buttons, Code column in blue `--csms-code-blue`). No invented data.

## 0. Decisions (given)
- No pre-loaded rules. Rules are entered by the user, per curriculum version.
- **College only.** SHS strand groups (parallel or elective tracks) are out of scope; documented as a limitation on the page and in the handoff. SHS versions keep working as today but get no new behaviour.
- **A prerequisite must be in an earlier term.** This is a system rule, not a CHED rule, and the UI says so. The picker lists only subjects placed in an earlier term than the target. A same-term pair is a conflict. Order comes from stored placements only, never from subject codes.
- Co-requisites and standing requirements (e.g. "3rd year standing") are out of scope. Existing co-requisite rows are not touched (the server preserves them on update).
- The PE 1-2 -> PE 3-4 check is **deferred** until a subject category field exists. No inference from codes.

## 1. What the code has today (read for this plan)
- `prerequisite_configuration.php` (257 lines), `prerequisite-configuration.js` (178), `prerequisite-configuration.css` (94). Three tabs: Prerequisite Rules (cards), Chain Viewer (SVG canvas with fit and reset), Validation Review (four count buttons and issue sections). Own classes (`pr-*`), own tab style, `&times;` clear buttons, dark pill-like controls.
- `requirement_actions.php`: `workspace` (`prerequisite.view`) returns `placements`, `relationships`, and `review` (`cycles`, `duplicates`, `sequencing`, `missing`, from `PrerequisiteGraph::review`); `update_rule` (atomic replace of one target's rule, expected ids for concurrency); `preview_sequence`; `placement_options`; `create`; `remove`; `list`.
- `AcademicSequenceValidator` already blocks same-period and later-period prerequisites (`prerequisite_same_period`, `prerequisite_after_target`) from `year_level` and `semester` only. The system rule in section 0 is therefore **already enforced on the server**; the redesign only makes the picker respect it.
- Placements carry `subject_code` and `subject_title` but **not the subject status** (see section 4).

## 2. Page design
### 2.1 Header and context
Shared page header and the shared Program / Curriculum / Version picker (as Builder and Subject Mapping). Read-only badge ("Read-only for your role / version") when the user lacks `prerequisite.manage` or the version is not editable. A short note: "Prerequisites must be placed in an earlier term. Co-requisites and standing requirements are not handled here. SHS strand groups are not supported."

### 2.2 Tabs
Prerequisite Rules | Chain Viewer | Validation Review, in the shared tab style (section 3.1).

### 2.3 Rules tab: grouped table
- One compact table per Year / Semester, same structure as the Builder term cards (header: "1st Year · 1st Semester · N subjects").
- Columns: **Code** (blue) | **Subject** | **Requires** (codes, comma separated, "-" when none) | warning icon (opens the conflict modal for that row, only when the row has a sequencing, missing-placement, duplicate or cycle issue) | row menu (Edit rule, Remove rule, View in chain).
- **Row click opens the edit dialog** (the details modal goes away; "details" are shown inside the edit dialog: Requires, and Unlocks as read-only).
- One compact filter row: Search (code or title, Enter to run, Escape clears) | Year | Semester | Has rules / No rules (All, Has rules, No rules). Summary line: "N subjects · M with rules · K without".
- Edit dialog: target fixed; a multi-select picker of **earlier-term subjects only** (client filter from `workspace.placements` using the same period order as the server; the server stays the authority). Selected subjects shown as removable chips; save uses `update_rule` with expected ids. Server refusals (409 sequencing, cycle, concurrency) shown in the dialog. A subject in the first term has an empty picker with the message "No earlier-term subjects in this version."
- Warning modal: lists the conflicts for that subject with the existing server messages (not rewritten), and a button "Edit rule".

### 2.4 Chain Viewer
- Subject picker (shared autocomplete, single clear X). Toggle **Requires | Unlocks** (segmented tabs-style control, not a dark pill): Requires walks prerequisites back; Unlocks walks dependents forward. Same SVG layout code with the direction flipped; cycles still reported.
- Fixes: the **double X** (the native search-input cancel button plus the custom clear button; remove one, see 3.2) and the **label overlapping the arrow** (the node label box is drawn on top of the arrow end; move arrow endpoints to the card edge with a gap and draw edges below nodes, test with long codes).
- Nodes use compact card style, Code in blue; node click opens the edit dialog (view only when read-only). Fit and reset buttons stay as text buttons.

### 2.5 Validation Review tab
Uses the shared review component (3.6). Checks: Circular dependencies, Duplicate rules, Sequencing conflicts (includes same-term), Missing placement, and the new **Rule uses an inactive subject** (a rule whose target or required subject has a non-active subject status). Each row: label, hint, count, expandable list with Code (blue), Subject, the rule, and "Edit rule". Empty state when no catalog/placements.

## 3. Shared components (build once, reused by every remaining module)
Built in `css/csms-components.css` (+ one small `css/csms-components.js` only if a behaviour cannot be CSS). Slice 1 builds them and moves the Prerequisite page onto them; Subject Mapping and the Builder adopt them where they differ today. Names are neutral (`csms-*`); the Builder's `builder-*` aliases stay as they are now.
1. **Tab active style, no focus-ring box.** Active tab = blue underline and text; the box outline appears only on `:focus-visible` (keyboard), never after a mouse click. One rule for `.csms-tabs [role=tab]`. Prerequisite's `.pr-tabs` is dropped.
2. **Single clear X with a larger hit area.** One clear button component: 32 x 32 px target (44 on touch widths), visible icon 14 px, `aria-label`, appears only when the field has a value, and the native `type=search` cancel decoration is hidden (`::-webkit-search-cancel-button { display:none }`) so there is never a double X. Used by every autocomplete and search field.
3. **Compact select / filter style.** One height (36-40 px), padding that leaves room for the arrow, `text-overflow: ellipsis` with the full value in `title`, min-width set from the longest option so text is never clipped ("All Semesters", "Has rules"). One filter row layout (flex wrap, same gaps) shared by Subject Mapping, Builder and this page.
4. **Row-click detail dialog.** Whole row is a button-like target (`tabindex=0`, Enter and Space open it, `aria-haspopup=dialog`); the row menu and links inside stop propagation. The dialog uses `.curriculum-modal` with the Builder header, Cancel / Save footer, error area, focus return to the row on close.
5. **Shared empty state.** `.csms-empty` (icon, strong line, one hint line, optional action), used for no version, no rows, no matches, no catalog.
6. **Shared review component.** `CsmsReview.render(target, checks, options)`: compact rows with label, hint, count chip, expandable list (table), "all clear" and "not available" states. It is the Subject Mapping Review code from slice 4 generalised (that page then calls it). Checks are plain data (`{key, label, hint, rows, columns}`), so each module only supplies its checks.
7. **Replace dark pill view switches with tabs** (or the light segmented control for in-panel toggles such as Requires | Unlocks). Audit list for later modules: any `.pr-*` / `*-view-toggle` style with a filled dark background.
A short `UI_Decisions.md` entry records items 1-7 as the standard.

## 4. Data and endpoint reuse
- **No schema change, no migration.** All reads and writes use `requirement_actions.php`: `workspace`, `update_rule`, `preview_sequence`, `remove`, `create` (kept, unused by the new UI), `placement_options` (not needed: the picker filters `workspace.placements`).
- Earlier-term filter: a small JS helper that mirrors `AcademicSequenceValidator::position` (year and semester parsing). To avoid two copies drifting, the only server change proposed is adding a numeric `period_position` to each `workspace.placements` row (computed by the existing `position()`; null when unverifiable). The client then compares numbers and never parses labels. Read-only, no new endpoint.
- "Rule uses an inactive subject": needs the subject status. The only other server change proposed: add `s.status AS subject_status` to the `allPlacements` select (read-only column) and compute the check in the browser from `placements` + `relationships`. (Column name to be confirmed against `subjects` before building; open question 3.) If the owner prefers zero server change, the check is dropped and the review shows four checks.
- Both server changes are additive, covered by `tests/prerequisite-http.php`, and do not change any existing response key.

## 5. Slices (each commits only after approval; explicit paths; no push)
1. **Shared components** (section 3, items 1-5 and 7) in `csms-components.css`; Prerequisite page chrome: header, tabs, picker, filter row, read-only badge. Builder and Subject Mapping screenshots unchanged except the focus-ring and X fixes.
2. **Rules tab**: grouped table, warning modal, row menu, row-click edit dialog, earlier-term picker, `period_position` on the workspace response.
3. **Chain Viewer**: double X, label/arrow overlap, Requires | Unlocks.
4. **Review**: shared review component (item 6, extracted from Subject Mapping and used by both), the four checks plus "inactive subject" (with the `subject_status` column).
5. **Cleanup and docs**: remove dead `pr-*` CSS and card code, handoff, `UI_Decisions.md`, limitation note (SHS, deferred PE check).

## 6. Test plan (sms_db_uitest only; never sms_db; one MySQL job at a time)
- **Setup:** `tools/uitest/setup-db.ps1` (or the documented `seed-users.php` workaround) and a small fixture script in `tools/uitest/`: a BSIT version with no rules, one valid rule, one same-term pair (conflict), one cycle, one rule with an inactive subject, a first-term subject. Uitest only.
- **PHP** (`tests/prerequisite-http.php`, `prerequisite-service.php` extended): same-term refused (409 `prerequisite_same_period`); later-term refused; earlier-term accepted; `period_position` present and null for unknown labels; `subject_status` present; RBAC per role (view vs manage); read-only version refused; concurrency (expected ids).
- **Node** (`prerequisite-workspace.cjs` extended): grouping and order, filters, summary counts, picker lists only earlier terms, first-term empty picker, Requires/Unlocks traversal, cycle message, review counts including the new check, escaping.
- **Static** (`ui-consistency-static.cjs`): shared tab, clear, select and empty classes used; no `.pr-tabs`, no `&times;` clear buttons, no dark pill switch.
- **Browser** (Super Admin, Admin, Staff, Acad Office, Acad Faculty; 1440 and 390): all three tabs, row click and Escape/Enter focus return, tab click shows no focus box but keyboard Tab does, one X on every search field, no clipped select text, chain with long codes (no label/arrow overlap), Requires | Unlocks, empty states, console issues. `sms_db` `.ibd` sizes recorded before and after each browser run.
- Only tests touching changed files per slice; the full suites once before the last commit.

## 7. Open questions
1. **Existing data.** Versions on `sms_db` may already hold rules that violate "earlier term" (same-term). Should they be shown as conflicts only (recommended; never auto-changed)?
2. **Cloned versions.** When a version is cloned, rules copy today (per the revision service). Confirm that stays, and that a rule is flagged, not removed, if its subject moves to the same or an earlier term in the Builder.
3. **Subject status.** Confirm the `subjects` status column and which values count as "inactive" (inactive only, or archived too).
4. **Server changes.** Approve the two additive read-only changes (`period_position`, `subject_status`), or choose zero-server-change (client-side order parsing, drop the inactive check).
5. **Unlocks depth.** Show only direct dependents, or the full downstream chain (recommended: full chain, depth shown as columns).
6. **SHS versions.** Hide the page's college behaviours for SHS, or show the page read-only with the limitation note?
7. **Create endpoint.** Remove the unused `create` action later, or keep it for API compatibility?
