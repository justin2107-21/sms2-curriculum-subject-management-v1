# Pre-oral Phase 2: controlled BSIT demonstration dataset

Dataset: **DEV-BSIT-PREORAL-2026**. Local data loaded and verified on 2026-10-01.
Repository: C:\xampp\htdocs\sms-csms; main, baseline ce44fb6.
Pre-flight found only the three expected preparation files untracked.

This dataset is used for pre-oral system demonstration. Subject codes, subject
names, year levels, semesters, and total units were derived from the user-supplied
BSIT subject list. Lecture/laboratory unit breakdowns and other unconfirmed
institutional attributes are not represented as official data.

## Source and stored structure

The single authoritative machine-readable dataset is
[bsit_preoral_manifest.json](../database/seeds/bsit_preoral_manifest.json).
The table below is its human-review rendering, not a second seed input.
Source spellings, capitalization, punctuation and codes are preserved, including
CCS1102/CC102, ITSP1-A, ITSP2A and "Advance Javaor OOP".

- Program: BSIT / Bachelor of Science in Information Technology (local ID 108).
- Curriculum: BSIT-DEMO-2026 / BSIT Pre-Oral Demonstration Curriculum (ID 46).
- Version: PREORAL-2026 (ID 42), technical draft state.
- Version description: Synthetic pre-oral demonstration version based on the user-supplied BSIT subject list.
- 50 subjects (IDs 107-156), 50 placements (IDs 112-161).
- Master technical states: active. No institutional approval implied.
- Program type, official effective academic year, subject descriptions and sequence
  order remain NULL. No institutional attributes were inferred.
- is_required=1 uses the existing technical/demo placement default, including ITE
  subjects. It is NOT a claim about official elective policy or student choice.
- No prerequisite, corequisite, elective, equivalency, weighting or compliance
  configuration was invented.
- All lecture/lab unit values are NULL, not zero. Total units are confirmed.
- No fourth-year second-semester data was supplied or created.

## Exact distribution and units

| Year | Semester | Subjects / placements | Total units |
| --- | --- | ---: | ---: |
| 1st | 1st | 9 | 26 |
| 1st | 2nd | 9 | 26 |
| 2nd | 1st | 8 | 23 |
| 2nd | 2nd | 8 | 23 |
| 3rd | 1st | 6 | 18 |
| 3rd | 2nd | 6 | 18 |
| 4th | 1st | 4 | 18 |
| 4th | 2nd | 0 | 0 (no source records) |
| Total | | 50 | 152 |

## Complete subject source review

Lecture/laboratory values for every row below are unavailable, stored NULL.

| Code | Exact subject name | Year | Semester | Total units |
| --- | --- | --- | --- | ---: |
| GE1 | Understanding the Self | 1st Year | 1st Semester | 3 |
| GE2 | Readings in Philippine History | 1st Year | 1st Semester | 3 |
| GE3 | The Contemporary World | 1st Year | 1st Semester | 3 |
| GE4 | Mathematics in the Modern World | 1st Year | 1st Semester | 3 |
| CC101 | Introduction to Computing | 1st Year | 1st Semester | 3 |
| CCS1102/CC102 | Computer Programming 1 | 1st Year | 1st Semester | 3 |
| KOMFIL | Kontekstwalisadong Komunikasyon sa Filipino | 1st Year | 1st Semester | 3 |
| NSTP1 | National Service Training Program 1 | 1st Year | 1st Semester | 3 |
| PE1 | Physical Fitness | 1st Year | 1st Semester | 2 |
| GE5 | Purposive Communication | 1st Year | 2nd Semester | 3 |
| GE6 | Art Appreciation | 1st Year | 2nd Semester | 3 |
| GE7 | Science and Technology (ST) and Society | 1st Year | 2nd Semester | 3 |
| GE8 | Ethics | 1st Year | 2nd Semester | 3 |
| MS101 | Discrete Mathematics | 1st Year | 2nd Semester | 3 |
| CC103 | Computer Programming 2 | 1st Year | 2nd Semester | 3 |
| FILDIS | Filipino sa iba't-ibang Disiplina | 1st Year | 2nd Semester | 3 |
| NSTP2 | National Service Training Program 2 | 1st Year | 2nd Semester | 3 |
| PE2 | Folk Dance and Rhythmic Activities | 1st Year | 2nd Semester | 2 |
| MS102 | Quantitative Methods with Modelling Simulation | 2nd Year | 1st Semester | 3 |
| CC104 | Data Structures And Algorithms | 2nd Year | 1st Semester | 3 |
| HCI101 | Introduction To Human Computer Interaction | 2nd Year | 1st Semester | 3 |
| IPT101 | Integrative Programming And Technologies 1 | 2nd Year | 1st Semester | 3 |
| NET101 | Networking 1 | 2nd Year | 1st Semester | 3 |
| ITE1 | IT ELECTIVE 1 (Web Fundamental) | 2nd Year | 1st Semester | 3 |
| SOSLIT | Sosyedad at Literatura | 2nd Year | 1st Semester | 3 |
| PE3 | Individual and Dual Sports | 2nd Year | 1st Semester | 2 |
| GE9 | The Life and Works of Jose Rizal | 2nd Year | 2nd Semester | 3 |
| DM102 | Financial Management | 2nd Year | 2nd Semester | 3 |
| SIA101 | System Integration and Architecture 1 | 2nd Year | 2nd Semester | 3 |
| CC105 | Information Management | 2nd Year | 2nd Semester | 3 |
| NET102 | Networking 2 | 2nd Year | 2nd Semester | 3 |
| WEB101 | Web Development (Advance Web / Platform) | 2nd Year | 2nd Semester | 3 |
| ITE2 | IT ELECTIVE 2 (Advance Javaor OOP) | 2nd Year | 2nd Semester | 3 |
| PE4 | Team Sports | 2nd Year | 2nd Semester | 2 |
| CC106 | Application Development and Emerging Technologies | 3rd Year | 1st Semester | 3 |
| IAS101 | Information Assurance and Security 1 | 3rd Year | 1st Semester | 3 |
| IM101 | Fundamentals of Database System | 3rd Year | 1st Semester | 3 |
| ITSP1-A | ENTERPRISE SYSTEMS: CONCEPT AND PRACTICE | 3rd Year | 1st Semester | 3 |
| PM101 | Project Management | 3rd Year | 1st Semester | 3 |
| ITE3 | IT ELECTIVE 3 (Research) | 3rd Year | 1st Semester | 3 |
| SP101 | Social and Professional Issues | 3rd Year | 2nd Semester | 3 |
| IAS102 | Information Assurance and Security 2 | 3rd Year | 2nd Semester | 3 |
| TEC101 | Technopreneurship | 3rd Year | 2nd Semester | 3 |
| BPM101 | Business Process Management in IT | 3rd Year | 2nd Semester | 3 |
| ITSP2A | Mobile Application and Development | 3rd Year | 2nd Semester | 3 |
| SA101 | System Administration And Maintenance | 3rd Year | 2nd Semester | 3 |
| ITSP3A | Big Data Analysis | 4th Year | 1st Semester | 5 |
| PRAC3301 | OJT - Practicum 1 | 4th Year | 1st Semester | 5 |
| CAP101 | Capstone Project 1 | 4th Year | 1st Semester | 3 |
| ITE4 | IT ELECTIVE 4 (System Integration and Architecture 2) | 4th Year | 1st Semester | 5 |

## Backup and approved cleanup

Backup outside the repository and web root:
C:\Users\esquitajustin\Documents\csms-local-backups\sms_db_preoral_before_final_phase2_20261001_044118.sql

- 212,678 bytes, 22 CREATE TABLE definitions, dump completion marker present.
- SHA-256: f3a1e06a0d2790e3abacb2f34d2c87c69f608e93e7d5613295fac3a613f7e6aa
- A read-only fingerprint plan is stored beside it as
  bsit_phase2_plan_20261001_044118.json.
- Neither backup nor fingerprint plan is tracked or publicly served.

Removed only these 12 reverified local demo rows:

| Table | ID | Code | Name |
| --- | ---: | --- | --- |
| academic_programs | 28 | ITE4 | IT Elective IV |
| academic_programs | 47 | PE | Physical Education |
| academic_programs | 50 | cap101 | capstone1 |
| academic_programs | 51 | IAS | INTEGRATION |
| academic_programs | 52 | ASDFASDF | ADSFASF |
| academic_programs | 53 | ASDFA | ASDFA |
| academic_programs | 54 | ASDFAS | ASDFASDFA |
| academic_programs | 55 | ASDFASDFASDF | ASDFASDFASDF |
| academic_programs | 56 | ASDFASF | ASDFASDF |
| subjects | 17 | ITE4 | IT Elective 4 |
| subjects | 54 | GE 9 | RIZAL |
| curricula | 34 | PE | PHYSICAL EDUCATION |

GE9 and ITE4 now exist with the authoritative source titles and 3/5 units respectively.
The old rows are recoverable through the external backup, not through a seed undo
that silently recreates random demo data.

Dependency-safe order, limited to confirmed snapshot/owned IDs:

1. compliance_validation_results
2. compliance_validation_runs
3. compliance_rules
4. compliance_references
5. grade_weighting_components
6. grade_weighting_schemes
7. subject_equivalencies
8. curriculum_elective_group_subjects
9. curriculum_elective_groups
10. curriculum_subject_requirements
11. curriculum_version_subjects
12. curriculum_versions
13. curricula
14. subjects
15. academic_programs

Only the last three tables had old rows. The replacement deleted the approved
snapshot and inserted the new core dataset in ONE transaction. No TRUNCATE,
foreign-key disabling, AUTO_INCREMENT reset, schema/migration change or production
connection occurred.

## Final local counts and protected data

| Table | Rows |
| --- | ---: |
| academic_programs | 1 |
| subjects | 50 |
| curricula | 1 |
| curriculum_versions | 1 |
| curriculum_version_subjects | 50 |
| curriculum_subject_requirements | 0 |
| curriculum_elective_groups | 0 |
| curriculum_elective_group_subjects | 0 |
| subject_equivalencies | 0 |
| grade_weighting_schemes | 0 |
| grade_weighting_components | 0 |
| compliance_references | 0 |
| compliance_rules | 0 |
| compliance_validation_runs | 0 |
| compliance_validation_results | 0 |

103 core domain rows, NOT 103 rows in the entire database.
Protected counts: users 2, roles 2, permissions 34, user_roles 2,
role_permissions 37, legacy students 25. Full protected-table fingerprints match
the pre-cleanup plan. All 749 prior audit rows match their original fingerprint.
Audit count after seed: 750.

41 FKs remain with zero orphans and all seven migration-013 identity keys use
ON UPDATE RESTRICT. No migration journal or schema was added to local sms_db.

## Ownership, transactions and repeat safety

[bsit_preoral.php](../database/seeds/bsit_preoral.php) is CLI-only and refuses
anything except explicitly confirmed local sms_db on XAMPP loopback port 3306,
with the expected datadir, MariaDB engine and FK state. Production APP_ENV,
remote connections and DATABASE_URL are refused. No runtime/startup integration.
The existing Docker allowlist excludes all database/seeds files.

[bsit_dataset.php](../database/seeds/bsit_dataset.php) validates the manifest
before writes, uses prepared inserts/deletes, an advisory lock, row locks and a
transaction. Initial replacement also requires the exact pre-reviewed identities,
full row fingerprints, protected-table fingerprints and matching backup checksum.
Use a maintenance window: avoid interactive edits while planning/replacing.

Ownership is recorded in the existing aggregate DEVELOPMENT_SEED_CREATE audit
under entity development_dataset: dataset ID, manifest digest, exact inserted
IDs and SHA-256 row fingerprints, plus the old cleanup fingerprints and backup
checksum. It does not prefix or modify source codes. It does not fabricate
103 human CRUD audit events. Normal application audit behavior is untouched.

A matching second seed verifies/no-ops without another audit. Changed manifest,
changed owned rows, malformed provenance, unowned code collisions or external
dependencies fail closed. Cleanup validates ALL ownership before deleting exact
IDs in reverse dependency order, preserves evidence and writes one aggregate
DEVELOPMENT_SEED_REMOVE audit. Changed data requires manual review, not force cleanup.

The reusable library is exercised directly only by the isolated test harness.
The operator entrypoint has no disposable-target/production override.

## Operator commands

These are controlled examples, not instructions to clear the now-loaded dataset.
Use a NEW verified local backup for any later mutation; the runner requires a
backup at most 24 hours old, outside the repository/web root. Replace ACTOR with
an existing local admin ID and PATH with explicit external paths.

Verification does not require a new backup:

    php database/seeds/bsit_preoral.php --action=verify --database=sms_db --actor=ACTOR --confirm-development

Safe seed/no-op (a code collision without ownership is refused):

    php database/seeds/bsit_preoral.php --action=seed --database=sms_db --actor=ACTOR --confirm-development --backup=PATH.sql

Owned-dataset cleanup ONLY, after deliberate operator approval:

    php database/seeds/bsit_preoral.php --action=cleanup --database=sms_db --actor=ACTOR --confirm-development --backup=PATH.sql

Initial legacy replacement used plan then replace, with --backup=PATH.sql and
--plan=PATH.json in the external backup directory. Plan filenames must match
bsit_phase2_plan_YYYYMMDD_HHMMSS.json; backup filenames must match
sms_db_preoral_before_final_phase2_YYYYMMDD_HHMMSS.sql.
Those legacy actions now correctly refuse because the original 12-row inventory
no longer exists. They are not a general reset mechanism.

## Verification performed

- Manifest: 50 exact source code/title/unit matches, 152 units, 100 NULL lecture/lab
  fields, seven exact count/unit distributions; pending, duplicate, blank,
  negative, invalid-year/semester and precision cases refused.
- 15 database-free manifest/source/target checks.
- Isolated local harness: migration guards 29; fresh FK 32; populated legacy FK
  upgrade 92; existing development seed safety 40.
- New BSIT safety: 27 assertions covering insertion rollback, collision refusal,
  idempotent no-op, altered fingerprints, three corrupt-provenance cases,
  external dependencies, owned-only cleanup, reseeding, stale cleanup plan and
  rollback restoring deleted legacy records after a failed replacement.
- Initial 172 BSIT service/search/filter/pagination/CSV checks, both isolated and local;
  the acceptance pass now verifies 181 read-only local assertions:
  searches for ALL 50 codes, exact source names/units, program/curriculum/version
  context, all year/semester filters, three pages without duplicates, both
  assignment views and stored-curriculum-usage history.
- Empty requirements/electives/equivalencies and empty optional weighting/compliance
  export sections verified. No unsupported module data inserted.
- Local proposed/demonstration CSV saved outside web root/repo:
  C:\Users\esquitajustin\Documents\csms-local-backups\bsit_preoral_demonstration.csv
  It contains all 50 exact subject rows, 152 aggregate units, correct placement
  labels and blank lecture/lab cells. Not an official BCP-approved export.
- Complete Docker build/runtime workflow passed: PHP image lint 111, both DB alias
  styles, ports 8080/9090, module read/mutation regressions, RBAC, CSRF, audit,
  prerequisite cycles, weights, compliance, export, login/logout, sensitive URL
  denials, bad-DB error safety and controlled production-bootstrap scenarios.
  All test containers, volumes and networks removed; HostForge untouched.
- Static: 129 PHP files, 19 JS/CJS files, 18 CSS structural checks, 16 DB config
  tests, CSV escaping/formula tests, mock-DOM frontend regression and diff checks.
- No real local credentials were requested in chat or changed.

## Final acceptance changes

The user manually reviewed the loaded localhost dataset and approved this focused
acceptance pass. The dataset was NOT rebuilt, reseeded or cleaned again.

- Export aggregation now preserves NULL when all lecture/lab values are NULL.
  The existing preview/print formatters display an em dash; CSV subject cells
  remain blank. Confirmed numeric zero still displays as zero. Stored values,
  total-unit calculations and all other export behavior remain unchanged.
- Curricula selected-version placements use native collapsible year/semester
  sections, with semester counts/units and the complete-version summary.
  All pages of the existing bounded assignment API are retrieved before grouping;
  stale, duplicate or incomplete loads cannot produce a false complete summary.
  Sorting is year label, semester label, subject code (natural ascending display).
  The Order column is absent, but the stored sequence field is unchanged.
  Existing Assign/Remove actions, permissions, confirmations and CSRF remain.
- Subject Master supports Curriculum Version, Year Level and Semester filters.
  Existing placement relationships are queried with a prepared EXISTS predicate:
  a subject shared across versions is still shown once. The original subject
  search/status/pagination and create/edit workflows remain authoritative.
  Context controls require the existing subject.view and curriculum.view
  permissions, also enforced independently in HTTP. Version options are bounded
  and loaded in pages; year/semester choices come from actual placements.
  Context is shown in the filters/note, not persisted on Subject Master or
  duplicated into subject records. Extra table columns were intentionally avoided.
- Scope-specific responsive CSS reuses existing cards, colors and table scrolling.
  Assets retain filemtime cache busting. No schema, RBAC mapping, CSRF, audit or
  module mutation/business-rule changes.

Focused tests: tests/phase2_ui.cjs verifies four year sections, seven semester
totals, 50/152 summary, code sorting, permission-aware Remove controls, HTML
escaping, complete pagination and stale/incomplete-load refusal. This is
deterministic non-visual testing, not screenshot QA.

Updated Docker HTTP regression passes 1,651 assertions per DB variable style
(3,302 total), including new context authorization/method/validation checks,
shared-subject deduplication, parameterized filters and empty/all-NULL/zero/numeric
lecture/lab summaries. Print view is exercised with NULL summaries. The entire
deployment/bootstrap workflow passes and removes its temporary resources.

The local read-only tests verify 181 assertions and owned-record fingerprints
still match: 50 subjects, 50 placements, 152 units, lecture/lab NULL. No local
data mutation was needed for this acceptance pass. Existing user manual review
is acknowledged; the new grouped presentation can receive a final visual look
after refresh. No automated screenshot claim is made.

All Phase 2 source, preparation, seed-safety and acceptance changes belong to the
single requested commit, "feat: add controlled BSIT pre-oral dataset".
No HostForge redeploy/population, CIMF or JWT work is included.

Future enrichment still needs confirmed prerequisites/corequisites, elective
policies, equivalencies, weighting, compliance and any missing curriculum source.
After final Phase 2 acceptance, the recommended next phase is the narrow,
human-reviewed CIMF MVP. It has NOT been started.
