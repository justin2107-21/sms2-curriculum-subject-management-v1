# CSMS-SMS2 — Legacy UI Consistency Audit (Phase 1, audit only)

Date: 2026-11-03 · Branch: `ui-redesign-pilot` · Scope: the **current legacy UI**, polished in place (no Bootstrap profile switch).
Rules: `docs/ui-reference/UI_Decisions.md` (D1–D8), `design-system/csms-sms2/MASTER.md`, and the `ui-ux-pro-max` skill.

## How this audit was run
- `ui-ux-pro-max` `scripts/search.py` cannot run (Python is not installed; only Microsoft Store stubs exist). The skill's `references/quick-reference.md`, `references/pro-rules.md` and `data/ux-guidelines.csv` were read directly. Rule IDs in the table below (e.g. `color-contrast`) come from `quick-reference.md`.
- Static analysis with throwaway Node scripts (kept outside the repo) over all 21 stylesheets (`css/` 10 files, `modules/curriculum/assets/` 11 CSS files, 6,722 lines) and 34 page entry points. Contrast ratios are computed with the WCAG formula.
- **Limits:** CSS numbers are *declared* values. `css/csms-typography.css` is loaded last on most pages and overrides some of them, so rendered values can differ. Contrast was measured against white/`#f8fafc`; colors meant for dark panels were excluded by hand. Every fix in Phase 2 must be confirmed in the browser.
- Nothing in the application was changed by this audit.

## Summary
The UI is coherent at a glance (navy sidebar, white cards, Segoe UI) but it has grown file by file without shared tokens:

| Measure | Found | Target |
|---|---|---|
| Distinct `font-size` values | **104** (e.g. `.62rem`, `.64rem`, `.66rem`, `.68rem`, `.7rem`, `.72rem`, `.74rem`, `.76rem`, `.78rem`…) | 6 (12/14/16/18/22/28) |
| Declarations below 12px | **195** in 16 files (worst: `curriculum.css` 54, `curriculum-dashboard.css` 27, `subject-mapping.css` 26, `dashboard.css` 18, `curriculum-export.css` 16, `grade-weighting.css` 14) | 0 |
| Distinct hex colors | **313** (+54 rgba) | ~24 tokens |
| Distinct blues/navies | **71** (`#1a3a8c`, `#2563eb`, `#1d5fc7`, `#1d4ed8`, `#2163c5`, `#1d5ed0`, `#2563c5`, `#155ac4`, `#1f5fbf`, `#2462c5`, `#174fba`…) | navy + 1 action blue + tints |
| Distinct greys | **112** | 7-step slate scale |
| Text colors under 4.5:1 on light backgrounds | **~35 values, ~80 uses** | 0 |
| Border radii | **25** (4,5,6,7,8,9,10,11,12,13,14,16,18,20,22px…) | 4 (6/8/12/pill) |
| Box shadows | **54** | 3 |
| z-index values | **19** (up to `99999`) | 7-level scale |
| Font weights | **9** incl. `650`, `750`, `800` | 400/500/600/700 |
| Button class families | 8 (`btn-signin`, `btn-save`, `btn-danger`, `primary-action-button`, `button-primary`, `button-muted`, `button-danger`, `row-action-button`) | one set: primary/secondary/danger/ghost/icon |
| Page-header patterns | 3 (`page-title-bar`, `mapping-workspace-header`, `subject-landing-header`) + `<h2 class="page-title">` on two pages | 1 |
| Icon set | Font Awesome 6.5.1, 241 `fa-solid` + 4 `fa-regular` — already almost uniform | solid only |
| Body text size | 15px (`--sms2-text-base: .9375rem`) | 16px (D6) |
| `prefers-reduced-motion` | 1 rule | global rule |

Good things to keep: Font Awesome is already nearly one style; there are 39 `:focus-visible` rules; most pages share `shared/topbar.php` and `shared/sidebar.php`; empty states exist on most lists; the sign-in page already has `autocomplete="username"` / `current-password` and disables the button while signing in.

## Untracked files at audit time (not touched)
- `task2-review.diff` — untouched (341,706 bytes, SHA-256 `471DF27F…D315`).
- `assets/ui/preview/` — the static Bootstrap preview from the previous session, still uncommitted.
- `docs/ui-reference/csmsui.zip` — new; appears to be the Manus React prototype (DESIGN-SYSTEM.md, src/*.jsx, styles.css). Not extracted, not used.

## Issue table
Severity: **H** = accessibility failure or broken rule from D1–D8; **M** = visible inconsistency; **L** = polish.

### Typography
| ID | Page / file | Problem | Rule | Sev | Proposed fix |
|---|---|---|---|---|---|
| T1 | `curriculum.css` (54), `curriculum-dashboard.css` (27), `subject-mapping.css` (26), `dashboard.css` (18), `curriculum-export.css` (16), `grade-weighting.css` (14), 10 more files | 195 font-size declarations below 12px (e.g. `curriculum-dashboard.css:223 .64rem` = 10.2px, `.62rem` ×10) | D6, `font-scale`, `readable-font-size` | H | Map to tokens; floor every size at `--csms-text-xs` (12px) |
| T2 | all CSS | 104 distinct sizes; near-duplicates `.7/.72/.74/.76/.78/.8/.82rem` | `font-scale` | M | One scale: 12/14/16/18/22/28 |
| T3 | `csms-typography.css:3-8` | Body is 15px (`--sms2-text-base: .9375rem`); D6 requires 16px; `--sms2-text-xs` is 13px, a fourth small size | D6 | M | Body 16px; tables/menus 14px; xs 12px |
| T4 | `csms-typography.css:3`, `account.css:9` (×7), `curriculum.css:1130`; `subjects.php:50-52`, `curriculum_builder.php:106-108` | Two font stacks (`'Segoe UI'` and `Inter, …`); those two pages load Google Fonts **Inter** from a CDN, so they render in a different font from every other page | `consistency`, `font-loading` | M | One system stack token; remove the two Inter CDN links (needs your OK) |
| T5 | all CSS | Weights `650`, `750`, `800` used (33 uses) | `weight-hierarchy` | L | 400/500/600/700 only |
| T6 | `admin/audit_logs.php:76`, `reports_tab/reports.php:69` | Page title is `<h2 class="page-title">` (no h1) — heading order differs from other pages | `heading-hierarchy` | M | Change to `<h1 class="page-title">` (same class, same look) |
| T7 | `subjects.php:79` vs others | Subjects uses `subject-landing-header`, Mapping/Electives use `mapping-workspace-header`, others `page-title-bar` | `consistency` | M | One header pattern (see P1) |

### Color and contrast
| ID | Page / file | Problem | Rule | Sev | Proposed fix |
|---|---|---|---|---|---|
| C1 | `csms-typography.css:19,123` | Sidebar section headings (`MAIN`, `CURRICULUM & SUBJECT MANAGEMENT`) `#818cf8` on navy `#1a3a8c` = **3.47:1** | D7, `color-contrast` | H | `#c9d4f2` (6.99:1), as in MASTER |
| C2 | `curriculum-dashboard.css:350,404`, `system-admin.css:76` +13 | `#94a3b8` used as text 16× = **2.56:1** | `color-contrast` | H | `--csms-text-muted` `#4b5563` (6.99) or `#64748b` (4.76) |
| C3 | `account.css:219`, `dashboard.css:518,834` (`#999`), `dashboard.css:665,673` (`#aaa`), `dashboard.css:978` (`#bbb`), `dashboard.css:1464` (`#9ca3af`) | grey text 1.9–2.9:1 | `color-contrast` | H | muted token |
| C4 | `account.css:134,270`, `dashboard.css:757` +3 | `#888` text = 3.54:1 | `color-contrast` | H | muted token |
| C5 | `prerequisite-configuration.css:29,49,56,57,65`, `subject-mapping-final.css:49,68,69,75` | `#a9bbd1` (1.96), `#7a8699` (3.69), `#7a8ca8` (3.42), `#8798af` (2.94) text | `color-contrast` | H | muted token |
| C6 | `electives-manager.css:22,26,31,34`, `curriculum-export.css:32,67,78,93,96`, `curriculum.css:653,1156,1273,1365,1368`, `program-assignment.css:31,35`, `curriculum-dashboard.css:373-375`, `archives.css:21` | ~20 slate-greys between 3.0 and 4.47:1 | `color-contrast` | H | muted token |
| C7 | `auth.css:290-293` | Sign-in error `#dc2626` on `#fee2e2` ≈ **3.95:1** | `contrast-feedback` | H | text `#8f1d17` on `#fbe5e3` (7.39) |
| C7b | `auth.css:103,115` | Sign-in left panel subtitle "Student Management System 2" `#4da6ff` on navy = **4.05:1** (14px, so normal-text rule applies) | `color-contrast` | M | `#8cc4ff` or `#c9d4f2` on navy |
| C8 | `dashboard.css:1107,1108,1143,1342`, `account.css:196,319`, `auth.css:298`, `subject-mapping-final.css:86,117` | Status text in raw Tailwind colors `#22c55e` (2.28), `#16a34a` (3.30), `#f59e0b` (2.15), `#d97706` (3.19), `#ef4444` (3.76), `#059669` (3.77), `#b87409` (3.79) | `contrast-feedback`, `color-semantic` | H | status tokens from MASTER §10 |
| C9 | all CSS | 71 blues; brand navy `#1a3a8c` competes with `#2563eb` (34), `#1d5fc7` (17), `#1d4ed8` (14), `#0f2f69`, `#2163c5`… for the same jobs (links, primary buttons, focus) | `color-semantic`, `consistency` | M | `--csms-navy`, `--csms-navy-700`, one `--csms-action` blue, tints |
| C10 | all CSS | 112 greys (`#64748b` ×111, `#475569`, `#52647f`, `#52657f`, `#334155`…) | `color-semantic` | M | 7-step slate scale |

### Spacing, sizing, shape
| ID | Page / file | Problem | Rule | Sev | Proposed fix |
|---|---|---|---|---|---|
| S1 | all CSS | 25 radii (4–22px, `7px`, `9px`, `11px`, `13px`) for the same cards/controls | `elevation-consistent` | M | `--csms-radius-sm 6px / md 8px / lg 12px / pill` |
| S2 | all CSS | 54 box-shadows; cards alternate between borders and 3–4 shadow recipes | `elevation-consistent` | M | 3 shadows: none (default card), sm, overlay |
| S3 | all CSS | Control heights 32/34/36/38/40/42/44/48px | `consistency`, `web-target-size` | M | `--csms-control-h 40px`, compact 32px |
| S4 | all CSS | 306 distinct padding/gap/margin values | `spacing-scale` | L | 4px scale (4/8/12/16/20/24/32/48) used as fixes touch each file |
| S5 | all CSS | z-index ranges 1…99999 (19 values) | `z-index-management` | L | 7-level scale (base/sticky/dropdown/topbar/overlay/modal/toast) |

### Buttons
| ID | Page / file | Problem | Rule | Sev | Proposed fix |
|---|---|---|---|---|---|
| B1 | markup-wide | 8 button families, two naming schemes (`button-*` vs `btn-*`) and `primary-action-button` vs `button-primary` for the same role | `consistency` | M | Shared `.csms-btn` variants; **add** classes alongside existing ones (keep old names for JS/tests) |
| B2 | `curriculum_builder.php` (13 primary buttons), `compliance_validator.php` (6), `subject_mapping.php` (5) | Many primary-styled buttons per screen | `primary-action` | M | One primary per view; others secondary |
| B3 | headers of Builder / Prerequisite / Electives / Subjects | Primary action sits in different spots (inside title bar right, in toolbar, in card header) | `consistency` | M | Title-row right, always (P1) |
| B4 | `auth/signin.php:68,105,110` | Arrow icon re-injected with `innerHTML`; label text swapped while loading without `aria-busy` | `loading-buttons` | L | Add `aria-busy`; keep icon `aria-hidden` |

### Forms
| ID | Page / file | Problem | Rule | Sev | Proposed fix |
|---|---|---|---|---|---|
| F1 | `auth/signin.php:63` | No show/hide password toggle on sign-in (the account page already has `.toggle-pw` eye buttons — reuse that pattern) | `password-toggle` | M | Eye button, `aria-label` Show/Hide password, `aria-pressed`, `type="button"` |
| F2 | `auth/signin.php` | No caps-lock warning | `error-feedback` | L | `getModifierState('CapsLock')` hint, `aria-live="polite"` |
| F3 | `auth/signin.php:47` | Error box has no `role="alert"`/`aria-live`; uses inline `style="display:none"` | `aria-live-errors` | H | `role="alert"`, toggle `hidden` |
| F4 | `auth/signin.php:55,63` | Inputs not `required`, no `aria-invalid`/`aria-describedby` on error; label icons not `aria-hidden` | `error-placement`, `icon-context` | M | add attributes only; logic unchanged |
| F5 | `dashboard/account.php:104,114,124` | Visible labels exist but have no `for`, so the password inputs have no programmatic label (screen readers fall back to the placeholder) | `form-labels` | H | Add `for="fCurrentPw"` etc. |
| F6 | `auth.css:164,269`, `account.css:180`, `dashboard.css:512,953`, `curriculum.css:171,641` | `outline: none` with only a border-color change on focus (`#d0d7e2`→`#2563eb`) — weak focus indicator | `focus-states`, `focus-appearance` | H | Shared `:focus-visible` ring (3px, ≥3:1) |
| F7 | forms across modules | Required markers inconsistent (`*` inside placeholder text: `prerequisite_configuration.php:192,207` "Search target subject *") | `required-indicators` | M | `*` in label + visually-hidden "(required)"; plain placeholder |
| F8 | `admin/account_form.php`, `auth/register2.php` | No `autocomplete` attributes (0 found) | `autofill-support` | L | Add `name`/`username`/`email` autocomplete values |
| F9 | — | No "remember me"/"forgot password": **backend has none**, so none will be added | — | — | Not in scope (no backend invented) |

### Tables
| ID | Page / file | Problem | Rule | Sev | Proposed fix |
|---|---|---|---|---|---|
| TB1 | all CSS | Only 3 `tabular-nums`; unit/credit/count columns are left-aligned in most tables | `number-tabular` | M | `.num` cell class: right-align + tabular |
| TB2 | `audit_logs.php:52-61` (inline `<style>`), `curriculum.css`, `system-admin.css` | Each table family has its own header style/row height | `consistency` | M | Shared table tokens (header 14px/600 on `#eef1f7`, row 44px) |
| TB3 | JS-rendered lists | 10 different empty-state classes (`mapping-state` ×20, `empty-cell` ×18, `subject-empty-state`, `export-empty-state`, `elective-empty`, `pr-empty`…) with different looks | `empty-states` | M | One `.csms-empty` look, applied **alongside** existing classes |
| TB4 | `audit_logs.php:52-61` | Inline `<style>` block on the page | `consistency` | L | Move to stylesheet |

### Icons
| ID | Page / file | Problem | Rule | Sev | Proposed fix |
|---|---|---|---|---|---|
| I1 | `shared/topbar.php:24`, `dashboard.php:148`, `subject-mapping-final.js:653`, `subjects.js:212` | 4 `fa-regular` among 241 `fa-solid` | `icon-style-consistent` | L | Use solid |
| I2 | `reports_tab/reports.php:70` | "Reports" title uses `fa-house` | clarity | L | `fa-chart-column` |
| I3 | `shared/sidebar.php:157,254` | Same `fa-clock-rotate-left` for "Subject Offering History" and "Audit Logs"; `fa-shield-halved` for both "Compliance Validator" and "System Settings" (`:208,258`) | clarity | L | Audit Logs → `fa-list-check`/`fa-file-shield`; System Settings → `fa-sliders` |
| I4 | `dashboard/account.php:107,117,127` | The three `.toggle-pw` eye buttons are icon-only with no `aria-label` and no pressed state | `aria-labels` | H | `aria-label="Show password"`/`"Hide password"` + `aria-pressed` |
| I5 | many pages | Decorative `<i>` icons beside text lack `aria-hidden="true"` (e.g. `signin.php:52,60,68`, `sidebar.php` items) | `icon-context` | M | add `aria-hidden` |
| I6 | all CSS | Icon sizes vary (`.9em`, 15px, 14px, 13px, 1rem…) | `Consistent Icon Sizing` | L | `--csms-icon-sm 14px / md 16px / lg 20px` |

### Batch 3 status (icons)
| ID | Status |
|---|---|
| I1 | Done. The 3 remaining `fa-regular` icons (topbar clock, Subjects "view" button, Subject Mapping success tick) are now solid. No regular/brand/light icons are left in PHP or JS. |
| I2 | Done. Reports header uses `fa-chart-column`. |
| I3 | Done. Audit Logs → `fa-clipboard-list`; System Settings → `fa-sliders`. Every sidebar item now has its own icon. |
| I4 | Done in Batch 2. A static scan finds 79 icon-only buttons/links in PHP and JS and all 79 have an `aria-label` (no unnamed "×" buttons either). |
| I5 | Done. 193 decorative `<i>` icons in 28 files now carry `aria-hidden="true"`. Icons that already had `aria-hidden`, `aria-label`, `role` or `title` were left alone. |
| I6 | Done. 16 icon rules moved onto one scale: `--csms-icon-sm` 14px (inline/search/row actions), `-md` 16px (sidebar, page titles), `-lg` 20px (hamburger, status icons, program icons), `-xl` 28px (empty-state illustrations; a fourth size added to the audit's three). All other icons inherit their text size. |

### Batch 4 status (buttons, forms, tables)
Measured with `tools/uitest/run.ps1 -Probe styles` on 25 real pages (admin, 1440 px), before and after.

| ID | Status |
|---|---|
| B1 | Done without renaming anything. `css/csms-components.css` gives the existing classes one variant set: **primary** (`.primary-action-button`, `.button-primary`, `.btn-save`: navy), **secondary** (`.secondary-action-button`, `.button-muted`: white, bordered), **danger** (`.btn-danger`, `.button-danger`), **small row action** (`.row-action-button`, `.remove-action-button`, `.view-button`). Content buttons went from heights 30/34/36/38/47 px, fonts 12/14 px, weights 400-700 and radii 6/7/8 px to **36 px (32 px for row actions), 14 px (13 px), weight 600, radius 6 px**. `.btn-save` is navy instead of a second blue. |
| B2 | No change needed. No real page has more than 3 filled primary buttons ("Save Permissions" x3 is one per role card). The "13 primary buttons" were in the legacy `curriculum_builder.php` redirect path. |
| B3 | Moves to Batch 5 (page headers). |
| B4 | Done in Batch 2 (sign-in). |
| F5, F7, F8 | F5 was Batch 2. F7: the two prerequisite pickers no longer put `*` in the placeholder; the label carries `*` (hidden from screen readers) plus "(required)" for them, and the input has `aria-required="true"` (no native validation added). F8: own-profile fields have `given-name`/`family-name`/`email`/`username`; the admin account form (editing other people) uses `autocomplete="off"`. |
| F6 | Done. One shared `:focus-visible` ring (3 px on buttons and links, 2 px on fields, white on the dark sidebar). Probe: every focusable control on the 25 pages shows an outline or shadow when focused by keyboard (the 268 sidebar submenu links inside closed, `inert` panels cannot take focus and are not counted). |
| Form fields | Heights were 36/38/39 px and text 12/13/14 px. Now **36 px and 14 px** in page content and dialogs. Pinned heights in `curriculum.css`, `account.css`, `dashboard.css`, `subject-mapping.css` and `subject-equivalency.css` use the control-height token. Sign-in (44 px) and the print/manuscript call to action were left alone on purpose. |
| TB1 | Done for the places found: Entity ID (audit log) and "Curricula" counts (programs tables) use `class="num"` (right-aligned, tabular figures). Audit-log IP addresses are text and stay left-aligned. |
| TB2 | Header text colour is now one value (#374151) across admin, audit and domain tables; header size/weight and padding were already shared. Row heights are left content-driven. |
| TB3 | Empty and error text is one size (14 px), colour (#4b5563) and line height; error text uses the danger token. Per-component boxes (dashed card, icon) keep their layout. Error states are not yet announced to screen readers (`role="alert"`); that is a separate item (ST2). |
| TB4 | Done. The inline `<style>` in `admin/audit_logs.php` moved into `css/system-admin.css` (same rules and order; two 11.5 px sizes raised to 12 px; the "no value" dash colour raised from 2.6:1 to 4.6:1). |
| ST5 | Done for the button variants: disabled is `opacity .5` with `cursor: not-allowed`. |

Not changed in this batch: tab strips (a separate component), the floating CIMF button, sign-in controls, and tables built only after a user action (they were not on screen for the probe).

### Batch 5 status (headers, context bar, reports, year filter, messages)
Measured with `tools/uitest/run.ps1 -Probe headers` / `-CtxFlow` / `-Probe live` on the 25 real pages (admin).

| ID | Status |
|---|---|
| P1, B3 | Done. One header on every page: title (h1) in Newsreader 700, about 27 px at 1440, subtitle 14 px muted, actions on the right (right edge at the same x on every page), 86 px high (111 px on the two history pages, which carry an extra line). Before: titles 23-30 px in Segoe UI or Newsreader, bands 84-127 px, title left edge 24 / 44 / 54 / 56 px, three container classes, action edge at 1384 or 1396. After: title left edge **24 px everywhere** (`--csms-page-gutter`, 16 px on tablets and phones); page-level content insets that were 32/44/54/56 px now use the same token. Audit Logs got a real h1 and subtitle; Account Settings got the standard header (its Archives heading became an h2, same look). |
| P2 | **Redesigned after Batch 5** (see D2-D4 in `UI_Decisions.md`): the 44 px pinned bar and its collapsed summary were replaced by a picker with labels above select-like fields, searchable panels, status on versions, a Recent group and a Reset link, on the five pages that use the three-field cascade. A shared working context now carries the choice to every module page and into the address; resolution rules (a), (b), (e) are built, (c) and (d) wait for the server store (`Working_Context_Proposal.md`). Validate Curriculum (plain selects) is not converted. |
| P6 | Done. `reports_tab/reports.php` uses `shared/topbar.php`: it now has the clock, the CIMF panel and the 5-minute session timeout with its warning (before, it had none of them), an h1 and a subtitle. The unescaped `$sess_initial` echo is gone with the private topbar. |
| P7 | Done. Subject Mapping keeps the year pills; the year dropdown is a hidden state holder (`#mappingYearFilter` stays because the page script and its test read it). Toolbar grid adjusted to two filters. |
| ST2 | Done. Error messages are `role="alert"`, success / empty / loading messages are `role="status"`; table cells get `aria-live` (assertive / polite) instead, so they stay cells; existing roles are never overwritten. `js/csms-live-states.js` covers the many places that build these messages (60 message elements on the 25 pages, all marked; all 35 error elements are alerts); the toast, inline form error and autocomplete state rows set the role themselves (an error toast is an alert, then switches back to status for success or warning). No CSS depends on these attributes, so nothing changes visually. |
| Fonts | Newsreader moved from `csms-dashboard.css` to `css/csms-fonts.css` so every page can use it. |

### Batch 6 status (sidebar, D7)
Measured with `tools/uitest/run.ps1 -Probe sidebar` before and after.

| ID | Status |
|---|---|
| P4 | Done. The only mid-word break was "Academic Strand/Course Assignment" ("Cour" / "se"): the label is `overflow-wrap: anywhere` and "Academic Strand" was no-break, so "/Course" could only fit by breaking inside the word. Labels now wrap at spaces, and the markup has a `<wbr>` after the slash ("Academic Strand/" / "Course Assignment"). |
| P5 | Done. Root cause of the uneven text start: `--sms2-sidebar-icon-column-size` was **10 px**, narrower than every icon, so each icon sized itself (12 to 20 px) and labels started at x = 52, 54, 56, 58 or 60. It is 20 px now (gap 12 px): every top-level and sub-item label starts at x = 59. The active page looks the same at every level (17% white fill, 8 px radius, 3 px white edge, semibold, 6.5:1 text contrast; before: top-level 13% on a 10 px-radius wrapper, sub-items 14% on an 8 px item); hover is a quieter 9%. |
| Headings | MAIN / CURRICULUM & SUBJECT MANAGEMENT / SYSTEM measure 6.99:1 on the sidebar blue (raised in Batch 1 from 3.47:1); the lowest contrast of any sidebar text is 6.5:1. |
| Burger at 390 px | Done. With the drawer open the sidebar covered the burger (hit test landed on the logo). The drawer and its overlay now start below the topbar (`top: var(--sms2-topbar-height)`), so the burger stays reachable; the burger check now closes the drawer with the burger itself instead of the overlay. |

### Placement and layout
| ID | Page / file | Problem | Rule | Sev | Proposed fix |
|---|---|---|---|---|---|
| P1 | 15 pages | Three header structures; subtitle present on some pages only; primary action position varies | `consistency` | M | `page-title-bar` everywhere: h1 + subtitle left, primary action right |
| P2 | `prerequisite_configuration.php:65-95`, `curriculum_builder.php`, `academic_program_assignment.php` | Program/Curriculum/Version selector is a tall panel (~125px) with three × buttons | D2, D3 | M | Compact ~44px sticky row, one "Reset", collapsed summary + "Change" — **CSS + small JS only, same IDs** |
| P3 | `css/cimf-panel.css:3`; `dashboard.css:88` (`padding-bottom: 20px`) | Floating CIMF launcher (60px, bottom/right 24px) can cover the last table row/buttons | D8 | M | Reserve `padding-bottom: 96px` on `.content` when the launcher exists |
| P4 | `dashboard.css:182,231`, `csms-typography.css:154` | Sidebar labels use `overflow-wrap: anywhere` → can split mid-word ("Academic Strand/Course Assignment") | D7, `long-token-wrapping` | M | `overflow-wrap: break-word` for nav labels |
| P5 | `shared/sidebar.php` | Sub-item indentation and active style differ between groups and single items | D7 | M | One indent, one active style |
| P6 | `reports_tab/reports.php:48-58` | Orphan page (not in sidebar): its own copy of the topbar **without** the clock, CIMF and the session-timeout script | `consistency` | M | Flag only; decide whether to retire or switch to `shared/topbar.php` (timeout behavior is a security item — needs your decision) |
| P7 | `subject_mapping.php` | Duplicate year filter (pills + dropdown) | D8 | M | Keep one (needs your choice) |

### States and interaction
| ID | Page / file | Problem | Rule | Sev | Proposed fix |
|---|---|---|---|---|---|
| ST1 | `subject-mapping-final.js:448,475`, `dashboard/account.php:312` | Native `window.confirm()` for destructive actions; other modules use styled modals | `confirmation-dialogs`, `consistency` | L | Use the existing CSMS modal (behavior unchanged) |
| ST2 | 10 JS files | Save feedback mixes toasts, inline status lines and nothing | `success-feedback`, `toast-accessibility` | M | One status/toast style with `aria-live="polite"` |
| ST3 | all CSS | Only 1 `prefers-reduced-motion` rule | `reduced-motion` | L | Global reduced-motion rule |
| ST4 | shared layout | No skip-to-content link | `skip-links` | L | Add to `shared/topbar.php` before the header |
| ST5 | disabled buttons | Disabled style varies (opacity .5/.6/.65, some none) | `disabled-states` | L | Token `--csms-disabled-opacity .5` + `cursor:not-allowed` |

### Out of scope, noted only
- `shared/topbar.php` `$topbarAvatarInitial` and `reports.php:13` `$sess_initial` echo session names; topbar escapes them, `reports.php:56` does not escape `$sess_initial` (first character of first name). Low risk but outside visual scope — reported, not changed.

## Top 15 (proposed Phase 2 priority)
1. **C1** Sidebar section headings 3.47:1 → 6.99:1.
2. **T1** 195 font sizes below 12px → floor at 12px.
3. **C2–C6** ~35 grey text colors under 4.5:1 → one muted token.
4. **C7** Sign-in error text 3.95:1.
5. **C8** Status colors (green/amber/red) under 4.5:1.
6. **F6** Weak focus indicators (`outline:none` + border tint) → shared focus ring.
7. **F3/F4** Sign-in error not announced; inputs lack `aria-invalid`/`required`.
8. **F1/F2** Password show/hide toggle and caps-lock hint on sign-in.
9. **F5/I4** Account page: labels not linked to the password inputs; eye buttons have no accessible name.
10. **T3** Body text 15px → 16px (D6).
11. **P4/P5** Sidebar mid-word wrapping, indentation, single active style (D7).
12. **P2** Compact Program/Curriculum/Version bar (D2–D4) on Prerequisite, Builder, Assignment.
13. **P3** CIMF launcher covering content (D8).
14. **B1–B3 / P1** One button set and one page-header layout with the primary action in the same place.
15. **C9/T2/S1/S2** Token consolidation: 71 blues, 104 sizes, 25 radii, 54 shadows.

## Proposed tokens (`css/csms-tokens.css`)
Values match `design-system/csms-sms2/MASTER.md`. Legacy `--sms2-*` variables stay and are mapped onto these, so nothing that reads them breaks.

```css
:root {
  /* Color — brand */
  --csms-navy: #1a3a8c;          /* sidebar, primary button; white on it 10.36:1 */
  --csms-navy-700: #122a68;      /* hover / pressed */
  --csms-navy-50: #e4eafa;       /* selected row, active tint; navy text on it 8.60:1 */
  --csms-action: #1d4ed8;        /* links/secondary emphasis on white 6.70:1 (replaces #2563eb, #1d5fc7, #2163c5 …) */

  /* Color — neutrals (slate) */
  --csms-text: #1f2937;          /* 13.58:1 on page bg */
  --csms-text-secondary: #374151;
  --csms-text-muted: #4b5563;    /* 6.99:1 — replaces #94a3b8, #888, #999, #7a8699 … */
  --csms-placeholder: #6b7280;   /* 4.83:1, hints only */
  --csms-border: #dfe4ee;
  --csms-border-strong: #cbd5e1;
  --csms-surface: #ffffff;
  --csms-surface-muted: #f4f6fb; /* page background */
  --csms-table-head: #eef1f7;

  /* Color — sidebar */
  --csms-sidebar-bg: var(--csms-navy);
  --csms-sidebar-text: #ffffff;
  --csms-sidebar-heading: #c9d4f2;          /* 6.99:1 on navy (D7) */
  --csms-sidebar-hover: rgba(255,255,255,.10);
  --csms-sidebar-active: rgba(255,255,255,.18);

  /* Color — status (text on tint, all ≥ 6:1) */
  --csms-success: #17653a;  --csms-success-bg: #e3f4ea;
  --csms-warning: #6b4600;  --csms-warning-bg: #fdf1d6;
  --csms-danger:  #8f1d17;  --csms-danger-bg:  #fbe5e3;  --csms-danger-solid: #b3261e;
  --csms-info:    #0b5c8a;  --csms-info-bg:    #e0f0f8;
  --csms-neutral: #374151;  --csms-neutral-bg: #e9ecf2;

  /* Focus */
  --csms-focus-ring: 0 0 0 3px rgba(26,58,140,.45);
  --csms-focus-outline: 2px solid var(--csms-navy);

  /* Type */
  --csms-font: "Segoe UI", system-ui, -apple-system, Roboto, "Helvetica Neue", Arial, sans-serif;
  --csms-font-mono: Consolas, "Cascadia Mono", monospace;
  --csms-text-xs: .75rem;    /* 12px — floor: badges, captions */
  --csms-text-sm: .875rem;   /* 14px — tables, menus, labels */
  --csms-text-md: 1rem;      /* 16px — body (D6) */
  --csms-text-lg: 1.125rem;  /* 18px — card titles */
  --csms-text-xl: 1.375rem;  /* 22px — section titles */
  --csms-text-2xl: 1.75rem;  /* 28px — page title */
  --csms-weight-regular: 400; --csms-weight-medium: 500; --csms-weight-semibold: 600; --csms-weight-bold: 700;
  --csms-leading: 1.5; --csms-leading-tight: 1.25;

  /* Spacing (4px scale) */
  --csms-space-1: 4px;  --csms-space-2: 8px;  --csms-space-3: 12px; --csms-space-4: 16px;
  --csms-space-5: 20px; --csms-space-6: 24px; --csms-space-8: 32px; --csms-space-12: 48px;

  /* Shape and elevation */
  --csms-radius-sm: 6px;  /* inputs, buttons */
  --csms-radius-md: 8px;  /* cards */
  --csms-radius-lg: 12px; /* modals, panels */
  --csms-radius-pill: 999px;
  --csms-shadow-sm: 0 1px 3px rgba(15,23,42,.06);
  --csms-shadow-md: 0 4px 14px rgba(15,23,42,.10);
  --csms-shadow-overlay: 0 12px 32px rgba(15,23,42,.18);

  /* Controls */
  --csms-control-h: 40px; --csms-control-h-sm: 32px;
  --csms-disabled-opacity: .5;

  /* Icons (Font Awesome 6.5.1, solid only) */
  --csms-icon-sm: 14px; --csms-icon-md: 16px; --csms-icon-lg: 20px;

  /* Layers */
  --csms-z-base: 1; --csms-z-sticky: 100; --csms-z-dropdown: 200; --csms-z-topbar: 300;
  --csms-z-overlay: 900; --csms-z-modal: 1000; --csms-z-toast: 1100;

  /* Motion */
  --csms-duration-fast: 120ms; --csms-duration: 200ms; --csms-ease: cubic-bezier(.2,0,0,1);
}
@media (prefers-reduced-motion: reduce) {
  *, *::before, *::after { animation-duration: .01ms !important; transition-duration: .01ms !important; }
}
```
`--csms-action` `#1d4ed8` on white measures 6.70:1.

## Decisions needed before Phase 2
1. **P6** `reports_tab/reports.php` is orphaned with its own topbar lacking the session-timeout script: retire it, or switch it to `shared/topbar.php`?
2. **P7** Subject Mapping year filter: keep the pills or the dropdown?
3. **T4** May the two Google Fonts Inter links be removed (system font only, matches "no CDN additions")? Font Awesome's cdnjs link stays.
4. **T3** Body text 15px → 16px will make every page slightly larger; confirm.
