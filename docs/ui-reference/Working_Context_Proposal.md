# Working context on the server: proposal (not built, waiting for approval)

Status: **approved and built 2026-11-04; applied to `sms_db_uitest` only, not yet to `sms_db`.** Final decisions are in D4 of `UI_Decisions.md`; where this proposal differs (rule c actions, rule d order), D4 and `WorkingContextService.php` are correct.

## What it adds
Today the shared working context (Program / Curriculum / Version) is kept in `sessionStorage`: it survives a reload and moves between pages in one tab, but it is gone in a new tab, after closing the browser and after signing out. The server store keeps it per user, so it follows the person. It also makes two resolution rules possible that need server data.

Resolution order on page load (rules a and b are already built on the temporary store):

| | Rule | Where |
|---|---|---|
| a | `program_id` / `curriculum_id` / `version_id` in the address, if valid and permitted for the page's module | client (built) |
| b | the user's saved working context | client now, server later |
| c | the version the user last edited | **server (needs approval)** |
| d | the newest active version of the first permitted program | **server (needs approval)** |
| e | nothing: empty picker, "Choose a curriculum version to begin" | client (built) |

Each saved id is validated when it is read. A deleted or unpermitted version fails its rule and the next rule is tried.

## 1. Migration (draft): `database/migrations/026_create_user_preferences.sql`
Same conventions as migrations 021-025 (header comment, `USE sms_db;` that the deploy and test scripts rewrite to the target database, InnoDB, utf8mb4, JSON check as in `audit_logs.metadata_json`).

```sql
-- Per-user interface preferences. First use: the shared working context (Program / Curriculum / Version).
USE sms_db;

CREATE TABLE user_preferences (
  user_id INT UNSIGNED NOT NULL,
  pref_key VARCHAR(64) NOT NULL,
  pref_value LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, pref_key),
  CONSTRAINT fk_user_preferences_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT ck_user_preferences_json CHECK (JSON_VALID(pref_value))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```
- `users.id` is `INT UNSIGNED` (checked), so the foreign key matches. Deleting a user deletes their preferences.
- Only one key is used at first: `working_context`. The value stores **integers only**: `{"program_id":1,"curriculum_id":10,"version_id":100,"recent":[{"program_id":..,"curriculum_id":..,"version_id":..}, ...]}` (at most 3 recent). Codes, names and status are never stored; they are read from the real tables each time, so nothing stale or user-typed is kept.
- No default data, no change to existing tables, no permission seeding (it is the user's own preference, so it needs no RBAC entry; see question 3).
- Not run anywhere yet. After approval: backup, apply to `sms_db_uitest` only, verify, and leave deployment to `sms_db` to the normal process.

## 2. Endpoint (draft): `modules/curriculum/http/working_context_actions.php`
Uses the same helpers as every other curriculum endpoint (`support.php` already requires an authenticated user, answers JSON, maps errors).

```php
<?php

require_once __DIR__ . '/support.php';
require_once __DIR__ . '/../services/WorkingContextService.php';

$action = curriculum_http_action();
$service = new WorkingContextService($conn);

try {
    switch ($action) {
        case 'read':    // GET: resolves the context for this user and module (rules a-d); read-only
            require_http_method(['GET']);
            json_response(true, 'Working context loaded.', $service->resolve(curriculum_actor_id(), $_GET));

        case 'save':    // POST: CSRF-checked, saves this user's own context
            require_http_method(['POST']);
            require_valid_csrf_token();
            json_response(true, 'Working context saved.', $service->save(curriculum_actor_id(), $_POST));

        default:
            json_response(false, 'Unknown action.', [], 400);
    }
} catch (Throwable $error) {
    curriculum_handle_error($error);
}
```

`WorkingContextService` (new, `modules/curriculum/services/`), behaviour:

- **Identity:** the user id always comes from the session (`curriculum_actor_id()`), never from the request. A user can only read or write their own row.
- **`save` input:** `program_id`, `curriculum_id`, `version_id` (positive integers, validated with `DomainValidator::positiveId`). It checks that the three belong together (version belongs to the curriculum, curriculum to the program) and that the user holds the base permissions, then stores the JSON with `INSERT ... ON DUPLICATE KEY UPDATE`. The `recent` list is maintained on the server (newest first, one per version, at most 3), so the client cannot inject entries. The stored value is capped at 2 KB.
- **`read` input:** optional `module` (an allowlist: `subject_mapping`, `prerequisite`, `electives`, `export`, `grade_weighting`, each mapped to that module's existing `*.view` permission) and optional address ids. It tries rules a, b, c, d in order and returns the first candidate that is consistent and permitted for that module:
  ```json
  { "rule": "saved", "context": { "program": {"id":1,"code":"BSIT","name":"..."}, "curriculum": {"id":10,"code":"..","name":".."}, "version": {"id":100,"label":"PREORAL-2026","status":"draft","year":".."} },
    "recent": [ ...up to 3 entries, each re-validated, invalid ones dropped... ] }
  ```
  `rule` is `null` and `context` is `null` when nothing applies (rule e).
- **Validation query** (prepared statement, used by every rule):
  ```sql
  SELECT p.id AS program_id, p.program_code, p.program_name,
         c.id AS curriculum_id, c.curriculum_code, c.curriculum_name,
         v.id AS version_id, v.version_label, v.status, v.effective_academic_year
  FROM curriculum_versions v
  JOIN curricula c ON c.id = v.curriculum_id
  JOIN academic_programs p ON p.id = c.academic_program_id
  WHERE v.id = ? AND c.id = ? AND p.id = ?;
  ```
- **Rule c, the version the user last edited** (checked on `sms_db_uitest` data: for the most active editor it returns PREORAL-2026, the BSIT draft they last changed):
  ```sql
  SELECT COALESCE(v1.id, v2.id) AS version_id
  FROM audit_logs a
  LEFT JOIN curriculum_versions v1 ON a.entity_type = 'curriculum_version' AND v1.id = a.entity_id
  LEFT JOIN curriculum_version_subjects s ON a.entity_type = 'curriculum_version_subject' AND s.id = a.entity_id
  LEFT JOIN curriculum_versions v2 ON v2.id = s.curriculum_version_id
  WHERE a.user_id = ?
    AND a.action IN ('CURRICULUM_VERSION_CREATE','CURRICULUM_VERSION_UPDATE',
                     'CURRICULUM_SUBJECT_ASSIGN','CURRICULUM_SUBJECT_UPDATE','CURRICULUM_SUBJECT_REMOVE')
    AND COALESCE(v1.id, v2.id) IS NOT NULL
  ORDER BY a.id DESC LIMIT 1;
  ```
  It only reads the audit log; it adds nothing to it. A version whose placements were all removed is skipped because the join finds nothing.
- **Rule d, newest active version of the first permitted program** (checked on the same data: returns BSCRIM / CRIMINALISM / ROTC):
  ```sql
  SELECT v.id AS version_id, c.id AS curriculum_id, p.id AS program_id
  FROM curriculum_versions v
  JOIN curricula c ON c.id = v.curriculum_id
  JOIN academic_programs p ON p.id = c.academic_program_id
  WHERE v.status = 'active' AND p.status = 'active'
  ORDER BY p.program_code ASC, v.updated_at DESC, v.id DESC LIMIT 1;
  ```
- **Errors and audit:** all failures go through `curriculum_handle_error` like the other endpoints. Preference writes are not audit-logged (they are personal UI state, and logging every picker change would flood `audit_logs`); see question 4.

## 3. Client change (small)
Only the `store` object in `js/curriculum-context.js` changes:
- on page load, one `GET working_context_actions.php?action=read&module=...&program_id=...` (address ids included) replaces rules a to d with the server's answer; the page still applies the answer through its own permission-checked lookups, so the module checks stay;
- on every change, a debounced `POST ...action=save` (CSRF token passed to the script through a data attribute, as the session-timeout script already does);
- `sessionStorage` stays as a fallback and a fast cache when the request fails, so a network error never leaves the picker unusable.

The picker, the pages' own scripts and the events do not change.

## 4. Test plan (all on `sms_db_uitest`)
1. Backup, then apply migration 026 to `sms_db_uitest` only. Confirm `sms_db` has no `user_preferences` table afterwards.
2. DB-free unit tests for the service rules (consistency check, rule order, recent list) with an in-memory repository.
3. Endpoint HTTP tests: unauthenticated 401, `save` without CSRF 403, `read` with `POST` 405, another user's ids cannot be written into my row, an id from a different program tree is refused, a 2 KB cap, `module` allowlist, deleted/unpermitted version falls to the next rule.
4. Logged-in run for all four roles at 1440 and 390 px: choose a context, open another tab, **sign out and in**, and confirm it is restored; confirm a viewer who lacks a module's permission gets the next rule on that module.

## Questions for you
1. **"Permitted"** is checked per module with that module's own `*.view` permission (the same check its option lists use). OK?
2. **Rule c** counts version create/update and subject placement assign/update/remove by the user. Should other actions count (for example prerequisite or outcome edits)? Adding them is one more line in the list.
3. **No RBAC entry** for reading and saving one's own working context. OK, or should it be gated by an existing permission?
4. **No audit log** for saving the context. OK?
5. **Rule d** orders programs by code, then newest update. Prefer a different order (for example the program the user edits most)?
