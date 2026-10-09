<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/version-protection-fixture.php';
require_once __DIR__ . '/../modules/curriculum/services/CurriculumVersionReferenceService.php';
require_once __DIR__ . '/../modules/curriculum/services/ComplianceReferenceService.php';
require_once __DIR__ . '/../modules/curriculum/services/SubjectOfferingHistoryService.php';
$db = protectionDatabase();
$base = protectionFixtures($db);
$actor = $base['actor'];
$service = new CurriculumVersionReferenceService($db);
$sourceService = new ComplianceReferenceService($db);
$checks = 0;
function task3Check(bool $ok, string $label): void { global $checks; if (!$ok) throw new RuntimeException('FAIL: '.$label); $checks++; }
function task3Reject(callable $call, string $label): Throwable {
    try { $call(); } catch (DomainConflictException|DomainValidationException|DomainNotFoundException $e) { task3Check(true,$label); return $e; }
    throw new RuntimeException('FAIL: accepted invalid Task 3 operation: '.$label);
}
function task3Version(mysqli $db, string $suffix, bool $withPlacement = true): array {
    $program = protectionInsert($db, "INSERT INTO academic_programs(program_code,program_name) VALUES ('T3-$suffix','Task 3 program')");
    $curriculum = protectionInsert($db, "INSERT INTO curricula(curriculum_code,curriculum_name,academic_program_id) VALUES ('T3-$suffix','Task 3 curriculum',$program)");
    $version = protectionInsert($db, "INSERT INTO curriculum_versions(curriculum_id,version_label,status) VALUES ($curriculum,'Task 3 version','draft')");
    $out = compact('program','curriculum','version');
    if ($withPlacement) {
        $subject = protectionInsert($db, "INSERT INTO subjects(subject_code,subject_title,description,units,lecture_units,laboratory_units) VALUES ('T3-$suffix-S','Task 3 subject','Captured description',3,2,1)");
        $placement = protectionInsert($db, "INSERT INTO curriculum_version_subjects(curriculum_version_id,subject_id,year_level,semester,sequence_order,is_required) VALUES ($version,$subject,'2nd Year','2nd Semester',7,0)");
        $out += compact('subject','placement');
    }
    return $out;
}
function task3Source(ComplianceReferenceService $svc, int $actor, string $suffix, string $kind='ched_sample_curriculum', string $status='draft'): array {
    return $svc->create(['reference_type'=>$kind==='institutional_curriculum'?'INSTITUTIONAL':'CHED',
        'source_kind'=>$kind,'reference_code'=>'T3-'.$suffix,'title'=>'Task 3 '.$suffix,
        'issuing_body'=>$kind==='institutional_curriculum'?'Institution-provided (declared)':'CHED',
        'source_url'=>'https://example.invalid/'.$suffix,'effective_date'=>'2026-01-01',
        'verification_status'=>$status,'notes'=>'Synthetic fixture'], $actor);
}
function task3Attach(CurriculumVersionReferenceService $svc, int $v, int $source, int $actor): void {
    $svc->attach(['curriculum_version_id'=>$v,'compliance_reference_id'=>$source,'reference_purpose'=>'school_curriculum'],$actor);
}
function task3Identity(ComplianceReferenceService $svc, int $source, int $actor, string $result='not_assessed'): void {
    $svc->recordEvidence($source,['verification_subject'=>'document_identity','result'=>$result,
        'evidence_locator'=>$result==='confirmed'?'fixture://synthetic-document':'','rationale'=>'Synthetic test evidence only.'],$actor);
}

$f = task3Version($db,'CLASSIFY');
$source = task3Source($sourceService,$actor,'SAMPLE');
task3Identity($sourceService,(int)$source['id'],$actor,'not_assessed');
$sourceService->recordEvidence((int)$source['id'],['verification_subject'=>'applicability','result'=>'not_assessed','rationale'=>'Applicability not evaluated.'],$actor);
task3Attach($service,$f['version'],(int)$source['id'],$actor);
$link = $db->query("SELECT reference_code_snapshot,reference_title_snapshot,source_kind_snapshot,issuing_body_snapshot,linked_by,linked_at FROM curriculum_version_references WHERE curriculum_version_id={$f['version']}")->fetch_assoc();
task3Check($link['reference_code_snapshot']==='T3-SAMPLE' && $link['source_kind_snapshot']==='ched_sample_curriculum' && (int)$link['linked_by']===$actor, 'attachment preserves source identity, kind, actor, and timestamp');
task3Reject(fn()=>task3Attach($service,$f['version'],(int)$source['id'],$actor),'duplicate attachment is rejected');
$same = task3SnapshotSourceCount($db,(int)$source['id']);
task3Check($same===1,'duplicate attachment leaves a single link');
$identitySource=task3Source($sourceService,$actor,'IDENTITY');
task3Reject(fn()=>$sourceService->update((int)$identitySource['id'],['reference_type'=>'CHED','source_kind'=>'ched_sample_curriculum','reference_code'=>'T3-IDENTITY','title'=>'Task 3 IDENTITY','issuing_body'=>'CHED','source_url'=>'https://example.invalid/IDENTITY','effective_date'=>'2026-01-01','verification_status'=>'verified']),'manual verified status without evidence is rejected');
$identitySource=$sourceService->recordEvidence((int)$identitySource['id'],['verification_subject'=>'document_identity','result'=>'confirmed','evidence_locator'=>'fixture://identity','rationale'=>'Synthetic confirmed identity evidence.'],$actor);
task3Check($identitySource['verification_status']==='verified','confirmed identity evidence sets the internal source status');
$beforeIdentityEdit=protectionSnapshot($db);
task3Reject(fn()=>$sourceService->update((int)$identitySource['id'],['reference_type'=>'CHED','source_kind'=>'ched_sample_curriculum','reference_code'=>'T3-IDENTITY-CHANGED','title'=>'Changed source title','issuing_body'=>'CHED','source_url'=>'https://example.invalid/changed','effective_date'=>'2026-01-01','verification_status'=>'verified']),'source edits cannot reuse evidence captured for old metadata');
task3Check($beforeIdentityEdit===protectionSnapshot($db),'stale verification evidence rejection preserves source rows');
$result = $service->classifyReference($f['version'],(int)$source['id'],'ched_sample',true,$actor);
task3Check($result['version_role']==='reference' && $result['review_status']==='not_started' && $result['status']==='draft', 'classification changes only version role');
task3Check($result['institutional_adoption_label']==='Institutional adoption/approval not verified', 'CHED sample classification never implies BCP adoption');
$snapshots = $db->query("SELECT s.*,p.year_level,p.semester,p.sequence_order,p.is_required FROM curriculum_version_subject_snapshots s JOIN curriculum_version_subjects p ON p.id=s.curriculum_version_subject_id WHERE p.curriculum_version_id={$f['version']}")->fetch_all(MYSQLI_ASSOC);
task3Check(count($snapshots)===1 && (int)$snapshots[0]['snapshot_complete']===1, 'classification captures one complete subject snapshot atomically');
task3Check($snapshots[0]['subject_code_snapshot']==='T3-CLASSIFY-S' && $snapshots[0]['subject_title_snapshot']==='Task 3 subject' && (float)$snapshots[0]['units_snapshot']===3.0 && (float)$snapshots[0]['lecture_units_snapshot']===2.0 && (float)$snapshots[0]['laboratory_units_snapshot']===1.0 && $snapshots[0]['description_snapshot']==='Captured description', 'snapshot contains subject identity, units, and description');
task3Check((int)$snapshots[0]['source_reference_id']===(int)$source['id'] && $snapshots[0]['capture_method']==='reference_capture' && $snapshots[0]['captured_at']!==null, 'snapshot records source, method, and capture time');
task3Check($snapshots[0]['year_level']==='2nd Year' && $snapshots[0]['semester']==='2nd Semester' && (int)$snapshots[0]['sequence_order']===7 && (int)$snapshots[0]['is_required']===0, 'version-scoped placement values remain preserved');
$history = new SubjectOfferingHistoryService($db);
$capturedHistory = $history->historyDetails((int)$f['placement'])['record'];
task3Check((int)$capturedHistory['snapshot_complete']===1 && $capturedHistory['subject_code']==='T3-CLASSIFY-S' && $capturedHistory['current_subject_code']==='T3-CLASSIFY-S', 'historical detail renders captured values and separates current context');
$legacyVersion = protectionInsert($db, "INSERT INTO curriculum_versions(curriculum_id,version_label,status) VALUES ({$f['curriculum']},'Legacy historical row','active')");
$legacyPlacement = protectionInsert($db, "INSERT INTO curriculum_version_subjects(curriculum_version_id,subject_id,year_level,semester,sequence_order,is_required) VALUES ($legacyVersion,{$f['subject']},'2nd Year','2nd Semester',7,0)");
$legacyHistory = $history->historyDetails($legacyPlacement)['record'];
task3Check((int)$legacyHistory['snapshot_complete']===0 && $legacyHistory['subject_code']===null && $legacyHistory['current_subject_code']==='T3-CLASSIFY-S', 'legacy historical detail discloses missing snapshot without substituting current subject data');
$historyList = $history->listHistory(['curriculum_version_id'=>$legacyVersion,'page'=>1,'per_page'=>20]);
task3Check(count($historyList['history'])===1 && $historyList['history'][0]['subject_title']===null && (int)$historyList['history'][0]['snapshot_complete']===0, 'historical list preserves explicit legacy snapshot gap');
task3Check((int)$db->query("SELECT COUNT(*) FROM audit_logs WHERE entity_type='curriculum_version' AND entity_id={$f['version']} AND action='CURRICULUM_VERSION_CLASSIFY_REFERENCE'")->fetch_row()[0]===1, 'classification and required audit commit together');
task3Reject(fn()=>$service->classifyReference($f['version'],(int)$source['id'],'ched_sample',true,$actor),'reference cannot be reclassified');

$inactiveVersion = task3Version($db,'INACTIVE');
$inactiveSource = task3Source($sourceService,$actor,'INACTIVE','ched_sample_curriculum','inactive');
task3Reject(fn()=>task3Attach($service,$inactiveVersion['version'],(int)$inactiveSource['id'],$actor),'inactive source cannot attach');
$missing = task3Version($db,'MISSING');
task3Reject(fn()=>$service->classifyReference($missing['version'],(int)$source['id'],'ched_sample',true,$actor),'unattached source cannot classify unrelated version');
$empty = task3Version($db,'EMPTY',false);
task3Reject(fn()=>$service->classifyReference($empty['version'],(int)$source['id'],'ched_sample',true,$actor),'empty placement set cannot classify');

$changedSourceVersion = task3Version($db,'SOURCECHANGED');
$changedSource = task3Source($sourceService,$actor,'SOURCECHANGED'); task3Identity($sourceService,(int)$changedSource['id'],$actor); task3Attach($service,$changedSourceVersion['version'],(int)$changedSource['id'],$actor);
$sourceService->update((int)$changedSource['id'],['reference_type'=>'CHED','source_kind'=>'ched_sample_curriculum','reference_code'=>'T3-SOURCECHANGED-NEW','title'=>'Corrected sample record','issuing_body'=>'CHED','source_url'=>'https://example.invalid/corrected','effective_date'=>'2026-01-01','verification_status'=>'draft']);
$before=protectionSnapshot($db);
task3Reject(fn()=>$service->classifyReference($changedSourceVersion['version'],(int)$changedSource['id'],'ched_sample',true,$actor),'classification rejects source metadata changed after attachment');
task3Check($before===protectionSnapshot($db),'changed-source rejection preserves old link evidence and rows');

$badReview = task3Version($db,'REVIEW');
task3Attach($service,$badReview['version'],(int)$source['id'],$actor);
$db->query("UPDATE curriculum_versions SET review_status='under_review' WHERE id={$badReview['version']}");
task3Reject(fn()=>$service->classifyReference($badReview['version'],(int)$source['id'],'ched_sample',true,$actor),'under-review unclassified version cannot classify');

$conflict = task3Version($db,'CONFLICT');
$conflictSource = task3Source($sourceService,$actor,'CONFLICT'); task3Identity($sourceService,(int)$conflictSource['id'],$actor); task3Attach($service,$conflict['version'],(int)$conflictSource['id'],$actor);
$db->query("INSERT INTO curriculum_version_subject_snapshots(curriculum_version_subject_id,subject_code_snapshot,subject_title_snapshot,units_snapshot,capture_method,source_reference_id,snapshot_complete) VALUES ({$conflict['placement']},'OLD','Old snapshot',1,'mapping_capture',{$source['id']},1)");
$before = protectionSnapshot($db);
task3Reject(fn()=>$service->classifyReference($conflict['version'],(int)$conflictSource['id'],'ched_sample',true,$actor),'conflicting historical snapshot blocks classification');
task3Check($before===protectionSnapshot($db),'rejected conflicting snapshot leaves every row unchanged');

$matching = task3Version($db,'MATCH');
$matchingSource = task3Source($sourceService,$actor,'MATCH'); task3Identity($sourceService,(int)$matchingSource['id'],$actor); task3Attach($service,$matching['version'],(int)$matchingSource['id'],$actor);
$db->query("INSERT INTO curriculum_version_subject_snapshots(curriculum_version_subject_id,subject_code_snapshot,subject_title_snapshot,units_snapshot,lecture_units_snapshot,laboratory_units_snapshot,description_snapshot,capture_method,source_reference_id,snapshot_complete) VALUES ({$matching['placement']},'T3-MATCH-S','Task 3 subject',3,2,1,'Captured description','reference_capture',{$matchingSource['id']},1)");
$capturedAt=$db->query("SELECT captured_at FROM curriculum_version_subject_snapshots WHERE curriculum_version_subject_id={$matching['placement']}")->fetch_row()[0];
$service->classifyReference($matching['version'],(int)$matchingSource['id'],'ched_sample',true,$actor);
task3Check($db->query("SELECT captured_at FROM curriculum_version_subject_snapshots WHERE curriculum_version_subject_id={$matching['placement']}")->fetch_row()[0]===$capturedAt,'identical complete snapshots are reused without overwrite');

$auditFailure = task3Version($db,'AUDIT');
$auditSource = task3Source($sourceService,$actor,'AUDIT'); task3Identity($sourceService,(int)$auditSource['id'],$actor); task3Attach($service,$auditFailure['version'],(int)$auditSource['id'],$actor);
$db->query("CREATE TRIGGER task3_fail_classification_audit BEFORE INSERT ON audit_logs FOR EACH ROW BEGIN IF NEW.action='CURRICULUM_VERSION_CLASSIFY_REFERENCE' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic audit failure'; END IF; END");
try { try { $service->classifyReference($auditFailure['version'],(int)$auditSource['id'],'ched_sample',true,$actor); throw new RuntimeException('FAIL: audit failure allowed classification'); } catch (mysqli_sql_exception) { task3Check(true,'audit insert failure aborts classification'); } }
finally { $db->query('DROP TRIGGER task3_fail_classification_audit'); }
task3Check($db->query("SELECT version_role FROM curriculum_versions WHERE id={$auditFailure['version']}")->fetch_row()[0]==='unclassified' && (int)$db->query("SELECT COUNT(*) FROM curriculum_version_subject_snapshots WHERE curriculum_version_subject_id={$auditFailure['placement']}")->fetch_row()[0]===0, 'audit failure rolls back role and snapshots');

$snapshotFailure = task3Version($db,'SNAPFAIL');
$snapshotSource = task3Source($sourceService,$actor,'SNAPFAIL'); task3Identity($sourceService,(int)$snapshotSource['id'],$actor); task3Attach($service,$snapshotFailure['version'],(int)$snapshotSource['id'],$actor);
$db->query("CREATE TRIGGER task3_fail_snapshot BEFORE INSERT ON curriculum_version_subject_snapshots FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic snapshot failure'");
try { try { $service->classifyReference($snapshotFailure['version'],(int)$snapshotSource['id'],'ched_sample',true,$actor); throw new RuntimeException('FAIL: snapshot failure allowed classification'); } catch (mysqli_sql_exception) { task3Check(true,'snapshot insert failure aborts classification'); } }
finally { $db->query('DROP TRIGGER task3_fail_snapshot'); }
task3Check($db->query("SELECT version_role FROM curriculum_versions WHERE id={$snapshotFailure['version']}")->fetch_row()[0]==='unclassified' && (int)$db->query("SELECT COUNT(*) FROM audit_logs WHERE entity_type='curriculum_version' AND entity_id={$snapshotFailure['version']} AND action='CURRICULUM_VERSION_CLASSIFY_REFERENCE'")->fetch_row()[0]===0, 'snapshot failure leaves no role or classification audit');

echo json_encode(['passed'=>true,'checks'=>$checks,'suite'=>'Task 3 reference baseline service'], JSON_THROW_ON_ERROR).PHP_EOL;

function task3SnapshotSourceCount(mysqli $db, int $sourceId): int {
    return (int)$db->query("SELECT COUNT(*) FROM curriculum_version_references WHERE compliance_reference_id=$sourceId")->fetch_row()[0];
}
