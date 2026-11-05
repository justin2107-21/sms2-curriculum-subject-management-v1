# Page override: Pre-requisite Configuration (pilot)

Overrides `../MASTER.md` only where stated. Source page: `modules/curriculum/pages/prerequisite_configuration.php` (permission `prerequisite.view`; manage actions gated by `$canManageRequirements`). Draft for user review.

## Goal
Migrate the page to the Bootstrap profile behind the profile switch, with **no change in function**: same permissions, endpoints, data attributes, element IDs and CIMF behavior. Legacy stays the default until the user approves this page.

## Layout (top to bottom)
1. Shell: Bootstrap sidebar (`active_nav` = `prerequisite-configuration`), topbar.
2. Page header: breadcrumb `Curriculum / Pre-requisite Configuration`, h1 "Pre-requisite Configuration", subtitle "Manage subject prerequisite rules and progression requirements." Right-aligned primary action **+ Add Prerequisite** (only if `$canManageRequirements`; stays disabled until a version is chosen, as today).
3. Compact context bar (MASTER §5, D1–D4), directly under the title: Program / Strand → Curriculum → Curriculum Version. Sticky, ~44px, collapses to `BSIT / BSIT-DEMO-2026 / PREORAL-2026` + Change.
4. Tabs (`nav-tabs`, ARIA tablist kept): **Prerequisite Rules** · **Chain Viewer** · **Validation Review**.
5. `#workspaceStatus` live region kept (`role="status"`) directly under the tabs.
6. Tab panels as below.

## Tab: Prerequisite Rules
- One compact filter row: search box (subject code or title) · Year/Grade · Semester · Configured state (All / With / Without prerequisites) · Reset link. No duplicated filters.
- Count next to the heading, e.g. "105 subjects" and the visible/filtered count.
- Rule list `#ruleCards`: a table (14px) grouped by year/semester, columns Subject · Prerequisite(s) · Rule type · Actions. Row click or "View" opens Details; status shown with icon + word badges (MASTER §10). Keep the grouped-rules behavior of the current UI.

## Tab: Chain Viewer
- Card "Prerequisite Chain": left column subject picker (`#focusSearch`) and the note "Only stored prerequisite relationships are shown. No rules are inferred from subject codes." Right column: viewport (`#chainViewport`, `tabindex="0"`, keeps its aria-label) with **Fit to View** and **Reset** buttons, and `#dependents` below.
- The graph canvas keeps its own colors but every node/edge state also needs a text or shape cue (MASTER §12). Arrow colors must meet 3:1.

## Tab: Validation Review
- Count tiles (`#reviewCounts`) as small cards, each with number, label and status icon; issue list (`#reviewIssues`) as a table or list group with severity icon + word. CIMF content stays labelled "AI suggestion · Human review required".

## Modals (Bootstrap modal markup, same IDs and behaviors)
`detailsModal`, `editorModal`, `removeModal` keep their ids, titles, field ids (`targetSearch`, `requiredSearch`, `appendRequired`, `relationshipPreview`, `sequenceWarning`, `ruleForm`, `saveRule`, `confirmRemove`) and the buttons gated by `$canManageRequirements`. Required fields marked `*`; errors in `role="alert"` regions; Save disables while saving; Remove is a danger button with the existing explanatory text ("removes only the prerequisite relationship…"). Focus trapped and returned to the trigger.

## Context bar mapping (open decision for the pilot)
The current JS (`prerequisite-configuration.js`, `curriculum-common.js`, `cimf-prerequisite.js`) is driven by search-autocomplete inputs: `programSearch/programId`, `curriculumSearch/curriculumId`, `versionSearch/versionId`, plus `data-*-api` attributes on `#prerequisiteWorkspace`. Two options; recommend **A**:
- **A (recommended):** keep the same IDs and API contracts; render the three bar fields as compact controls with those IDs and add the D4 enhancements (auto-open next, auto-pick single option, session memory, URL params) in a small new script, leaving the existing business logic untouched.
- **B:** replace with native `<select>` elements and adapt the page JS — larger risk to function; only if A cannot meet D2's 44px height.

## Known risks to resolve while building
- Existing JS and CSS rely on legacy class names (`record-autocomplete`, `curriculum-modal`, `pr-*`, `button-*`). Bootstrap-profile versions need equivalent markup or the JS selectors must be made class-agnostic without changing behavior.
- CIMF panel (`shared/cimf_panel.php`) loads legacy CSS and Font Awesome, so it cannot be included as-is on a Bootstrap page. Need a Bootstrap presentation with unchanged behavior (advisory-only, route token, CSRF), and the floating launcher must not cover content (D8 spacing).
- `curriculum-context.js` session-memory is legacy; the D4 memory has to coexist or replace it without breaking other legacy pages.
- Footer "Bestlink College of the Philippines · CSMS-SMS2 © 2026" exists in the legacy layout; keep as a quiet footer line.

## Page-specific checks (add to MASTER §13)
- [ ] Rules list, filters, chain viewer, validation review, add/edit/remove produce the same results as legacy against the same disposable DB.
- [ ] A user without `prerequisite.manage`-type rights (`$canManageRequirements` false) sees no add/edit/remove controls.
- [ ] Sequence-warning and error `role="alert"` regions still announce.
- [ ] Existing `tests/prerequisite-workspace.cjs`, `prerequisite-service.php` and `prerequisite-http.php` pass (the DB ones against a disposable DB only).
