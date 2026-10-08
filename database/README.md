# Database migrations

## Academic identity FK portability (migration 013)

Subject/program/curriculum IDs are immutable technical identities, not institutional
policy. MySQL/MariaDB portability requires the seven CHECK-related reference keys
below to use **ON UPDATE RESTRICT / ON DELETE RESTRICT**:

- `curriculum_subject_requirements`: `fk_curriculum_subject_requirements_target_placement`,
  `fk_curriculum_subject_requirements_required_placement` (composite version/subject keys).
- `subject_equivalencies`: `fk_subject_equivalencies_subject`,
  `fk_subject_equivalencies_equivalent_subject`.
- `compliance_rules`: `fk_compliance_rules_subject`, `fk_compliance_rules_program`,
  `fk_compliance_rules_curriculum`.

Fresh definitions 005/009/011 were revised with explicit approval. All CHECKs,
UNIQUEs, column types, delete restrictions and other FKs remain unchanged.
Fresh deployment order is baseline then 001-013: 14 journal entries, 22 application
tables plus the journal, 41 FKs and 34 permissions. No default users/demo records.

For an existing **journaled** database: back up, restrict application access for
maintenance, run `php database/deploy.php --status`, then apply with the exact
database confirmation as documented in the deployment guide. The runner recognizes
only the three exact old normalized SQL hashes from commit `87db247`, and only
when current sources match their exact reviewed replacement hashes. It retains
old journal digests/timestamps; 013 gets its own new digest. Arbitrary checksum
changes, unknown migrations, running markers and out-of-order histories still fail.
No previous environment is assumed to have an empty journal.

For an existing **unjournaled** XAMPP installation already through 012: do not
invent journal entries or rerun baseline/001-012. After backup and schema review,
apply only `013_mariadb_subject_fk_compatibility.sql` once with an authorized SQL
client in a maintenance window. Its `USE sms_db` must be explicitly reviewed and
retargeted to the confirmed application database if named differently. Verify the
seven FK actions, all CHECK/UNIQUE/composite keys and unchanged row counts afterward.
The deployment runner deliberately refuses automatic adoption of untracked schemas.

013 adds temporary RESTRICT guards, drops the old named FKs, restores their original
names with RESTRICT, then removes the temporary guards. FK checks stay enabled and
there is no unguarded DML interval. MariaDB cannot replace the same FK name in one
ALTER (see [MDEV-30416](https://jira.mariadb.org/browse/MDEV-30416)). DDL is not atomic
across statements; an interruption leaves a running journal marker and may leave
guard FKs. Do not blindly replay/delete the marker. Inspect the actual DDL and
complete reviewed recovery or restore a backup before reopening application access.
An old partial 005/009/011 failure is NOT an automatically upgradeable installation.

Tests use a new MariaDB 10.11 Docker database and the actual XAMPP 10.4.32 binary in
an isolated data directory. They cover populated legacy upgrades, exact row/CHECK/
index/AUTO_INCREMENT preservation, unchanged historical hashes, hostile digest
rejection, same-version relationships, self/duplicate/canonical/type rules,
protected deletes and blocked identity updates. Local `sms_db` is never a target.

## Phase 1 Task 1: additive curriculum lifecycle schema (migration 021)

**Status: schema foundation only.** This migration does not activate an academic
approval workflow, assign responsibilities, clone records, verify any BCP policy,
grant roles, create student data, or edit any existing curriculum/subject values.

File: `migrations/021_create_curriculum_lifecycle_foundation.sql`.
It adds nullable `curriculum_versions.baseline_version_id`, defaulted
`version_role='unclassified'`, defaulted `review_status='not_started'`,
and four narrowly scoped tables:

- `curriculum_version_references` links existing `compliance_references` to
  curriculum versions with a source-purpose and metadata captured at link time.
  The registry remains authoritative; no duplicate document registry is created.
- `curriculum_version_personnel` records actual users and provisional
  preparer/reviewer/approver responsibilities, without conferring permissions.
- `curriculum_version_review_events` reserves append-style academic review
  event records, separate from system `audit_logs`.
- `curriculum_version_subject_snapshots` reserves one subject-attribute capture
  per existing mapped placement. No existing placement is changed or snapshotted
  by the migration.

All new foreign keys retain IDs and restrict academic-record deletion; optional
actor-attribution FKs use `ON DELETE SET NULL`. New values on legacy versions
remain neutral. New `reference`/ `proposed` roles and
`not_started`/`draft`/`under_review`/`returned`/`approved` review states
are **not** replacements for the existing technical
`draft`/`active`/`archived` column. `approved` is stored vocabulary
only and is **not** an institutional approval claim.

The composite FK
`(baseline_version_id, curriculum_id) -> curriculum_versions(id,curriculum_id)`
enforces a real baseline from the same curriculum. `proposed` requires a
baseline, and `reference`/`unclassified` must not have one. **Known
limitation:** MySQL/MariaDB do not permit an AUTO_INCREMENT column (`id`)
inside a CHECK expression; therefore the schema does not prevent
`baseline_version_id = id`. A locked, transactional application policy
must reject a self-baseline and more complex cycles before the workflow is
enabled in Phase 1 Task 2+. No UI or API accepts this new field yet.

### Development-only verification

Run *only* against a newly provisioned, disposable test schema matching one
of `csms_docker_test_...` or
`sms_db_foundation_test_lifecycle_...`. Never use your working `sms_db`,
production database, or a schema containing genuine academic records.

After preparing an empty disposable database, set the connection environment
to that database and confirm the same exact name in
`SMS_TEST_CONFIRM_DATABASE` (see `tests/README.md` and
`shared/database_config.php`). Inspect the migration plan:

```sh
php database/deploy.php --status
```

Only if it shows the expected pending migrations *and the target is disposable*,
use the existing guarded runner:

```sh
php database/deploy.php --apply --confirm-database=YOUR_DISPOSABLE_SCHEMA
php tests/curriculum_lifecycle_schema.php
php database/inspect.php
```

`tests/curriculum_lifecycle_schema.php` requires a confirmed disposable
schema, verifies journal digest/foreign keys/CHECKs/legacy defaults,
creates synthetic fixture rows inside a transaction, challenges invalid
relationships, and rolls them back. It refuses production-like database names.
`tests/deployment_migrations.php` remains an **empty database only**
deployment regression that also checks journal replays and mismatch failures;
do not run it against the populated lifecycle test schema.

The runner refuses incomplete/unknown/out-of-order migration journals and
cannot automatically adopt untracked databases. The local development
environment and the HostForge database are not the same environment.
Live production migration state was **not** inspected in Task 1.

### Safe rollback and rollout boundary

MySQL/MariaDB DDL can implicitly commit; do not assume an entire migration is
transactional. For a failed application on a **disposable test database**,
inspect the ledger for a `running` marker, then discard/recreate that
explicitly disposable schema rather than blindly replaying partial DDL.
For any future non-disposable rollout, obtain an independently verified
backup and approved maintenance window first. Restore that verified backup if
a schema migration fails. Once lifecycle rows exist, never drop new tables
as a shortcut without preserving those records and obtaining approval.

**Not performed by this task:** applying migration 021 to production, role
granting, BCP workflow approval, modifying PHP service mutation controls,
creating source links, reference protections, snapshot-capture jobs, cloning
procedures, reviewer/approver access, or UI changes.

## Existing initialization notes

Production/HostForge initialization uses the separate CLI-only
[`deploy.php`](deploy.php) journaled runner, not the development helper below.
See [`hostforge-deployment.md`](../docs/hostforge-deployment.md). It operates on
an explicitly confirmed, already-provisioned schema, omits all sample records,
and refuses untracked existing schemas or incomplete migration history.
Connection settings share `shared/database_config.php`: preferred `DB_*`,
HostForge `DB_DATABASE`/`DB_USERNAME`/`DB_PASSWORD`, then legacy `SMS_DB_*`.
`DB_PORT`/`SMS_DB_PORT` are supported. Local XAMPP fallbacks remain development-only.

Controlled synthetic demo data is documented separately in
[`seeds/README.md`](seeds/README.md). It is opt-in and is not a migration.

## Reproducible fresh installation

Requirements: PHP 8.0+ with mysqli/mysqlnd, and a local MySQL-compatible server
with enforced CHECK constraints (verified on MariaDB 10.4.32). Back up an existing
database before maintenance. The bootstrap below is **only for an empty/new
database**, not an upgrade runner or a restore tool.

From the project root:

```powershell
php database/bootstrap.php --database=sms_db_foundation_test --confirm-empty
$env:SMS_DB_NAME = 'sms_db_foundation_test'
php database/inspect.php
Remove-Item Env:SMS_DB_NAME
```

Use the XAMPP PHP executable's full path if PHP is not on PATH. Connection
settings use the existing SMS_DB_HOST / SMS_DB_USER / SMS_DB_PASS environment
variables; credentials must not be committed. The target database is mandatory.
The CLI helper refuses any non-empty target and system databases. It reads
`students.sql` first, then every numbered migration below in filename order,
retargeting only explicit CREATE DATABASE / USE directives in memory. Applied
migrations are not rewritten by that helper. The legacy baseline includes 25 template student
samples; these are not validated BCP academic data. No administrator password is
seeded. Development administrator setup remains separately opt-in via the
existing guarded `shared/setup.php`.

A fresh installation produces 22 tables, 41 foreign keys, 34 permissions,
34 temporary admin permission mappings and 3 student mappings. Existing users
are backfilled by migration 002; a fresh empty user table has no user mappings.
`database/inspect.php` is read-only and checks tables, row counts and every
foreign key, including composite keys.

DDL is not transactionally reversible. On failure the helper stops, rolls back
any pending DML transaction, and leaves the partial **new** schema for inspection.
Never rerun it against the working database to repair/upgrade it. Recreate only
your explicitly disposable test database after confirming its identity.

All migrations are one-time ordered migrations, except 002 whose upserts/backfill
are repeatable for compatibility. Do not replay applied one-time migrations.
The prerequisite baseline is `students.sql`; the numbering is the dependency
order. SQL files themselves target `sms_db` when run directly.

Local SQL dumps and development artifacts are excluded from Git and denied over
Apache by the root `.htaccess`. Apache must allow these overrides; non-Apache
deployments require equivalent web-server restrictions. Keep backups outside the
web root regardless.

Apply migrations to `sms_db` in numeric order:

1. `001_create_rbac_tables.sql` creates the additive RBAC schema and must run once.
2. `002_backfill_legacy_roles.sql` seeds temporary compatibility mappings and safely backfills existing users.
3. `003_create_audit_logs.sql` creates the append-only audit table and grants `audit.view` to the temporary admin role.
4. `004_create_curriculum_foundation.sql` creates the initial curriculum domain and temporary development permissions.
5. `005_create_curriculum_subject_requirements.sql` creates version-scoped prerequisite/corequisite relationships and temporary module permissions.
6. `006_create_curriculum_elective_groups.sql` creates version-scoped elective groups, placement-backed memberships, and temporary module permissions.
7. `007_add_program_assignment_permissions.sql` adds temporary permissions for the Academic Strand/Course Assignment workspace. It creates no domain table because existing curriculum placements are authoritative.
8. `008_add_subject_history_permission.sql` adds the read-only Subject Offering History permission. It creates no domain table because stored curriculum-version placements are the authoritative history source.
9. `009_create_subject_equivalencies.sql` creates canonical, human-reviewed Subject Equivalency records and temporary module permissions.
10. `010_create_grade_weighting_setup.sql` creates placement-scoped grade-weighting schemes and components plus temporary module permissions.
11. `011_create_compliance_validator.sql` creates configurable compliance references, deterministic rules, immutable validation-run snapshots, and temporary module permissions. It seeds no regulatory source, threshold, or requirement.
12. `012_add_curriculum_export_permission.sql` adds the read-only Curriculum Export Tool capability to the temporary admin role. It creates no domain table and seeds no export template.

The legacy `users.role` ENUM remains temporarily for template compatibility and display. Authorization decisions are authoritative only when resolved through:

- `roles`
- `permissions`
- `user_roles`
- `role_permissions`

The `admin` and `student` mappings are temporary development roles. Final Bestlink College roles, curriculum capabilities, and approval workflows must be based on confirmed client requirements.

## Curriculum domain foundation

`academic_programs` stores neutral program, course, strand, or other academic-program classifications without enforcing unverified institutional categories.

`subjects` is the authoritative subject master. Prerequisites and equivalencies are deliberately not stored as columns on this table.

`curricula` identifies a curriculum belonging to one academic program. Curriculum codes are globally unique identifiers, while changes over time are represented by versions.

`curriculum_versions` stores technical versions of a curriculum. Its `draft`, `active`, and `archived` values are system record states only and do not represent official BCP approval.

`curriculum_version_subjects` assigns a subject to a specific curriculum version with flexible year-level and semester labels. `is_required` is only a placement indicator; it is not an Electives Manager implementation.

`curriculum_subject_requirements` links two subject placements within the same curriculum version as either a prerequisite or corequisite. Composite foreign keys guarantee that both subjects are mapped to that version. These records configure curriculum relationships only and do not determine enrollment eligibility.

`curriculum_elective_groups` stores flexible, curriculum-version-scoped elective group names and descriptions without imposing unverified selection or unit rules.

`curriculum_elective_group_subjects` links an elective group to existing subject placements in the same curriculum version. Membership does not alter `curriculum_version_subjects.is_required`, which remains only a placement indicator.

The Academic Strand/Course Assignment module derives program-to-subject usage through `curriculum_version_subjects` → `curriculum_versions` → `curricula` → `academic_programs`. It does not store or maintain a second direct subject-to-program relationship. Program-centric and subject-centric views are read models over the same authoritative placements, while assignment mutations continue using the existing curriculum placement service and audit events.

Subject Offering History is also a read model over these authoritative relationships. Existing curriculum versions act as stored snapshots for subject inclusion and placement comparison. The module does not represent live class sections, faculty assignments, enrolment counts, rooms, schedules, or official offering dates.

`subject_equivalencies` stores one canonical unordered pair of Subject Master records (`subject_id < equivalent_subject_id`) and an explicit human review decision. The current decisions are `equivalent`, `not_equivalent`, and `needs_review`; legacy `pending` rows remain unreviewed until an authorized user records a decision. Migration 018 adds archive metadata while retaining the unique pair, so an archived pair cannot be duplicated. Objective field comparison and CIMF output are advisory only; neither automatically decides equivalence, awards credit, alters a curriculum, or modifies grades, enrollment, or student records.

`grade_weighting_schemes` stores at most one explicitly created configuration for each `curriculum_version_subjects` placement. It has no institution-specific scheme label or approval state; its optional description is explanatory only.

`grade_weighting_components` stores flexible component names, deterministic display order, and exact `DECIMAL(5,2)` percentage weights. The application derives `Complete` only when the component total is exactly `100.00`; a lower total is `Incomplete`. These are configuration-completeness labels, not official grading or approval states. This foundation does not calculate student grades, transmute scores, enforce passing thresholds, or define BCP grading policy.

`compliance_references` is a traceable registry of user-entered CHED, DepEd, institutional, or other sources. Its `verified` status means an authorized application user internally confirmed the intended source; it does not mean that an external agency certified the record or the curriculum.

`compliance_rules` stores only the implemented deterministic checks: minimum/maximum total mapped units, required subject presence, and minimum/maximum units for a configured subject. Each rule applies to exactly one academic program or one curriculum. Rules never change curriculum status, approve a curriculum, or block another workflow.

`compliance_validation_runs` and `compliance_validation_results` retain append-only run summaries and concise rule/reference/result snapshots. The engine evaluates only active rules linked to verified references and reports `PASS`, `FAIL`, or `NOT_EVALUATED`. These outputs are internal decision support, not official CHED, DepEd, or Bestlink College approval, certification, or legal advice.

The Curriculum Export Tool is a read model over the stored curriculum version and its related subject, prerequisite/corequisite, elective-group, grade-weighting, and latest compliance-run records. Generic CSV output is streamed directly and print-ready HTML is rendered on request; neither format is stored in MySQL or the public filesystem. No official regulatory, accreditation, or institution-approved template is defined by migration 012.

Current historical retention is limited to placement rows that remain in `curriculum_version_subjects`. Removing a placement permanently removes it from this derived history, although the existing append-only audit log may retain mutation evidence. Immutable placement history or soft-deletion must not be introduced until client retention requirements are validated.

Relationship summary:

```text
academic_programs
    ↓
curricula
    ↓
curriculum_versions
    ↓
curriculum_version_subjects
    ↑
subjects

curriculum_version_subjects
    ↓ target placement + required placement
curriculum_subject_requirements

curriculum_versions
    ↓
curriculum_elective_groups
    ↓ group + placement
curriculum_elective_group_subjects
    ↑
curriculum_version_subjects

subjects
    ↓ existing placement, no duplicate assignment table
curriculum_version_subjects
    ↓
curriculum_versions → curricula → academic_programs

subjects + curriculum_version_subjects
    ↓ read-only stored usage and same-curriculum version comparison
Subject Offering History

subjects
    ↓ canonical human-reviewed subject pair
subject_equivalencies

curriculum_version_subjects
    ↓ one explicit scheme per subject placement
grade_weighting_schemes
    ↓ flexible percentage components
grade_weighting_components

compliance_references
    -> traceable source
compliance_rules <- academic_programs or curricula scope
    -> active rule + verified source
curriculum_versions -> compliance_validation_runs
    ->
compliance_validation_results (stored rule/reference/result snapshots)
```

All authoritative domain relationships use `ON DELETE RESTRICT` to prevent accidental removal of referenced academic records. A subject placement must first have its prerequisite/corequisite relationships, elective-group memberships, and grade-weighting scheme removed before that placement can be removed. An elective group must be empty before it can be removed. A grade-weighting scheme must have no components before the normal application workflow permits its removal. The `created_by` references use `ON DELETE SET NULL` so user-account maintenance does not delete domain data.

The temporary admin role receives the initial `program.*`, `subject.*`, `curriculum.*`, `prerequisite.*`, `elective.*`, `program_assignment.*`, `subject_history.view`, `equivalency.*`, `grade_weighting.*`, `compliance.*`, and `curriculum_export.view` development permissions. The temporary student role receives none. These mappings must be revised after client-approved roles are known.

Later modules may reference these tables, but the following are intentionally deferred:

- official grading components and labels, passing thresholds, transmutation, GPA, grading periods, and student-grade calculation
- authoritative CHED/DepEd source catalogs, institution-approved thresholds, official compliance interpretations, and regulatory document-version policy
- automatic curriculum approval/blocking, compliance certification, required-subject counts, grouped/conditional rules, prerequisite/equivalency/elective validation, and AI recommendations
- official or institution-approved export templates, signatories, seals, document numbers, and regulatory formatting
- OpenAI/CIMF data, embeddings, and semantic similarity

The modular plain-PHP backend is under `modules/curriculum/`, separated into repositories, services, validators, and small HTTP action endpoints. Current browser endpoints use session authentication, CSRF for mutations, server-side RBAC, prepared statements, transactions, and audit logging. JWT remains deferred.

## Rollback considerations

MySQL/MariaDB DDL commits independently, so restoring the verified pre-migration backup is the safest rollback. Migrations 007, 008, and 012 are data-only and may be reversed, when explicitly approved, by removing their temporary admin role-permission mappings and permission records. Reversing migration 011 in a disposable database requires removing results, runs, rules, and references in that dependency order, then its temporary admin mappings and permission records. Do not reverse it after genuine validation history exists without an approved export and retention plan. Reversing migration 010 requires removing grade-weighting components before schemes, then removing its temporary admin mappings and permission records. Reversing migration 009 also requires first removing its temporary admin mappings and only then dropping `subject_equivalencies` and its permission records; do not reverse either data-bearing migration after real configuration or review decisions exist without an approved export/retention plan. In a disposable local database only, reversing earlier domain migrations must first remove temporary module role-permission mappings, drop `curriculum_elective_group_subjects`, `curriculum_elective_groups`, and `curriculum_subject_requirements`, then remove the earlier curriculum mappings/tables in dependency order. Do not perform that rollback after real curriculum data exists without an approved data migration and backup.

Do not commit database dumps, credentials, or generated logs.

## Provisional CSMS roles and prototype accounts

Migration `019_user_account_security.sql` adds active-account and first-login-password-change flags. Role/account data is installed separately by the CLI-only local `setup_prototype_roles.php --confirm-database=sms_db`. It installs the four provisional role matrices, skips existing usernames/emails without resetting passwords, and prints newly generated temporary credentials once. The explicit `reset_prototype_password.php --confirm-database=sms_db --username=<prototype_username>` command is available for local operator recovery.

Migration `020_assign_prototype_rbac_roles.sql` is the idempotent data migration for the four prototype role rows, permission grants and `user_roles` assignments. The `users.role` ENUM stays `admin`/`student` for parent-system compatibility; CSMS authorization and login session roles use `user_roles` joined to `roles`, then `role_permissions` joined to `permissions`.

See [the implementation and permission report](../docs/provisional-rbac-implementation.md) for the installed role IDs/accounts, permission matrix, security tests, local migration notes, and remaining limitations. These are provisional user roles used for prototype development and evaluation, subject to client validation.
