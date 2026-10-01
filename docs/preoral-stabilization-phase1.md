# Pre-oral stabilization phase 1

Scope: the active `sms-csms` working copy; no production database maintenance,
final defense seeding, CIMF, JWT, role redesign or module redesign.

## Local schema maintenance (2026-10-01)

Verified the actual local XAMPP MariaDB 10.4.32 server on loopback port 3306,
its XAMPP data directory, and the `sms_db` target before maintenance.
A complete timestamped mysqldump (22 tables, routines/events/triggers included)
was created in the operator's Documents/csms-local-backups directory, outside
the repository and Apache document root. It must stay private and uncommitted.
Backup completion/table-count checks and SHA-256 verification succeeded before
the maintenance command. This is not a claim of a backup restore rehearsal.

Applied only the existing migration 013 using the documented unjournaled-schema
procedure. No old migrations were replayed and no journal entries invented.
All seven approved identity-reference keys now use ON UPDATE RESTRICT and retain
ON DELETE RESTRICT. There are still 41 FKs and no detected orphans.

Before/after snapshots verified identical full-row hashes/counts, column
definitions, primary/unique keys, index definitions, CHECK constraints,
AUTO_INCREMENT values, FK names and all unrelated FK actions. Snapshot evidence
is retained beside the private backup; it is not part of the repository.
The only differences were the seven approved UPDATE_RULE values.

The local schema remains intentionally **unjournaled**. The production deployment
runner still refuses automatic adoption. Do not run baseline/all migrations
against it or fabricate journal history; use the documented upgrade procedure.

## Application changes

- `auth/register.php` returns a controlled 404 without rendering a form.
- `register2.php` retains its existing sign-in redirect.
- `action=register` returns 403 before database access, after the existing
  method/CSRF guards. The unreachable public account-creation branch is removed.
- CLI first-admin provisioning, compatibility roles, login, logout, passwords,
  sessions, CSRF and domain permissions are unchanged.
- Subject total/lecture/laboratory unit inputs use step 0.01, matching the existing
  two-decimal backend/database precision. Min 0 and backend validation remain.

## Local inventory: no deletion performed

The owner identified the academic records as development rubbish. Conservative
review still separates obviously random values from plausible academic labels.

| Classification | Local records | Disposition |
| --- | --- | --- |
| Structural/system | 2 users, 2 roles, 34 permissions, 2 user-role mappings, 37 role-permission mappings, 749 audits | Preserve |
| Legacy template | 25 students | Preserve; outside academic cleanup |
| Clearly random programs | IDs 52, 53, 54, 55, 56 (ASDF-style codes/names) | Cleanup candidates only; retained |
| Development programs with questionable classification | 28 ITE4, 47 PE, 50 cap101, 51 IAS | Review before deletion; some labels resemble subjects rather than programs |
| Development curriculum | 34 PE, linked to program 47 | Review/cleanup candidate; retained |
| Plausible subject labels | 17 ITE4, 54 GE 9 | Potentially useful reference labels, not validated BCP data; retained |

No current versions, placements, requirements, elective groups/memberships,
equivalency decisions, weighting configurations or compliance records exist.
If a later cleanup is approved, re-inventory dependencies first. The present
curriculum 34 must be removed before program 47; do not assume future records
will have no dependencies. Use exact approved IDs, a backup and a transaction.
Do not reset counters or remove account/audit/structural records.

| Table | Before | After |
| --- | ---: | ---: |
| academic_programs | 9 | 9 |
| subjects | 2 | 2 |
| curricula | 1 | 1 |
| users | 2 | 2 |
| roles | 2 | 2 |
| permissions | 34 | 34 |
| user_roles | 2 | 2 |
| role_permissions | 37 | 37 |
| students | 25 | 25 |
| audit_logs | 749 | 749 |
| Each of the other 12 academic/configuration tables | 0 | 0 |

## Regression and remaining boundaries

The existing foundation suite now verifies disabled registration and supplies
its compatibility user exclusively through disposable CLI fixture setup. The
isolated local compatibility runner also runs seed safety in a separate schema.
Docker testing covers the real image, both DB alias styles and ports 8080/9090,
module/authentication/RBAC/CSRF/audit regressions and startup provisioning.
See `tests/README.md` for reproducible commands and cleanup behavior.

Phase-1 results: PHP lint 124 files; JS syntax 17 files; CSS structure 18 files;
DB configuration 16 checks; CSV safety and mock sidebar/clock tests passed.
Isolated MariaDB 10.4: migration guards 29, fresh FK checks 32, populated legacy
upgrade checks 92, seed safety 40; all passed and all temporary schemas/data
directories removed. Full Docker build/runtime suite passed, including 111
in-image PHP lints and 1,580 foundation checks for each DB alias style (3,160
total), injected ports 8080/9090, sensitive URL restrictions, controlled DB
failure, and first-admin/startup rollback/idempotence tests. Docker containers,
anonymous database volumes and test networks were removed by the harness.
The first Docker attempt found a stopped engine; the installed Docker Desktop
was started and the complete suite then passed. No test was bypassed.

Direct XAMPP checks confirmed registration 404, the legacy step redirect, login
page 200, protected unauthenticated redirects, registration POST 403 with valid,
missing and invalid CSRF, GET registration 405, and unchanged users/mappings/audits.

Automated HTTP/DOM-source/mock tests are not a manual browser usability review.
Existing personal localhost credentials are not reset or solicited for testing;
authenticated workflows are tested using isolated generated accounts.

The proper connected BSIT synthetic defense dataset is still pending user-supplied
content and approval. The current 42-row seed is only regression/demo tooling,
not that final dataset. CIMF, JWT and client-confirmed institutional roles remain
unimplemented/deferred. Derived history is not live offering history; compliance
and equivalency remain configured/human-reviewed support, not official approval.
