# CSMS Offerings API: contract for other subsystems

Version 1. Curriculum & Subject Management (CSMS) is the master of subjects and curricula. Your system reports which subjects it offered or cancelled; CSMS stores and shows that information as **reported data**, separate from curriculum placement, and does not verify it.

Items marked **(our assumption)** are CSMS's current choice and are not yet confirmed with you. Tell us if your data differs.

## 1. Endpoint and authentication

- URL: `https://<csms-host>/api/v1/offerings` (HTTPS is required in production).
- Methods: `POST` (report offerings) and `GET` (list what you reported). Any other method returns 405.
- Header on every request: `Authorization: Bearer <your key>`. Content type for POST: `application/json`.
- You receive one key per system, handed over privately. It is shown once. Keep it secret, never put it in a URL or a repository. If it leaks or is lost, ask CSMS to rotate it; the old key stops working.
- Each key is bound to one source system name chosen by CSMS. You cannot report as another system, and you can only read back your own records.
- A key has a scope: `write`, `read` or `read_write`. Using a call your key does not allow returns 403.

## 2. Limits

| Limit | Value |
|---|---|
| Request body | 256 KB |
| Items per POST | 100 |
| Requests per minute per key | 60 (a 429 response carries `Retry-After` seconds) |

## 3. POST: report offerings

Body: `{"offerings":[ item, item, ... ]}`. Nothing else is accepted at the top level.

Each item has exactly these fields; any other field is rejected.

| Field | Required | Rule |
|---|---|---|
| `external_id` | yes | Your own id for this offering. 1-64 characters: letters, digits, `.` `_` `:` `-`. Unique per source system. |
| `subject_code` | yes | The subject code as shown in CSMS. 1-60 characters: letters, digits, space and `. _ / -`. |
| `program_code` | yes | The program code as shown in CSMS. Same character rules. |
| `academic_year` | yes | `YYYY-YYYY` with consecutive years, for example `2026-2027`. **(our assumption)** |
| `term` | yes | Exactly one of `1st Semester`, `2nd Semester`, `Summer` (case-sensitive). **(our assumption)** |
| `status` | yes | `offered` or `cancelled`. |
| `section_count` | yes | Integer 0 to 999. |
| `enrolled_count` | no | Integer 0 to 1,000,000, or `null`. An aggregate count only. |
| `reported_at` | no | When your system produced the record, ISO 8601 (for example `2026-11-10T08:00:00Z`). |

**Do not send personal data** (names, emails, phone numbers, student, employee or instructor identifiers). Items containing such fields are rejected, and the values are never stored.

### Behaviour

- Items are processed **independently**. One bad item does not block the others, and the response tells you the result of each.
- **Idempotent per `external_id`.** Sending the same item again returns `unchanged` and changes nothing, so retrying a whole request after a timeout is safe. Sending the same `external_id` with different content updates the record (`updated`).
- A `reported_at` older than the one already stored for that `external_id` is rejected as `stale`.
- To cancel an offering, send it again with `"status":"cancelled"`. Records are never deleted through the API.
- A subject code CSMS does not know is **rejected** and no subject is ever created.
- A known subject that is not part of that program's curriculum, an inactive subject, or an inactive program is **accepted but flagged** (`not_in_program_curriculum`, `subject_inactive`, `program_inactive`). Flags are information, not errors.

### Response (HTTP 200)

```json
{
  "summary": {"received": 3, "accepted": 1, "flagged": 1, "rejected": 1, "created": 2, "updated": 0, "unchanged": 0},
  "results": [
    {"index": 0, "external_id": "A-0001", "status": "accepted", "action": "created", "flags": []},
    {"index": 1, "external_id": "A-0002", "status": "flagged",  "action": "created", "flags": ["not_in_program_curriculum"]},
    {"index": 2, "external_id": "A-0003", "status": "rejected", "error": {"code": "unknown_subject", "field": "subject_code"}}
  ],
  "request_id": "9f2c1d6e0a4b47c8b1e35d7a62f0c914"
}
```

`status` is `accepted`, `flagged` (accepted with flags) or `rejected`. `action` is `created`, `updated` or `unchanged`. Quote `request_id` when you contact us about a call.

### Item rejection codes

`invalid_item`, `unknown_field`, `personal_field`, `missing_field`, `invalid_type`, `invalid_external_id`, `invalid_code`, `invalid_academic_year`, `invalid_term`, `invalid_status`, `invalid_number`, `invalid_timestamp`, `unknown_subject`, `unknown_program`, `duplicate_in_request` (the same `external_id` twice in one request; the second is rejected), `stale`.

## 4. GET: list what you reported

`GET /api/v1/offerings` returns your own records only.

Optional query parameters: `subject_code`, `program_code`, `academic_year`, `term`, `status`, `page` (default 1), `per_page` (default 50, maximum 100).

Response: `{"data":[ ... ],"page":1,"per_page":50,"total":120,"request_id":"..."}`. Each record carries the fields you sent plus `flags`, `received_at` and `updated_at`.

## 5. Whole-request errors

Shape: `{"error":{"code":"...","message":"..."},"request_id":"..."}`

| HTTP | `code` | Meaning |
|---|---|---|
| 400 | `bad_request` | The body is not valid JSON. |
| 401 | `unauthorized` | Missing, malformed, unknown, wrong or revoked key (always the same answer). |
| 403 | `forbidden` / `https_required` | Your key's scope does not allow this call, or HTTPS was not used. |
| 405 | `method_not_allowed` | Use GET or POST. |
| 413 | `payload_too_large` / `too_many_items` | Over 256 KB or over 100 items. |
| 422 | `invalid_request` / `invalid_query` | Wrong shape, empty list or unexpected top-level field (POST); invalid filter value (GET). |
| 429 | `rate_limited` | Slow down; wait `Retry-After` seconds. |
| 500 | `server_error` | Our fault. Retry later and quote `request_id`. |

## 6. Examples

Report two offerings:

```bash
curl -sS -X POST "https://<csms-host>/api/v1/offerings" \
  -H "Authorization: Bearer $CSMS_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{"offerings":[
    {"external_id":"A-0001","subject_code":"<subject code>","program_code":"<program code>","academic_year":"2026-2027","term":"1st Semester","status":"offered","section_count":4,"enrolled_count":132,"reported_at":"2026-11-10T08:00:00Z"},
    {"external_id":"A-0002","subject_code":"<subject code>","program_code":"<program code>","academic_year":"2026-2027","term":"1st Semester","status":"cancelled","section_count":0}
  ]}'
```

List your records for one academic year:

```bash
curl -sS "https://<csms-host>/api/v1/offerings?academic_year=2026-2027&per_page=50" \
  -H "Authorization: Bearer $CSMS_API_KEY"
```

## 7. What CSMS shows

CSMS shows your records on a subject's history screen as "Reported offerings", clearly marked as reported by other systems and not verified, apart from curriculum placement. Only the fields above are shown, together with your source system name and the date received.

## 8. Open points we need from you

1. Confirm the `academic_year` format and the list of `term` values (or send yours).
2. Tell us whether you will send `enrolled_count`.
3. Tell us how often you will send data (per change, or a periodic full batch).
