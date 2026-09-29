<?php
// Offline development operator tool. No HTTP execution and no user provisioning.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$options = getopt('', ['action:', 'database:', 'actor:', 'confirm-development']);
$action = $options['action'] ?? '';
$database = $options['database'] ?? '';
$actor = filter_var($options['actor'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
if (!in_array($action, ['seed','cleanup','verify'], true)
    || !is_string($database) || !preg_match('/^[a-z][a-z0-9_]{2,63}$/D', $database)
    || in_array($database, ['mysql','sys','information_schema','performance_schema'], true)
    || !$actor || !array_key_exists('confirm-development', $options)) {
    fwrite(STDERR, "Usage: php database/seeds/development.php --action=seed|verify|cleanup --database=EXPLICIT_DEV_DB --actor=EXISTING_ADMIN_ID --confirm-development\n");
    exit(1);
}
putenv('SMS_DB_NAME=' . $database);
require_once __DIR__ . '/../../shared/db.php';
require_once __DIR__ . '/../../shared/http.php';
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../shared/authorization.php';
require_once __DIR__ . '/../../shared/audit.php';
// Attribution in this CLI process only: no session_start(), cookie or browser session.
$_SESSION = ['user_id'=>(int)$actor];
const DEMO_DATASET = 'csms-dev-demo-v1';
const DEMO_MARKER = 'DEVELOPMENT / SYNTHETIC DATA — NOT OFFICIAL BESTLINK DATA';
const DEMO_COUNTS = [
    'academic_programs'=>2, 'subjects'=>6, 'curricula'=>2, 'curriculum_versions'=>3,
    'curriculum_version_subjects'=>11, 'curriculum_subject_requirements'=>3,
    'curriculum_elective_groups'=>1, 'curriculum_elective_group_subjects'=>2,
    'subject_equivalencies'=>3, 'grade_weighting_schemes'=>1,
    'grade_weighting_components'=>2, 'compliance_references'=>1, 'compliance_rules'=>5,
];

function demoRows(mysqli $db, string $table, string $where): array
{
    // Identifiers/predicates are exclusively source-owned, never CLI input.
    return $db->query("SELECT * FROM `$table` WHERE $where ORDER BY id FOR UPDATE")->fetch_all(MYSQLI_ASSOC);
}
function demoIds(array $rows): string
{
    return implode(',', array_map('intval', array_column($rows, 'id'))) ?: '0';
}
function demoFingerprint(array $row): string
{
    return hash('sha256', json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
}
function demoSql(mysqli $db, string $filename): void
{
    $sql = file_get_contents(__DIR__ . '/' . $filename);
    if ($sql === false) { throw new RuntimeException('Cannot read development SQL.'); }
    $db->multi_query($sql);
    do {
        if ($result = $db->store_result()) { $result->free(); }
    } while ($db->more_results() && $db->next_result());
}
function demoCapture(mysqli $db): array
{
    $rows=[];
    $rows['academic_programs']=demoRows($db,'academic_programs',"program_code IN ('DEV-DEMO-PROG-A','DEV-DEMO-PROG-B')");
    $rows['subjects']=demoRows($db,'subjects',"subject_code IN ('DEV-DEMO-101','DEV-DEMO-102','DEV-DEMO-201','DEV-DEMO-202','DEV-DEMO-ELEC1','DEV-DEMO-ELEC2')");
    $rows['curricula']=demoRows($db,'curricula','academic_program_id IN ('.demoIds($rows['academic_programs']).')');
    $rows['curriculum_versions']=demoRows($db,'curriculum_versions','curriculum_id IN ('.demoIds($rows['curricula']).')');
    $versions=demoIds($rows['curriculum_versions']);
    foreach (['curriculum_version_subjects','curriculum_subject_requirements','curriculum_elective_groups','curriculum_elective_group_subjects'] as $table) {
        $rows[$table]=demoRows($db,$table,"curriculum_version_id IN ($versions)");
    }
    $subjects=demoIds($rows['subjects']);
    $rows['subject_equivalencies']=demoRows($db,'subject_equivalencies',"subject_id IN ($subjects) AND equivalent_subject_id IN ($subjects)");
    $rows['grade_weighting_schemes']=demoRows($db,'grade_weighting_schemes','curriculum_version_subject_id IN ('.demoIds($rows['curriculum_version_subjects']).')');
    $rows['grade_weighting_components']=demoRows($db,'grade_weighting_components','scheme_id IN ('.demoIds($rows['grade_weighting_schemes']).')');
    $rows['compliance_references']=demoRows($db,'compliance_references',"reference_type='OTHER' AND reference_code='DEV-DEMO-REFERENCE'");
    $rows['compliance_rules']=demoRows($db,'compliance_rules','compliance_reference_id IN ('.demoIds($rows['compliance_references']).')');
    $manifest=[];
    foreach(DEMO_COUNTS as $table=>$expected) {
        if(count($rows[$table])!==$expected) { throw new RuntimeException('Unexpected seeded count: '.$table); }
        foreach($rows[$table] as $row) { $manifest[$table][(int)$row['id']]=demoFingerprint($row); }
    }
    return $manifest;
}

$locked=false; $transaction=false;
$lockName='csms_demo_' . substr(hash('sha256',$database),0,40);
try {
    if (!in_array('admin',get_user_roles((int)$actor),true)) {
        throw new RuntimeException('Actor must be an existing temporary development admin. No account will be created.');
    }
    $stmt=$conn->prepare('SELECT GET_LOCK(?,0)'); $stmt->bind_param('s',$lockName); $stmt->execute();
    $locked=(int)$stmt->get_result()->fetch_row()[0]===1; $stmt->close();
    if(!$locked) { throw new RuntimeException('Another demo-data operation is running.'); }
    $conn->query('CREATE TEMPORARY TABLE csms_demo_context (actor_id INT UNSIGNED NOT NULL, marker VARCHAR(255) NOT NULL)');
    $conn->query('CREATE TEMPORARY TABLE csms_demo_owned (table_name VARCHAR(64) NOT NULL, record_id BIGINT UNSIGNED NOT NULL, PRIMARY KEY(table_name,record_id))');
    $stmt=$conn->prepare('INSERT INTO csms_demo_context VALUES (?,?)');
    $marker=DEMO_MARKER; $stmt->bind_param('is',$actor,$marker); $stmt->execute(); $stmt->close();
    $conn->begin_transaction(); $transaction=true;
    $dataset=DEMO_DATASET;
    $stmt=$conn->prepare("SELECT id,action,metadata_json FROM audit_logs WHERE entity_type='development_dataset' AND action IN ('DEVELOPMENT_SEED_CREATE','DEVELOPMENT_SEED_REMOVE') AND JSON_UNQUOTE(JSON_EXTRACT(metadata_json,'$.dataset'))=? ORDER BY id DESC LIMIT 1");
    $stmt->bind_param('s',$dataset); $stmt->execute(); $last=$stmt->get_result()->fetch_assoc(); $stmt->close();
    $active=$last && $last['action']==='DEVELOPMENT_SEED_CREATE';
    if($action==='seed') {
        if($active) { throw new RuntimeException('Demo dataset already exists. Verify or clean it before seeding again.'); }
        foreach(['academic_programs'=>'program_code','subjects'=>'subject_code','curricula'=>'curriculum_code','compliance_references'=>'reference_code'] as $table=>$column) {
            if(demoRows($conn,$table,"`$column` LIKE 'DEV-DEMO-%'")) {
                throw new RuntimeException('Existing DEV-DEMO identifiers detected. No data was overwritten.');
            }
        }
        demoSql($conn,'development_seed.sql');
        $records=demoCapture($conn);
        audit_log_required('DEVELOPMENT_SEED_CREATE','development_dataset',null,'Synthetic development dataset created.',[
            'dataset'=>DEMO_DATASET,'records'=>$records,
        ]);
        $conn->commit(); $transaction=false;
        echo json_encode(['action'=>'seed','database'=>$database,'counts'=>DEMO_COUNTS,'notice'=>DEMO_MARKER],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE).PHP_EOL;
    } else {
        if(!$active) { throw new RuntimeException('No active seed provenance found. Nothing was removed.'); }
        $metadata=json_decode($last['metadata_json'],true,512,JSON_THROW_ON_ERROR);
        $records=$metadata['records']??[];
        if(array_keys($records)!==array_keys(DEMO_COUNTS)) { throw new RuntimeException('Unsupported seed manifest. Nothing was removed.'); }
        foreach(DEMO_COUNTS as $table=>$expected) {
            if(count($records[$table])!==$expected) { throw new RuntimeException('Incomplete seed provenance.'); }
            foreach($records[$table] as $id=>$fingerprint) {
                if(!ctype_digit((string)$id) || (int)$id<1) { throw new RuntimeException('Invalid seed identifier.'); }
                $rows=demoRows($conn,$table,'id='.(int)$id);
                if(count($rows)!==1 || !hash_equals($fingerprint,demoFingerprint($rows[0]))) {
                    throw new RuntimeException('Seed record changed or missing in '.$table.'. Review manually; no automated deletion performed.');
                }
            }
        }
        if($action==='verify') {
            $conn->rollback(); $transaction=false;
            echo json_encode(['action'=>'verify','database'=>$database,'counts'=>DEMO_COUNTS,'unchanged'=>true],JSON_PRETTY_PRINT).PHP_EOL;
        } else {
            // Snapshots generated later during the demo are disposable ONLY when
            // both their version and every referenced rule belong to this dataset.
            $versionIds=implode(',',array_map('intval',array_keys($records['curriculum_versions'])));
            foreach(demoRows($conn,'compliance_validation_runs',"curriculum_version_id IN ($versionIds)") as $run) {
                foreach(demoRows($conn,'compliance_validation_results','validation_run_id='.(int)$run['id']) as $result) {
                    if(!isset($records['compliance_rules'][(int)$result['compliance_rule_id']])) {
                        throw new RuntimeException('Demo run contains an external rule. Manual review required.');
                    }
                    $records['compliance_validation_results'][(int)$result['id']]=demoFingerprint($result);
                }
                $records['compliance_validation_runs'][(int)$run['id']]=demoFingerprint($run);
            }
            $stmt=$conn->prepare('INSERT INTO csms_demo_owned (table_name,record_id) VALUES (?,?)');
            foreach($records as $table=>$entries) {
                foreach($entries as $id=>$hash) { $stmt->bind_param('si',$table,$id); $stmt->execute(); }
            }
            $stmt->close();
            demoSql($conn,'development_cleanup.sql');
            audit_log_required('DEVELOPMENT_SEED_REMOVE','development_dataset',null,'Synthetic development dataset removed.',[
                'dataset'=>DEMO_DATASET,'seed_audit_id'=>(int)$last['id'],
            ]);
            $conn->commit(); $transaction=false;
            echo "Synthetic dataset removed from $database. Audit evidence retained.\n";
        }
    }
} catch(Throwable $error) {
    if($transaction) { $conn->rollback(); }
    fwrite(STDERR,$error instanceof mysqli_sql_exception
        ? "Development operation rolled back. A database constraint/operation failed; no partial changes were committed. Check schema/dependencies.\n"
        : $error->getMessage()."\n");
    exit(1);
} finally {
    if($locked) {
        $stmt=$conn->prepare('SELECT RELEASE_LOCK(?)'); $stmt->bind_param('s',$lockName); $stmt->execute(); $stmt->close();
    }
    $conn->close();
}
