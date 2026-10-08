<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/version-protection-fixture.php';
$db=protectionDatabase();
foreach(['CurriculumVersionService','CurriculumVersionSubjectService','CurriculumSubjectRequirementService','ElectivesWorkspaceService','CurriculumElectiveGroupService','CurriculumElectiveGroupSubjectService','GradeWeightingService','ArchiveCenterService','SubjectService','AcademicProgramService','CurriculumService','ComplianceReferenceService','ComplianceRuleService','ComplianceValidationService','CurriculumExportService','SubjectOfferingHistoryService','CIMFContextBuilder','ProgramSubjectAssignmentService','SubjectEquivalencyService'] as $service)require_once __DIR__."/../modules/curriculum/services/$service.php";
$fixtures=protectionFixtures($db);$actor=$fixtures['actor'];$checks=0;
function protectionCheck(bool $ok,string $label):void{global $checks;if(!$ok)throw new RuntimeException("FAIL: $label");$checks++;}
function protectionReject(callable $operation,string $label):void{
    global $db;
    $before=protectionSnapshot($db);
    try{$operation();throw new RuntimeException("Accepted protected mutation: $label");}
    catch(DomainConflictException $e){protectionCheck(str_contains($e->getMessage(),'protected') || str_contains($e->getMessage(),'invalid lifecycle'),$label.' policy conflict');}
    protectionCheck($before===protectionSnapshot($db),$label.' records unchanged');
}
foreach(['reference','under_review','approved'] as $state){
    $f=$fixtures['versions'][$state];$i=protectionInputs($f);
    $v=new CurriculumVersionService($db);$p=new CurriculumVersionSubjectService($db);$r=new CurriculumSubjectRequirementService($db);
    $e=new ElectivesWorkspaceService($db);$g=new CurriculumElectiveGroupService($db);$m=new CurriculumElectiveGroupSubjectService($db);$w=new GradeWeightingService($db);$a=new ArchiveCenterService($db);
    $operations=[
        'rename'=>fn()=>$v->rename($f['version'],['version_label'=>'Changed'], $actor),
        'assign'=>fn()=>$p->assign($i['placement']+['curriculum_version_id'=>$f['version'],'subject_id'=>$f['subjects'][3]],$actor),
        'placement update forged owner'=>fn()=>$p->updatePlacement($f['placements'][2],$i['placement']+['curriculum_version_id'=>$fixtures['versions']['legacy']['version']]),
        'placement remove'=>fn()=>$p->remove($f['placements'][2]),
        'requirement create'=>fn()=>$r->create(['curriculum_version_id'=>$f['version'],'subject_id'=>$f['subjects'][2],'required_subject_id'=>$f['subjects'][0],'requirement_type'=>'corequisite'],$actor),
        'requirement remove'=>fn()=>$r->remove($f['requirement']),
        'requirement updateRule'=>fn()=>$r->updateRule(['curriculum_version_id'=>$f['version'],'subject_id'=>$f['subjects'][2],'required_subject_ids'=>[],'expected_ids'=>[]],$actor),
        'workspace create'=>fn()=>$e->save($i['context']+$i['group'],$actor),
        'workspace update forged context'=>fn()=>$e->save(protectionInputs($fixtures['versions']['legacy'])['context']+$i['group'],$actor,$f['group']),
        'workspace archive'=>fn()=>$e->archive($i['context']+['id'=>$f['group']]),
        'legacy group create'=>fn()=>$g->create($i['group'],$actor),
        'legacy group update'=>fn()=>$g->update($f['group'],$i['group']),
        'legacy group remove'=>fn()=>$g->remove($f['group']),
        'legacy membership add'=>fn()=>$m->add(['elective_group_id'=>$f['group'],'subject_id'=>$f['subjects'][2]],$actor),
        'legacy membership remove'=>fn()=>$m->remove($f['members'][0]),
        'scheme create'=>fn()=>$w->createScheme(['curriculum_version_subject_id'=>$f['placements'][2]],$actor),
        'scheme update'=>fn()=>$w->updateScheme($f['scheme'],['description'=>'Changed']),
        'scheme remove'=>fn()=>$w->removeScheme($f['scheme']),
        'component create'=>fn()=>$w->createComponent($i['component']+['scheme_id'=>$f['scheme']],$actor),
        'component update'=>fn()=>$w->updateComponent($f['component'],$i['component']),
        'component remove'=>fn()=>$w->removeComponent($f['component']),
        'configuration forged version'=>fn()=>$w->saveConfiguration($f['placements'][0],$fixtures['versions']['legacy']['version'],$i['configuration'],$actor),
        'shared subject'=>fn()=>(new SubjectService($db))->update($f['subjects'][0],$i['subject']),
        'shared program'=>fn()=>(new AcademicProgramService($db))->update($f['program'],$i['program']),
        'shared curriculum'=>fn()=>(new CurriculumService($db))->update($f['curriculum'],$i['curriculum']),
        'linked source'=>fn()=>(new ComplianceReferenceService($db))->update($f['reference'],$i['reference']),
        'scoped rule create'=>fn()=>(new ComplianceRuleService($db))->create($i['rule'],$actor),
        'scoped rule update'=>fn()=>(new ComplianceRuleService($db))->update($f['rule'],$i['rule']),
    ];
    foreach(['program'=>$f['program'],'curriculum'=>$f['curriculum'],'subject'=>$f['subjects'][0],'elective_group'=>$f['group']] as $module=>$id){
        $operations["archive $module"]=fn()=>$a->archive($module,$id,$actor);
        $operations["restore $module"]=fn()=>$a->restore($module,$id);
    }
    foreach($operations as $label=>$operation)protectionReject($operation,"$state $label");
    // Protected reads/append-only diagnostics must still work.
    protectionCheck((new CurriculumVersionService($db))->get($f['version'])['id']==$f['version'],'protected version read');
    protectionCheck(count($e->workspace($i['context'])['groups'])===1,'protected elective read');
    protectionCheck($w->getSchemeForPlacement($f['placements'][0])['scheme']!==null,'protected grade read');
    protectionCheck((new ComplianceValidationService($db))->run($f['version'],$actor)['run']!==null,'protected append-only validation');
    protectionCheck((new CIMFContextBuilder($db))->build($f['version'],'review')!==[],'protected CIMF context read');
}
foreach(['legacy','draft','returned'] as $state){
    $f=$fixtures['versions'][$state];$i=protectionInputs($f);
    (new CurriculumVersionService($db))->rename($f['version'],['version_label'=>'Edited '.$state],$actor);
    (new CurriculumVersionSubjectService($db))->updatePlacement($f['placements'][2],$i['placement']);
    (new GradeWeightingService($db))->saveConfiguration($f['placements'][0],$f['version'],$i['configuration'],$actor);
    (new ElectivesWorkspaceService($db))->save($i['context']+$i['group'],$actor,$f['group']);
    protectionCheck(true,"$state version/mapping/grade/elective edits allowed");
}
$legacy=$fixtures['versions']['legacy'];$draft=$fixtures['versions']['draft'];
$before=protectionSnapshot($db);
try{(new CurriculumVersionSubjectService($db))->remove($legacy['placements'][2]);throw new RuntimeException('Legacy snapshot deleted');}
catch(DomainConflictException $e){protectionCheck(str_contains($e->getMessage(),'snapshots'),'legacy snapshot retained');}
protectionCheck($before===protectionSnapshot($db),'legacy snapshot rejection preserves records');
(new CurriculumVersionSubjectService($db))->remove($draft['placements'][2]);
protectionCheck((int)$db->query("SELECT COUNT(*) FROM curriculum_version_subject_snapshots WHERE curriculum_version_subject_id={$draft['placements'][2]}")->fetch_row()[0]===0,'proposed snapshot cleaned');
protectionCheck((int)$db->query("SELECT COUNT(*) FROM audit_logs WHERE action='CURRICULUM_SUBJECT_SNAPSHOT_REMOVE'")->fetch_row()[0]===1,'snapshot cleanup audited');
// A failed required audit must roll back snapshot and placement deletion.
$returned=$fixtures['versions']['returned'];
$db->query("CREATE TRIGGER task2_fail_snapshot_audit BEFORE INSERT ON audit_logs FOR EACH ROW BEGIN IF NEW.action='CURRICULUM_SUBJECT_SNAPSHOT_REMOVE' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic failure'; END IF; END");
$before=protectionSnapshot($db);
try{(new CurriculumVersionSubjectService($db))->remove($returned['placements'][2]);throw new RuntimeException('Audit failure ignored');}
catch(mysqli_sql_exception $e){protectionCheck($e->getCode()===1644,'forced audit failure surfaced');}
finally{$db->query('DROP TRIGGER task2_fail_snapshot_audit');}
protectionCheck($before===protectionSnapshot($db),'audit failure rolls snapshot and placement back');
$policy=new CurriculumVersionEditPolicy($db);
foreach([[$draft['version'],$draft['version']],[$draft['version'],$legacy['version']]] as [$owner,$baseline]){
    $db->begin_transaction();
    try{$policy->validateBaseline($owner,$baseline);throw new RuntimeException('Invalid baseline accepted');}
    catch(DomainConflictException $e){protectionCheck(true,'self/cross curriculum baseline rejected');}
    finally{$db->rollback();}
}
// Create an actual indirect cycle using legal SQL shapes, with FK checks enabled.
$db->query("UPDATE curriculum_versions SET version_role='proposed',review_status='draft',baseline_version_id={$draft['version']} WHERE id={$draft['baseline']}");
$db->begin_transaction();
try{$policy->validateBaseline($draft['version'],$draft['baseline']);throw new RuntimeException('Cycle accepted');}
catch(DomainConflictException $e){protectionCheck(str_contains($e->getMessage(),'cycle'),'indirect cycle rejected');}
finally{$db->rollback();}
$db->query("UPDATE curriculum_versions SET version_role='reference',review_status='not_started',baseline_version_id=NULL WHERE id={$draft['baseline']}");
$db->query("UPDATE curriculum_versions SET review_status='draft' WHERE id={$legacy['version']}");
protectionReject(fn()=>(new CurriculumVersionService($db))->rename($legacy['version'],['version_label'=>'Invalid'],$actor),'unexpected lifecycle');
$db->query("UPDATE curriculum_versions SET review_status='not_started' WHERE id={$legacy['version']}");
// Independent Subject Master creation/update remains available.
$subject=(new SubjectService($db))->create(['subject_code'=>'UNRELATED','subject_title'=>'Unrelated','units'=>3],$actor);
(new SubjectService($db))->update((int)$subject['id'],['subject_code'=>'UNRELATED','subject_title'=>'Edited Unrelated','units'=>4]);
protectionCheck(true,'unrelated subject creation/update allowed');
// Concurrent current-read locks: a second connection cannot change lifecycle while guarded.
$cfg=csms_database_config();$other=csms_database_connect($cfg);$other->query('SET SESSION innodb_lock_wait_timeout=1');
$db->begin_transaction();$policy->assertEditable($legacy['version']);
try{$other->query("UPDATE curriculum_versions SET review_status='under_review' WHERE id={$legacy['version']}");throw new RuntimeException('Concurrent lifecycle change bypassed lock');}
catch(mysqli_sql_exception $e){protectionCheck($e->getCode()===1205,'concurrent lifecycle change waits on lock');}
finally{$db->rollback();$other->close();}

// Current read after a concurrently committed lifecycle lock must reject the write.
function protectionConcurrentWorker(string $mode,int $id,?int $baseline=null): array {
    $args=[PHP_BINARY,__DIR__.'/version-protection-lock-worker.php',$mode,(string)$id];
    if($baseline!==null)$args[]=(string)$baseline;
    $proc=proc_open($args,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    fclose($pipes[0]);usleep(300000);
    protectionCheck(proc_get_status($proc)['running'],'concurrent service/validator waits on owner lock');
    return [$proc,$pipes];
}
function protectionWorkerResult(array $worker): void {
    [$proc,$pipes]=$worker;$out=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($proc);
    protectionCheck($exit===0 && $out==='CONFLICT' && $error==='','concurrent operation rejects newly committed protected state/chain');
}
$db->begin_transaction();$policy->assertEditable($legacy['version']);
$db->query("UPDATE curriculum_versions SET review_status='under_review' WHERE id={$legacy['version']}");
$before=protectionSnapshot($db);$worker=protectionConcurrentWorker('rename',$legacy['version']);
$db->commit();protectionWorkerResult($worker);
protectionCheck(protectionSnapshot($db)===$before,'blocked concurrent service leaves records intact');
$db->query("UPDATE curriculum_versions SET review_status='not_started' WHERE id={$legacy['version']}");
// Serialize two assignments that would otherwise form an indirect cycle.
$owner=$draft['version'];$baseline=$draft['baseline'];
$db->query("UPDATE curriculum_versions SET version_role='unclassified',review_status='not_started',baseline_version_id=NULL WHERE id=$owner");
$db->begin_transaction();$policy->validateBaseline($owner,$baseline);
$db->query("UPDATE curriculum_versions SET version_role='proposed',review_status='draft',baseline_version_id=$baseline WHERE id=$owner");
$before=protectionSnapshot($db);$worker=protectionConcurrentWorker('baseline',$baseline,$owner);
$db->commit();protectionWorkerResult($worker);
protectionCheck(protectionSnapshot($db)===$before,'concurrent cycle-producing baseline assignment rejected intact');
// A malformed existing baseline chain fails closed with FK checks still enabled.
$db->query("UPDATE curriculum_versions SET version_role='unclassified',review_status='draft' WHERE id=$baseline");
$db->begin_transaction();
try{$policy->validateBaseline($owner,$baseline);throw new RuntimeException('Malformed chain accepted');}
catch(DomainConflictException $e){protectionCheck(str_contains($e->getMessage(),'invalid lifecycle'),'malformed existing baseline chain rejected');}
finally{$db->rollback();}
$db->query("UPDATE curriculum_versions SET version_role='reference',review_status='not_started' WHERE id=$baseline");
$db->query("UPDATE curriculum_versions SET review_status='not_started' WHERE id=$owner");
(new CurriculumVersionService($db))->rename($owner,['version_label'=>'Proposed not started'],$actor);
protectionCheck(true,'proposed not_started editable');


$f=$fixtures['versions']['legacy'];$i=protectionInputs($f);
$mapping=new CurriculumVersionSubjectService($db);
$added=$mapping->assign($i['placement']+['curriculum_version_id'=>$f['version'],'subject_id'=>$f['subjects'][3]],$actor);
$mapping->remove((int)$added['id']);
$requirements=new CurriculumSubjectRequirementService($db);
$relationship=$requirements->create(['curriculum_version_id'=>$f['version'],'subject_id'=>$f['subjects'][2],'required_subject_id'=>$f['subjects'][0],'requirement_type'=>'corequisite'],$actor);
$requirements->remove((int)$relationship['id']);
$requirements->updateRule(['curriculum_version_id'=>$f['version'],'subject_id'=>$f['subjects'][2],'required_subject_ids'=>[],'expected_ids'=>[]],$actor);
$groups=new CurriculumElectiveGroupService($db);$members=new CurriculumElectiveGroupSubjectService($db);
$empty=$groups->create(array_replace($i['group'],['group_name'=>'Empty Legacy Group']),$actor);$groups->update((int)$empty['id'],array_replace($i['group'],['group_name'=>'Updated Legacy Group']));
$member=$members->add(['elective_group_id'=>$empty['id'],'subject_id'=>$f['subjects'][2]],$actor);
$members->remove((int)$member['id']);$groups->remove((int)$empty['id']);
$weighting=new GradeWeightingService($db);$scheme=$weighting->createScheme(['curriculum_version_subject_id'=>$f['placements'][1]],$actor);
$weighting->updateScheme((int)$scheme['id'],['description'=>'Legacy editable']);
$component=$weighting->createComponent(['scheme_id'=>$scheme['id'],'component_name'=>'Legacy','weight_percentage'=>40],$actor)['component'];
$weighting->updateComponent((int)$component['id'],['component_name'=>'Updated','weight_percentage'=>50]);
$weighting->removeComponent((int)$component['id']);$weighting->removeScheme((int)$scheme['id']);
(new SubjectService($db))->update($f['subjects'][0],$i['subject']);
(new AcademicProgramService($db))->update($f['program'],$i['program']);
(new CurriculumService($db))->update($f['curriculum'],$i['curriculum']);
(new ComplianceReferenceService($db))->update($f['reference'],$i['reference']);
(new ComplianceRuleService($db))->update($f['rule'],$i['rule']);
$rule=(new ComplianceRuleService($db))->create(array_replace($i['rule'],['rule_name'=>'Independent legacy scope']),$actor);
protectionCheck($rule!==[],'all legacy service mutations remain available');
$archive=new ArchiveCenterService($db);$archive->archive('subject',(int)$subject['id'],$actor);$archive->restore('subject',(int)$subject['id']);
protectionCheck(true,'unrelated archive and restore remain available');
$before=protectionSnapshot($db);
try{$weighting->saveConfiguration($f['placements'][0],$f['version'],['components'=>[['component_name'=>'Invalid','weight_percentage'=>50]]],$actor);throw new RuntimeException('Invalid grade total accepted');}
catch(DomainValidationException $e){protectionCheck(str_contains($e->getMessage(),'100%'),'grade total rule remains enforced');}
protectionCheck(protectionSnapshot($db)===$before,'invalid grade total records unchanged');

// Numerically identical DECIMAL input is not a material shared-master change.
$f=$fixtures['versions']['reference'];
$old=(new SubjectService($db))->get($f['subjects'][0]);
(new SubjectService($db))->update($f['subjects'][0],$old);
protectionCheck((new SubjectService($db))->get($f['subjects'][0])['subject_title']===$old['subject_title'],'protected subject identical material data allowed');
echo json_encode(['passed'=>true,'checks'=>$checks,'suite'=>'version protection domain']).PHP_EOL;
