<?php

require_once __DIR__ . '/ApiClientService.php';

/**
 * Offerings reported by other subsystems. Validates, upserts idempotently and lists a client's own rows.
 * Writes only reported_offerings and api_rejected_items. It never writes curriculum, subject, program or placement tables.
 * The contract values (academic year format, terms) are CSMS assumptions, documented in docs/ui-reference/Offering_API_Plan.md.
 */
final class OfferingApiService
{
    public const MAX_ITEMS = 100;
    public const TERMS = ['1st Semester', '2nd Semester', 'Summer'];
    public const STATUSES = ['offered', 'cancelled'];
    private const FIELDS = ['external_id', 'subject_code', 'program_code', 'academic_year', 'term', 'status', 'section_count', 'enrolled_count', 'reported_at'];
    private const REQUIRED = ['external_id', 'subject_code', 'program_code', 'academic_year', 'term', 'status', 'section_count'];
    // Key names that suggest personal data. Checked only on keys outside the whitelist, to give a clearer rejection code.
    private const PERSONAL_KEY = '/name|e?mail|phone|mobile|tel|contact|address|birth|gender|sex|student|learner|employee|faculty|teacher|instructor|professor|staff|lrn|tin|ssn|passport|guardian|parent/i';

    public function __construct(private mysqli $db) {}

    private function row(string $sql, string $types, array $params): ?array
    {
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        return $row;
    }

    /** @return array{0:?array,1:?array} [normalized item, [code, field]] */
    public function validateItem(mixed $item): array
    {
        $fail = static fn(string $code, ?string $field = null): array => [null, [$code, $field]];
        if (!is_array($item) || api_is_list($item)) return $fail('invalid_item');
        foreach (array_keys($item) as $key) {
            $key = (string)$key;
            if (in_array($key, self::FIELDS, true)) continue;
            $safe = preg_match('/^[A-Za-z0-9_.-]{1,60}$/D', $key) ? $key : '[invalid]';
            return $fail(preg_match(self::PERSONAL_KEY, $key) ? 'personal_field' : 'unknown_field', $safe);
        }
        foreach (self::REQUIRED as $field) if (!array_key_exists($field, $item) || $item[$field] === null || $item[$field] === '') return $fail('missing_field', $field);
        foreach (['external_id', 'subject_code', 'program_code', 'academic_year', 'term', 'status'] as $field) if (!is_string($item[$field])) return $fail('invalid_type', $field);
        $out = [];
        $out['external_id'] = $item['external_id'];
        if (!preg_match('/^[A-Za-z0-9._:-]{1,64}$/D', $out['external_id'])) return $fail('invalid_external_id', 'external_id');
        foreach (['subject_code', 'program_code'] as $field) {
            $out[$field] = trim($item[$field]);
            if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9 ._\/-]{0,59}$/D', $out[$field])) return $fail('invalid_code', $field);
        }
        // Assumption: "YYYY-YYYY" with consecutive years.
        if (!preg_match('/^(\d{4})-(\d{4})$/D', $item['academic_year'], $y) || (int)$y[2] !== (int)$y[1] + 1 || (int)$y[1] < 2000 || (int)$y[1] > 2100) return $fail('invalid_academic_year', 'academic_year');
        $out['academic_year'] = $item['academic_year'];
        if (!in_array($item['term'], self::TERMS, true)) return $fail('invalid_term', 'term');
        $out['term'] = $item['term'];
        if (!in_array($item['status'], self::STATUSES, true)) return $fail('invalid_status', 'status');
        $out['status'] = $item['status'];
        if (!is_int($item['section_count']) || $item['section_count'] < 0 || $item['section_count'] > 999) return $fail('invalid_number', 'section_count');
        $out['section_count'] = $item['section_count'];
        $enrolled = $item['enrolled_count'] ?? null;
        if ($enrolled !== null && (!is_int($enrolled) || $enrolled < 0 || $enrolled > 1000000)) return $fail('invalid_number', 'enrolled_count');
        $out['enrolled_count'] = $enrolled;
        $reported = $item['reported_at'] ?? null;
        if ($reported !== null) {
            $date = is_string($reported) ? DateTimeImmutable::createFromFormat(DATE_ATOM, str_ends_with($reported, 'Z') ? substr($reported, 0, -1) . '+00:00' : $reported) : false;
            if (!$date) return $fail('invalid_timestamp', 'reported_at');
            $reported = $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        }
        $out['reported_at'] = $reported;
        return [$out, null];
    }

    private function canonicalHash(array $item): string
    {
        ksort($item);
        return hash('sha256', json_encode($item, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function logRejected(int $clientId, string $requestId, int $index, ?string $externalId, string $code, ?string $field, ?string $reportedCode, mixed $rawItem): void
    {
        // Evidence only: code, field name, keyed hash. The body and rejected values are never stored.
        $hash = ApiClientService::evidenceHash(json_encode($rawItem, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_UNICODE) ?: '');
        $externalId = is_string($externalId) && preg_match('/^[A-Za-z0-9._:-]{1,64}$/D', $externalId) ? $externalId : null;
        $stmt = $this->db->prepare('INSERT INTO api_rejected_items (api_client_id, request_id, item_index, external_id, reason_code, field_name, reported_code, payload_hash) VALUES (?,?,?,?,?,?,?,?)');
        $stmt->bind_param('isisssss', $clientId, $requestId, $index, $externalId, $code, $field, $reportedCode, $hash);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Processes a batch. Items are independent; one rejection never blocks the others and a replay is safe.
     * @return array{summary:array,results:array}
     */
    public function ingest(array $client, array $items, string $requestId): array
    {
        $clientId = (int)$client['id']; $source = $client['source_system'];
        $results = []; $seen = [];
        $summary = ['received' => count($items), 'accepted' => 0, 'flagged' => 0, 'rejected' => 0, 'created' => 0, 'updated' => 0, 'unchanged' => 0];
        foreach (array_values($items) as $index => $raw) {
            $externalId = is_array($raw) && is_string($raw['external_id'] ?? null) ? $raw['external_id'] : null;
            $reject = function (string $code, ?string $field = null, ?string $reportedCode = null) use (&$results, &$summary, $clientId, $requestId, $index, $externalId, $raw): void {
                $this->logRejected($clientId, $requestId, $index, $externalId, $code, $field, $reportedCode, $raw);
                $summary['rejected']++;
                $safeId = is_string($externalId) && preg_match('/^[A-Za-z0-9._:-]{1,64}$/D', $externalId) ? $externalId : null;
                $results[] = ['index' => $index, 'external_id' => $safeId, 'status' => 'rejected', 'error' => ['code' => $code, 'field' => $field]];
            };
            [$item, $error] = $this->validateItem($raw);
            if ($error) { $reject($error[0], $error[1]); continue; }
            if (isset($seen[$item['external_id']])) { $reject('duplicate_in_request', 'external_id'); continue; }
            $seen[$item['external_id']] = true;
            $subject = $this->row('SELECT id, subject_code, status, archived_at FROM subjects WHERE subject_code = ?', 's', [$item['subject_code']]);
            if (!$subject) { $reject('unknown_subject', 'subject_code', $item['subject_code']); continue; }
            $program = $this->row('SELECT id, program_code, status FROM academic_programs WHERE program_code = ?', 's', [$item['program_code']]);
            if (!$program) { $reject('unknown_program', 'program_code', $item['program_code']); continue; }
            $flags = [];
            if ($subject['status'] !== 'active' || $subject['archived_at'] !== null) $flags[] = 'subject_inactive';
            if ($program['status'] !== 'active') $flags[] = 'program_inactive';
            if (!$this->row('SELECT 1 AS x FROM curriculum_version_subjects cvs JOIN curriculum_versions v ON v.id = cvs.curriculum_version_id JOIN curricula c ON c.id = v.curriculum_id WHERE cvs.subject_id = ? AND c.academic_program_id = ? LIMIT 1', 'ii', [(int)$subject['id'], (int)$program['id']])) $flags[] = 'not_in_program_curriculum';
            $hash = $this->canonicalHash($item);
            $outcome = $this->upsert($clientId, $source, $item, (int)$subject['id'], $subject['subject_code'], (int)$program['id'], $program['program_code'], $flags, $hash);
            if ($outcome['result'] === 'stale') { $reject('stale', 'reported_at'); continue; }
            $flags = $outcome['flags'];
            $summary[$outcome['result']]++;
            $summary[$flags ? 'flagged' : 'accepted']++;
            $results[] = ['index' => $index, 'external_id' => $item['external_id'], 'status' => $flags ? 'flagged' : 'accepted', 'action' => $outcome['result'], 'flags' => $flags];
        }
        return ['summary' => $summary, 'results' => $results];
    }

    private function upsert(int $clientId, string $source, array $item, int $subjectId, string $subjectCode, int $programId, string $programCode, array $flags, string $hash): array
    {
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $this->db->begin_transaction();
            try {
                $existing = $this->row('SELECT id, payload_hash, source_reported_at, flags FROM reported_offerings WHERE source_system = ? AND external_id = ? FOR UPDATE', 'ss', [$source, $item['external_id']]);
                $flagText = $flags ? implode(',', $flags) : null;
                if ($existing) {
                    if (hash_equals($existing['payload_hash'], $hash)) {
                        $this->db->commit();
                        return ['result' => 'unchanged', 'flags' => $existing['flags'] ? explode(',', $existing['flags']) : []];
                    }
                    if ($item['reported_at'] !== null && $existing['source_reported_at'] !== null && $item['reported_at'] < $existing['source_reported_at']) {
                        $this->db->commit();
                        return ['result' => 'stale', 'flags' => []];
                    }
                    $stmt = $this->db->prepare('UPDATE reported_offerings SET subject_id=?, subject_code=?, program_id=?, program_code=?, academic_year=?, term=?, status=?, section_count=?, enrolled_count=?, flags=?, source_reported_at=?, payload_hash=?, api_client_id=? WHERE id=?');
                    $id = (int)$existing['id'];
                    $stmt->bind_param('isissssiisssii', $subjectId, $subjectCode, $programId, $programCode, $item['academic_year'], $item['term'], $item['status'], $item['section_count'], $item['enrolled_count'], $flagText, $item['reported_at'], $hash, $clientId, $id);
                    $stmt->execute(); $stmt->close();
                    $this->db->commit();
                    return ['result' => 'updated', 'flags' => $flags];
                }
                $stmt = $this->db->prepare('INSERT INTO reported_offerings (source_system, external_id, subject_id, subject_code, program_id, program_code, academic_year, term, status, section_count, enrolled_count, flags, source_reported_at, payload_hash, api_client_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
                $stmt->bind_param('ssisissssiisssi', $source, $item['external_id'], $subjectId, $subjectCode, $programId, $programCode, $item['academic_year'], $item['term'], $item['status'], $item['section_count'], $item['enrolled_count'], $flagText, $item['reported_at'], $hash, $clientId);
                $stmt->execute(); $stmt->close();
                $this->db->commit();
                return ['result' => 'created', 'flags' => $flags];
            } catch (mysqli_sql_exception $e) {
                $this->db->rollback();
                // A concurrent request inserted the same (source_system, external_id): read it and update on the second pass.
                if ($e->getCode() !== 1062 || $attempt === 1) throw $e;
            }
        }
        throw new RuntimeException('Offering upsert failed.');
    }

    /** A client's own rows only, newest academic year first. @throws ApiException */
    public function listOwn(array $client, array $query): array
    {
        $bad = static fn(string $field): ApiException => new ApiException('invalid_query', 'Invalid value for ' . $field . '.', 422);
        $page = filter_var($query['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100000]]);
        $perPage = filter_var($query['per_page'] ?? 50, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
        if ($page === false) throw $bad('page');
        if ($perPage === false) throw $bad('per_page');
        $where = ['source_system = ?']; $types = 's'; $params = [$client['source_system']];
        foreach (['subject_code' => '/^[A-Za-z0-9][A-Za-z0-9 ._\/-]{0,59}$/D', 'program_code' => '/^[A-Za-z0-9][A-Za-z0-9 ._\/-]{0,59}$/D', 'academic_year' => '/^\d{4}-\d{4}$/D'] as $field => $pattern) {
            if (!isset($query[$field]) || $query[$field] === '') continue;
            if (!is_string($query[$field]) || !preg_match($pattern, $query[$field])) throw $bad($field);
            $where[] = "$field = ?"; $types .= 's'; $params[] = $query[$field];
        }
        if (isset($query['term']) && $query['term'] !== '') {
            if (!in_array($query['term'], self::TERMS, true)) throw $bad('term');
            $where[] = 'term = ?'; $types .= 's'; $params[] = $query['term'];
        }
        if (isset($query['status']) && $query['status'] !== '') {
            if (!in_array($query['status'], self::STATUSES, true)) throw $bad('status');
            $where[] = 'status = ?'; $types .= 's'; $params[] = $query['status'];
        }
        $clause = implode(' AND ', $where);
        $total = (int)$this->row("SELECT COUNT(*) AS n FROM reported_offerings WHERE $clause", $types, $params)['n'];
        $offset = ($page - 1) * $perPage;
        $stmt = $this->db->prepare("SELECT external_id, subject_code, program_code, academic_year, term, status, section_count, enrolled_count, flags, source_reported_at, received_at, updated_at FROM reported_offerings WHERE $clause ORDER BY academic_year DESC, term ASC, subject_code ASC, id ASC LIMIT ? OFFSET ?");
        $stmt->bind_param($types . 'ii', ...[...$params, $perPage, $offset]);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        $data = array_map(static fn(array $r): array => [
            'external_id' => $r['external_id'], 'subject_code' => $r['subject_code'], 'program_code' => $r['program_code'],
            'academic_year' => $r['academic_year'], 'term' => $r['term'], 'status' => $r['status'],
            'section_count' => (int)$r['section_count'], 'enrolled_count' => $r['enrolled_count'] === null ? null : (int)$r['enrolled_count'],
            'flags' => $r['flags'] ? explode(',', $r['flags']) : [],
            'reported_at' => $r['source_reported_at'] ? gmdate('Y-m-d\TH:i:s\Z', strtotime($r['source_reported_at'] . ' UTC')) : null,
            'received_at' => $r['received_at'], 'updated_at' => $r['updated_at'],
        ], $rows);
        return ['data' => $data, 'page' => $page, 'per_page' => $perPage, 'total' => $total];
    }
}

/** PHP 8.0 compatible array_is_list (the local XAMPP is 8.0, Docker is 8.2). */
function api_is_list(array $value): bool
{
    return $value === [] || array_keys($value) === range(0, count($value) - 1);
}
