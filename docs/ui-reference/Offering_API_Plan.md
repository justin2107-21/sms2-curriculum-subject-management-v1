# Subject Offering History: Offering API (Slice 4A, design only)

Date: 2026-11-11 · Branch `ui-redesign-pilot` · Status: **design, awaiting approval. No code, schema or data was changed.** `sms_db` was not touched; `sms_db_uitest` was queried read-only. The module name "Subject Offering History" is fixed.

Purpose: Curriculum & Subject Management is the master of subjects. Class Scheduling (Section Assignment Tool) is the essential source of real offerings; Enrollment (aggregate counts) and Faculty are optional. They call a small REST API; CSMS stores what they report and shows it next to, never mixed with, the curriculum placement history. We do not assume how other subsystems are built.

## Status (2026-11-11): slices 4B (backend), 4C (UI, read-only) and 4E (demo + contract) BUILT. Slice 4D (admin screen) is skipped: the CLI stays. Where this section and the design text below differ, this section wins.

Commits: archived-edit rule (9ac49c6) and the API (see `git log`, "Offering API slice 4B"). Not applied to `sms_db`; applied only to `sms_db_uitest`. Slices 4C and 4E are built (see "Slices 4C and 4E" and "Demo steps" below). Slice 4D (admin screen) is intentionally not built; clients are managed with `scripts/api-client-create.php`.

### Final decisions (the owner's)
1. Migration **029** for the API; Electives is renumbered **030** (`Electives_Plan.md` updated).
2. New permission `api_client.manage`, **Super Admin only**, declared in `database/legacy_baseline.php` (`permissions_added`, `new_role_grants_only`, pinned checksum). `ProvisionalRoles`, `Roles_and_Permissions.md`, `tests/rbac-final-roles.php` and `tests/rbac-drift-report.php` now count 52 permissions.
3. Keys: `csms_<8 chars>_<43 chars>`, **HMAC-SHA256 of the secret part with `API_KEY_PEPPER`** (environment, at least 32 characters, fails closed), `hash_equals` compare, shown once, never logged or audited (audit holds the 8-character prefix only).
4. Batches are **per item, independent**. Each item reports `accepted`, `flagged` (accepted with flags) or `rejected`.
5. A client reads **its own rows only** (its `source_system`).
6. Contract values are **our assumptions**, to be confirmed with the client (needs client input / may be NDA-restricted): `academic_year` = `YYYY-YYYY` with consecutive years (2000-2100); `term` = `1st Semester`, `2nd Semester`, `Summer` (exact, case-sensitive); anything else is rejected and logged.
7. A known subject not placed in that program's curriculum is **accepted** with flag `not_in_program_curriculum`.
8. An inactive subject is **accepted** with flag `subject_inactive`. Unknown subject codes are rejected, written to `api_rejected_items` (with the code) and **never create a subject**. Inactive program: accepted with `program_inactive` (same logic, not in the decision list).
9. Machine calls are traced by client id, `received_at`, `payload_hash` and the rejected log. **Admin actions** (`API_CLIENT_CREATE`, `API_CLIENT_ROTATE`, `API_CLIENT_REVOKE`) write normal audit rows with the operator as actor.

### What exists
- `database/migrations/029_offering_api.sql` (tables `api_clients`, `reported_offerings`, `api_rejected_items`, permission and Super Admin grant) and `database/rollbacks/029_rollback.sql`.
- `api/v1/offerings.php` (GET and POST; pretty URL `/api/v1/offerings` via `.htaccess`, which also keeps the `Authorization` header), `shared/api_bootstrap.php` (no session, no cookies, no CSRF, JSON errors, generic 500), `ApiClientService`, `OfferingApiService`.
- `scripts/api-client-create.php` (CLI only; `/scripts` is blocked in `.htaccess`; the script answers 404 over the web): `create`, `rotate`, `revoke`, `list`. It needs `API_KEY_PEPPER`, and the operator must hold `api_client.manage`.
- `.env.example` lists `API_KEY_PEPPER` and `API_RATE_LIMIT_PER_MIN` (default 60 per minute per client).
- Runs on PHP 8.0 (local XAMPP) and 8.2 (Docker); no composer, hand-written validation.
- It writes only `reported_offerings`, `api_rejected_items` and the client counters. Tests assert that curriculum, subject, program, version and placement tables are unchanged.

### Final contract (supersedes section B where different)
Base `/api/v1/offerings`. `Authorization: Bearer <key>`. HTTPS required when `APP_ENV=production`.
- **Limits:** body 256 KB (413 `payload_too_large`); at most 100 items (413 `too_many_items`); 60 requests per minute per client, configurable (429 `rate_limited` with `Retry-After`).
- **Whole-request errors** `{"error":{"code","message"},"request_id"}` (request id: 32 hex characters): 400 `bad_request` (invalid JSON), 401 `unauthorized` (missing, malformed, unknown, wrong or revoked key: one answer), 403 `forbidden` (scope) or `https_required`, 405 `method_not_allowed`, 413, 422 `invalid_request` (shape, empty list, extra top-level key) or `invalid_query` (GET), 429, 500 `server_error`.
- **POST** `{"offerings":[ ... ]}`; item fields (nothing else is accepted): `external_id` (1-64 of `A-Za-z0-9._:-`), `subject_code`, `program_code` (1-60, letters, digits, space and `. _ / -`), `academic_year`, `term`, `status` (`offered`|`cancelled`), `section_count` (integer 0-999), `enrolled_count` (integer 0-1,000,000 or null), `reported_at` (ISO 8601; optional).
- **Per item:** `{"index","external_id","status":"accepted|flagged|rejected","action":"created|updated|unchanged","flags":[...]}` or `{"index","external_id","status":"rejected","error":{"code","field"}}`. Summary: `received, accepted, flagged, rejected, created, updated, unchanged`.
- **Reject codes:** `invalid_item`, `unknown_field`, `personal_field` (a key outside the whitelist that looks like personal data: name, email, phone, student, employee, instructor, and so on), `missing_field`, `invalid_type`, `invalid_external_id`, `invalid_code`, `invalid_academic_year`, `invalid_term`, `invalid_status`, `invalid_number`, `invalid_timestamp`, `unknown_subject`, `unknown_program`, `duplicate_in_request`, `stale`. Every string field is format-checked, so there is no free-text field; rejected values are never stored or echoed, only the field name, a code and a keyed hash.
- **Idempotency:** unique (`source_system`, `external_id`); `source_system` comes from the key, never from the body. Identical payload: `unchanged`, no write (not even `updated_at`). Changed payload: `updated`. Older `reported_at` than stored: `stale`. Cancellation is an update with `status: cancelled`; the API never deletes.
- **GET** (scope `read` or `read_write`): own rows; filters `subject_code`, `program_code`, `academic_year`, `term`, `status`; `page`, `per_page` (50, max 100); response `{"data":[...],"page","per_page","total","request_id"}`.

**Sample batch**
```json
{"offerings":[
 {"external_id":"CS-2026-0001","subject_code":"CC101","program_code":"BSIT","academic_year":"2026-2027","term":"1st Semester","status":"offered","section_count":4,"enrolled_count":132,"reported_at":"2026-11-10T08:00:00Z"},
 {"external_id":"CS-2026-0002","subject_code":"CC103","program_code":"BSIT","academic_year":"2026-2027","term":"1st Semester","status":"cancelled","section_count":0}
]}
```
**Sample response (one flagged, one rejected)**
```json
{"summary":{"received":3,"accepted":1,"flagged":1,"rejected":1,"created":2,"updated":0,"unchanged":0},
 "results":[{"index":0,"external_id":"CS-2026-0001","status":"accepted","action":"created","flags":[]},
            {"index":1,"external_id":"CS-2026-0002","status":"flagged","action":"created","flags":["not_in_program_curriculum"]},
            {"index":2,"external_id":"CS-2026-0003","status":"rejected","error":{"code":"personal_field","field":"instructor_name"}}],
 "request_id":"9f2c1d6e0a4b47c8b1e35d7a62f0c914"}
```
**OpenAPI-style summary for the other groups (no internal details)**
```yaml
openapi: 3.0.3
info: {title: CSMS Offerings API, version: "1"}
paths:
  /api/v1/offerings:
    post: {summary: "Report offerings (batch, max 100, idempotent per external_id). Items are processed independently.", security: [{bearerKey: []}],
           responses: {"200": per-item accepted/flagged/rejected, "400": invalid JSON, "401": unauthorized, "403": forbidden, "413": too large, "422": invalid request, "429": rate limited}}
    get:  {summary: "List the offerings you reported", security: [{bearerKey: []}], parameters: [subject_code, program_code, academic_year, term, status, page, per_page], responses: {"200": page of offerings}}
components:
  securitySchemes: {bearerKey: {type: http, scheme: bearer}}
  schemas:
    Offering: {required: [external_id, subject_code, program_code, academic_year, term, status, section_count], additionalProperties: false,
      properties: {external_id: {type: string, maxLength: 64}, subject_code: {type: string}, program_code: {type: string},
        academic_year: {type: string, pattern: '^[0-9]{4}-[0-9]{4}$', description: "Consecutive years. Assumption pending confirmation."},
        term: {enum: ["1st Semester", "2nd Semester", "Summer"], description: "Assumption pending confirmation."},
        status: {enum: [offered, cancelled]}, section_count: {type: integer, minimum: 0, maximum: 999},
        enrolled_count: {type: integer, minimum: 0, nullable: true}, reported_at: {type: string, format: date-time}}}
```
Use the codes shown in CSMS. Do not send names, emails, student or staff identifiers: such fields are rejected.

### Tests
`DB_NAME=sms_db_uitest php tests/offering-api-http.php` (168 checks, disposable schema and loopback server): authentication failures (none, malformed, unknown prefix, wrong secret, Basic), cookies ignored and never set, scope, 405, bad JSON, oversize, 101 items, empty list, extra key, first batch, **idempotent replay (rows byte-identical)**, changed payload, stale, a 15-item mixed batch (per-item partial success, unknown subject rejected and logged with its code, inactive subject and not-in-program flagged, bad year and term, personal and unknown fields, duplicate, string number), no personal values anywhere (tables and server log), no subject created, own-rows-only read, paging and filters, rotate and revoke with audit, rate limit with `Retry-After`, **no change to curriculum tables**, plain key and pepper stored nowhere, HMAC hash stored, CLI refusals (non-Super-Admin, unknown operator, no pepper, bad source), script not reachable over the web, **rollback then re-apply**. `DB_NAME=sms_db_uitest php tests/archived-version-protection-http.php` (47 checks). Also green: `tests/legacy-baseline-declarations.php`, `tests/legacy-baseline-fingerprint.php`, `tests/rbac-final-roles.php`, `tests/rbac-drift-report.php`, `tests/offering-history-http.php`.

### Applying migration 029 to `sms_db` (NOT done; after your backup; exact steps)
1. Back up: `C:\xampp\mysql\bin\mysqldump.exe --single-transaction --routines -u root sms_db > C:\xampp\htdocs\sms-csms\backups\sms_db_before_029_<date>.sql`. Confirm the file is not empty.
2. Generate the pepper once and keep it outside git: `C:\xampp\php\php.exe -r "echo bin2hex(random_bytes(32));"`. Set it as `API_KEY_PEPPER` in the Apache/PHP runtime environment (the same way as the CIMF keys) and restart Apache. Never commit it. Losing or changing it invalidates every API key.
3. Check: `set DB_NAME=sms_db` then `C:\xampp\php\php.exe database\deploy.php --status` (expect 28 applied, pending only `029_offering_api.sql`) and `C:\xampp\php\php.exe tests\legacy-baseline-declarations.php` (no database).
4. Apply: `C:\xampp\php\php.exe database\deploy.php --apply --confirm-database=sms_db`.
5. Verify: `deploy.php --status` (29 applied, none pending), `C:\xampp\php\php.exe tests\legacy-baseline-fingerprint.php`, `C:\xampp\php\php.exe tools\rbac-drift-report.php` (no differences; `api_client.manage` only on Super Admin).
6. First client (prints the key once): `set API_KEY_PEPPER=<value>` then `C:\xampp\php\php.exe scripts\api-client-create.php create --name="Class Scheduling" --source=class_scheduling --scope=read_write --operator=<super admin username>`. Hand the key to the other group over a private channel.
7. Smoke test with that key: `curl -H "Authorization: Bearer <key>" http://localhost/sms-csms/api/v1/offerings` returns `{"data":[],...}`.
8. Rollback if needed: restore the dump from step 1, or run `database\rollbacks\029_rollback.sql` then `DELETE FROM schema_migrations WHERE migration = '029_offering_api.sql';`, and revert the code.

### Still open
Slice 4D is skipped by decision; whether other groups confirm the term list and year format (client input); the not-yet-decided points from section "Decisions" that were not in the owner's list (retention of `api_rejected_items`: proposal 90 days, manual); the admin screen for clients (CLI only for now).

### Slices 4C and 4E (built 2026-11-11)
- **UI (4C), read-only:** Subject History Details dialog, below the version timeline: "Reported offerings" (info text: reported by other subsystems through the API, not verified by this system, separate from curriculum placement history). Columns: Academic year, Term, Status chip, Sections, Enrolled, Flags (chips), Source system (+ "Demo" chip when the source starts with `demo-`), Received. Empty state "No offering data received yet." Newest academic year first, 100 rows maximum with a "Showing N of M" line. Main table: compact "Reported" column (count for that subject in the selected program, "-" if none). Header chip: "Read-only: placement history plus reported offerings." Only shared classes (`csms-chip`, `csms-info`, `csms-empty`, `csms-ctable`); no new CSS, no wider dialog.
- **Read scope:** a user restricted to approved or active versions sees a reported row only where the subject is placed, in that program, in a version they may read (an `EXISTS` on placements using `CurriculumReadScope`). Unrestricted readers see all rows. The rows and counts never carry `external_id`, client id or hash.
- **No table, no error:** if migration 029 is not applied (as on `sms_db` today) the page shows "-" and an empty section, and CIMF adds no reported findings.
- **CIMF (Summarize History):** two new advisory counts, per subject and program scope: *programs with reported offerings but no curriculum placement* and *programs with a placement but no reported offerings* (the second states it depends on whether a source system sends data). Programs are listed by code only; the AI payload stays compact; nothing is written.
- **CLI change:** `--source` now also accepts hyphens (`demo-class-scheduling`); still lowercase, starts with a letter, 2-40 characters.
- **Contract for other groups (4E):** `docs/api/OFFERING_API_CONTRACT.md` (standalone; no internal names or secrets; year format and terms marked as our assumptions). Demo requests: `docs/api/offering-demo.http`.

### Demo steps (sms_db_uitest only; never sms_db)
1. In a terminal: `set DB_NAME=sms_db_uitest` and `set API_KEY_PEPPER=<any 32+ characters>` (the same pepper must be in the Apache/PHP environment, then restart Apache).
2. Create the demo client; the key is printed once, keep it out of git: `C:\xampp\php\php.exe scripts\api-client-create.php create --name="Demo Class Scheduling" --source=demo-class-scheduling --scope=read_write --operator=<super admin username>`
3. Send request 1 of `docs/api/offering-demo.http` with that key (REST Client in VS Code, or curl with `Authorization: Bearer <key>`). Expected summary: received 7, accepted 5, flagged 1 (CC101, inactive subject), rejected 1 (unknown subject NOPE999), created 6. Send it again: 6 unchanged.
4. Open Subject Offering History, choose Program BSIT and a version, look at the "Reported" column (CC104 = 2, CC105 = 1, CC106 = 1, DM102 = 1, CC101 = 1), open CC104 or CC101: the section shows the rows, the Demo chip and the flag chip. A subject with no reports shows "-" and "No offering data received yet.".
5. To stop further use of the demo client, revoke it (`revoke --name="Demo Class Scheduling"`); the demo rows stay as history on the demo database.

---

## 0. Answers from the code

- **Q1 Existing patterns.** No API-key or token authentication exists (`Authorization: Bearer` appears only in outbound calls to AI providers, `GroqProvider`, `OpenAIClient`). JSON endpoints do exist (`modules/curriculum/http/*_actions.php`, envelope `{success, message, ...}` from `json_response`, `shared/http.php`) but all of them depend on the PHP session cookie (`shared/bootstrap.php` starts it), CSRF on every POST (`require_valid_csrf_token`) and database RBAC by user id (`require_permission`, `shared/authorization.php`). A machine call must differ in four ways: no session and no cookie (a new `shared/api_bootstrap.php` that never calls `session_start`), no CSRF (the bearer key is the credential), its own identity and scope (an API client, not a user), and its own JSON error format, status codes, rate limit and size limit.
- **Q2 Identifiers.** `subjects.subject_code` is `varchar(60) NOT NULL` with `UNIQUE uq_subjects_code`; `academic_programs.program_code` has `UNIQUE uq_academic_programs_code`. In `sms_db_uitest`: 55 subjects, 55 distinct codes, 0 blank, 0 differing only by case or spaces; 12 programs, 12 distinct codes. **Unique but not immutable:** `SubjectService::update` lets a code change (it only checks the new code is free). So the code is a good business key for exchange but not a permanent one; CSMS must resolve it to `subject_id` at receipt and store both. 4 of 55 subjects are inactive or archived.
- **Q3 Migrations.** Last applied is 027; 028 is reserved for Electives (on hold). Next free number for this work is **029**. The runner (`database/deploy.php`) requires the applied journal to be an ordered prefix of the files ("not an ordered prefix; manual review required"), so if 029 is applied first, a later 028 would be refused: **Electives would have to be renumbered 030** (or the API takes 028 and Electives becomes 029). Legacy baseline: new tables outside the baseline list need no entry (`database/README.md`, "New tables ... need none"). A new permission row and its `role_permissions` rows are fingerprint-covered and need a `permissions_added` declaration with the file checksum in `csms_legacy_baseline_declared_changes()`, then `php tests/legacy-baseline-declarations.php`, apply on `sms_db_uitest`, `deploy.php --status` and `tests/legacy-baseline-fingerprint.php`. Rollbacks live in `database/rollbacks/` (027 has one).
- **Q4 Secrets.** CIMF keys are runtime environment variables only (`CIMFConfig::load` reads `getenv`); `.env`, `.env.*` and `config/secrets.php` are git-ignored, `.env.example` lists names with empty values. API client keys are different: CSMS generates them, shows the plain key once, and stores only a hash in the database. The only environment value needed is an optional pepper (`API_KEY_PEPPER`, listed empty in `.env.example`).
- **Q5 Validation.** No `composer.json`, no `vendor/`, no JSON-schema library. Validation is hand-written PHP in the existing `DomainValidator` style: strict `json_decode` with `JSON_THROW_ON_ERROR`, an allow-list of keys, type and length checks.
- **Q6 Read scope.** `CurriculumReadScope` is per curriculum version, and offerings are not version-scoped (they are keyed by subject, program, academic year and term). Rule proposed: a user sees a reported offering only if the same subject is placed, in that program, in a version the user may read (`CurriculumReadScope::sql('v')` inside an `EXISTS` on `curriculum_version_subjects`). The dialog already lists only readable versions, so the offerings it shows are consistent with the timeline above them.

## A) Data model (migration 029, three new tables)

No personal data fields anywhere. All tables are new (no legacy baseline entry); only the new permission needs a declaration.

**`api_clients`** (one row per calling subsystem)
| Column | Notes |
|---|---|
| id, name (unique, e.g. "Class Scheduling") | |
| source_system VARCHAR(40) unique | fixed value this client may report as, e.g. `class_scheduling`; the client cannot report as another source |
| key_prefix CHAR(8) unique | public lookup part of the key |
| key_hash CHAR(64) | HMAC-SHA256 of the secret part with the pepper; compared with `hash_equals`. The key is 256-bit random, so a fast hash is appropriate |
| scope VARCHAR(12) | CHECK in (`write`, `read`, `read_write`) |
| is_active TINYINT, revoked_at, created_by, created_at, last_used_at | |
| window_start, window_count | per-minute rate counter, updated atomically |

**`reported_offerings`**
| Column | Notes |
|---|---|
| id | |
| source_system VARCHAR(40), external_id VARCHAR(64) | **UNIQUE (source_system, external_id)** |
| subject_id (FK subjects, RESTRICT), subject_code | resolved at receipt; code kept as reported |
| program_id (FK academic_programs, RESTRICT), program_code | same |
| academic_year CHAR(9) | `2026-2027`, validated in PHP that the second year is the first plus one |
| term VARCHAR(20) | allow-list proposal: `1st Semester`, `2nd Semester`, `Summer`, `Term 1`, `Term 2`, `Term 3` (needs client input, section 6) |
| status | CHECK in (`offered`, `cancelled`) |
| section_count SMALLINT UNSIGNED NOT NULL | |
| enrolled_count INT UNSIGNED NULL | aggregate, optional |
| source_reported_at DATETIME NULL | when the source made the record; older data never overwrites newer |
| received_at, updated_at | CSMS times |
| payload_hash CHAR(64) | SHA-256 of the canonical item JSON |
| api_client_id (FK) | |
Indexes: `(subject_id, program_id, academic_year, term)`, `(program_id)`.

**`api_rejected_items`** (rejected-items log)
id, api_client_id, request_id (CHAR(26)), item_index, external_id (nullable), reason_code, field_name (nullable), payload_hash, received_at. **Stores no payload body and no rejected values**, only the field name and a code, so a rejected personal field is never kept. Retention proposal: purge after 90 days (manual task for now).

**Permission:** `api_client.manage` (admin screen), granted to super_admin by default. Alternative to avoid touching the baseline: reuse `rbac.manage`.

**Migration and rollback.** `database/migrations/029_offering_api.sql` (three `CREATE TABLE`, one permission, its `role_permissions` row); `database/rollbacks/029_rollback.sql` drops the tables in dependency order and deletes the permission rows. Baseline: declare `permissions_added: ['api_client.manage']` plus the file checksum; run the baseline tests above on `sms_db_uitest`. Tests use disposable schemas only; never `sms_db`.

## B) REST contract

Base path `/api/v1`. Served by `api/v1/offerings.php` (a rewrite to `/api/v1/offerings` is optional and host-dependent). JSON only. HTTPS required outside local development. Cookies are ignored entirely.

**Authentication:** `Authorization: Bearer csms_<8-char prefix>_<secret>`. Missing or malformed: 401. Unknown, revoked or inactive client: 401 (same message, no hint which). Wrong scope: 403. The key is shown once when created; rotation = create a new client key and revoke the old one.

**Limits:** body at most 256 KB (413), at most **100 items** per POST (413 `too_many_items`), rate limit 60 requests per minute per client (429 with `Retry-After`).

**`POST /api/v1/offerings`: batch upsert.** Request: `{"offerings":[ {item}, ... ]}`. Item fields (all others are rejected):
`external_id` (string, 1-64, required), `subject_code` (required), `program_code` (required), `academic_year` (`YYYY-YYYY`, required), `term` (required), `status` (`offered`|`cancelled`, required), `section_count` (integer 0-999, required), `enrolled_count` (integer, optional, null allowed), `reported_at` (ISO 8601 UTC, optional).
- Items are processed **independently**; one bad item does not block the others. A retry of the whole request is safe.
- Per item result: `created`, `updated`, `unchanged` or `rejected`.
- Idempotency: key is (client's `source_system`, `external_id`). Same key and same `payload_hash` → `unchanged` (no write). Same key, different content → `updated`. `reported_at` older than the stored `source_reported_at` → rejected `stale`. A repeated `external_id` inside one request → second one rejected `duplicate_in_request`. A cancellation is an update with `status: cancelled`; rows are never deleted by the API.
- Rejections: `unknown_field` (any key outside the allow-list, including anything personal such as names, emails, student or instructor data; the value is never echoed or stored), `unknown_subject`, `unknown_program`, `subject_inactive`, `invalid_academic_year`, `invalid_term`, `invalid_status`, `invalid_number`, `missing_field`, `stale`, `duplicate_in_request`.
- Response `200`: `{"request_id":"...","summary":{"received":3,"created":1,"updated":1,"unchanged":0,"rejected":1},"results":[{"index":0,"external_id":"...","result":"created"},{"index":2,"external_id":"...","result":"rejected","error":{"code":"unknown_subject","field":"subject_code"}}]}`. Whole-request failures use the error format below (400 malformed JSON or wrong shape).

**`GET /api/v1/offerings`: read.** Filters: `subject_code`, `program_code`, `academic_year`, `term`, `status`, `page`, `per_page` (50 default, 100 max). A client reads **only its own `source_system` rows**. Response: `{"data":[...],"page":1,"per_page":50,"total":120}`; items use the same field names plus `received_at`, `updated_at`.

**Error format (all endpoints):** `{"error":{"code":"unauthorized","message":"Invalid or missing API key."},"request_id":"..."}`. Codes: `unauthorized` 401, `forbidden` 403, `bad_request` 400, `payload_too_large` 413, `too_many_items` 413, `rate_limited` 429, `method_not_allowed` 405, `server_error` 500 (generic message, details only in the server log, never a stack trace or SQL).

**Sample 1 (Class Scheduling, two offerings)**
```json
{"offerings":[
 {"external_id":"CS-2026-0001","subject_code":"CC101","program_code":"BSIT","academic_year":"2026-2027","term":"1st Semester","status":"offered","section_count":4,"enrolled_count":132,"reported_at":"2026-11-10T08:00:00Z"},
 {"external_id":"CS-2026-0002","subject_code":"CC103","program_code":"BSIT","academic_year":"2026-2027","term":"1st Semester","status":"cancelled","section_count":0}
]}
```
**Sample 2 (a response with one rejection)**
```json
{"request_id":"01JBX0000000000000000000","summary":{"received":2,"created":1,"updated":0,"unchanged":0,"rejected":1},
 "results":[{"index":0,"external_id":"CS-2026-0001","result":"created"},
            {"index":1,"external_id":"CS-2026-0003","result":"rejected","error":{"code":"unknown_field","field":"instructor_name"}}]}
```

**OpenAPI-style summary for the other groups (no internal details)**
```yaml
openapi: 3.0.3
info: {title: CSMS Offerings API, version: "1"}
servers: [{url: https://<csms-host>/api/v1}]
security: [{bearerKey: []}]
paths:
  /offerings:
    post: {summary: Report offerings (batch, max 100, idempotent per external_id), responses: {"200": per-item results, "400": bad request, "401": unauthorized, "403": forbidden, "413": too large, "429": rate limited}}
    get:  {summary: List offerings you reported, parameters: [subject_code, program_code, academic_year, term, status, page, per_page], responses: {"200": page of offerings}}
components:
  securitySchemes: {bearerKey: {type: http, scheme: bearer}}
  schemas:
    Offering: {required: [external_id, subject_code, program_code, academic_year, term, status, section_count],
      properties: {external_id: {type: string, maxLength: 64}, subject_code: {type: string}, program_code: {type: string},
        academic_year: {type: string, pattern: '^[0-9]{4}-[0-9]{4}$'}, term: {type: string}, status: {enum: [offered, cancelled]},
        section_count: {type: integer, minimum: 0}, enrolled_count: {type: integer, minimum: 0, nullable: true}, reported_at: {type: string, format: date-time}},
      additionalProperties: false}
```
Codes (`subject_code`, `program_code`) are the codes shown in CSMS. Do not send names, emails, student or staff identifiers: such fields are rejected.

## C) UI plan (shared components only)

- **Where:** a new section in the existing Subject History Details dialog (`modal-dialog-large`), **below** the version timeline, titled "Reported offerings" with an info tooltip ("Reported by other subsystems. Not part of the curriculum placement history."). It is a separate table (`csms-page-table` / `csms-ctable`): Source, Academic year, Term, Status chip (`csms-chip`), Sections, Enrolled, Received. Rows are for the subject within the program of the opened placement, newest academic year first, with a "Showing N of M" line and paging only if needed.
- **Empty state:** `csms-empty` "No offering data received yet".
- **Never mixed:** offerings are not added to the version timeline, not counted in "Appears in", and carry no version label. The wording says "reported", never "placement".
- **Read scope:** the EXISTS rule in Q6. A restricted user sees only offerings of subjects placed in a readable version of that program.
- **CIMF:** later, findings may add counts of reported offerings, flagged as reported data. Not in these slices.
- **Admin screen (slice 4D):** "API clients" under Setup (permission `api_client.manage`): list (name, source system, scope, active, last used), create (key shown once with a copy button and a warning), revoke, rotate; recent rejected items per client. Same context-free table pattern as Users.

## D) Slices, risks, approvals

| Slice | Content |
|---|---|
| E (first, small) | Archived-version edit block (section E). No schema. |
| 4B | Migration 029 + rollback + baseline declaration; `api_bootstrap.php`; client auth; POST and GET; validation; rate limit; tests on a disposable schema and `sms_db_uitest` (auth, scope, validation matrix, idempotency, stale, duplicate, unknown and personal fields, size limits, no cookie or CSRF path, nothing written on reject, rollback runs). |
| 4C | Read-only "Reported offerings" section in the dialog with the scope rule and tests. |
| 4D | Admin screen for API clients (permission, audit of create, rotate and revoke). |
| 4E | Demo payloads and a published `Offering_API.md` / OpenAPI file for the other groups; a seed script for demo offerings on `sms_db_uitest` only. |

**Risks:** (1) subject or program code changed after reporting: the stored `subject_id` keeps the link, but senders using an old code get `unknown_subject`; (2) other subsystems do not exist yet, so the API is verified only with our own scripts; (3) rate limiting needs a DB write per request; (4) keys leak if a group stores them badly (revocation and per-client scope limit the damage); (5) migration 029 forces Electives to 030; (6) academic year and term formats are guesses until the client confirms; (7) no audit row for machine calls (`audit_logs` is user-based; to verify in 4B whether `user_id` can be null); traceability is `api_client_id` + `received_at` + the rejected log; (8) without a stored snapshot, offering history shows codes as reported, which may differ from current Subject Master titles.

**Needs your approval:** see the numbered list at the end of this file.

## E) Archived versions: placement edits blocked (separate small slice, no schema change)

Decision recorded: yes, do it. Current behaviour: `CurriculumVersionEditPolicy::isEditable()` (`modules/curriculum/services/CurriculumVersionEditPolicy.php:50`) decides only from `version_role`, `review_status` and `baseline_version_id`; it never reads `status`. `assertChildEditable` loads the version with `SELECT *` (so `status` is present) and calls `assertEditable` → `assertState` → `isEditable`.

**Exact change:**
1. In `isEditable()`, first line: `if (($version['status'] ?? null) === 'archived') return false;`.
2. In `assertState()`, before the generic message: if `($version['status'] ?? null) === 'archived'`, throw `DomainConflictException('This curriculum version is archived and cannot be changed.')`.
No other file changes. Because every placement, requirement, elective, grade-weighting, CLO and outcome-mapping write goes through `assertEditable` / `assertChildEditable`, the rule covers them all, and the `editable` flags that `CurriculumCloAssessmentService`, `CurriculumOutcomeMappingService` and `CurriculumVersionReferenceService` return become false for archived versions, so the UI stops offering edits. Rows without a `status` key (arrays not loaded from `curriculum_versions`) are unaffected.

**Test** (new `tests/archived-version-protection-http.php`, disposable schema cloned from `sms_db_uitest`, same pattern as `tests/offering-history-http.php`; `sms_db_uitest` has no archived version, so the fixture creates one):
- archived unclassified/not-started version: `assign`, `updatePlacement` and `remove` each return 409 with the archived message, and a checksum of `curriculum_version_subjects`, requirements, electives and audit rows is unchanged;
- archived proposed version with a baseline: same result;
- same shaped **active** unclassified version: still editable (regression guard), assign succeeds;
- `editable` is false in the Subject Mapping and Builder payloads for the archived version;
- reads, history, export and comparison of the archived version still work (history stays visible).
Note: no workflow in the code sets a version to `archived` today (only the column and filters exist), so this is a defensive rule; the 409 text and the tests make that explicit.

## Decisions for you (numbered)

1. **Migration number:** API as 029 and Electives renumbered 030 (recommended), or API as 028 and Electives 029. Electives is on hold and unapplied, so either is cheap.
2. **Permission for the admin screen:** new `api_client.manage` (recommended; needs the baseline declaration) or reuse `rbac.manage`.
3. **Key hashing:** HMAC-SHA256 with an `API_KEY_PEPPER` environment value (recommended; fails closed if missing) or plain SHA-256 without a pepper.
4. **Per-item vs all-or-nothing batches:** per item, independent and retry-safe (recommended).
5. **Read access for clients:** own rows only (recommended) or all rows.
6. **Allowed term values and academic-year format:** needs client input; may be NDA-restricted. Proposal above.
7. **Subjects that are known but not placed in that program's curriculum:** accept and show with a "not in a curriculum version" flag (recommended), or reject.
8. **Inactive or archived subjects:** reject with `subject_inactive` (recommended), or accept.
9. **Machine-call auditing:** rely on `api_client_id` + `received_at` + rejected log (recommended), or add `audit_logs` rows (needs a null-able actor).
10. **Go-ahead for slice E now** (small, no schema).
