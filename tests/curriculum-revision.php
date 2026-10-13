<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/curriculum-revision-fixture.php';
require_once __DIR__.'/../modules/curriculum/services/CurriculumVersionSubjectService.php';
require_once __DIR__.'/../modules/curriculum/services/CurriculumSubjectRequirementService.php';
require_once __DIR__.'/../modules/curriculum/services/ElectivesWorkspaceService.php';
require_once __DIR__.'/../modules/curriculum/services/GradeWeightingService.php';
$db=protectionDatabase();$base=protectionFixtures($db);$actor=$base['actor'];$checks=0;
function revisionCheck(bool $ok,string $label):void { global $checks;if (!$ok) throw new RuntimeException('FAIL: '.$label);$checks++;echo 'PASS: '.$label.PHP_EOL; }
function revisionReject(callable $fn,string $label): void {
    global $db;$before=protectionSnapshot($db);
    try {$fn();} catch (DomainValidationException|DomainConflictException|DomainNotFoundException $e) {
        revisionCheck(protectionSnapshot($db)===$before,$label.' leaves all rows and success audits unchanged');return;
    }
    throw new RuntimeException('FAIL: accepted '.$label);
}
$service=new CurriculumRevisionService($db);$f=revisionFixture($db,$actor,'FULL');
revisionCheck((int)$db->query("SELECT COUNT(*) FROM role_permissions rp JOIN permissions p ON p.id=rp.permission_id WHERE p.name='curriculum.version.clone'")->fetch_row()[0]===0,'migration registers clone capability without default grants');
// Reconstruct the upgrade after 022 on disposable data; migrations 023 and 024
// must preserve the populated pre-existing academic and reference records.
$upgradeDigest=revisionSourceDigest($db,$f);
$db->query('DROP TABLE curriculum_version_subject_outcomes');
$db->query('DROP TABLE curriculum_version_outcome_coverage');
$db->query('DROP TABLE curriculum_outcome_catalog');
$db->query("DELETE FROM role_permissions WHERE permission_id=(SELECT id FROM permissions WHERE name='curriculum.outcome_mapping.manage')");
$db->query("DELETE FROM permissions WHERE name='curriculum.outcome_mapping.manage'");
$db->query("DELETE FROM schema_migrations WHERE migration='023_curriculum_revision_clone_permission.sql'");
$db->query("DELETE FROM schema_migrations WHERE migration='024_curriculum_outcome_mapping.sql'");
$db->query("DELETE FROM permissions WHERE name='curriculum.version.clone'");
$proc=proc_open([PHP_BINARY,__DIR__.'/../database/deploy.php','--apply','--confirm-database='.getenv('DB_NAME')],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($proc);
revisionCheck($exit===0 && str_contains($out,'Applied 023_curriculum_revision_clone_permission.sql') && str_contains($out,'Applied 024_curriculum_outcome_mapping.sql') && revisionSourceDigest($db,$f)===$upgradeDigest,'022 through 024 upgrade preserves populated academic records and reference evidence');
$digest=revisionSourceDigest($db,$f);
$preview=$service->preview($f['version'],$f['curriculum']);
revisionCheck($preview['counts']===['requirements'=>4,'groups'=>1,'memberships'=>2,'schemes'=>1,'components'=>2,'outcomes'=>0,'placements'=>4,'snapshots'=>4],'preview counts all owned structures');
$result=$service->create(revisionInput($f,'Synthetic revision 1')+['version_role'=>'reference','review_status'=>'approved','status'=>'active','baseline_version_id'=>999],$actor);
$v=(int)$result['version']['id'];
revisionCheck($result['version']['version_role']==='proposed' && $result['version']['review_status']==='not_started' && $result['version']['status']==='draft' && (int)$result['version']['baseline_version_id']===$f['version'],'server owns exact baseline and neutral lifecycle despite forged input');
revisionCheck($v!==$f['version'] && $result['version']['effective_academic_year']===null,'new identity; missing year is not invented');
$new=$db->query("SELECT * FROM curriculum_version_subjects WHERE curriculum_version_id=$v ORDER BY subject_id")->fetch_all(MYSQLI_ASSOC);
foreach ($new as $i=>$p) {
    $old=$db->query("SELECT * FROM curriculum_version_subjects WHERE id={$f['placements'][$i]}")->fetch_assoc();
    revisionCheck((int)$p['id']!==(int)$old['id'] && array_intersect_key($p,array_flip(['subject_id','year_level','semester','sequence_order','is_required']))===array_intersect_key($old,array_flip(['subject_id','year_level','semester','sequence_order','is_required'])),'independent placement with exact fields '.$i);
    $snap=$db->query("SELECT * FROM curriculum_version_subject_snapshots WHERE curriculum_version_subject_id={$p['id']}")->fetch_assoc();
    revisionCheck($snap['capture_method']==='clone' && (int)$snap['snapshot_complete']===1 && (int)$snap['source_reference_id']===$f['sourceId'] && $snap['units_snapshot']==='3.25','exact creation snapshot '.$i);
}
$edges=$db->query("SELECT subject_id,required_subject_id,requirement_type FROM curriculum_subject_requirements WHERE curriculum_version_id=$v ORDER BY id")->fetch_all(MYSQLI_ASSOC);
revisionCheck($edges===$db->query("SELECT subject_id,required_subject_id,requirement_type FROM curriculum_subject_requirements WHERE curriculum_version_id={$f['version']} ORDER BY id")->fetch_all(MYSQLI_ASSOC),'all prerequisites and corequisites use new version and shared subjects');
$group=$db->query("SELECT * FROM curriculum_elective_groups WHERE curriculum_version_id=$v")->fetch_assoc();
revisionCheck((int)$group['id']!==$f['group'] && $group['required_selections']==='1' && $group['description']==='Preserved group description','independent group with metadata and selection rule');
revisionCheck((int)$db->query("SELECT COUNT(*) FROM curriculum_elective_group_subjects WHERE curriculum_version_id=$v AND elective_group_id={$group['id']}")->fetch_row()[0]===2,'memberships remap group and version');
$scheme=$db->query("SELECT * FROM grade_weighting_schemes WHERE curriculum_version_subject_id={$new[0]['id']}")->fetch_assoc();
revisionCheck((int)$scheme['id']!==$f['scheme'] && $scheme['description']==='Exact decimal test','scheme remaps new placement');
$components=$db->query("SELECT component_name,weight_percentage,sequence_order FROM grade_weighting_components WHERE scheme_id={$scheme['id']} ORDER BY id")->fetch_all(MYSQLI_ASSOC);
revisionCheck(array_column($components,'weight_percentage')===['33.33','66.67'] && array_column($components,'sequence_order')===['4','9'],'components preserve exact decimals and order');
foreach (['curriculum_version_references','curriculum_version_personnel','curriculum_version_review_events','compliance_validation_runs','cimf_ai_logs'] as $table) revisionCheck((int)$db->query("SELECT COUNT(*) FROM $table WHERE curriculum_version_id=$v")->fetch_row()[0]===0,'does not duplicate '.$table);
$workspace=(new CurriculumVersionReferenceService($db))->workspace($v);
revisionCheck($workspace['editable'] && $workspace['references_inherited_from_version_id']===$f['version'] && $workspace['references'][0]['current_assessments']['document_identity']['status']==='not_assessed','proposal inherits honest sample provenance without new verification');
revisionCheck(revisionSourceDigest($db,$f)===$digest,'source, evidence, snapshots and shared/global records unchanged');
$service->create(revisionInput($f,'Synthetic revision 2'),$actor);
revisionReject(fn()=>$service->create(revisionInput($f,'Synthetic revision 1'),$actor),'duplicate revision label');
revisionReject(fn()=>$service->create(revisionInput($f,'') ,$actor),'empty label');
revisionReject(fn()=>$service->create(array_replace(revisionInput($f,'Wrong context'),['curriculum_id'=>$base['versions']['legacy']['curriculum']]),$actor),'cross-curriculum source');
revisionReject(fn()=>$service->create(array_replace(revisionInput($f,'Missing'),['source_version_id'=>9999999]),$actor),'missing source');
revisionReject(fn()=>$service->create(array_replace(revisionInput($f,'No confirmation'),['confirm_reference_unchanged'=>0]),$actor),'missing confirmation');
foreach (['legacy','draft','returned','under_review','approved'] as $state) revisionReject(fn()=>$service->create(revisionInput($base['versions'][$state],'Reject '.$state),$actor),'non-reference '.$state);
// Failure injection uses only this guarded disposable fixture; never production hooks.
foreach (['audit','midcopy'] as $mode) {
    $trigger=$mode==='audit' ? "CREATE TRIGGER t4_fail BEFORE INSERT ON audit_logs FOR EACH ROW BEGIN IF NEW.action='CURRICULUM_REVISION_CREATE' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic audit failure'; END IF; END" : "CREATE TRIGGER t4_fail BEFORE INSERT ON grade_weighting_components FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic mid-copy failure'";
    $db->query($trigger);$before=protectionSnapshot($db);$thrown=false;
    try {$service->create(revisionInput($f,'Failure '.$mode),$actor);} catch (mysqli_sql_exception $e) {$thrown=true;}
    finally {$db->query('DROP TRIGGER t4_fail');}
    revisionCheck($thrown && protectionSnapshot($db)===$before,$mode.' failure rolls back version, all structures, snapshots and success audits');
}
// Corrupt/stale source cases are injected in disposable data, then restored exactly.
$mutations=[
    ['incomplete snapshot',"UPDATE curriculum_version_subject_snapshots SET snapshot_complete=0 WHERE curriculum_version_subject_id={$f['placements'][0]}","UPDATE curriculum_version_subject_snapshots SET snapshot_complete=1 WHERE curriculum_version_subject_id={$f['placements'][0]}"],
    ['snapshot/master conflict',"UPDATE subjects SET subject_title='Changed master' WHERE id={$f['subjects'][0]}","UPDATE subjects SET subject_title='Synthetic subject 0' WHERE id={$f['subjects'][0]}"],
    ['withdrawn source',"UPDATE compliance_references SET verification_status='inactive' WHERE id={$f['sourceId']}","UPDATE compliance_references SET verification_status='draft' WHERE id={$f['sourceId']}"],
    ['stale source metadata',"UPDATE compliance_references SET title='Changed document' WHERE id={$f['sourceId']}","UPDATE compliance_references SET title='Synthetic document title for revision tests' WHERE id={$f['sourceId']}"],
    ['invalid selection',"UPDATE curriculum_elective_groups SET required_selections=3 WHERE id={$f['group']}","UPDATE curriculum_elective_groups SET required_selections=1 WHERE id={$f['group']}"],
    ['missing group name',"UPDATE curriculum_elective_groups SET group_name='' WHERE id={$f['group']}","UPDATE curriculum_elective_groups SET group_name='Synthetic elective' WHERE id={$f['group']}"],
    ['missing component name',"UPDATE grade_weighting_components SET component_name='' WHERE scheme_id={$f['scheme']} AND component_name='First'","UPDATE grade_weighting_components SET component_name='First' WHERE scheme_id={$f['scheme']} AND component_name=''"],
    ['elective placement mismatch',"UPDATE curriculum_elective_groups SET semester='2nd Semester' WHERE id={$f['group']}","UPDATE curriculum_elective_groups SET semester='1st Semester' WHERE id={$f['group']}"],
    ['invalid aggregate weights',"UPDATE grade_weighting_components SET weight_percentage=70 WHERE scheme_id={$f['scheme']} AND component_name='Second'","UPDATE grade_weighting_components SET weight_percentage=66.67 WHERE scheme_id={$f['scheme']} AND component_name='Second'"],
    ['invalid prerequisite sequence',"UPDATE curriculum_version_subjects SET semester='2nd Semester' WHERE id={$f['placements'][0]}","UPDATE curriculum_version_subjects SET semester='1st Semester' WHERE id={$f['placements'][0]}"],
];
foreach ($mutations as [$label,$bad,$restore]) {$db->query($bad);try {revisionReject(fn()=>$service->create(revisionInput($f,'Invalid '.$label),$actor),$label);} finally {$db->query($restore);}}
$broken=revisionFixture($db,$actor,'BROKEN',false);
$db->query("DELETE FROM curriculum_version_subject_snapshots WHERE curriculum_version_subject_id={$broken['placements'][0]}");
revisionReject(fn()=>$service->create(revisionInput($broken,'Missing snapshot'),$actor),'missing reference snapshot');
$db->query("DELETE FROM curriculum_version_subjects WHERE id={$broken['placements'][0]}");
revisionReject(fn()=>$service->create(revisionInput($broken,'Empty reference'),$actor),'reference with no placements');
$noLocator=revisionFixture($db,$actor,'NO-LOCATOR',false);
$db->query("UPDATE compliance_reference_evidence_events SET evidence_locator=NULL,evidence_sha256=REPEAT('a',64) WHERE compliance_reference_id={$noLocator['sourceId']}");
revisionReject(fn()=>$service->create(revisionInput($noLocator,'Digest-only'),$actor),'fingerprint alone cannot replace documentary locator');
$stale=revisionFixture($db,$actor,'STALE-EVENT',false);
$db->query("UPDATE compliance_reference_evidence_events SET title_snapshot='Different historical identity' WHERE compliance_reference_id={$stale['sourceId']}");
revisionReject(fn()=>$service->create(revisionInput($stale,'Stale assessment'),$actor),'stale evidence event with unchanged attachment');
$corrupt=revisionFixture($db,$actor,'CYCLE');
$db->query("INSERT INTO curriculum_subject_requirements(curriculum_version_id,subject_id,required_subject_id,requirement_type) VALUES ({$corrupt['version']},{$corrupt['subjects'][0]},{$corrupt['subjects'][2]},'prerequisite')");
revisionReject(fn()=>$service->create(revisionInput($corrupt,'Cyclic reference'),$actor),'cyclic prerequisite graph');
$single=revisionFixture($db,$actor,'SINGLE-MEMBER');
$db->query("DELETE FROM curriculum_elective_group_subjects WHERE elective_group_id={$single['group']} AND subject_id={$single['subjects'][3]}");
revisionReject(fn()=>$service->create(revisionInput($single,'Invalid single-member elective'),$actor),'single-member elective rejected consistently with existing manager');
// Enabled database constraints independently reject broken composite ownership.
foreach (["INSERT INTO curriculum_subject_requirements(curriculum_version_id,subject_id,required_subject_id,requirement_type) VALUES ({$f['version']},999999,{$f['subjects'][0]},'prerequisite')",
    "INSERT INTO curriculum_elective_group_subjects(elective_group_id,curriculum_version_id,subject_id) VALUES ({$f['group']},{$broken['version']},{$broken['subjects'][0]})"] as $bad) {
    $before=protectionSnapshot($db);$rejected=false;try{$db->query($bad);}catch(mysqli_sql_exception $e){$rejected=true;}
    revisionCheck($rejected && protectionSnapshot($db)===$before,'enabled composite FK rejects corrupt or cross-version relationship');
}
$simple=revisionFixture($db,$actor,'SIMPLE',false);$simpleResult=$service->create(revisionInput($simple,'Basic revision'),$actor);
revisionCheck($simpleResult['counts']['placements']===1 && $simpleResult['counts']['requirements']===0,'basic reference without optional structures clones');
$db->query("UPDATE curriculum_versions SET status='archived' WHERE id={$simple['version']}");
revisionCheck($service->create(revisionInput($simple,'Archived reference revision'),$actor)['version']['status']==='draft','technical archive remains separate from immutable reference eligibility');
// Exercise existing editing services against the created proposal, then assert reference immutability.
$digest=revisionSourceDigest($db,$f);
(new CurriculumVersionSubjectService($db))->updatePlacement((int)$new[3]['id'],['year_level'=>'2nd Year','semester'=>'1st Semester','sequence_order'=>22,'is_required'=>0]);
(new CurriculumSubjectRequirementService($db))->updateRule(['curriculum_version_id'=>$v,'subject_id'=>$f['subjects'][2],
    'expected_ids'=>array_map('intval',array_column($db->query("SELECT id FROM curriculum_subject_requirements WHERE curriculum_version_id=$v AND subject_id={$f['subjects'][2]} AND requirement_type='prerequisite'")->fetch_all(MYSQLI_ASSOC),'id')),
    'required_subject_ids'=>[$f['subjects'][1]]],$actor);
(new ElectivesWorkspaceService($db))->save(['program_id'=>$f['program'],'curriculum_id'=>$f['curriculum'],'curriculum_version_id'=>$v,'group_name'=>'Revised elective','year_level'=>'2nd Year','semester'=>'1st Semester','required_selections'=>2,'subject_ids'=>[$f['subjects'][2],$f['subjects'][3]]],$actor,(int)$group['id']);
(new GradeWeightingService($db))->saveConfiguration((int)$new[0]['id'],$v,['components'=>[['component_name'=>'Revised','weight_percentage'=>'100']]],$actor);
revisionCheck(revisionSourceDigest($db,$f)===$digest,'existing mapping, prerequisite, elective and weighting services edit proposal while reference remains exact');
$audit=$db->query("SELECT metadata_json FROM audit_logs WHERE action='CURRICULUM_REVISION_CREATE' AND entity_id=$v")->fetch_row();
revisionCheck((int)json_decode($audit[0],true)['source_version_id']===$f['version'],'required audit names exact baseline');
revisionCheck((int)$db->query("SELECT COUNT(*) FROM audit_logs WHERE action='CURRICULUM_REVISION_PLACEMENT_COPY' AND JSON_EXTRACT(metadata_json,'$.curriculum_version_id')=$v")->fetch_row()[0]===4,'every copied placement records immutable source lineage');
echo json_encode(['passed'=>true,'checks'=>$checks,'suite'=>'Task 4 revision domain','copy_counts'=>$result['counts']],JSON_THROW_ON_ERROR).PHP_EOL;
