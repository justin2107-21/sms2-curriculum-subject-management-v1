<?php
declare(strict_types=1);

$path = __DIR__ . '/../docs/source-data/ched-cmo25-2015-bsit.json';
$data = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
$assert = static function (bool $ok, string $message): void {
    if (!$ok) {
        throw new RuntimeException($message);
    }
};

$assert(($data['source']['sha256'] ?? '') === 'AEB87088118D4341B8F8A173952E77B07AEA3ACD87D140770D73B4B2309FB6CF', 'Source fingerprint missing.');
$assert(($data['source']['pdf_page_count'] ?? 0) === 57, 'PDF page count mismatch.');
$assert(count($data['curriculum_components']['records'] ?? []) === 27, 'Expected 27 Section 9.1 component rows.');
$assert(count($data['intended_program_outcomes']['records'] ?? []) === 13, 'Expected IT01–IT13 outcomes.');
$assert(array_column($data['intended_program_outcomes']['records'], 'id') === array_map(
    static fn(int $n): string => sprintf('IT%02d', $n), range(1, 13)
), 'Outcome identifiers are incomplete or out of order.');
$assert(($data['curriculum_components']['records'][0]['page'] ?? null) === 16, 'Component page citation mismatch.');
$assert(($data['curriculum_components']['records'][6]['page'] ?? null) === 17, 'Component continuation page citation mismatch.');
$assert(($data['curriculum_components']['records'][7]['page'] ?? null) === 18, 'Component continuation page citation mismatch.');
$assert(($data['sample_program_of_study']['placements'][0]['term'] ?? null) === '1st Year / 1st Semester', 'Schedule term missing.');
$assert(($data['sample_program_of_study']['placements'][0]['page'] ?? null) === 20, 'Schedule page citation mismatch.');
$assert(($data['sample_program_of_study']['placements'][2]['page'] ?? null) === 21, 'Schedule continuation page citation mismatch.');
$assert(($data['source']['institutional_adoption'] ?? null) === 'Institutional adoption/approval not verified', 'Institutional adoption must remain unverified.');
$assert(($data['importability']['eligible'] ?? true) === false, 'Unresolved source data must block import.');
$assert(array_key_exists('units', $data['curriculum_components']['records'][26])
    && $data['curriculum_components']['records'][26]['units'] === null, 'HCI102 blank units must remain null.');

$componentRows = $data['curriculum_components']['records'];
$scheduleRows = $data['sample_program_of_study']['placements'];
$assert(count(array_filter($componentRows, static fn(array $r): bool => isset($r['cross_table_conflict']))) >= 1, 'Component-side conflict was not recorded.');
$assert(count(array_filter($scheduleRows, static fn(array $r): bool => isset($r['cross_table_conflict']))) >= 3, 'Schedule-side conflicts were not recorded.');
$assert(($data['other_document_inventory'][0]['local_source_inspected'] ?? true) === false, 'Unavailable related sources must not be marked inspected.');

echo "PASS: CMO25 source package; 27 component rows, " . count($scheduleRows)
    . " separate schedule observations, 13 graduate outcomes; unresolved rows retained; import refused.\n";
