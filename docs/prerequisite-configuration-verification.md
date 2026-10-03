# Prerequisite configuration — localhost verification

Implemented in the existing PHP module. No schema migration, deployment, commit, push, Subject Master edit, curriculum edit, or placement edit was performed.

## Files changed for this task

- `modules/curriculum/pages/prerequisite_configuration.php`
- `modules/curriculum/assets/prerequisite-configuration.css`
- `modules/curriculum/assets/prerequisite-configuration.js`
- `modules/curriculum/http/requirement_actions.php`
- `modules/curriculum/repositories/CurriculumSubjectRequirementRepository.php`
- `modules/curriculum/services/CurriculumSubjectRequirementService.php`
- `modules/curriculum/services/PrerequisiteGraph.php` (new)
- `tests/prerequisite-service.php` (new)
- `tests/prerequisite-http.php` (new)
- `tests/prerequisite-workspace.cjs` (new)
- `docs/prerequisite-configuration-verification.md` (this report)

Other uncommitted repository changes predated this task and were preserved.

## Implementation and data ownership

The existing requirement repository, service, table, version lock, prepared SQL, graph reachability check, HTTP guards, transactional audit service, shared autocomplete, cascading selectors, centered modal helper, and CSMS layout are reused.

`workspace` returns only the selected version's mapped subjects, stored prerequisite edges, and structural review. `create` adds a single edge; `update_rule` atomically saves a target's entire prerequisite set and checks the expected relationship IDs to reject stale edits; `remove` retains its existing individual-edge API. Unchanged relationship IDs and existing corequisites are preserved. No second prerequisite store exists.

The create and edit paths reject self-reference, duplicates, cycles, and unmapped endpoints. Missing year/grade or semester blocks a relationship save. Later placements require `confirm_sequencing=1`; the UI shows a checkbox explaining the warning. This is not an academic-policy verdict.

Creation uses the existing deterministic reachability traversal: starting at the proposed prerequisite, it follows target-to-required edges and rejects if it reaches the proposed target. Review enumerates directed simple cycles in required-to-target display order, anchoring each at the smallest ID to prevent rotational duplicates. Reverse reachability prunes paths that cannot return to an anchor. Dense malformed graphs with very many distinct cycles can still produce large review results.

The Chain Viewer walks only the selected subject's prerequisite ancestors, groups nodes by dependency depth, draws each stored edge separately, and lists direct dependents below. It does not use curriculum placement or code numbering to invent paths. Cyclic legacy chains are directed to Validation Review. Fit calculates the actual graph bounds, centers the result, and limits shrinking to 80%; large chains remain scrollable. Reset restores the transform and scroll position while keeping context and selected subject.

Review counts come from PHP analysis of actual edges: cycles, duplicate occurrences beyond the first pair, sequencing warnings, and relationships with incomplete endpoints. The success message excludes academic correctness and may coexist with sequencing warnings.

College/SHS labels follow the program classification and actual mapping records. Standard numeric year, grade, and semester labels are compared. Unknown custom labels are not assigned an invented chronological order. All subjects already mapped to the version remain visible, including inactive Subject Master records with existing placements.

## Security and audits

- Reads: `prerequisite.view`.
- Mutations: `prerequisite.manage`, POST only, valid existing CSRF token.
- Cascading program/curriculum APIs retain their existing `program.view` and `curriculum.view` requirements.
- Existing audit names are retained: `SUBJECT_REQUIREMENT_CREATE`, `SUBJECT_REQUIREMENT_UPDATE`, `SUBJECT_REQUIREMENT_REMOVE`.
- Audit metadata includes `prerequisite.created`, `prerequisite.updated`, or `prerequisite.removed`, plus version/target/required identifiers.
- An audit failure rolls back the relationship change.
- Existing foreign keys remain in place; no orphan-generating schema change was made.

## Automated results

Run from `C:\xampp\htdocs\sms-csms`:

```powershell
C:/xampp/php/php.exe tests/prerequisite-service.php
C:/xampp/php/php.exe tests/prerequisite-http.php
node tests/prerequisite-workspace.cjs
node tests/curriculum-cascade.cjs
node tests/subject-mapping-graph.cjs
node tests/subject-mapping-workspace.cjs
C:/xampp/php/php.exe tests/subject-mapping-reads.php
```

- Service: 56 checks passed against connection-local temporary tables, including audit failure rollback and exact permanent-table fingerprints before/after.
- HTTP: 56 checks passed against a randomly named disposable local schema and temporary loopback PHP server, including authenticated page rendering, view/manage RBAC, missing CSRF, GET mutations, add/edit/remove, validation responses, audit events, and PHP error-log checks. Temporary accounts, schema, server, and session files were removed.
- UI interaction scenarios passed with 105 subjects: cascading scope, maximum three suggestions, grouped targets, combined search/year/semester/state filters, details, branching chains, real edges, dependents, Fit/Reset, Add/Edit/Remove confirmation, SHS, sequencing/missing placement indicators, calculated review, and HTML escaping. These are mock-DOM tests, not rendered-browser acceptance.
- Existing cascade, Subject Mapping graph and workspace suites passed.
- Read-only mapping checks: 430 passed across five versions, 55 placements, and zero stored prerequisites.
- Database inspection: 41 foreign keys checked, no reported issues.
- Changed PHP/JS syntax checks passed.

### Existing dataset discrepancy

The legacy `tests/bsit_reads.php` test fails its active-subject manifest assertion. Read-only inspection shows that CC101, CC103, BPM101, and CAP101 already have `inactive` status. Their titles and units match the manifest. The current `BSIT-DEMO-2026` / `PREORAL-2026` version has **52** placements, rather than the old seed's expected 50. The prerequisite workspace reports 52. These records were not changed, deleted, or reseeded.

A repository-wide `git diff --check` also reports an unrelated pre-existing trailing-whitespace line in `modules/curriculum/pages/subjects.php`. This task's changed files pass the scoped whitespace check.

## Exact localhost manual checks

Open `http://localhost/sms-csms/modules/curriculum/pages/prerequisite_configuration.php` while signed in. Hard-refresh once to load the changed assets.

1. Search Program / Strand for `BSIT`. Select it; confirm only matching curricula appear, with no more than three suggestions visible. Choose `BSIT-DEMO-2026`, then `PREORAL-2026`. Confirm the current context labels and actual **52 Subjects** count.
2. Switch through Prerequisite Rules, Chain Viewer, and Validation Review. Confirm all three context selections persist. Initially there are zero stored prerequisite relationships; empty states are expected.
3. In Rules, search a real subject code, then a title fragment. Combine Year, Semester, and With/Without Prerequisites filters. Clear them individually and confirm results recover. Check that each target appears once.
4. Perform mutation acceptance in an existing disposable local curriculum version with at least four fully mapped subjects. Identify those actual mapped subjects as A, B, C, and D below; these letters are test labels, not subject-code inference. Do not edit real subject identities or placements just to run a scenario.
5. Add A as required before C. Confirm both searches offer at most three mapped subjects, the preview reads required subject → target, and Save creates a single rule. Click the full C card and confirm its centered details modal.
6. Edit C: keep its target read-only, add B, and save. Confirm one C card with two prerequisites. Confirm both A and B details list C as a direct dependent.
7. Add C before D. Select D in Chain Viewer. Confirm A and B branch into C and then D, with exactly the relationships you saved. Confirm A/B are not linked to each other. Click a node to inspect details.
8. Use Fit to View, scroll horizontally/vertically where necessary, and use Reset. Confirm readable nodes and unchanged context/focused subject. Select an unconfigured subject and inspect its empty state and Add action.
9. Try A before A, repeat A before C, and attempt D before A. Confirm the exact self-reference, duplicate, and circular-chain errors, with no saved invalid edge. Also test a direct reverse cycle in the disposable context.
10. Edit C, remove one required subject, and cancel its confirmation; confirm the editor returns unchanged. Confirm removal, then Save, and verify only that edge is removed. Use the card's Remove action to inspect and cancel removal of the entire target rule before confirming it. Verify subjects and mappings remain.
11. If the disposable context already includes suitable placements, choose a later required placement before an earlier target. Confirm the warning and explicit checkbox, then save. Confirm Sequencing Warnings increases and shows both placements. Do not change real placements merely for this test.
12. With an existing incomplete test placement, attempt Add/Edit and confirm the missing-placement message and Review Subject Mapping link. Validation Review detects stored relationships made incomplete by a later legitimate mapping edit; it does not label every unrelated unmapped/no-rule subject as an issue. The automated fixture covers this case without altering real records.
13. Inspect Validation Review counts and affected rule/mapping actions. Normal creation must never produce cycles or duplicates; malformed legacy cycle/duplicate detection is covered by isolated fixtures, so do not corrupt the normal database to reproduce it.
14. Select an existing SHS program/version. Confirm Grade 11/12 filters and stored semester values; repeat a scoped search and switch tabs.
15. With a view-only authorized account, confirm rules and chain/review are visible but mutation buttons are absent. With browser developer tools, verify forged mutations are rejected, invalid/missing CSRF is rejected, and mutation GET requests are rejected. The automated HTTP suite already verifies these boundaries.
16. Check audit records after your intentional test additions, edits, and removals. Confirm the event metadata and IDs. Remove only the prerequisite edges you intentionally created for acceptance.
17. Check desktop, tablet, and mobile widths: wrapping filters, single-column mobile cards, centered near-full-width modals, scrollable long lists and chains, keyboard suggestions, Escape, tab switching, focus return, and visible focus styles. Check the browser console and Network panel for errors.
18. Open Subject Mapping's relationship visualization for the same disposable version and confirm it reads the same stored edges. Refresh after edits. Verify Subject Master, curriculum context, and placement counts remain unchanged.

## Remaining visual acceptance

The three Manus reference screenshots were not attached during this implementation. The interface follows the supplied textual layout, but exact screenshot proportions have not been verified. No browser was connected to this session, so rendered desktop/mobile appearance and browser-console checks remain manual. Authenticated PHP page rendering and server error logs were checked through the isolated HTTP suite.
