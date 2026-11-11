# Subject Offering History: Offering API (Slice 4A, design only)

Date: 2026-11-11 · Branch `ui-redesign-pilot` · Status: **design, awaiting approval. No code, schema or data was changed.** `sms_db` was not touched; `sms_db_uitest` was queried read-only. The module name "Subject Offering History" is fixed.

Purpose: Curriculum & Subject Management is the master of subjects. Class Scheduling (Section Assignment Tool) is the essential source of real offerings; Enrollment (aggregate counts) and Faculty are optional. They call a small REST API; CSMS stores what they report and shows it next to, never mixed with, the curriculum placement history. We do not assume how other subsystems are built.

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
