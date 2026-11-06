# Curriculum Builder redesign: plan

Status: **approved 2026-11-05; building in five slices.** Date: 2026-11-05. Branch: `ui-redesign-pilot`.
Facts come from reading the code and from read-only queries on `sms_db`. Line numbers are as of commit `17348b8`.

## 1. Summary
- Reaching a version's plan goes from 4–5 page loads to one: open Curriculum Builder and the working context restores the version.
- **No schema change is needed** for the proposed scope.
- **One small server addition:** a read-only summary action for the version header (section 3.2). Every existing write endpoint is reused unchanged.
- **Six decisions are needed** (section 2). The most important: every version on `sms_db` is `unclassified`, and the review workflow only accepts proposed revisions, so a Submit button would not work on any of them today.

## 2. Decisions needed
| # | Question | Finding | Recommendation |
|---|---|---|---|
| 1 | What does the header show on versions that cannot be submitted? | `sms_db` has 6 versions, all `version_role = unclassified`, `review_status = not_started`. Only proposed revisions can be submitted, and a revision comes from cloning a classified reference | Show the stepper at Draft and no Submit button, with one line: "To send this for review, classify a reference and create a revision". Link it to Details and references. The rule does not change |
| 2 | Add version archive? | `version_actions.php` has no archive action (cases: `clone_preview`, `clone_reference`, `list`, `get`, `create`, `rename`). `ArchiveCenterService` lists programs, curricula, subjects and elective groups, not versions. `curriculum_versions` has no `archived_at` columns | **Leave it out of this release.** It is new lifecycle behaviour with no restore path, and adding one properly needs a separate design and likely a schema change. The panel lists archived versions read-only |
| 3 | Summer column? | Today's form offers only 1st and 2nd Semester, and `sms_db` has no summer rows. `AcademicSequenceValidator::position()` returns null for "Summer", and `validate()` reports a prerequisite touching such a placement as `unresolved_sequence` | Show a Summer column **only if a version already stores one**, with no "+ add" there. Offering it needs a validator rule decision, which is a separate task |
| 4 | May Admin edit the Plan? | The approved matrix gives Admin `curriculum.update`, which includes placements. Splitting it is already a recorded later task | Keep today's rule: Admin can edit. Doing the split first would be a permission change |
| 5 | May Faculty see the unit target? | The target comes from `compliance_rules`, and Faculty has no `compliance.view` | Yes: only the number and rule name, through the new summary action. No compliance page access |
| 6 | Where do "Add subject" and "Move" check conflicts? | The server already blocks a placement that breaks sequence (`updatePlacement` throws `AcademicSequenceException`) | Keep that block and show its message. Add a preview before saving only for users who hold `prerequisite.manage`, matching the `preview_sequence` permission |

**Owner's decisions (2026-11-05):** 2 to 6 accepted as recommended. Decision 1 accepted with one change: when a version can't be submitted, the empty state shows the next step as an action button where permissions allow ("Classify as reference" with `curriculum.reference.classify`; "Create revision from this reference" with `curriculum.version.clone` on an eligible reference), otherwise a one-line explanation. Rules unchanged.

## 3. Current pages and endpoints
### 3.1 Pages
| File | Role today |
|---|---|
| `modules/curriculum/pages/academic_programs.php` and `curricula.php` (4 lines each) | Set `$curriculumWorkspacePage` and include the renderer |
| `modules/curriculum/pages/curriculum_builder.php` (514 lines) | Direct requests redirect (`:11-33`). Otherwise renders both pages: landing cards `:174`, program list `:191`, curricula panels `:220-230`, version workspace `:249`, references `:252`, review `:259`, clone form `:276`, modals `:301-497` |
| `modules/curriculum/assets/curriculum-builder.js` (1603 lines) | All behaviour. Year tabs `renderWorkspaceYear()` `:1100` |
| `modules/curriculum/assets/curriculum-review.js` (73 lines) | Review panel |
| `modules/curriculum/assets/placement-groups.js` | Elective group display in the board |
| `shared/sidebar.php:29,73-95`, `shared/ui/sidebar.php:21-22` | Builder submenu |

Reported bugs: subtitle "Create an academic program" is fixed in the shared modal at `curriculum_builder.php:347`. "See Curricula" is in the program details modal at `:311`. The "Edit" that opens "Edit Academic Program" comes from the Curricula list reusing the program rows, which goes away with that list.

### 3.2 Endpoints (`modules/curriculum/http/`)
All are reused unchanged unless noted.
| File | Action | Permission |
|---|---|---|
| `program_actions.php` | `list` `:12`, `get` `:22` | `program.view` |
| | `summary` `:16` | `program.view` and `curriculum.view` |
| | `create` `:27` | `program.create` |
| | `update`, `archive` `:32,38` | `program.update` |
| `curriculum_actions.php` | `list`, `get` `:12,16` | `curriculum.view` |
| | `create` `:21` | `curriculum.create` |
| | `update`, `archive` `:26,32` | `curriculum.update` |
| `version_actions.php` | `list`, `get` `:23,28` | `curriculum.view` |
| | `create` `:34` | `curriculum.version.create` |
| | `rename` `:39` | `curriculum.update` |
| | `clone_preview`, `clone_reference` `:12,18` | `curriculum.version.clone` |
| | **New `builder_summary`** | `curriculum.view` plus read scope. Read-only |
| `assignment_actions.php` | `list` `:21` | `curriculum.view` |
| | `placement_subject_options`, `preview_placement` `:11,16` | `curriculum.update` (first also `subject.view`) |
| | `assign` `:42`, `update_mapping` `:53`, `remove` `:65` | `curriculum.update` |
| `requirement_actions.php` | `workspace` `:18` (returns `PrerequisiteGraph::review`) | `prerequisite.view` |
| | `preview_sequence` `:11` | `prerequisite.manage` |
| `review_actions.php` | `history` `:10` | `curriculum.view` |
| | `submit` `:15` | `curriculum.review.submit` |
| | `decide` `:21` | `curriculum.review.decide` |
| `version_reference_actions.php` | `workspace` `:10` | `curriculum.view` |
| | `attach` `:15` | `curriculum.reference.attach` |
| | `classify_reference` `:20` | `curriculum.reference.classify` |
| `working_context_actions.php` | `read`, `save` | Authenticated, CSRF on save |

`builder_summary` returns placed units, the active `MIN_TOTAL_UNITS` target (curriculum rule, otherwise program rule, as on the dashboard), and which actions apply. The conflict count comes from `requirement_actions.php?action=workspace`, so the logic stays in one place.

Lifecycle rules stay on the server: `CurriculumVersionEditPolicy::isEditable()` allows `unclassified` + `not_started`, or `proposed` + `not_started`/`draft`/`returned`. The page only mirrors this to hide controls. The server still refuses.

## 4. New screens and what they reuse
### 4.1 Academic Programs
- One list with All / College / Senior High tabs, using `program_actions.php?action=list&program_category=`.
- Row menu (Edit, Archive), shown by `program.update`. Reuses `#programModal` and `#programArchiveModal`.
- Clicking a row opens a side panel: curricula and versions from `summary`, `curriculum_actions list`, `version_actions list`. Each version links to `curriculum_builder.php?program_id=&curriculum_id=&version_id=`. Users without `curriculum.view` see no curricula section.

### 4.2 Curriculum Builder (`curriculum_builder.php` becomes the page)
- Context bar: `js/csms-context-bar.js` with the page's hidden cascade, as on Electives Manager.
- Working context: add `'builder' => 'curriculum.view'` to the module map in `WorkingContextService`. Save and restore follow D4, and the Faculty read scope already applies.
- Header: name, status, stepper, units vs target, conflicts, primary action.
- Stepper mapping: `not_started`/`draft` = Draft; `under_review` = Review; `returned` = back at Draft with a "Returned" tag and the reason; `approved` = Approved. "Submit" is the step between Draft and Review.
- Tabs: **Plan** (default), **Details and references** (existing `#versionReferencesPanel`, attach and classify forms, clone form), **Review history** (`history`; `curriculum-review.js` holds the forms).
- Versions drawer: list, new (`#versionModal`), rename, clone, compare (link to `curriculum_history_view.php`), Export and Outcome mapping links, which sit on the version header today.
- New curriculum: `#curriculumModal`, from a "+ New curriculum" link beside the context bar, shown by `curriculum.create`.

### 4.3 Plan tab
- Rows = years, columns = semesters. Summer per decision 3. Labels and order come from `AcademicSequenceValidator::position()`, the same normalisation as `DashboardShaper`, so stored "1st / 1st" (one row on `sms_db`) lands in 1st Year, 1st Semester. Values it cannot read go in an "Unplaced" row, never hidden.
- Data: `assignment_actions list` (page through, as now at `curriculum-builder.js:1133`), `requirement_actions workspace`.
- Each term: subject rows, unit total. Overall total in the header.
- "+ add subject": existing `placement_subject_options` picker in a popover, then `assign` with year and semester prefilled.
- Move: a "Move to…" menu on each subject calling `update_mapping` (sends the existing `is_required`). A menu, not drag only, so it works by keyboard and on phones. Drag can be a later addition.
- Conflicts: a flag on the subject from `review.sequencing` and `review.cycles`, with the message on focus or tap and never by colour alone.
- Remove: existing `#removeAssignmentModal`.
- Elective groups: keep `placement-groups.js` labels.
- Read-only when `isEditable` is false or the user lacks `curriculum.update`: no add, move or remove controls.

### 4.4 Sidebar
Plain links: "Curriculum Builder" (`curriculum.view`) and "Academic Programs" (`program.view`), in both sidebar files. Remove the submenu and its stored open state.

## 5. Redirects
Permissions are checked by the target page.
| Old URL | New URL |
|---|---|
| `curricula.php` | `curriculum_builder.php` (working context restores) |
| `curricula.php?program_id=` | `curriculum_builder.php?program_id=` |
| `curricula.php?curriculum_id=` or `?id=` (with or without `program_id`, `version_id`) | `curriculum_builder.php?curriculum_id=…` plus the others |
| `academic_programs.php?category=college` or `shs` | Same page, tab selected. Invalid category still returns 400 |

A partial address (program or curriculum without a version) must open with that program or curriculum chosen and the next list opened, per D4. Direct links to update: `dashboard/DashboardAnalyticsService.php` `CURRICULA`, `dashboard/DashboardShaper.php:341-342`, `js/curriculum-dashboard.js:68`, `subjects.js:471`, `subjects-redesign.js:506`, `tools/uitest/screens.cjs:378`, and tests in section 9. `UI_Decisions.md` D2 says Builder does not use the context, so it needs updating.

## 6. Per-role behaviour
Derived from the approved matrix. "Protected" means `isEditable` is false.
| | Super Admin | Admin | Staff | Acad Office | Acad Faculty |
|---|---|---|---|---|---|
| Sidebar: Builder / Programs | Yes / Yes | Yes / Yes | Yes / Yes | Yes / Yes | Yes / Yes |
| Program create, edit, archive | Yes | Yes | No | No | No |
| New curriculum | Yes | Yes | No | No | No |
| Versions visible | All | All | All | All | Active or approved only |
| New version | Yes | Yes | Yes | No | No |
| Clone | Yes | No | Yes | No | No |
| Plan edit (unless protected) | Yes | Yes (decision 4) | Yes | No | No |
| Primary action | Submit or Decide, never on own submission | None | Submit | Approve / Return | None |
| Attach / classify reference | Both | No | Attach | Classify | No |
| Export link | Yes | Approved only | Not approved only | Approved only | No |
| Conflict flags | Yes | Yes | Yes | Yes | Yes |

## 7. Wireframes
### 1440 px: Builder
```
┌ Curriculum Builder ───────────────────────────────── [Versions ▸] ┐
│ Program           Curriculum          Version                     │
│ [BSIT        ▾] › [BSIT-DEMO-2026 ▾] › [PREORAL-2026 · Draft ▾]   │
│ Reset · + New curriculum                                          │
├───────────────────────────────────────────────────────────────────┤
│ PREORAL-2026  [Draft]    (●Draft)─(○Submit)─(○Review)─(○Approved) │
│ 157 units · No target set · ⚑ 1 conflict             [Submit]     │
├ [Plan] Details and references  Review history ────────────────────┤
│            1st Semester  18 u        2nd Semester  21 u           │
│ 1st Year   GE1  Title     3 ⋮        GE5  Title      3 ⋮          │
│            CC101 Title    3 ⋮        ⚑CC102 Title    3 ⋮          │
│            + add subject             + add subject                │
│ 2nd Year   …                         …                            │
│ 3rd Year   …                         …                            │
│ 4th Year   …                         …                            │
│                                               Total 157 units     │
└───────────────────────────────────────────────────────────────────┘
```
### 390 px: Builder
```
┌───────────────────┐
│ ☰            Clock│
│ Curriculum Builder│
│ Program           │
│ [BSIT          ▾] │
│ Curriculum        │
│ [BSIT-DEMO-2026▾] │
│ Version           │
│ [PREORAL-2026  ▾] │
│ [Versions]        │
├───────────────────┤
│ PREORAL-2026 Draft│
│ ●─○─○─○ Draft     │
│ 157 u · No target │
│ ⚑ 1 conflict      │
│ [Submit        ]  │
├ Plan│Details│Review┤
│ ▼ 1st Year  39 u  │
│  1st Semester 18 u│
│  GE1 Title     3 ⋮│
│  + add subject    │
│  2nd Semester 21 u│
│  ⚑CC102 Title  3 ⋮│
│  + add subject    │
│ ▶ 2nd Year  41 u  │
└───────────────────┘
```
### 1440 px: Academic Programs with side panel
```
┌ Academic Programs ──────── [+ New] ┬ BSIT ─────────────────── ✕ ┐
│ [All] College  Senior High  [Search]│ Curricula                  │
│ Code   Name         Level Status ⋮ │ BSIT-DEMO-2026             │
│ BSIT ▸ Bachelor…    Coll. Active ⋮ │  PREORAL-2026 · Draft    ↗ │
│ BSCRIM Bachelor…    Coll. Active ⋮ │  FINAL · Draft           ↗ │
│ ICT    Information… SHS   Active ⋮ │ 123                        │
│                                    │  1 · Active              ↗ │
└────────────────────────────────────┴────────────────────────────┘
```
### 390 px: Academic Programs
```
┌───────────────────┐
│ Academic Programs │
│ [+ New]           │
│ All│College│Senior│
│ [Search         ] │
│ BSIT · Active   ⋮ │
│ Bachelor of…      │
│ BSCRIM · Active ⋮ │
└───────────────────┘
Tap: the panel opens full width, with a back button.
```

## 8. Risks
| Risk | Mitigation |
|---|---|
| A 1603-line script mixes both pages, and existing tests depend on its IDs | Split into `academic-programs.js` and a Builder script, moving code rather than rewriting. Keep IDs and modals. Build in slices |
| Tests and links rely on `curricula.php` page content | Keep redirects. Tests follow them or target the new URL. Change assertions only where the screen really changed, and report each one |
| Large plans (the 52-placement version) in one view | Page through existing `list`. Check with the synthetic volume on `sms_db_uitest` |
| Header promises something the server refuses | Hide controls by the same rules, and always show the server's message on refusal |
| Stale screen after a concurrent edit | Reload the Plan after every write and on a conflict response |
| Sequence block on Move surprises users | Show the validator's message and leave the subject in place |
| Faculty opening a link to a draft | Existing read scope returns 403; show a clear message and fall back to the picker |
| Super Admin sees controls on its own submission | Server refuses; hide Decide using the last submitter from `history` |
| `.csms-dash` styles and Newsreader/D5 scope | Use `csms-components.css` and the shared header; DM Sans stays dashboard-only |

## 9. Test plan
One job at a time on `sms_db_uitest`, with real `.ibd` size and time checks on `sms_db` before and after.
- **New `tests/curriculum-builder-http.php`:** page access per role; `builder_summary` permission, read scope and target; each redirect with its context; invalid category 400; add, move and remove on an editable version; refusal on approved and reference versions; CSRF refusal; audit entries for add, move and remove; a sequence-breaking move refused.
- **New `tests/curriculum-builder-plan.cjs`:** grouping, labels ("1st / 1st" merged, unknown values shown), totals, Summer only when stored.
- **Update and re-run:** `tests/curriculum-revision-http.php`, `curriculum-reference-http.php`, `curriculum-comparison-http.php`, `curriculum-revision-ui.cjs`, `phase2_ui.cjs`, `ui-consistency-static.cjs`, `rbac-final-roles.php`, `rbac-safety-http.php`, `working-context-service.php`, `dashboard-shaping.php`.
- **Logged-in check per role:** `tools/uitest/run.ps1 -Role <role> -Widths 1440,390` on `curriculum_builder.php` and `academic_programs.php`, for all five roles. Add a scenario: open Builder, restore context, add, move and remove a subject as Staff; Submit as Staff; Return and Approve as Acad Office; read-only as Faculty.
- **Also check:** keyboard use of the menus, picker and tabs; old sidebar storage key harmless; no console errors, overflow or burger failures.

## 10. Build slices
Each ends with tests and your review; commits only when you approve.
1. Academic Programs: tabs, row menu, side panel, bug fixes.
2. Builder shell: context bar, working context, header, `builder_summary`, tabs, moving the existing panels. The board stays as it is.
3. Plan tab: full grid, add, move, remove, conflict flags, 390 px layout.
4. Versions drawer and new-curriculum dialog.
5. Sidebar, redirects, retire the Curricula list, link and doc updates.

## 11. Build log
**Slice 1 (committed `5de99cf`):** Academic Programs tabs, row menu, record modal; programs may be archived from inactive; archive/restore refusals return 409/404 with their reason.

**Slice 2 (built, awaiting review):** `curriculum_builder.php` is now the Builder workspace (`pages/builder_workspace.php`, `assets/builder-workspace.js` and `.css`). It has the shared context bar and working context (new `builder` module), the version header (status badges, review stepper, units against the target, conflict count, next step per role), and three tabs. Two read-only server additions: `version_actions.php?action=builder_summary` (placed units and the `MIN_TOTAL_UNITS` target) and `academic_program_id` in the version workspace payload.
- **Plan tab:** all years and semesters at once, unit totals, conflict flags with the validator's message, read-only note. Editing placements still happens in Subject Mapping; slice 3 adds add, move and remove here.
- **Details and references tab:** facts, attached sources, Attach source dialog.
- **Review history tab:** events with actor, status and reason.
- **Header actions (decision 1):** Submit for review (Staff, Super Admin); Approve and Return (Acad Office, Super Admin, never on their own submission); Classify as reference (users with `curriculum.reference.classify`); Create revision from this reference (`curriculum.version.clone`); otherwise one line saying what happens next. Rules are the server's.
- **Not in this slice:** versions drawer, new-curriculum dialog, rename (slice 4); sidebar, redirects, retiring the Curricula list (slice 5). The classic Curricula page still works and still serves its own links.
- **Known limits:** a Faculty address pointing at a draft opens the saved context instead (D4). The three `*-http.php` revision, reference and comparison suites need the isolated Task 2 Docker database and were not run here.

### Slice 3: Plan editing

- **Add:** "Add subject" above the plan and + on each term header (term preselected). Searchable picker; subjects already placed are left out by the server.
- **Move and remove:** actions column on the right, shown only when the server says the version is editable and the user holds `curriculum.update`. Move opens a menu of terms; remove asks for confirmation.
- **Refusals:** a refused move opens the Prerequisite conflict dialog with the server's conflicts; add and remove show the server's message in their dialog; lifecycle refusals on a stale page show as a toast and the plan refreshes. No rule lives in the page.
- **Endpoints:** existing `assignment_actions.php` actions only (`placement_subject_options`, `map_subject`, `update_mapping`, `remove`). The workspace payload gained `program_type` for the term choices.

### Slice 4: New version, new curriculum, renames (no versions drawer)

- **No Versions button or drawer:** the selector already lists versions (each option shows its status, e.g. "PREORAL-2026 · Draft").
- **Footer actions in the picker:** "+ New version" at the foot of the Version list (`curriculum.version.create`) and "+ New curriculum" at the foot of the Curriculum list (`curriculum.create`: Super Admin, Admin). Shared picker feature: a level may carry `footer: { label, onClick }`; reachable by keyboard (Tab from the search box). Both open the existing dialogs.
- **New curriculum** also asks for the first version label, because the shared working context opens a curriculum through a version. The two creates are separate server calls; if the version step fails the dialog says so and retries only that step.
- **More ⋮ menu** in the version header, right of the title: Rename version (editable versions, `curriculum.update`), Rename curriculum (`curriculum.update`), Compare versions (`subject_history.view`). Create revision is only the header button, shown for reference versions. Items appear only when role and state allow; the server still decides, and its refusal shows in the dialog (e.g. renaming a curriculum with a protected version: "This curriculum version is protected or has an invalid lifecycle state.").
- Pages reload after a create or rename so the selector shows the new names; a toast is carried across the reload. No version archive. No new endpoints.

### Slice 5: Sidebar and retirement of the Curricula list

- **Sidebar:** Main, Records, Curriculum (ten modules, plain links, no dropdowns), Setup. See UI_Decisions D12. Both sidebar files (`shared/sidebar.php`, `shared/ui/sidebar.php`) follow it. System Settings stays in Setup (it was not in the list given, but removing it would orphan the page).
- **Sub-pages stay reachable** through a view switcher under the title of Assignment, Subject Offering History, Equivalency and Compliance pages.
- **Redirects:** `curricula.php` answers 302 to `curriculum_builder.php` keeping valid `program_id`, `curriculum_id` (or `id`) and `version_id`; invalid values are dropped. A partial address resolves to the newest active version under it (D4). The classic Curricula rendering was removed from `curriculum_builder.php`, which now renders Academic Programs only (its JS still carries unused Curricula branches; removing them is a later clean-up).
- **Links updated:** dashboard analytics and shaper, curriculum dashboard script, Subjects scripts, Academic Programs script, test and probe addresses.
- **Tests:** `curriculum-builder-http.php` gains redirect, partial-address and sidebar checks (126 checks). The old "classic Curricula page still opens" check was replaced by the redirect checks. `curriculum-revision-http.php`, `curriculum-reference-http.php` and `curriculum-comparison-http.php` only covered the old page's markup (clone form, References & Baseline panel, comparison link); they now point at the Builder page and its script, but need the isolated Docker database and were **not run**. The old `-ReviewFlow` probe in `tools/uitest` drove the retired page and was removed; the Builder probe covers the review dialogs.
