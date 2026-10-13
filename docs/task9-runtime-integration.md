# Task 9 runtime integration findings

## Scope and evidence

Task 9 was exercised on the isolated `feature/phase1-task9-runtime-integration-stabilization` worktree. HTTP tests used newly initialized loopback MariaDB 10.4.32 schemas with foreign-key checks enabled and migrations 001–025. The existing XAMPP academic database and production services were not accessed. A second disposable database remains available through the preview instructions in `task9-preview.md`.

The observed initial failure reports and original UI behavior were investigated from the application code. No connection was made to the database named by the local default configuration, so its schema and migration state remain unknown. A disposable fault-injection test confirms that a missing lifecycle column produces the same version-list query failure and that the new endpoint returns a safe 503.

## Failure paths and corrections

- **Program details counts:** `modules/curriculum/assets/curriculum-builder.js` previously assembled counts by paging through curricula, versions, and placements and treated a failed version request as a wholly unavailable summary. It also stopped at page size 50. `AcademicProgramRepository::curriculumSummary()` now calculates stored curricula, versions, and version placements in one aggregate; `program_actions.php?action=summary` is permission checked and validates the program. The UI labels the scope, distinguishes loading/error from valid zero, and reports the failure.
- **Version list:** `CurriculumVersionRepository::paginateByCurriculum()` selects Task 1 lifecycle fields including `version_role`. On a schema missing fields introduced by migration 021, MariaDB reports an unknown column. `curriculum_handle_error()` now logs the server exception and returns a stable schema-compatibility 503 for MariaDB missing-table/column errors; other unexpected domain failures return a generic 500. The Builder displays “Not loaded,” an actionable error, and Retry instead of zero versions. No automatic migration runs.
- **Dashboard context:** the dashboard had its own first-page-only selector and metrics requests could finish after the selected context changed. `curriculum-dashboard.js` now uses the shared cascading selector, carries selected IDs into linked workflows, invalidates old metrics requests, and checks the returned program and curriculum context. `dashboard/analytics.php` validates optional expected ownership IDs and reads its metrics in one read-only consistent snapshot.
- **Subject Mapping context:** when initialized from an explicit version link, the old code populated selector values without enabling dependent controls. It now applies the authoritative program/curriculum/version tuple through `setContext()`, suppresses saved-context restoration for that link, clears dependent IDs when a parent changes, and ignores delayed CLO/outcome responses from an older selection.

These fixes reuse existing permissions. The new program summary requires both `program.view` and `curriculum.view`; analytics retains `dashboard.view` and `curriculum.view`. No existing role grants or migration files changed.

## Verification limits

The fault-injection HTTP test proved the missing-column behavior against a disposable schema and verified the safe 503, absence of a false empty list, no SQL-detail disclosure, independent truthful program counts, and successful recovery after the test restored the column. The PHP built-in-server log file could not be captured reliably by the harness, so the test does not assert its bytes. The production/shared logger call is present in `modules/curriculum/http/support.php` and the exception is passed to it.

Static source review and isolated integration cannot establish why a separately existing academic installation showed the original error. Its database migration status and runtime configuration require a separately authorized, read-only review of that specific installation.