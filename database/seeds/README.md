# Controlled synthetic development dataset

**These records are DEVELOPMENT / SYNTHETIC DATA and do not represent official
Bestlink College of the Philippines curricula, subjects, policies, or processes.**

Do not run this tooling on production. Nothing is loaded automatically.
The working local `sms_db` is intentionally left unseeded by foundation testing.
Every master identifier uses DEV-DEMO-, and descriptions identify the data as
synthetic. DEV-V1/DEV-V2 and DEV-Year/DEV-Term labels are artificial examples,
not institutional conventions.

## Prerequisites and explicit target

- An already migrated development schema (baseline + migrations 001–012).
- PHP 8.0+ with mysqli/mysqlnd; tested on XAMPP PHP 8.0.30 / MariaDB 10.4.32.
- An existing development admin user and its authoritative admin role mapping.
  The scripts do not create users, roles, permissions or passwords.
- A current backup outside the web root. Stop interactive writes during seed or
  cleanup operations. The helper's advisory lock serializes other helper runs,
  not every possible SQL client or application edit.

Use `SMS_DB_HOST`, `SMS_DB_USER`, `SMS_DB_PASS` for the existing connection
configuration. The mandatory `--database` option sets the exact database target;
it does not silently use `sms_db`. Use `--actor` with an existing admin ID for
audit attribution. Determine the ID from your local Users page/database; do not
assume a production account number.

From the project root, replacing the example database and actor deliberately:

```powershell
php database/seeds/development.php --action=seed --database=sms_db --actor=1 --confirm-development
php database/seeds/development.php --action=verify --database=sms_db --actor=1 --confirm-development
php database/seeds/development.php --action=cleanup --database=sms_db --actor=1 --confirm-development
```

These commands are **manual examples**, not instructions to reset or migrate an
existing database. Use the full XAMPP PHP executable path if necessary.

Do **not** import the two SQL payloads through phpMyAdmin or SOURCE. They require
connection-local temporary context/ownership tables supplied by the CLI wrapper.
Without those tables their INSERT/DELETE statements fail. The wrapper owns the
transaction, failure rollback and provenance checks. There is no web endpoint,
permanent helper table, migration, stored procedure, TRUNCATE, schema drop,
AUTO_INCREMENT reset or foreign-key disabling.

## Exact dataset

| Table / purpose | Records |
| --- | ---: |
| Academic programs A/B | 2 |
| Subject Master (101, 102, 201, 202, ELEC1, ELEC2) | 6 |
| Curricula A/B | 2 |
| Versions (A: DEV-V1/DEV-V2; B: DEV-V1) | 3 |
| Subject placements | 11 |
| Requirements (C requires B; B requires A; E corequires F in A/V1) | 3 |
| Elective group / memberships | 1 / 2 |
| Equivalency reviews (Pending / Equivalent / Not Equivalent) | 3 |
| Grade-weighting scheme / components (40.00 + 60.00) | 1 / 2 |
| Artificial reference / deterministic rules | 1 / 5 |

Total: **42 domain rows**. Seeding adds one aggregate
`DEVELOPMENT_SEED_CREATE` audit record, not fabricated per-user CRUD events.
The pre-existing module audit events remain unchanged.

Compare curriculum A's DEV-V1 to DEV-V2: D is Added, F is Removed, C is Placement
Changed, and A/B/E are Unchanged. Subject A is shared with synthetic program B.
The required/optional value remains a placement indicator, not an elective quota.
Review decisions/weights are synthetic configurations, not credit/grading policy.

The OTHER-category reference is explicitly NOT a real CHED, DepEd or Bestlink
source. Its Verified value is only the existing technical flag enabling this
demo. It has no real order number, source URL or official claim. Artificial
thresholds exercise all five supported deterministic rule types. Validation on
A/DEV-V1 yields 3 PASS, 1 FAIL and 1 NOT_EVALUATED. Runs/results are created by
the existing validation workflow, not forged by the seed.

## Safety and reruns

- Seed fails clearly if an active seed manifest or any conflicting DEV-DEMO
  master code already exists; it never adopts or overwrites those records.
- Every related write and aggregate audit manifest shares one transaction.
  Any error rolls back the entire operation.
- The manifest in append-only `audit_logs` records exact IDs and row fingerprints.
  No passwords or user rows are included.
- Verify confirms the original records still match those fingerprints.
- Cleanup verifies provenance, locks the owned records and deletes exact IDs in
  reverse dependency order using the visible cleanup SQL.
- Edited/missing seed rows cause cleanup to refuse and roll back. New external
  dependencies are protected by existing RESTRICT foreign keys. Review such
  cases manually; do not broaden cleanup predicates or disable FK checks.
- Later validation snapshots are disposable only if their version and **every**
  referenced rule belong to this seed. Their audit evidence remains.
- Audit records, unrelated rows, user accounts, permissions and schema remain.
  Auto-increment gaps are normal and are not reset.
- After successful cleanup, seed can be run again. Repeating cleanup with no
  active seed fails harmlessly with no writes.

This intentionally conservative cleanup does not silently discard demo records
that have since been edited/adopted. Keep the seed manifest audit records.

## Isolated verification

```powershell
php database/bootstrap.php --database=sms_db_foundation_test_seed --confirm-empty
$env:SMS_DB_NAME = 'sms_db_foundation_test_seed'
php tests/development_seed.php
php database/inspect.php
Remove-Item Env:SMS_DB_NAME
```

The test refuses non-test database names and non-fresh user/program tables. It
creates a random-password, disposable actor only inside that isolated schema,
exercises rollback/collision/rerun/modified-record/external-dependency safeguards,
reads all demonstration modules, generates synthetic compliance history and CSV
in memory, cleans up, and compares all unrelated row data. It removes its actor
and sentinel. On a failed test, inspect the isolated schema; never use a broad
cleanup on the working database.

After verifying success, remove **only the explicitly named disposable database**
using your SQL administration tool. Audit evidence in the working database is
never removed by these tests. No screenshot-level or browser Print/PDF QA is
implied by the automated service-level demo checks.
