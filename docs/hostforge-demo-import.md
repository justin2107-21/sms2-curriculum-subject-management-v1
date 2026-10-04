# One-time HostForge demonstration import

This operation inserts the reviewed synthetic BSIT pre-oral dataset into an empty academic schema. It does not create users, students, approvals, archives, offerings, or official BCP rules. The source is `database/seeds/bsit_preoral_manifest.json`, pinned to its reviewed SHA-256 digest. It contains 50 subjects, 50 placements and 152 units. The manifest has no approved prerequisite relationships. Its ITE1–ITE4 subjects occupy different terms, so this import does not assign them to an invented common elective group. Both relationship tables and elective group tables remain empty.

## Temporary HostForge variables

Set these only for the import deployment. Preserve the normal `APP_ENV=production` and existing `DB_*` settings.

```text
CSMS_RUN_DEMO_IMPORT=1
CSMS_DEMO_IMPORT_CONFIRM_DATABASE=<production DB>
CSMS_DEMO_IMPORT_CONFIRM_DATASET=DEV-BSIT-PREORAL-2026
CSMS_DEMO_IMPORT_CONFIRM_MODE=PRODUCTION-DEMO
CSMS_DEMO_IMPORT_ACTOR_ID=<existing admin ID>
```

The configured DB name, confirmation value, and `SELECT DATABASE()` must agree. The actor must be an existing user with both the legacy admin role and normalized admin assignment. The current users table has no active/inactive field; existence and admin membership are the available account checks. Leave `CSMS_BOOTSTRAP_ADMIN` and `CSMS_RUN_MIGRATIONS` unset for this deployment. Migrations 014 and 015 must already be applied.

## Procedure

1. Verify a current recoverable production backup outside the application and verify the current admin login.
2. Confirm that production academic tables are empty and migrations through 015 are applied. Add the temporary variables above with the exact production database name and existing admin ID.
3. Deploy the reviewed `main` revision. The startup hook invokes `database/import_demo.php --apply --confirm-dataset=DEV-BSIT-PREORAL-2026` before Apache. A failure stops startup. A completed prior import reports `Controlled demo dataset already present; no changes made.`
4. Check safe startup logs for the success message. Verify `/health.php`, admin login, and dashboard curriculum counts. Expect 1 program, 1 curriculum, 1 version, 50 subjects, 50 placements, 152 total units and one aggregate `DEMO_DATASET_IMPORT` audit event.
5. Remove **all five** temporary import variables from HostForge. Deploy again normally. Verify the data remains and the import hook is not invoked.

With an operator CLI, `php database/import_demo.php --dry-run` performs the same environment, schema, actor, ownership and collision checks without writes. Apply requires `php database/import_demo.php --apply --confirm-dataset=DEV-BSIT-PREORAL-2026` plus every environment variable above. An unowned or partially populated academic schema is refused. Do not reset or clear it to make the import pass; investigate it first.

The web server denies the `database` directory and the script itself returns 404 outside CLI. The original XAMPP-only BSIT tool keeps its local database and backup guards.
