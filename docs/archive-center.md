# Settings Archives

The Archives tab in `dashboard/account.php` reads authoritative curriculum tables through an explicit four-module registry. It does not copy or delete records. The table is paginated in SQL, with case-insensitive identifier/title search ranked by exact code, code prefix, title prefix, code contains, then title contains. Module, actor, previous status, program/strand context, and date have separate filters. Archive dates are stored as UTC `DATETIME`; date filters use Asia/Manila calendar boundaries.

## Supported records

| Module | Archive state | Restore checks |
| --- | --- | --- |
| Academic Programs | `status=inactive` plus `archived_at` | Current archive state; duplicate active code; database uniqueness |
| Curricula | `status=inactive` plus `archived_at` | Active parent program; duplicate active code; database uniqueness |
| Subjects | `status=inactive` plus `archived_at` | Duplicate active code; database uniqueness; placements remain untouched |
| Elective Groups | `status=archived` | Active program and curriculum; version exists and is not archived; at least two active member subjects; matching placements and selection count; duplicate active name in the same version |

Ordinary `inactive` program, curriculum, or subject records are **not** archives. The old Program Archive button changed only `status` to `inactive`; pre-migration inactive programs have no reliable way to distinguish an archive action from an ordinary status edit. They are intentionally not backfilled into Archives. Older elective groups with `status=archived` appear, but their archive date, actor, and previous status are unknown. Restoration is blocked until an administrator has reviewed their previous status; the UI explains this.

Curriculum Versions can be created directly with the technical status `archived`, but the current application has no version archive transition or restore action. They are excluded until that status is given an explicit archive lifecycle. Subject Mapping placements are removed or unassigned, not archived. Prerequisite rules use Remove Rule and have no archived state. These are intentionally excluded. Archiving a program does not cascade to curricula or versions; that is existing behavior. Restoring a child while its parent is inactive is blocked.

## Access and audit

Migration 015 adds `archive.view` and `archive.restore` to the existing permission tables and grants both to the temporary `admin` role. Restore also requires the module's update/manage permission. The archive endpoints require authentication, CSRF for mutation, a valid positive ID, and a registry module key. Archive and restore audit events are written in the same transaction as record changes, with module, identifier, previous and new status. `archived_by` points to the authenticated actor where the module action uses HTTP; missing older actor information displays as Unknown.

## Deployment and verification

Apply `015_central_archives.sql` after migration 014. It adds archive metadata and indexes to four existing tables and adds two permissions. On a journaled installation, use the normal deployment runner. On an unjournaled installation, verify the selected schema and prior migrations before applying only 015. Do not rerun old migrations.

For a disposable test database, run `php database/bootstrap.php --database=sms_db_archive_test --confirm-empty`, set `SMS_DB_NAME=sms_db_archive_test`, then run `php tests/archives.php`. Run `php tests/archives-http.php` against a local source schema with 015 applied; it creates and removes its own isolated schema and HTTP server. Manual browser checks remain for the desktop and small-screen table, wrapped filters, both modals, keyboard focus, and module archive dialogs.
