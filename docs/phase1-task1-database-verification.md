# Phase 1 Task 1 database verification

Date: 2026-10-08 (Asia/Manila). Status: VERIFIED on MariaDB 10.4.32.
Branch: `feature/phase1-task1-curriculum-lifecycle-schema`; starting commit: `baf3f9a`.

## Isolation and tools

PHP CLI 8.0.30 (mysqli/mysqlnd enabled) and XAMPP MariaDB 10.4.32 server,
initialization, client and administration binaries were available by absolute path.
Docker CLI 29.8.1 was present, but neither the default engine pipe nor the
Docker Desktop Linux engine pipe was available. Docker also reported denied
access to its user configuration. No Docker container could be created.

Fallback used the MariaDB binary with a fresh temporary data directory,
new random root password, `--no-defaults`, loopback binding and dedicated port
53954. The process was started with a hidden window. Before creating test
databases, the harness verified server version, port, canonical data-directory
identity and enabled foreign-key checks. Canonical paths account for Windows
short/long path aliases. No existing database credentials were used.

Only `csms_docker_test_task1_deploy` and `csms_docker_test_task1_upgrade`
were created, on this separate process. The existing XAMPP service and its
academic data directory were never targeted. No production connection occurred.

## Executed checks

- PHP syntax: all 178 repository PHP files passed; the edited deployment test
  was checked again afterward.
- `tests/deployment_migrations.php`: final result 29/29 passed. Includes fresh
  deployment through 021, confirmations, untracked-schema refusal, migration
  locking, repeat apply, checksum mismatch, incomplete markers and unknown entries.
- `tests/curriculum_lifecycle_schema.php`: 70/70 passed. Checks journal checksum,
  schema constraints, neutral defaults, invalid/duplicate relationships, delete
  restrictions and complete fixture rollback.
- Temporary populated-upgrade harness: 83/83 passed. A temporary copy of the
  unchanged deployment runner, baseline and migrations 001-020 built a journaled
  pre-021 database. The repository runner then applied only pending migration 021.
  No repository migration was removed or changed to construct that fixture.
- `git diff --check`: passed.

The populated fixture included a synthetic user and student, program, curriculum,
three versions (draft/active/archived), two subjects, four subject placements,
a prerequisite, equivalency, compliance reference and audit record. All original
column values across 25 pre-existing tables were compared before/after, including
timestamps, permissions, role grants and original migration journal rows. Empty
tables were also compared. All 47 original FK definitions, actions and column/
target mappings remained intact. Existing protected-delete and orphan-insert
constraints still rejected invalid operations. All three legacy versions received
`unclassified`, `not_started` and NULL baseline defaults. Four new tables remained
empty; 021 was recorded as applied in the journal.

## Confirmed defects corrected

1. The first deployment failed at 021 with MariaDB error 1005. InnoDB could not
   resolve the new composite referenced index while adding the self-referencing
   FK in the same ALTER. Migration 021 now creates the columns/index/CHECKs first,
   then adds the baseline FK in a second ALTER. The final fresh and populated
   deployments both passed. Migrations 001-020 were untouched.
2. The next deployment run applied 021 but failed the test's stale permission
   count of 36. Existing migration 020 adds `dashboard.view` and `settings.view`;
   the verified total is 38. Only the deployment regression assertion changed.

These two initial failures were resolved; the final three database suites had
182 passing checks and zero failures. Failed disposable schemas were recreated
before rerunning; incomplete migrations were never blindly replayed.

## Compatibility and limitations

Verified engine: MariaDB 10.4.32 only. MariaDB 10.11 Docker and MySQL compatibility
remain unverified because no Docker daemon was reachable. Synthetic preservation
checks do not establish production state, production-scale runtime or lock duration.

Migration 021 is additive schema infrastructure, not an approval workflow. The
schema does not prevent self-baselines or more complex baseline cycles; those
remain application-policy requirements for later authorized work. DDL can commit
partially, including between the two ALTER statements. The runner's incomplete
marker must remain a recovery gate. Changing 021 changes its checksum; this result
does not authorize rewriting an already-applied journal entry in any environment.

The temporary server was shut down and its temporary files/data were removed.
No changes to main, production, HostForge, existing `sms_db`, or migrations 001-020.
No merge, push, deployment, or Phase 1 Task 2 work was performed. Corrections remain
uncommitted on the development branch, awaiting approval.
