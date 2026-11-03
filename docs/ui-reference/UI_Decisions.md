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

## Constraints that still apply
- Functionality is never sacrificed. No page may load both legacy and Bootstrap CSS. Migrate page by page; pilot page = Pre-requisite Configuration. The legacy UI stays the default until a page is approved.
- UI UX Pro Max output is advisory. Its auto design system for this project (dark glassmorphism, green accent) was rejected as a poor fit.
- Do not touch auth, authorization, CSRF, CIMF behavior, the database, migrations or permissions as part of UI work.
