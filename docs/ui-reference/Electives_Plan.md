# Electives Manager redesign: plan

Status: **plan only, awaiting approval. Nothing is built.** Written 2026-11-07.
Standard: the shared `csms-*` components from the Pre-requisite pass (tabs with the focus box on keyboard focus only, single clear X, `csms-filter-row` / `csms-select`, `csms-row-click` rows, `csms-empty`, `csms-info` tooltip, `js/csms-review.js`) and the Builder table style (Code column in blue `--csms-code-blue`). No invented data; no hardcoded unit targets.

## 0. Decisions (given)
- College only. SHS elective categories are out of scope and noted on the page (info tooltip, as on the Pre-requisite page).
- An elective group = name, type, placement term (year + semester), required units, subject pool.
- Types: **Professional Elective**, **GE Elective**, **Mandatory Elective** (prescribed pool), **Free Elective** (open pool). *Proposed mapping, pending client confirmation.*
- No hardcoded targets (12 professional units, 9 GE units belong to the Compliance Validator). The page shows a data-derived summary only, e.g. "3 groups · 12 units required · 5 pools".

## 1. What exists today
**Files:** `pages/electives_manager.php` (123 lines), `assets/electives-manager.js` (163), `assets/electives-manager.css` (40, `elective-*` classes, own `elective-tabs`), `http/elective_group_actions.php`, `http/elective_membership_actions.php`, `services/ElectivesWorkspaceService.php` (workspace, review, save, archive), `services/CurriculumElectiveGroupService.php`, `services/CurriculumElectiveGroupSubjectService.php`, `repositories/ElectivesWorkspaceRepository.php`, `CurriculumElectiveGroupRepository.php`, `CurriculumElectiveGroupSubjectRepository.php`. Tests: `tests/electives-workspace.cjs`, `electives-service.php`, `electives-http.php`.

**Endpoints** (`elective_group_actions.php`): `workspace` (`elective.view`), `list`, `context_options`, `create` / `update` (`elective.manage`, version must be editable), `archive`, `remove` (always refused: groups are archived, never deleted). `elective_membership_actions.php` is the older per-subject add/remove API (not used by the workspace page). Permissions: `elective.view` all five roles; `elective.manage` Super Admin and Staff (`Roles_and_Permissions.md`).

**Schema** (migrations 006, 014, 015):
- `curriculum_elective_groups`: `id`, `curriculum_version_id`, `group_name` (unique per version), `description`, `year_level`, `semester` (both NULL for legacy rows: "left unset for review"), `required_selections INT NOT NULL DEFAULT 1`, `status ENUM('active','inactive','archived')`, audit and archive columns.
- `curriculum_elective_group_subjects`: `elective_group_id`, `curriculum_version_id`, `subject_id`; unique (group, subject); a composite FK to `curriculum_version_subjects` (the subject must be placed in the version).
- **There is no type column and no units column.** `required_selections` is a *count* of subjects to choose, not units.
- Data: `sms_db_uitest` has 1 group (1st Year / 2nd Semester, not active); `sms_db` has 1 group. Not enough to test the redesign; a fixture script is needed (section 6).

**What the page does today:** context picker (own markup, `elective-context`), two tabs (Elective Groups, Validation Review), a filter row (search, Year, Semester, Status), and a two-column grid of big cards per group (placement, status badge, "pick N", pool list, View/Edit/Archive). The workspace response carries `groups` (each with `members`), `placements`, `prerequisites`, `years`, `terms`, `terms_by_year`, `review`. The create/edit dialog needs a name, year, semester, "number of subjects to choose", status and a pool picked from subjects **placed in that term**.

**Save rules today (`save()`):** name required; year and semester required and must match a current placement in the version; pool of at least 2 distinct subjects; `required_selections` between 1 and the pool size; every pool subject must be placed in that same term and be `active`; identical name+term refused; archived groups cannot be edited; the version must be editable (`CurriculumVersionEditPolicy`). Revision clone (`CurriculumRevisionService`) copies groups and members and re-validates `required_selections`.

**Review today (`ElectivesWorkspaceService::review`) — the 7 score cards:**
| Card | What it computes | Verdict |
|---|---|---|
| Empty Groups (`empty`) | group has no members | keep (can occur for legacy groups) |
| Selection (`selection`) | `required_selections` < 1 or greater than the distinct member count | keep, reworded for units (below) |
| Duplicate (`duplicate`) | same subject twice in one group | **cannot happen**: the unique key forbids it; drop |
| Placement Mismatches (`mismatch`) | a member's placement term differs from the group's term | **meaningful**: it appears when a subject is moved in the Builder after the group was made. Keep, renamed "Pool subject placed in a different term" |
| Unmapped Subjects (`unmapped`) | member with no placement row or an empty year/semester | the composite FK guarantees the placement row; only an empty year/semester remains, which `mismatch` already reports. **Drop** (as requested) |
| Inactive (`inactive`) | member subject status is not `active`, only for active groups | keep |
| Missing Context (`context`) | group has no year or semester, or a broken version/curriculum/program chain, or a member from another version | the FK chain cannot break; only "no term" remains. **Drop as a card**; its useful case becomes "Group has no placement" |
Issue links today go to Subject Mapping (placement fixes), which no longer edits placements; they must go to the Curriculum Builder.

## 2. Page design
- **Header and context:** shared header, shared Program / Curriculum / Version picker, read-only badge "Read-only for your role" (permission-based; the version-editable refusal comes from the server, as on the Pre-requisite page). `Create elective group` stays the header action. Info tooltip: college only, SHS categories not supported, targets belong to the Compliance Validator.
- **Tabs:** Elective Groups | Validation Review (`csms-tabs`, same 16 px gap under the context bar and 15 px tab text as the other two pages).
- **Filter row (`csms-filter-row`):** search (group name or subject code/title, Enter / Escape) | Year (label by program level: "1st Year" style for college, "Grade 11" as stored; labels come from `CurriculumPlacementView.yearLabel`) | Semester | Type | Status (All, Active, Inactive, Archived) | summary on the right, derived only from the loaded rows: **"3 groups · 12 units required · 5 pools"**. Units appear only where a group has a units value (section 4); otherwise the summary omits that part. Pool count = distinct subjects across the visible groups' pools.
- **Groups tab:** tables grouped by Year / Semester (`csms-term` + `csms-ctable`, as the Pre-requisite Rules tab). Columns: **Group** (name in Code blue) | **Type** | **Required** (e.g. "3 units" or "Choose 1") | **Pool** ("5 subjects · 15 units") | warning icon (opens a modal with that group's issues) | row menu (View pool, Edit, Archive). **Row click** (or Enter) opens the group detail dialog: header (name, type chip, term, status chip), the rule line, and the **pool table** (Code blue | Subject | Units | Placement | Status), with "Edit" and "Archive" for managers; read-only roles see the same dialog without those buttons.
- **Empty states (shared `csms-empty`):** "No elective groups yet" with a **Create elective group** button (managers; a hint for others) vs "No groups match these filters" (clear-filters button). Plus the existing no-version state.
- **Create/edit dialog:** fields: name, type, year, semester, required units (or selections, section 4), status; **pool picker**: search of subjects placed in the chosen term (existing rule), shown as removable chips and a small table with units; live message when the pool is smaller than the requirement. Server refusals shown in the dialog. Free Elective: pool picker replaced by "Open pool: any subject may be taken" (needs a server rule, section 4). Mandatory Elective: hint that every pool subject is prescribed.
- **Review tab:** the 7 big score cards are replaced by the shared review component (`CsmsReview.create`), one section "Group checks", rows with count and an expandable table (Group | Subject | Detail | Edit group / Open in Builder):
  1. **Empty group**: no subject in the pool.
  2. **Pool smaller than required**: pool units (or pool size) below the requirement. No hardcoded targets.
  3. **Same subject in two groups**: a subject appears in more than one active group of the version. (New check; the schema allows it.)
  4. **Inactive subject in a pool**: any status other than `active`, row shows the real status.
  5. **Group has no placement**: year or semester missing, or no current placement in that term.
  6. **Pool subject placed in a different term** (the kept "Placement Mismatches"; see the table above).
  "Unmapped Subjects" and "Missing Context" are removed; "Duplicate" is removed because it cannot occur.

## 3. Shared components
All exist already. The plan only needs: `csms-tabs`, `csms-filter-row`, `csms-select`, `csms-filter-row__summary`, `csms-info`, `csms-term` / `csms-ctable`, `csms-row-click`, `csms-empty`, `csms-link`, `csms-chip`, and `CsmsReview`. Two small additions to build once and reuse: a **type chip** style (`csms-chip--type`, neutral colours, same shape as status chips) and a **row menu** component (the Pre-requisite page's floating `pr-menu`, promoted to `csms-menu` with its keyboard handling) so Electives does not copy it.

## 4. What needs a schema or server change
| Need | Change | Proposal |
|---|---|---|
| Elective type | **Schema.** No column exists. | Migration **028**: `ALTER TABLE curriculum_elective_groups ADD COLUMN elective_type ENUM('professional','ge','mandatory','free') NULL AFTER group_name;` Nullable so legacy rows read "Type not set" and nothing is guessed. `curriculum_elective_groups` is a covered baseline table, so the migration needs an entry in `csms_legacy_baseline_declared_changes()` (`database/legacy_baseline.php`) under `028_*.sql`: `checksum` (SHA-256, CRLF normalised, `USE sms_db;` removed), `columns` => `curriculum_elective_groups` => `elective_type` => `COLUMN_TYPE` `enum('professional','ge','mandatory','free')`, `IS_NULLABLE` `YES`, `COLUMN_DEFAULT` `NULL`. Tested on `sms_db_uitest` first (`deploy.php --status`, `tests/legacy-baseline-declarations.php`, `tests/legacy-baseline-fingerprint.php`); backup before the owner applies it to `sms_db`. |
| Required **units** | **Schema (optional).** Only `required_selections` (a count) exists. | Preferred: add `required_units DECIMAL(4,1) NULL` in the same migration 028 (same declaration pattern). Zero-schema alternative: derive units as `required_selections` x the pool's unit value **only when every pool subject has the same units**; otherwise show "Choose N" and skip the unit totals. Recommendation: the column, because Mandatory and Free electives are defined by units, not counts (open question 2). |
| Free Elective with an open pool | **Server rule.** `save()` demands at least 2 pool subjects and `required_selections` <= pool size. | For `free`: allow an empty pool, validate `required_units` > 0, and skip the empty / pool-size review checks. Clone validation in `CurriculumRevisionService` gets the same exception and copies `elective_type` and `required_units`. |
| Mandatory Elective | None beyond the type (label and hint). | Rule semantics need client confirmation (open question 3). |
| Summary, same-subject-in-two-groups, pool-smaller-than-required, inactive, no-placement, mismatch | **None.** All computable client-side from `workspace.groups[].members` (which already carries units, status, placement). | The server `review()` stays as the single definition for the checks it already owns (`empty`, `selection`, `inactive`, `mismatch`, `context`); the new check "same subject in two groups" is computed in the browser, or added to `review()` as one additive key if tests prefer one source (decision at build time, no schema impact). |
| Year labels by program level | None, or one additive key. | `workspace` does not return `program_type` although `context()` has it; add it as an additive response key, or label by stored value as the Pre-requisite page does. |
| Archive from the row menu | None. | Existing `archive` action. |
| Issue links | None. | Re-point to the Builder (`curriculum_builder.php?...`). |
Everything else (grouped table, filters, dialogs, review) is front-end only. No change to `elective.view` / `elective.manage`.

## 5. Slices (each commits only after approval; explicit paths; no push)
1. **Chrome:** shared tabs, picker spacing, filter row (search, Year, Semester, Status), summary, info tooltip, read-only badge, the two empty states. Groups still shown as today.
2. **Grouped table:** `csms-term` tables, warning modal, row menu component (`csms-menu`), row-click group detail dialog with the pool table. Cards removed.
3. **Review:** shared review component with the six checks above; remove the seven cards; links to the Builder. No schema.
4. **Create/edit dialog:** restyle with the pool picker (chips + table, live "pool smaller than required" message). No schema.
5. **Type and units (needs approval of migration 028):** migration + legacy-baseline declaration, `save()` / `workspace` / clone changes, Type field and filter, type chip, unit-based summary and review checks, Free/Mandatory behaviours.
6. **Cleanup and docs:** remove `elective-*` leftovers, `UI_Decisions.md`, handoff.
Slices 1-4 are independent of the migration and can ship without it.

## 6. Test plan (sms_db_uitest only; never write to sms_db; one MySQL job at a time; `.ibd` sizes before and after)
- **Fixture** `tools/uitest/elective-fixtures.php` (uitest only, idempotent, removable): groups in two terms, one group per type (after slice 5), one pool with an inactive subject, one subject in two groups, one pool smaller than its requirement, one group with no term, one legacy group with no type.
- **Node** (`electives-workspace.cjs`, rewritten): grouping and order, filters (incl. Type), summary text, row click, row menu, warning modal, empty states, six review checks and counts, escaping, read-only role, create-dialog pool messages, SHS note.
- **PHP** (`electives-service.php`, `electives-http.php` with `DB_NAME=sms_db_uitest`; the HTTP test uses its own disposable schema): existing save rules unchanged; slice 5: type accepted/refused, units validation, Free with an empty pool, Mandatory, `elective_type` and `required_units` present in `workspace`, RBAC (view vs manage), non-editable version refused, clone copies the new columns (`tests/curriculum-revision.php`).
- **Migration:** `tests/legacy-baseline-declarations.php` (no DB), apply 028 on a `sms_db_uitest` copy then `deploy.php --status` and `tests/legacy-baseline-fingerprint.php`. The owner applies it to `sms_db` after a backup.
- **Static:** `ui-consistency-static.cjs` extended (shared classes, no `elective-tabs`, review component loaded).
- **Browser** (`tools/uitest/screens.cjs` probe): Super Admin at 1440 and 390 plus one read-only role (Acad Faculty on an approved version) for every tab, dialogs, empty states; computed-style comparison against Subject Mapping and Pre-requisite (`-Probe cmpstyles`); quick look at Builder, Subject Mapping and Pre-requisite at 1440 to confirm nothing regressed.

## 7. Open questions
1. **Types.** Confirm the four names and that they are the only types (Professional, GE, Mandatory, Free). Should existing groups default to "Type not set" (recommended) rather than be mapped by name?
2. **Required units.** Add `required_units` (recommended) or derive it from the count and uniform unit values? If the column: is the requirement "at least" N units or exactly N?
3. **Mandatory Elective.** "Prescribed pool": must the student take **every** pool subject (requirement = sum of pool units), or choose from a fixed list? This decides whether the Required column is derived or entered.
4. **Free Elective.** Open pool means no subject list at all, or a list of *suggested* subjects? Where is the units requirement checked (here or the Compliance Validator)?
5. **Same subject in two groups.** Always a problem, or allowed across types (for example a GE pool and a free pool)? Decides severity and whether the check is a warning or an error.
6. **Year labels.** Return `program_type` from `workspace`, or label by stored value only (as the Pre-requisite page does)? College only is decided, so SHS groups only need the note.
7. **Status filter default.** Show Active and Inactive and hide Archived by default (recommended)? Archived groups remain visible under Archives.
8. **Legacy data.** `sms_db` has 1 group with no term unless it was set since 014; the owner to confirm what it should be (the page will flag it as "no placement", never change it).
9. **Migration timing.** Slices 1-4 first without schema, then 028 after the client confirms questions 1-4?
