# CHED/DepEd Compliance Validator: slice 0 (read-only audit and plan)

Status: **plan only. Nothing is built; no code, schema or data was changed.** Written 2026-11-12, branch `ui-redesign-pilot`. Module name is fixed: **CHED/DepEd Compliance Validator**.
Evidence: code read (file:line below); `sms_db_uitest` read-only; `sms_db` counts only. Item 11 (running two suites on a disposable database) is the one action beyond reading; see section 12. The regulatory PDFs are not in the repository: section 10 uses only the summary and the verified rule pack supplied with the request, labelled STATED / INTERPRETED. Anything not checkable here is marked **UNVERIFIED**.

## 1. The page today

**Files**
- `pages/compliance_validator.php` (280 lines) is the real page; `validate_curriculum.php`, `compliance_rules.php`, `compliance_references.php`, `validation_history.php` are 3-line stubs that set `$complianceView` (`validate` / `rules` / `references` / `history`) and include it; opened without a view it redirects (302) to `validate_curriculum.php` (`compliance_validator.php:1-17`).
- Script `assets/compliance-validator.js` (676 lines), styles `assets/compliance-validator.css` (117), view switcher `pages/_module_views.php:6` (`csms-segmented` links under the title).
- Endpoint `http/compliance_actions.php:15-98`; services `ComplianceValidationService` (274 lines), `ComplianceRuleService` (163), `ComplianceReferenceService` (181); repositories `ComplianceValidationRepository`, `ComplianceRuleRepository`, `ComplianceReferenceRepository`; validators `DomainValidator.php:522-615, 759-775`.
- Tables: `compliance_references` and `compliance_rules`, `compliance_validation_runs`, `compliance_validation_results` (migration `011_create_compliance_validator.sql:9, 38, 95, 115`), `compliance_reference_evidence_events` and `curriculum_version_references` (migration 022, `:19`, plus `source_kind` / `supersedes_reference_id` added to references).

**Endpoints** (`compliance_actions.php`): GET `program_options`, `curriculum_options`, `version_options`, `subject_options`, `reference_list`, `reference_get`, `rule_list`, `rule_get`, `history_list`, `run_get` (`compliance.view`); POST `reference_create`, `reference_update`, `rule_create`, `rule_update` (`compliance.manage`), `reference_evidence` (`compliance.reference.verify`), `validation_run` (`compliance.run`). All POSTs go through `curriculum_require_mutation` (CSRF, `support.php:22-28`).

**Four screens, four titles** (`compliance_validator.php:22-35, 94-101`): "Validate Curriculum", "Compliance Rules", "References", "Validation History", each with its own subtitle. The module name "CHED/DepEd Compliance Validator" is never a page title (it appears only in the sidebar and the curriculum documents). A pill "Deterministic checks" and a standing note "Results are internal decision-support output only. They are not official CHED, DepEd, or Bestlink College approval, certification, or legal advice." (`:100-107`) sit under every title.
- *Validate:* a card "Select a curriculum version" with **three native `<select>`s** (Academic Program "Loading programs...", Curriculum, Curriculum Version) and a disabled "Run Validation" button, then a "Validation Results" card whose first state is a one-row table "Select a curriculum version to run configured checks." and a hidden summary (Rules Evaluated / **Pass / Fail / Not Evaluated**). No shared Program > Curriculum > Version bar.
- *Rules / References / History:* old `list-toolbar` + `domain-table` lists with native selects (type, status, rows), create / edit dialogs (`curriculum-modal` + `domain-form`), a run-detail dialog.
- **Old components:** the page contains no shared `csms-*` component class (`csms-filter-row`, `csms-select`, `csms-ctable`, `csms-chip`, `csms-info`, `csms-empty`); it uses `domain-card`, `domain-table`, `mapping-filter-card`, `read-only-pill`, `curriculum-notice`, own `compliance-*` classes. Only the switcher is shared.
- **Blank first states** on every tab (empty table rows).
- Wording that could read as a verdict: the reference field "Internal Compliance Status" (Draft / Verified / Inactive; `:195`) and the run message "Validation completed using active rules from verified references."

## 2. What the validator really does

- **Rules are data rows** (`compliance_rules`), entered by a user; **no rule is defined in code**. The code knows five rule *types* (`chk_compliance_rule_type`, `011...sql:57`): `MIN_TOTAL_UNITS`, `MAX_TOTAL_UNITS` (sum of units of every mapped placement of the version), `REQUIRED_SUBJECT` (a chosen Subject Master record must be placed), `MIN_SUBJECT_UNITS`, `MAX_SUBJECT_UNITS` (a chosen subject's units). A rule has one number, optionally one subject, and applies to **one program or one curriculum** (`chk_compliance_rule_scope`, `:64`), status active / inactive.
- **Which rules run:** `eligibleForContext` (`ComplianceRuleRepository.php:124-139`): active rule whose reference is `verified` and whose program or curriculum matches. No date, no version, no level, no source section.
- **Computation:** deterministic PHP on stored placements and subject units, decimal arithmetic in hundredths (`ComplianceValidationService.php:158-231, 246-255`). Nothing is typed by hand at run time; the thresholds were typed when the rule was created. Statuses stored: **PASS / FAIL / NOT_EVALUATED** (`chk_compliance_result_status`, `:131`); NOT_EVALUATED only for a subject-unit rule whose subject is not placed. Labels on screen: Pass / Fail / Not Evaluated.
- **A run** (`ComplianceValidationService.php:71-139`): one transaction creates a run row (counts), one result row per rule with snapshots (rule name, type, reference identifier and URL, requirement text, actual value, status, explanation), and an audit row `COMPLIANCE_VALIDATION_RUN`; with no eligible rule it stores nothing and says so. Runs and results are append-only; a rule whose semantic fields would change after it has history is refused (`ComplianceRuleService.php:92, 138`).
- **Verdict:** there is **no overall "compliant" verdict** in code or UI; the page shows counts only. The risk is wording (section 1).
- **Demo rules:** the synthetic `DEV-DEMO-REFERENCE` and its 5 rules (names `DEV-DEMO ...`, thresholds 1.00 / 9.00, note "NOT A REAL CHED, DEPED, OR BESTLINK POLICY") come from `database/seeds/development_seed.sql:102-121`. The seed inserts that reference **already `verified`**, bypassing the evidence rule that the UI enforces. **Nothing on the page labels or hides demo rows** (no "demo" handling in the page, script or services): they are identified only by their code.

## 3. Reference management and evidence

- **References** (`compliance_references`): type (CHED / DEPED / INSTITUTIONAL / OTHER), `source_kind` (regulatory document, CHED sample curriculum, institutional curriculum / policy, supporting / other document, unknown), code, title, issuing body, source URL (http/https only, `DomainValidator.php:538-546`), effective date, notes, verification status (draft / verified / inactive), `supersedes_reference_id` (immutable once set, `ComplianceReferenceService.php:92-94`). Provenance fields present: issuance (code / title), authority (issuing body), year (effective date), URL. **Not present:** section, PDF / printed page, document label (stated / interpreted).
- **Verification flow:** a reference is created as Draft; "verified" can only be reached through a recorded `document_identity` evidence event with result `confirmed` that matches the current source details (`:36-39, 95-100, 130-160`). Evidence events (`compliance_reference_evidence_events`) store subject (document identity / declared origin / applicability), result (confirmed / not confirmed / not assessed), snapshots of the source fields, **`evidence_locator` (free text, 1,000 characters) and `evidence_sha256` (64 hex characters typed or pasted)**, rationale, recorder, time. The audit row states `institutional_adoption: not_assessed`.
- **Uploads: none.** No `$_FILES` / `move_uploaded_file` in the module; no storage location, size or type limit, hashing or access control exists because no file is accepted. The "evidence" is a pointer and a user-supplied fingerprint, not a stored document. The 2026-11 CHED PDFs live outside the repository.
- **Links to versions:** `curriculum_version_references` (version, reference, purpose, snapshots of the reference at attach time) is used by the Builder, not by the validator.

## 4. Consumers

| Consumer | Reads | Verdict wording |
|---|---|---|
| Curriculum Builder | the active `MIN_TOTAL_UNITS` rule of the curriculum or program as the "target units" (`CurriculumVersionService.php:48-53`), **without requiring a verified reference** (the validator does require it: inconsistent) | a target, not a verdict |
| Dashboard | last run per version (failed count -> "Failing checks", "needs attention", "Compliance of versions under review" bars) and active `MIN_TOTAL_UNITS` rules (`DashboardAnalyticsRepository.php:175-216, 314-352, 515, 544`; `DashboardAnalyticsService.php:188-196, 253, 395`) | counts "failed validator checks"; no "compliant" |
| Curriculum Export | optional `include_compliance`: the latest run counts, run by, date (`CurriculumExportRepository.php:228-246`, `CurriculumExportService.php:186, 405`) | a disclaimer line says it is not official CHED / DepEd / accreditation output |
| CIMF | one run's results, or one rule, or one reference (section 6) | "No official CHED or DepEd approval may be concluded" |
| Offering History | nothing | -- |

No screen displays "compliant". The strongest words are "Pass", "Failing checks" and the reference status "Verified".

## 5. Permissions, read scope, audit, CSRF

| Action | Permission | Roles holding it (`sms_db_uitest`) |
|---|---|---|
| read everything | `compliance.view` | acad_office, academic_curriculum_staff, admin, department_head_curriculum_reviewer, faculty_academic_viewer, legacy_admin, staff, super_admin, system_administrator |
| run validation | `compliance.run` | acad_office, academic_curriculum_staff, department_head_curriculum_reviewer, legacy_admin, staff, super_admin, system_administrator |
| create / edit references and rules | `compliance.manage` | legacy_admin, super_admin, system_administrator |
| record evidence | `compliance.reference.verify` | acad_office, super_admin |

- **CSRF:** every POST (`support.php:22-28`). **Audit:** `COMPLIANCE_REFERENCE_CREATE / UPDATE / EVIDENCE`, `COMPLIANCE_RULE_CREATE / UPDATE`, `COMPLIANCE_VALIDATION_RUN`, all inside the transaction.
- **Read scope** (`CurriculumReadScope::enforceInput` looks at version-like request keys, `CurriculumReadScope.php:47-66`): `validation_run` is scoped (it carries `curriculum_version_id`). **Gaps (code reading, not exercised):** `run_get` takes a run id, so a restricted reader can fetch the counts and result texts of any run; `history_list` without a version filter lists every run across versions; `version_options` lists versions of a curriculum without the scope (same as other modules). A reference or rule edit also takes the edit-policy locks of every version it is linked to (`CurriculumVersionEditPolicy.php:187-194`), so editing a source can be refused when a linked version is protected.

## 6. CIMF (advice only)

- Profile `compliance_validator` (actions Explain result / finding / rule / reference, Summarize review items); route `global_cimf_actions.php` (`compliance.view`). It needs a stored run, rule or reference: "CIMF does not run or change validation." (`CIMFGlobalContextBuilder.php:231-244`), and states "Based on configured validation rules only. No official CHED or DepEd approval may be concluded."
- The prompt forbids "regulatory compliance, official approval" (`CIMFPromptBuilder.php:23`); the response validator rejects "CHED/DepEd approved / compliant" and "I approve / saved / updated" (`CIMFResponseValidator.php:176`). No path writes validation data (only the `cimf_ai_logs` usage row).
- Defect: the context builder picks fields that do not exist on the run and rule records (`needs_review_count`, `authority`, `requirement_value`), so those facts are silently empty.

## 7. Data (counts only)

| | `sms_db_uitest` | `sms_db` |
|---|---|---|
| references (verified / draft) | 1 (1 / 0) | 0 |
| rules (active) | 1 (1) | 0 |
| runs / results | 0 / 0 | 0 / 0 |
| evidence events | 0 | 0 |
| curriculum-reference links | 0 | 0 |

The `sms_db_uitest` reference and rule are a UITEST fixture (a minimum-units rule), **not** the DEV-DEMO set: **no DEV-DEMO rows exist in either database** (the demo seed was not applied, or was cleaned up). Nothing in the application hides demo rows (section 2).

## 8. Scale

Lists are server-side and paged: references, rules and history use `DomainValidator::listQuery` (page sizes 10 / 20 / 50) and ranked search (`ComplianceReferenceRepository.php:57`, `ComplianceRuleRepository.php:70`, `ComplianceValidationRepository.php:161-206`). A run loads all eligible rules of the program or curriculum in one query (tens). The `history` query joins runs to programs, curricula and versions and orders by date; fine at thousands of runs; results are fetched per run. No scale test was run (read-only slice).

## 9. What can be computed from data we already store (no new tables)

Stored per placement: year level, semester, sequence, `is_required`, subject units / lecture units / laboratory units; per version: elective groups (`required_selections` is a count of subjects to pick, no units); per subject: code, title, status; per program: `program_type` ("Bachelor's Degree" or "Senior High School"; no BSIT vs COE distinction); grade weighting schemes (total = 100 enforced); prerequisites (single required subject per row).

| Check | From stored data? |
|---|---|
| total units min / max of a version | **Yes** (exists). Note it sums every placement, electives included |
| units of required-flag placements; units per term; subject count | **Yes**, new rule types, no new table |
| a named subject is placed; a named subject's units | **Yes** (exists) |
| elective group count and `required_selections` | **Yes** (counts only) |
| every subject has a complete weighting scheme | **Yes** (counts; no weight values from any regulation) |
| units per category (GE, common, professional, electives, additional math) | **No.** There is no subject category field. Guessing from codes is not allowed |
| PE (8 units), NSTP (6 units) | **Partly.** Only if the client names the subjects; a rule over a *set* of subjects needs a link table (new table) |
| SHS hours (2,000 / 2,080 total, elective hours) | **No.** Subjects hold units, not hours |
| SHS subject counts (17-20, 8-9) | **Yes** as placement counts, **if** BCP offers SHS and the version represents the whole program |
| any-of / N-of-M elective prerequisite groups | **No.** The prerequisite model holds single required subjects |
| component weights per SHS subject group | **No.** Subject groups are not stored (weights are per subject) |
| passing grade 75, final grade as an average | not curriculum data: informational only |

## 10. Sources: STATED vs INTERPRETED (rule pack verified from the Project PDFs on 2026-11-12; page = PDF page index)

Corrections applied: DO 017 Table 10 (Tech-Pro Track) is **2,080 hours total and at least 1,280 elective hours**; 2,000 / 1,200 is Table 9, Academic Track only. DO 015 Table 10 is the SHS weight table (WWs / PTs / EXs) and is a different table from DM 074 par. 7.

| Id | Source | Content | Label | Computable (section 9) |
|---|---|---|---|---|
| C1 | CMO 25 s.2015 Art. V Sec. 8.1 (p.10) | BSIT minimum total 146 units (COE 134) | STATED | total units: yes; BSIT vs COE needs a per-program choice |
| C2 | same table | GE 54, common 18, professional 48, professional electives 12, additional math 0, PE 8, NSTP 6 (sum 146) | STATED; **GE figure conflicts with CMO 20: show both, never auto-fail** | per-category: no |
| C3 | CMO 20 Advisory (9 Dec 2019) | 24 units GE core + 9 units GE electives from AY 2019-2020 (so 33; CMO 25 Sec 23, cited via the summary, gives a minimum of 36 from AY 2018-2019; three GE figures: 54 / 36 / 33) | STATED, conflicting | no |
| C4 | CMO 39 s.2021 Sec. 6.1, 6.7, 6.9 (pp.6-7) | PE = 8 units; PE 1-2 foundation for PE 3-4; no substitution; graduation requirement | STATED; modelling INTERPRETED | needs named subjects |
| C5 | RA 9163 Sec. 4-6 | NSTP required for graduation; two semesters per component (6 units per CMO 25) | STATED | needs named subjects |
| S1 | DO 017 s.2026 Table 9 (p.16) | Academic Track: 800 h core, at least 1,200 h electives, 2,000 h total, 17-20 subjects, weekly load at most 40 h | STATED | hours: no; counts: yes |
| S2 | DO 017 Table 10 (p.17) | Tech-Pro: 800 h core, at least 1,280 h electives, 2,080 h total, 8-9 subjects | STATED | same |
| S3 | DO 015 s.2026 Table 10 (p.14) | component weights per subject group, each row totals 100 | STATED; advisory template only, never a default | groups not stored: no |
| S4 | DO 015 par. 4, 6 | non-DepEd schools are encouraged, not required; modifications need regional-office approval | STATED | informational |
| S5 | DO 015 par. 48-50, 52 | pass 75; Final Grade = average of term grades; zero-based grading from SY 2027-2028 | STATED; informational, needs valid-from / valid-to | informational |

**SHS rules apply only if BCP offers SHS (OPEN).** Status of every SHS rule: **pending BCP confirmation**. Not on the DM 036 Annex D Grade 12 pilot list is an INTERPRETED reading.

**Cross-check with `docs/source-data/ched-cmo25-2015-bsit.json` and its README (item a; item 10).**
- The package is a **visual transcription of CMO 25 Sections 9.1 / 9.2 only** (57-page scan, `status: source_transcribed_with_unresolved_cross_table_conflicts`, `importability.eligible: false`, "Institutional adoption/approval not verified", authenticity not independently validated). It holds: 27 component rows (Section 9.1, pp.16-18: 21 `required`, 6 `recommended_elective`, knowledge-area codes, units, page and confidence per row), 24 schedule cells (Section 9.2, pp.20-21, nine terms including a 3rd-year summer), and the 13 Section 6.3 graduate outcomes. Totals: known units 81 (66 required + 15 elective), stated to be "not a program total".
- **It does not contain Section 8.1 at all** (no 146, no GE 54, no PE, NSTP or category rows; a text search of the file finds none). **C1 and C2 are therefore UNVERIFIED against the repository data**: the dataset can neither confirm nor contradict them, and its 66 + 15 units of Section 9.1 cannot be reconciled with "professional 48 + professional electives 12" from the data alone (UNVERIFIED how the two sections relate). CMO 20, the 2019 GE advisory and CMO 39 are listed as `local_source_inspected: false`, so C3 and C4 have no repository evidence either.
- **Known conflicts inside the dataset:** `IM101` has different titles in the two tables; `PF101` has different titles; Section 9.1 lists `IPT102` where the schedule shows `IPT101`; `HCI102` has blank units and no scheduled row. The README states import is disabled because the schema cannot hold a conflicting code or a blank unit value.
- **Rule inputs it could feed (once reconciled):** subject codes and units, required vs recommended elective, knowledge area, a sample year / semester placement. Not rule inputs: it has no category totals, no hours, no weights.

**DO NOT ACTIVATE** (as listed in the request): DepEd "unit system" values as credit units; DM 074 or DO 015 weights as defaults; DO 015 adjusted transmutation (raw 70 to 75) as a standing rule (SY 2026-27 transition only, needs valid-from / valid-to); KS4 General Average computation (separate issuance not in the Project); DM 036 Grade 12 pilot tables (2,080 hours) for schools not on Annex D, including BCP; DO 015 par. 67(d) as a curriculum rule (student-record rule); NSTP GPA exclusion; NSTP 1 to NSTP 2 prerequisite; elective rules using the panel's "Mandatory / Free" terms; anything from sample annexes or "recommended" lists. **BCP-specific policy is not confirmed by the client (AA)**: nothing BCP-specific may be hardcoded, and no page may say "official BCP policy" or "compliant". The elective prerequisite data of DO 017 / DM 036 Annex A is **blocked** (the prerequisite model cannot hold any-of / N-of-M groups).

## 11. Proposed design

**One page titled "CHED/DepEd Compliance Validator"**, shared shell (same as Offering History / Equivalency / Grade Weighting), a `csms-info` next to the title ("Rules come from the sources listed on each rule. Results show what the stored curriculum data does or does not show. They are not an official CHED, DepEd or institutional determination."), the **shared Program > Curriculum > Version bar** (results are version-scoped) and, instead of four tabs, one shared segmented control inside the page for **Results | Rule library | Sources | History** (the same page, not four titles; each view is a section of the one page, URL `?view=`). No blank first state: every view has a `csms-empty` and server-side paging (10 / 20 / 50 / 100).

**Rule library (data, not code).** Every rule is a row with: id; title; source issuance (reference id); section; PDF page and printed page; **label** (STATED / INTERPRETED / UNCLEAR); **status** (candidate / active / do-not-activate / pending BCP confirmation); applies-to (college program / SHS; optionally a program); computation method (a key from a small registry of deterministic computations: total units, units of required placements, units per term, placement count, named subject placed, subject units, weighting schemes complete, elective group count); parameters (numbers, subject ids); valid-from / valid-to; evidence requirement (none / source confirmed / named evidence). Adding a BCP policy later is a new row with its own source and status `adopted`, with no change to code as long as it uses an existing computation key; a genuinely new computation is a small code addition.
- **Can the current schema hold it? No.** `compliance_rules` has `rule_name`, one of five `rule_type` values enforced by a CHECK (`011...sql:57`), one `numeric_value`, one `subject_id`, program or curriculum scope, status active / inactive, and nothing for section, page, label, applies-to level, validity dates, evidence requirement or a richer status; results are limited to PASS / FAIL / NOT_EVALUATED by a CHECK (`:131`). Changing those CHECKs or adding columns touches **baseline-covered tables** (`compliance_rules`, `compliance_validation_results` are in `database/legacy_baseline.php:6-7`), which needs declared entries and pinned checksums. **Proposal for migration 032 (next free; 030 Electives, 031 optional equivalency list index): new tables only** (no change to the covered tables): `compliance_rule_library` (+ `compliance_rule_parameters` or a JSON column), `compliance_assessment_runs` and `compliance_assessment_results` with the five statuses below and snapshots of rule, source, section, page and label, and an optional link from a rule to its reference in the existing `compliance_references` (which already holds issuance, authority, year, URL, verification). The old tables and their history stay readable and are labelled "legacy runs".

**Result statuses per rule:** **Met**, **Not met**, **Needs evidence** (the rule requires evidence and none is confirmed), **Not applicable** (applies-to does not match the program, or outside valid-from / valid-to), **Cannot determine from stored data** (the input is not stored: category, hours, subject group, or a referenced subject is missing). **Never an overall "Compliant" verdict**: the summary is counts per status, with each rule showing its source and label. Pass / Fail / Not Evaluated disappear from the UI. `do-not-activate` and `pending BCP confirmation` rules are never evaluated; they appear in the library with their reason.

**Evidence attach / verify:** keep the existing model (a locator and a SHA-256 typed or pasted, an assessment by a person with the permission, immutable events, audit) and tie it to a **rule and its section**, so a rule that requires evidence shows "Needs evidence" until a confirmed event exists. No file upload in the first slices (a store would need a location, size and type limits, hashing, malware handling and access control; decide with the client). References stay traceable: each result shows `[type] code, issuing body, year, section, page, label`.

**Demo rules:** a flag on the library row (`is_demo`) and a filter "Include demo rules" off by default; demo rows are labelled with a chip "Demo: not a real source" and are excluded from every non-demo view, from the Dashboard counts and from Export. The seed that inserts a pre-verified demo reference is changed to record the same evidence step.

**Slices** (next free migration is **032**)

| Slice | Content | Schema? |
|---|---|---|
| **S1** (about 2 days) | One page and title, shared bar and components, server-side lists, statuses renamed in the UI (Met / Not met / Needs evidence / Not applicable / Cannot determine) over the existing results (PASS -> Met, FAIL -> Not met, NOT_EVALUATED -> Cannot determine), counts-only summary, wording fixes ("Internal Compliance Status" -> "Source status"), demo rows labelled and hidden by default (by code prefix until a flag exists), read-scope fix for `run_get` and `history_list`, Builder target rule limited to verified references like the validator, CIMF pick-list fix, HTTP regression test (permissions, CSRF, scope, audit, no overall verdict) | **None** |
| **S2** (about 2 days) | More deterministic computations from stored data (section 9, first three rows) as new `rule_type` values | CHECK change on a covered table, or wait for S3 |
| **S3** (about 4 days, needs the client's answers) | Rule library with source, section, page, label, status, applies-to, validity, evidence requirement, the five statuses and per-rule evidence, "Not applicable" and "Needs evidence" logic; seed **nothing** until sources are confirmed, then load the verified rule pack of section 10 as `candidate` rows with their labels | **Yes, migration 032 (new tables)** |
| S4 (optional) | Evidence file storage | storage design, client decision |

**Risks**
- Any "Met" can be read as compliance: keep the counts-only summary, the source label and the standing note; never add a combined status.
- GE has three conflicting figures (54 / 36 / 33): show all, never auto-fail (C2, C3).
- Without a category field most college unit rules stay "Cannot determine"; adding a category to subjects or placements is a larger data decision (needs client input).
- Evidence by locator and fingerprint proves that someone recorded a pointer, not that a document exists; say so on screen.
- The demo seed inserts a pre-verified reference; keep demo out of real counts.
- Two suites that cover this area need the Task 2 database; they fail for unrelated reasons (section 12).

**Questions (each: needs client input / may be NDA-restricted)**
1. Does BCP offer SHS? Which tracks, and is BCP a pilot or adapting school (DO 015 par. 4 and 6, DM 036 Annex D)? Every SHS rule stays "pending BCP confirmation" until answered.
2. Which college programs apply (BSIT only, or also BSCS, BSIS, COE with their 146 / 134 unit figures), and which GE figure does BCP follow (CMO 25 Sec. 8.1, CMO 20, the 2019 advisory)?
3. Which Subject Master records are the PE (PATHFIT) and NSTP subjects, and how are GE core and GE electives told apart (a category field)?
4. Are the original PDFs (CMO 25, CMO 20, CMO 39, DO 015, DO 017, DM 036, DM 074) to be stored with the project or only referenced by locator and hash?
5. Who may record evidence and who may activate a rule (today `compliance.reference.verify` is held by Acad Office and Super Admin; `manage` by admin-type roles)?
6. Should a "Needs evidence" rule block submission of a version for review, or only inform?
7. Does the client want valid-from / valid-to on rules (needed for the SY 2026-27 transition and SY 2027-28 changes)?

## 12. Item 11: the two suites that stop at the Task 2 database guard

- **The guard** (`tests/version-protection-fixture.php:8-17`) requires, together: CLI; a database named `csms_docker_test_task2_...` (`^csms_docker_test_task2_[a-z0-9_]+$`); `SMS_TEST_CONFIRM_DATABASE` equal to that name; `DB_HOST` = `127.0.0.1`; `APP_ENV` = `development`; `DB_PORT` not 3306 (a dedicated server); and **empty** academic tables.
- **How it was satisfied without touching the guard:** the repository's own sanctioned runner `tests/version-protection-isolated.ps1 -OnlyTest <suite>`. It starts a second MariaDB 10.4.32 (`mysqld.exe`) on a free loopback port with a temporary data directory, verifies version, port, canonical data directory and `foreign_key_checks`, creates `csms_docker_test_task2_run_<n>_<hex>`, applies every migration with `deploy.php --apply`, runs the suite, drops the schema, shuts the server down and removes the directory. No file was edited; `sms_db` and `sms_db_uitest` were not used.
- **Results:** `curriculum-revision-http.php` ran 26 checks and then failed at "invalid label: expected 422 got 403 {This curriculum version is not available to your role.}" (read scope answered before validation for the suite's synthetic role, which has no `curriculum.view_unapproved`). `version-protection-http.php` failed at its first checks, "refresh authenticated CSRF token: expected 200 received 403" (the synthetic viewer, whose role no longer carries the permission the dashboard needs since the final user types). **Both fail identically in a throwaway git worktree of 7b7ca02**, a commit from before the Equivalency and Grade Weighting slices, so they are not caused by them; both are stale against the role and read-scope changes of the final user types. Not fixed (unrelated). The runner also executes a fixed list of frontend suites after the test; some of those (`frontend.cjs`, `curriculum-comparison-ui.cjs`, `outcomes-ui.cjs`, `foundation-outcomes-ui.cjs`) failed; they are unrelated to this plan and were not investigated. The disposable server and data directory were removed (the runner reported "Disposable server/data removed"); the worktree was removed.

## 13. Not verified here
The page was read, not opened in a browser; role behaviour and the read-scope gaps are from code and the permission table, not exercised; the regulatory documents were not available (section 10 uses the supplied pack only); the relation between the dataset's Section 9.1 and the Section 8.1 unit table is UNVERIFIED; no scale test was run; the AI provider was not called.
