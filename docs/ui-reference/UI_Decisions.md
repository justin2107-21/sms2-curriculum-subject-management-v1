# CSMS-SMS2 — UI/UX Decisions (approved by the user, 2026-11-03)

Status: approved direction for the `ui-redesign-pilot` branch. Nothing below is implemented yet.
Design authority: Claude designs the UI/UX using the `ui-ux-pro-max` skill within these rules. The Manus AI design is optional reference only; do not wait for it.
Stack: plain PHP + Bootstrap 5.3 (custom Sass build, compiled CSS committed), vanilla JS, Bootstrap Icons. No React, Tailwind or shadcn.

## D1. Program / Curriculum / Version selector stays on the page
- It is NOT in the header. The header holds only global items: menu toggle, clock, user, notifications.
- On pages that need it (Subject Mapping, Pre-requisite Configuration, and any page that depends on a curriculum version), it sits directly under the page title.
- Dashboard may show a small read-only context line (for example "BSIT · College/CHED"), not a selector.

## D2. Program / Curriculum / Version picker (revised 2026-11-03; replaces the compact bar)
- Three select-like fields in one row under the page header, left-aligned: **label above** each field ("Program", "Curriculum", "Version", 13 px semibold), the field about **40 px** high, white, 1 px border, 8 px radius, value text, chevron on the right, the standard keyboard focus ring. Fields 240 px wide (not full width), a small "›" between them. On phones each field is full width.
- Clicking a field opens a **searchable panel** (the `csms-program-picker.js` pattern): search box, result count, grouped options, arrow keys / Enter / Escape, ARIA combobox and listbox. Program options are grouped College / Senior High; Version options show the status ("PREORAL-2026 · Draft", "FINAL · Active"), active first.
- **Recent:** the Program panel starts with a "Recent" group of the user's last 3 complete contexts (`BSIT / BSIT-DEMO-2026 / PREORAL-2026 · Draft`); choosing one applies all three at once. A recent context whose version is gone is refused and forgotten.
- Choosing a program opens Curriculum, then Version; a list with a single option is picked automatically.
- One small **Reset** link (clears the context everywhere). When no version is chosen the row shows "Choose a curriculum version to begin".
- Not pinned (confirmed 2026-11-04): with labels above the fields the row is about 60 px tall, so it scrolls with the page. (The earlier 44 px pinned bar is replaced.)
- Built by `js/csms-context-bar.js` + `css/csms-components.css` on Subject Mapping, Pre-requisite Configuration, Electives Manager, Curriculum Export and Grade Weighting Setup. The page's own autocomplete cascade stays underneath as the engine and the original inputs stay in the page (hidden), so every page keeps its own permission-checked lookups, handlers and IDs. Curriculum Builder's program and curriculum screens do not use this context (they pick by card), and Validate Curriculum uses plain selects, so they are not converted.

## D3. Collapsed summary after choosing: removed (2026-11-03)
- The one-line `BSIT / BSIT-DEMO-2026 / PREORAL-2026` summary with a "Change" link is gone. The picker stays as the same row once all three are chosen; the fields themselves show the choice.

## D4. Keep the cascading selection, and one working context across modules
- Each choice narrows the next list to what belongs to it. The next list opens automatically; a single option is picked automatically.
- **One working context** is shared by every module page. Any change on any page is saved and applies to all of them, and is always written to the address (`?program_id=&curriculum_id=&version_id=`) so links work.
- **Resolution order on page load:** (a) ids in the address; (b) the user's saved working context; (c) the version the user last edited: newest audit entry by this user that changed version content (placements, prerequisites/corequisites, electives, grade weighting, version create/clone; viewing and exporting do not count); (d) the newest active version by updated_at among programs the user may access, tie-break by program code; (e) otherwise an empty picker with "Choose a curriculum version to begin".
- **Permitted** means the user holds that module's view permission and the version exists in a consistent program > curriculum > version chain. The application has no per-program access rules beyond that. An invalid item fails its rule and the next one is tried.
- **Storage:** per user on the server (`user_preferences`, migration 026; `modules/curriculum/http/working_context_actions.php`): authenticated, CSRF on save, no extra RBAC permission, saves not audit-logged, integers only. `sessionStorage` is the fallback if the request fails.

## D5. Manus design (optional reference): what to take and what to leave
| Take | Leave |
|---|---|
| Breadcrumb above the title | Green sidebar (keep BCP navy) |
| Sidebar groups: Workspace, Curriculum, Setup | Serif headings (one sans font) |
| White cards with clear titles and a small link-out icon | Number badges (01 to 05) on menu items |
| Quiet, airy spacing | "Developer demo" box in the sidebar |
| Search with keyboard shortcut (later phase) | Three dropdowns in the header |
| | Prototype and "synthetic data" banners |

**D5 override (approved by the user, 2026-11-03):** the "one sans font, no serif headings" rule is replaced, starting with the **dashboard only**: **DM Sans** for the interface and **Newsreader** (serif) for page and card titles. Both are self-hosted (variable woff2 or ttf) under `assets/ui/dist/fonts/` with their SIL Open Font Licence files; no Google Fonts or other CDN link. Other pages keep the current system font until the user extends the override.

**D5 extension (approved 2026-11-03, Batch 5):** the **page title (h1) uses Newsreader 700 on every page**, same size and position as the dashboard title, so headers match everywhere. The face is declared once in `css/csms-fonts.css` (loaded by `shared/topbar.php`). The interface font on other pages stays the system font; DM Sans remains dashboard-only.

## D6. Text size floor
- Body 16px, tables and menus 14px, nothing below 12px. Contrast at least 4.5:1.

## D7. Current-sidebar fixes to include
- "Academic Strand/Course Assignment" must not break mid-word.
- Raise the contrast of section headings (MAIN, CURRICULUM & SUBJECT MANAGEMENT); measure it.
- Consistent indentation of sub-items and one active-item style everywhere.

## D8. Smaller page items (Subject Mapping)
- Remove the duplicate year filter (keep the pills or the dropdown, not both).
- Show "52 placements" next to the heading and unit totals.
- Reserve space for the floating CIMF button so it does not cover content.
- **Update 2026-11-05:** CIMF launcher overlap accepted temporarily; CIMF button will be redesigned later. (A reserved strip beside the dashboard was tried and reverted; page margins stay equal on both sides. The bottom padding that clears the launcher at the end of the page stays.)

## D9. Session idle timeout: 5 minutes, with a 60-second warning (approved 2026-11-03)
- Replaces the open "3 vs 5 minutes" question. Idle timeout is **300 seconds**.
- **One source of truth:** `CSMS_IDLE_TIMEOUT_SECONDS` in `shared/auth.php`, with the warning window `CSMS_IDLE_WARNING_SECONDS = 60` next to it. The topbar (and the Bootstrap layout) pass both to `js/session-timeout.js` through `data-timeout-seconds` and `data-warning-seconds`; the script has no numbers of its own. Warning time = timeout − window = **240 s**.
- **Server stays authoritative:** a request after 300 s idle is signed out and redirected to sign-in even with JavaScript off. The warning is display only.
- **Warning modal** (native `<dialog>`, `role="alertdialog"`, focus trapped, background inert): "You'll be signed out in N seconds due to inactivity", a per-second countdown, **Stay signed in** (primary, focused) and **Sign out**. Screen readers get the message on open and announcements at 30 s and 10 s, not every tick. Escape means "Stay signed in" (it never signs out or silently hides the warning).
- **Stay signed in** POSTs `action=activity` to `shared/auth_actions.php`, the existing keep-alive: POST only, CSRF-checked, authenticated, refreshes the server's last-activity time, answers 401 once the session has expired (the client then goes to sign-in). No new endpoint. If the request fails (network, 403) the modal shows an error, the countdown continues and the user can retry or sign out.
- While the warning is open, ordinary mouse/keyboard activity is ignored; only the buttons, or activity in another tab of the same user, dismiss it.
- The keep-alive heartbeat now has a trailing call, so the server's clock is never more than a moment behind the browser's. Without it the "60 seconds" could be up to 30 s too long.
- Tests: `tests/session-timeout-config.php` (DB-free) and the logged-in idle checks (`tools/uitest/run.ps1 -IdleSeconds 300 [-Stay]`).

## D10. Program filter placement on dashboards (approved 2026-11-05)
- **Most cards are program-scoped** (Admin, Staff, Acad Office, Acad Faculty): keep the page-level Program / Strand filter in the page header.
- **Only one card is program-scoped** (Super Admin: Curriculum pipeline by program): no page-level filter. A compact filter sits inside that card's header, right of the title: a small button showing "All programs ▾" that opens the same searchable panel (search, All / College / Senior High, count). It filters only that card. The URL parameter `?program=` is kept.
- The server enforces it: for Super Admin the program filter narrows the pipeline only; the other cards, including Awaiting review, count all programs. Their "View in module" links carry no program. Test: `tests/dashboard-role-payload.php`.
- Rule of thumb for a new profile: count its program-scoped cards; several means page-level, one means card-level.

## Constraints that still apply
- Functionality is never sacrificed. No page may load both legacy and Bootstrap CSS. Migrate page by page; pilot page = Pre-requisite Configuration. The legacy UI stays the default until a page is approved.
- UI UX Pro Max output is advisory. Its auto design system for this project (dark glassmorphism, green accent) was rejected as a poor fit.
- Do not touch auth, authorization, CSRF, CIMF behavior, the database, migrations or permissions as part of UI work.
