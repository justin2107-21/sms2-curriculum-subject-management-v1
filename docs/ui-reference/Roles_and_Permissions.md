# Roles and permissions: final user types

Status: **built and tested on `sms_db_uitest`; not applied to `sms_db`.** The matrix below is approved. The code in this tree needs migration 027: without it, every permission check fails on `roles.is_active`.

## Students table
Kept for SMS2 integration and rollback; not used by CSMS. No CSMS page, menu or dashboard shows students, and only Super Admin holds `student.*`. The owner confirms the 25 rows are synthetic, not real people.

## 1. User types and roles
| User type | Role (`roles.name`) | Display name | Replaces |
|---|---|---|---|
| Super Admin | `super_admin` (new) | Super Admin | `system_administrator` |
| Admin | `admin` (existing row, redefined) | Admin | legacy `admin` |
| Staff | `staff` (new) | Staff | `academic_curriculum_staff` |
| Acad | `acad_office` (new) | Acad — Office | `department_head_curriculum_reviewer` |
| Acad | `acad_faculty` (new) | Acad — Faculty | `faculty_academic_viewer` |

`student` is retired with no replacement.

## 2. Decisions and design notes
- **Review workflow:** built as `CurriculumReviewService`, `http/review_actions.php` and a panel on the version workspace. Only proposed revisions can be submitted. A reason is required to approve or return. Nobody can decide their own submission. Each step writes a review event and an audit entry.
- **Faculty read scope:** one setting, `CurriculumReadScope::FACULTY_READ_SCOPE` (or env `CSMS_FACULTY_READ_SCOPE`). It is `active_or_approved` now; change it to `approved` once approvals exist. Enforced on the server for version ids, placements, elective groups, lists, CIMF context, the dashboard and the working context.
- **Export:** approved versions need `curriculum_export.official` (Super Admin, Admin, Acad Office). Others need `curriculum_export.draft` (Super Admin, Staff).
- **`rbac.manage`** now means editing permissions only. Accounts use `user.manage`.
- **Accounts:** `csms_admin` and `kennethbonio` become Super Admin. `curriculum_reviewer` and `KENNETH123` become Acad Office. `admin` becomes plain Admin. `takashii001` is deactivated, its data kept, and rollback restores it.
- **Faculty CLOs:** read-only until a faculty-subject link exists; Staff manage CLOs and assessment plans until then.
- **Fresh install:** `setup.php` and `provision_admin.php` create a Super Admin.

## Deprecated: `users.role`
The `users.role` column (enum admin/student) is **deprecated**. It is kept, it does not hold the new user types, and it is not used for authorization; `user_roles` and `role_permissions` decide. `tests/rbac-final-roles.php` asserts that changing it changes nothing.

Remaining uses:
- Writes: `shared/setup.php:65` and `database/provision_admin.php:65` write the legacy value `admin`. Other inserts take the column default `student`.
- Migrations `001`, `002`, `020` mention it and must not change.
- Test fixtures set or read it: `tests/bootstrap_assertions.php`, `demo_import_integration.php`, `foundation.php`, and fixtures inserting users.

Moved to `user_roles`: the access decisions in `database/import_demo.php`, `repair_demo_program.php` and `provision_admin.php`.

## Later tasks
- Split `curriculum.update` so Admin can't edit placements.
- Drop `users.role`.
- Add the faculty-subject link, then give Faculty CLO management of their own subjects.
- Switch the Faculty read scope to `approved` once approvals exist.

## 3. Where roles and permissions are referenced
**Schema and seeds**
- `database/migrations/001_create_rbac_tables.sql:7,17,28,47`: the four tables.
- `002_backfill_legacy_roles.sql`: legacy roles and grants. `007, 008, 012, 018`: added permissions. `020_assign_prototype_rbac_roles.sql:6-64`: current four roles, grants, account links.
- `database/ProvisionalRoles.php:5-34`: definitions and grantable list; `:26` holds four sensitive permissions. `:35` `seed()` has no callers in the application, tests, seeds or tools.
- Migrations 021 to 025: lifecycle tables and the sensitive permissions. Must not change (the deploy checksum guard rejects edits).

**Authorization core**
- `shared/authorization.php:19,61,95,104`: role and permission lookup, `user_can`, `require_permission`. Neither query considers whether a role is active.
- `shared/auth.php:56-64`: session role display from `user_roles`.
- `shared/auth_actions.php:231`, `dashboard/account.php:5,13`: self-delete blocked for defined roles; role label.
- `shared/setup.php:52,61,67,89`: temporary `admin` user; `users.role` ENUM.
- `database/provision_admin.php:35,44,49,66`; `database/import_demo.php:100-104`; `database/repair_demo_program.php:35`; `database/seeds/bsit_dataset.php:64`; `database/seeds/development.php:85`: all expect `admin`.

**Admin pages**
- `admin/AccountAdministration.php:11,24,30,38,42,54-61`: role list, self-edit block, allowed roles, permission saves. `:42` writes `users.role='student'`, a legacy column that is not an authorization source.
- `admin/account_actions.php:3`, `admin/account_form.php:3,5-6,17`, `admin/users.php:3,63`, `admin/permissions.php:3`, `admin/role_permission_forms.php:3,5,10-11`.
- `admin/SystemAdminRepository.php:14,45`, `admin/audit_logs.php:3`, `admin/settings.php:3`.

**Navigation and dashboards**
- `shared/sidebar.php:10-25`, `shared/ui/sidebar.php:12,52-56`.
- `dashboard/dashboard.php:3,12`, `dashboard/dashboard_data.php:10,28`, `dashboard/analytics.php:5-6`, `dashboard/loading.php:3`, `dashboard/archive_actions.php:12,22,27`, `home_tab/overview.php:5`, `reports_tab/reports.php:7,12,16`.

**Curriculum module**
- `modules/curriculum/http/*_actions.php` and `pages/*.php`: permission checks throughout; none reference a role name.
- Sensitive permissions: `http/version_reference_actions.php:16,21`, `http/compliance_actions.php:71`, `http/version_actions.php:13,19`, `http/outcome_mapping_actions.php:14,22,28`, `http/clo_assessment_actions.php:145-146`; `pages/curriculum_builder.php:62-64`, `pages/subject_mapping.php:18-20`, `pages/compliance_validator.php:49`.
- `shared/student_actions.php:24-32`: `student.*`.

**CIMF**
- `modules/curriculum/services/CIMFProfileRegistry.php:41-42,63-64`: `admin/users.php` route uses `rbac.manage`, must move to `user.manage`.
- `modules/curriculum/http/global_cimf_actions.php:21-22`, `shared/cimf_panel.php:9-10`, `services/CIMFGlobalContextBuilder.php:81,102`.
- Working context: `modules/curriculum/services/WorkingContextService.php` module-to-permission map; rule (d) must respect the Faculty read scope.

**Tests and tooling**
- `tests/curriculum-clo-assessment-http.php:33`, `tests/curriculum-outcome-mapping-http.php:17`: assert sensitive permissions have **no** grants; will fail after 027 and must change.
- `tests/electives-http.php:24`: role name.
- Use `admin` or legacy roles: `tests/archives-http.php`, `archives.php`, `bootstrap_assertions.php`, `bsit_seed.php`, `demo_import_integration.php`, `deployment_admin.php`, `deployment_migrations.php`, `development_seed.php`, `foundation.php`, `prerequisite-http.php`, `subject-mapping-http.php`, `version-protection-fixture.php`, `restored_baseline.php`, `original_post_migration_readonly.php`, `docker-bootstrap-scenarios.ps1`.
- `tools/uitest/seed-users.php:28-31,39-40`, `tools/uitest/run.ps1`, `tools/task*-preview-fixture.php`.

**Docs:** `docs/ui-reference/UI_Testing.md:14-18`, `Dashboard_Plan.md:59`, `database/README.md`, `docs/hostforge-deployment.md`, `docs/core-foundation-v1-report.md`.

## 4. Permission matrix
`✓` granted. `—` not granted. `◐` scoped by a server rule. **New** = key added by 027. **(P)** = my proposal where your responsibilities did not settle it.

| Permission | Super Admin | Admin | Staff | Acad Office | Acad Faculty |
|---|---:|---:|---:|---:|---:|
| **Dashboard and account** | | | | | |
| `dashboard.view` | ✓ | ✓ | ✓ | ✓ | ✓ |
| `account.view` | ✓ | ✓ | ✓ | ✓ | ✓ |
| `account.update` | ✓ | ✓ | ✓ | ✓ | ✓ |
| **Administration** | | | | | |
| `rbac.manage` (edit permissions only, after the split) | ✓ | — | — | — | — |
| `user.manage` **New** | ✓ | ◐ Staff and Acad only | — | — | — |
| `audit.view` | ✓ | ✓ | — | — | — |
| `settings.view` | ✓ | — | — | — | — |
| `api_client.manage` (create, rotate, revoke API clients; migration 029) | ✓ | — | — | — | — |
| **Programs** | | | | | |
| `program.view` | ✓ | ✓ | ✓ | ✓ | ✓ |
| `program.create` | ✓ | ✓ | — | — | — |
| `program.update` | ✓ | ✓ | — | — | — |
| `program_assignment.view` | ✓ | ✓ | ✓ | ✓ | — |
| `program_assignment.manage` (P) | ✓ | ✓ | — | — | — |
| **Curricula and versions** | | | | | |
| `curriculum.view` | ✓ | ✓ | ✓ | ✓ | ◐ read scope (see below) |
| `curriculum.view_unapproved` **New** | ✓ | ✓ | ✓ | ✓ | — |
| `curriculum.create` | ✓ | ✓ | — | — | — |
| `curriculum.update` (records and placements) | ✓ | ✓ | ✓ | — | — |
| `curriculum.version.create` | ✓ | ✓ | ✓ | — | — |
| `curriculum.version.clone` * | ✓ | — | ✓ | — | — |
| `curriculum.review.submit` **New** | ✓ | — | ✓ | — | — |
| `curriculum.review.decide` **New** (approve/return with reason) | ✓ | — | — | ✓ | — |
| **Reference documents** | | | | | |
| `curriculum.reference.attach` * (P) | ✓ | — | ✓ | — | — |
| `curriculum.reference.classify` * (P) | ✓ | — | — | ✓ | — |
| `compliance.reference.verify` * | ✓ | — | — | ✓ | — |
| **Subjects** | | | | | |
| `subject.view` | ✓ | ✓ | ✓ | ✓ | ✓ |
| `subject.create` (P) | ✓ | ✓ | ✓ | — | — |
| `subject.update` (P) | ✓ | ✓ | ✓ | — | — |
| `subject_history.view` | ✓ | ✓ | ✓ | ✓ | — |
| **Prerequisites and electives** | | | | | |
| `prerequisite.view` | ✓ | ✓ | ✓ | ✓ | ✓ |
| `prerequisite.manage` | ✓ | — | ✓ | — | — |
| `elective.view` | ✓ | ✓ | ✓ | ✓ | ✓ |
| `elective.manage` | ✓ | — | ✓ | — | — |
| **Outcomes** | | | | | |
| `curriculum.outcome_mapping.manage` * | ✓ | — | ✓ | — | — |
| `curriculum.clo.manage` * | ✓ | — | ✓ (P, interim) | — | — until a link exists |
| `curriculum.assessment_plan.manage` * (P) | ✓ | — | ✓ | — | — until a link exists |
| **Grade weighting** | | | | | |
| `grade_weighting.view` | ✓ | ✓ | ✓ | ✓ | — |
| `grade_weighting.manage` | ✓ | — | ✓ | — | — |
| **Equivalency** | | | | | |
| `equivalency.view` | ✓ | ✓ | ✓ | ✓ | — |
| `equivalency.manage` | ✓ | — | — | ✓ | — |
| **Compliance validator (advisory)** | | | | | |
| `compliance.view` | ✓ | ✓ | ✓ | ✓ | — |
| `compliance.run` (P for Acad Office) | ✓ | — | ✓ | ✓ | — |
| `compliance.manage` | ✓ | — | — | — | — |
| **Export** | | | | | |
| `curriculum_export.view` (opens the page) | ✓ | ✓ | ✓ | ✓ | — |
| `curriculum_export.draft` **New** (versions not approved) | ✓ | — | ✓ | — | — |
| `curriculum_export.official` **New** (approved versions) | ✓ | ✓ | — | ✓ | — |
| **Archives** | | | | | |
| `archive.view` (P) | ✓ | ✓ | ✓ | — | — |
| `archive.restore` (P; also needs the module's update permission) | ✓ | ✓ | ✓ | — | — |
| **Legacy student records** | | | | | |
| `student.view`, `.create`, `.update`, `.delete`, `.status.update` (P) | ✓ | — | — | — | — |

`*` = one of the seven sensitive permissions.

Totals: 45 existing keys plus 6 new = 51, plus `api_client.manage` (migration 029, Super Admin only) = 52.

### Existing permission keys (45)
`account.update`, `account.view`, `archive.restore`, `archive.view`, `audit.view`, `compliance.manage`, `compliance.reference.verify`, `compliance.run`, `compliance.view`, `curriculum.assessment_plan.manage`, `curriculum.clo.manage`, `curriculum.create`, `curriculum.outcome_mapping.manage`, `curriculum.reference.attach`, `curriculum.reference.classify`, `curriculum.update`, `curriculum.version.clone`, `curriculum.version.create`, `curriculum.view`, `curriculum_export.view`, `dashboard.view`, `elective.manage`, `elective.view`, `equivalency.manage`, `equivalency.view`, `grade_weighting.manage`, `grade_weighting.view`, `prerequisite.manage`, `prerequisite.view`, `program.create`, `program.update`, `program.view`, `program_assignment.manage`, `program_assignment.view`, `rbac.manage`, `settings.view`, `student.create`, `student.delete`, `student.status.update`, `student.update`, `student.view`, `subject.create`, `subject.update`, `subject.view`, `subject_history.view`.

### Access people lose
- **Staff:** creating/updating programs, creating curricula, program assignment changes.
- **Acad Faculty:** equivalency, grade weighting, compliance, program assignment, subject history and export pages (403).
- **Legacy `admin` account:** permissions, compliance rules, settings, content editing other than records.

## 5. Safety rules (all server-side)
Enforced in `AccountAdministration`, inside the transaction with row locks, whatever the UI shows:

1. **Role ceiling.** An actor without `rbac.manage` may create, edit, deactivate or assign only accounts whose current **and** target roles are `staff`, `acad_office` or `acad_faculty`. So Admin cannot touch Super Admin or other Admin accounts, and `get` also refuses to load them (403).
2. **No self-escalation.** Keep the self-edit block (`:24`) and the own-role permission block (`:55-56`). Add: `assigned_by` must differ from the target; ID checks use the database value, never a posted role name.
3. **Last Super Admin.** Under `FOR UPDATE`, refuse to demote or deactivate if it would leave zero active Super Admins. Self-delete remains blocked for defined roles.
4. **Permission ceiling.** `rbac.manage` is required for permission saves. `rbac.manage` and `settings.view` are never grantable to any role but `super_admin`, and the `super_admin` role cannot be edited, so it cannot be stripped. `user.manage` is grantable only to `super_admin` and `admin`.
5. **Retired roles.** `get_user_role_assignments` and `get_user_permissions` ignore `is_active = 0` roles; retired roles cannot be assigned.
6. **Review separation (P).** A user cannot decide a version they submitted. Return requires a reason. Every decision writes a review event and an audit entry.
7. **Unchanged:** CSRF on all writes, lifecycle guards, advisory-only CIMF. 403 for pages, JSON 403 for endpoints.

## 6. Migration 027
`database/migrations/027_final_user_types.sql`, applied through `deploy.php`. It deletes nothing, because `deploy.php` refuses `DELETE` in migrations:
1. Fails first if accounts exist but none would be an active Super Admin.
2. Snapshots roles, assignments and grants into `rbac_snapshot_027_*`.
3. Adds `roles.user_type` and `roles.is_active`, and 6 permissions.
4. Renames the legacy `admin` row to `legacy_admin`, and marks it and the old roles inactive. Their rows stay and have no effect, because authorization ignores inactive roles.
5. Creates the five roles with the matrix grants and gives each account its highest mapped role.
6. Deactivates accounts left with no active role, and writes one audit entry.

Rollback: `database/rollbacks/027_rollback.sql`, kept outside `migrations/`. It also needs the code reverted. Accounts created after 027 map back to the old equivalent role. Review events recorded after 027 remain.

## 7. Tests
- `tests/rbac-final-roles.php`: the matrix in both directions, retired roles, account mapping, `users.role` ignored, no students on CSMS pages.
- `tests/rbac-safety-http.php`: the safety rules, page access per role, sidebar per role, read scope, export split and review workflow over real HTTP.
- `tools/uitest/run.ps1 -Role superadmin|admin|staff|acadoffice|acadfaculty`: the logged-in check. Run one job at a time.
