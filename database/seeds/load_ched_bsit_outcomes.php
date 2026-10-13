<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$databaseName = (string)getenv('DB_NAME');
$confirmed = (string)getenv('SMS_TEST_CONFIRM_DATABASE');
$tempRoot = (string)(getenv('CSMS_TASK7_TEMP_ROOT') ?: getenv('CSMS_TASK2_TEMP_ROOT'));
if ($databaseName === '' || $confirmed !== $databaseName
    || !preg_match('/^csms_docker_test_task(?:2|7)_run_[0-9]+_[a-f0-9]+$/', $databaseName)
    || getenv('DB_HOST') !== '127.0.0.1' || $tempRoot === ''
    || getenv('APP_ENV') !== 'development' || (int)getenv('DB_PORT')===3306) {
    fwrite(STDERR, "BLOCKED: catalog loader requires the guarded Task 7 disposable test database.\n"); exit(2);
}

require_once __DIR__ . '/../../shared/db.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$identity = $conn->query('SELECT DATABASE() db,@@port port,@@datadir datadir,@@foreign_key_checks fk')->fetch_assoc();
$expectedData = realpath($tempRoot . DIRECTORY_SEPARATOR . 'data');
$actualData = realpath((string)$identity['datadir']);
if ($identity['db'] !== $databaseName || (int)$identity['port'] !== (int)getenv('DB_PORT')
    || (int)$identity['fk'] !== 1 || !$expectedData || !$actualData
    || strcasecmp($expectedData,$actualData)!==0) {
    fwrite(STDERR, "BLOCKED: database server identity did not match the isolated Task 7 data directory.\n"); exit(2);
}

$data = json_decode((string)file_get_contents(__DIR__ . '/../../docs/source-data/ched-cmo25-2015-bsit.json'),true,512,JSON_THROW_ON_ERROR);
$source = $data['source'] ?? [];
$outcomes = $data['intended_program_outcomes'] ?? [];
$records = $outcomes['records'] ?? [];
$expectedHash = 'AEB87088118D4341B8F8A173952E77B07AEA3ACD87D140770D73B4B2309FB6CF';
if (($source['sha256'] ?? null) !== $expectedHash || (int)($source['pdf_page_count'] ?? 0)!==57
    || count($records)!==13 || count($outcomes['pages'] ?? [])!==2) {
    throw new RuntimeException('Task 6 source fingerprint, page manifest, or outcome count is invalid.');
}
$expectedCodes=array_map(static fn(int $n):string=>sprintf('IT%02d',$n),range(1,13));
if (array_column($records,'id')!==$expectedCodes) throw new RuntimeException('Outcome identifiers are not exactly IT01–IT13 in source order.');
$pages=[]; foreach ($outcomes['pages'] as $page) $pages[(int)$page['printed_page']]=(int)$page['pdf_page_index'];
$section=(string)$outcomes['section'];
$sourceKey='CHED-CMO-25-2015-BSIT';
$conn->begin_transaction();
try {
    foreach ($records as $i=>$record) {
        $code=(string)$record['id']; $text=trim((string)$record['text']); $printed=(int)$record['page'];
        if ($text==='' || !isset($pages[$printed])) throw new RuntimeException('Outcome text or page provenance is incomplete.');
        $values=[$sourceKey,$code,$text,$i+1,(string)$source['document_number'],(string)$source['official_title'],
            (string)$source['issuing_authority'],$section,$printed,$pages[$printed],$expectedHash];
        $stmt=$conn->prepare('SELECT source_order,outcome_text,document_code,document_title,issuing_authority,source_section,printed_page,pdf_page_index,source_sha256 FROM curriculum_outcome_catalog WHERE source_key=? AND outcome_code=? FOR UPDATE');
        $stmt->bind_param('ss',$sourceKey,$code); $stmt->execute(); $existing=$stmt->get_result()->fetch_assoc(); $stmt->close();
        if ($existing) {
            $actual=[(int)$existing['source_order'],$existing['outcome_text'],$existing['document_code'],$existing['document_title'],
                $existing['issuing_authority'],$existing['source_section'],(int)$existing['printed_page'],(int)$existing['pdf_page_index'],$existing['source_sha256']];
            $wanted=[$i+1,$text,$values[4],$values[5],$values[6],$section,$printed,$pages[$printed],$expectedHash];
            if ($actual!==$wanted) throw new RuntimeException('Existing source outcome differs from the validated Task 6 dataset; refusing overwrite.');
            continue;
        }
        $insert=$conn->prepare('INSERT INTO curriculum_outcome_catalog (source_key,outcome_code,outcome_text,source_order,document_code,document_title,issuing_authority,source_section,printed_page,pdf_page_index,source_sha256) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
        $insert->bind_param('sssissssiis',$values[0],$values[1],$values[2],$values[3],$values[4],$values[5],$values[6],$values[7],$values[8],$values[9],$values[10]);
        $insert->execute(); $insert->close();
    }
    $count=(int)$conn->query("SELECT COUNT(*) FROM curriculum_outcome_catalog WHERE source_key='CHED-CMO-25-2015-BSIT'")->fetch_row()[0];
    if ($count!==13) throw new RuntimeException('Catalog contains an unexpected number of rows.');
    $conn->commit();
    echo "PASS: immutable CMO 25 BSIT outcome catalog is present and matches the Task 6 source package (13 records).\n";
} catch (Throwable $e) { $conn->rollback(); throw $e; }
