<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/curriculum-revision-fixture.php';
require_once __DIR__.'/../modules/curriculum/services/SubjectOfferingHistoryService.php';
$db=protectionDatabase();$actor=protectionFixtures($db)['actor'];$f=revisionFixture($db,$actor,'READVIEW');
$v=(int)(new CurriculumRevisionService($db))->create(revisionInput($f,'Concurrent synthetic revision'),$actor)['version']['id'];
/** Test-only interception at a real statement boundary, no production timing hook. */
class ComparisonBarrierConnection extends mysqli
{
    public $barrier=null;
    private int $stateReads=0;
    public function prepare($query): mysqli_stmt
    {
        if (str_contains($query,'SELECT p.*, s.subject_code') && ++$this->stateReads===2 && $this->barrier) ($this->barrier)($this);
        return parent::prepare($query);
    }
}
$config=csms_database_config();$reader=new ComparisonBarrierConnection($config['host'],$config['user'],$config['password'],$config['database'],$config['port']);$reader->set_charset('utf8mb4');
$writer=csms_database_connect($config);$writer->query('SET SESSION innodb_lock_wait_timeout=2');
$checks=0;
function comparisonConcurrencyCheck(bool $ok,string $label):void {global $checks;if(!$ok)throw new RuntimeException('FAIL: '.$label);$checks++;echo 'PASS: '.$label.PHP_EOL;}
$reader->query('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
$oldDigest=protectionSnapshot($db);$writerCommitted=false;$readOnlyEnforced=false;
$reader->barrier=function(mysqli $connection)use($writer,$f,$v,&$writerCommitted,&$readOnlyEnforced):void {
    // The report has read the reference and provenance; commit an atomic proposal edit before its proposal query.
    try {$connection->query("UPDATE curriculum_versions SET description='Must not write' WHERE id=$v");}
    catch(mysqli_sql_exception $e) {$readOnlyEnforced=(int)$e->getCode()===1792;}
    $writer->begin_transaction();
    try {
        $writer->query("UPDATE curriculum_version_subjects SET sequence_order=99 WHERE curriculum_version_id=$v AND subject_id={$f['subjects'][0]}");
        $writer->query("DELETE FROM curriculum_subject_requirements WHERE curriculum_version_id=$v AND subject_id={$f['subjects'][1]}");
        $writer->query("UPDATE curriculum_elective_groups SET required_selections=2 WHERE curriculum_version_id=$v");
        $writer->query("UPDATE grade_weighting_components c JOIN grade_weighting_schemes s ON s.id=c.scheme_id JOIN curriculum_version_subjects p ON p.id=s.curriculum_version_subject_id SET c.sequence_order=77 WHERE p.curriculum_version_id=$v");
        $writer->commit();$writerCommitted=true;
    }catch(Throwable $e){$writer->rollback();throw $e;}
};
try {
    $input=['curriculum_id'=>$f['curriculum'],'version_b_id'=>$v];
    $r=(new SubjectOfferingHistoryService($reader))->compareRevision($input);
    comparisonConcurrencyCheck($writerCommitted,'independent writer committed between actual report queries without being blocked');
    comparisonConcurrencyCheck($readOnlyEnforced,'MariaDB rejects attempted write inside report transaction (1792)');
    comparisonConcurrencyCheck($r['summary']['unchanged']===4 && !$r['partial'],'in-flight report retains pre-edit subject view');
    foreach (['requirements','electives','grade_weighting'] as $dimension) comparisonConcurrencyCheck($r[$dimension]['changes']===[],'in-flight report retains same pre-edit '.$dimension.' view');
    $afterWrite=protectionSnapshot($db);
    $next=(new SubjectOfferingHistoryService($reader))->compareRevision($input);
    comparisonConcurrencyCheck($next['summary']['moved']===1 && count($next['requirements']['changes'])===1 && count($next['electives']['changes'])===1 && count($next['grade_weighting']['changes'])===1,'subsequent report sees all committed edits together');
    comparisonConcurrencyCheck(protectionSnapshot($db)===$afterWrite && $oldDigest!==$afterWrite,'only deliberate writer changed rows; reports added no writes');
    comparisonConcurrencyCheck(strtoupper($reader->query('SELECT @@tx_isolation')->fetch_row()[0])==='READ-COMMITTED','transaction-specific isolation preserves session default');
    comparisonConcurrencyCheck((int)$reader->query('SELECT @@in_transaction')->fetch_row()[0]===0,'read transaction ended');
    $reader->begin_transaction();$failed=false;
    try {(new SubjectOfferingHistoryService($reader))->compareRevision($input);}catch(RuntimeException $e){$failed=true;}
    comparisonConcurrencyCheck($failed && (int)$reader->query('SELECT @@in_transaction')->fetch_row()[0]===1,'nested caller transaction is rejected without committing it');$reader->rollback();
    echo json_encode(['passed'=>true,'checks'=>$checks,'suite'=>'Task 5 consistent comparison view']).PHP_EOL;
}finally{$reader->rollback();$writer->rollback();$reader->close();$writer->close();}
