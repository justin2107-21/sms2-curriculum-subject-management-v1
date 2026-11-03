# CSMS-SMS2 — UI/UX Decisions (approved by the user, 2026-11-03)

Status: approved direction for the `ui-redesign-pilot` branch. Nothing below is implemented yet.
Design authority: Claude designs the UI/UX using the `ui-ux-pro-max` skill within these rules. The Manus AI design is optional reference only; do not wait for it.
Stack: plain PHP + Bootstrap 5.3 (custom Sass build, compiled CSS committed), vanilla JS, Bootstrap Icons. No React, Tailwind or shadcn.

## D1. Program / Curriculum / Version selector stays on the page
- It is NOT in the header. The header holds only global items: menu toggle, clock, user, notifications.
- On pages that need it (Subject Mapping, Pre-requisite Configuration, and any page that depends on a curriculum version), it sits directly under the page title.
- Dashboard may show a small read-only context line (for example "BSIT · College/CHED"), not a selector.

## D2. Compact single-row bar
- About 44px high (the current one is about 125px). Small labels beside the fields.
- Pinned under the header while the page scrolls.
- One "Reset" link replaces the three × buttons.

## D3. Collapsed summary after choosing
- Once all three are chosen, the bar collapses to one line: `BSIT / BSIT-DEMO-2026 / PREORAL-2026` with a "Change" link.

## D4. Keep the cascading selection
- Each choice narrows the next list to what belongs to it (the current system's best behavior).
- Add: open the next dropdown automatically; auto-pick when only one option exists; remember the last choice for the session so related pages open on the same context; put the selection in the URL so a page can be linked.

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

## D9. Session idle timeout: 5 minutes, with a 60-second warning (approved 2026-11-03)
- Replaces the open "3 vs 5 minutes" question. Idle timeout is **300 seconds**.
- **One source of truth:** `CSMS_IDLE_TIMEOUT_SECONDS` in `shared/auth.php`, with the warning window `CSMS_IDLE_WARNING_SECONDS = 60` next to it. The topbar (and the Bootstrap layout) pass both to `js/session-timeout.js` through `data-timeout-seconds` and `data-warning-seconds`; the script has no numbers of its own. Warning time = timeout − window = **240 s**.
- **Server stays authoritative:** a request after 300 s idle is signed out and redirected to sign-in even with JavaScript off. The warning is display only.
- **Warning modal** (native `<dialog>`, `role="alertdialog"`, focus trapped, background inert): "You'll be signed out in N seconds due to inactivity", a per-second countdown, **Stay signed in** (primary, focused) and **Sign out**. Screen readers get the message on open and announcements at 30 s and 10 s, not every tick. Escape means "Stay signed in" (it never signs out or silently hides the warning).
- **Stay signed in** POSTs `action=activity` to `shared/auth_actions.php`, the existing keep-alive: POST only, CSRF-checked, authenticated, refreshes the server's last-activity time, answers 401 once the session has expired (the client then goes to sign-in). No new endpoint. If the request fails (network, 403) the modal shows an error, the countdown continues and the user can retry or sign out.
- While the warning is open, ordinary mouse/keyboard activity is ignored; only the buttons, or activity in another tab of the same user, dismiss it.
- The keep-alive heartbeat now has a trailing call, so the server's clock is never more than a moment behind the browser's. Without it the "60 seconds" could be up to 30 s too long.
- Tests: `tests/session-timeout-config.php` (DB-free) and the logged-in idle checks (`tools/uitest/run.ps1 -IdleSeconds 300 [-Stay]`).

## Constraints that still apply
- Functionality is never sacrificed. No page may load both legacy and Bootstrap CSS. Migrate page by page; pilot page = Pre-requisite Configuration. The legacy UI stays the default until a page is approved.
- UI UX Pro Max output is advisory. Its auto design system for this project (dark glassmorphism, green accent) was rejected as a poor fit.
- Do not touch auth, authorization, CSRF, CIMF behavior, the database, migrations or permissions as part of UI work.
