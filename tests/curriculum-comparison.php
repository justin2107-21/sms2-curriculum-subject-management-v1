<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/curriculum-revision-fixture.php';
require_once __DIR__.'/../modules/curriculum/services/SubjectOfferingHistoryService.php';
$db=protectionDatabase();$actor=protectionFixtures($db)['actor'];$f=revisionFixture($db,$actor,'COMPARE');
$revision=(new CurriculumRevisionService($db))->create(revisionInput($f,'Synthetic working revision'),$actor)['version'];
$v=(int)$revision['id'];$service=new SubjectOfferingHistoryService($db);$checks=0;
function comparisonCheck(bool $ok,string $label):void {global $checks;if(!$ok)throw new RuntimeException('FAIL: '.$label);$checks++;echo 'PASS: '.$label.PHP_EOL;}
$input=['curriculum_id'=>$f['curriculum'],'version_b_id'=>$v,'per_page'=>50];
$read=function()use($db,$service,$input):array {
    $before=protectionSnapshot($db);$r=$service->compareRevision($input);
    comparisonCheck(protectionSnapshot($db)===$before,'comparison changes no rows, evidence, snapshots or audits');return $r;
};
$r=$read();comparisonCheck($r['summary']['unchanged']===4 && !$r['partial'],'identical clone with independent placement IDs');
comparisonCheck((int)$r['version_a']['id']===$f['version'],'baseline defaults from revision');
foreach (['requirements','electives','grade_weighting'] as $key) comparisonCheck($r[$key]['changes']===[] && $r[$key]['complete'],'identical '.$key.' ignores copied row IDs');
comparisonCheck($r['references'][0]['source_kind_snapshot']==='ched_sample_curriculum' && $r['references'][0]['current_assessments']['document_identity']['status']==='not_assessed' && $r['institutional_adoption_label']==='Institutional adoption/approval not verified','demonstration identity, authenticity and adoption stay separate');
foreach ([['version_a_id'=>9999999],['version_a_id'=>$v],['version_b_id'=>$f['version']],['curriculum_id'=>9999999]] as $change) {
    $before=protectionSnapshot($db);$failed=false;
    try {$service->compareRevision(array_replace($input,$change));}catch(DomainValidationException|DomainNotFoundException $e){$failed=true;}
    comparisonCheck($failed && protectionSnapshot($db)===$before,'invalid pair rejected without writes '.json_encode($change));
}
$other=revisionFixture($db,$actor,'OTHER',false);
foreach ([['version_a_id'=>$other['version']],['curriculum_id'=>$other['curriculum']],['version_b_id'=>$other['version']]] as $change) {
    $failed=false;try{$service->compareRevision(array_replace($input,$change));}catch(DomainValidationException $e){$failed=true;}
    comparisonCheck($failed,'cross-curriculum/role forgery rejected');
}
$alt=protectionInsert($db,"INSERT INTO curriculum_versions(curriculum_id,version_label,version_role) VALUES ({$f['curriculum']},'Other reference','reference')");
$failed=false;try{$service->compareRevision($input+['version_a_id'=>$alt]);}catch(DomainValidationException $e){$failed=true;}
comparisonCheck($failed,'same-curriculum wrong baseline rejected');
$p=$db->query("SELECT id FROM curriculum_version_subjects WHERE curriculum_version_id=$v AND subject_id={$f['subjects'][0]}")->fetch_row()[0];
$db->query("UPDATE curriculum_version_subjects SET year_level='3rd Year',semester='2nd Semester',sequence_order=88,is_required=0 WHERE id=$p");
$r=$read();comparisonCheck($r['summary']['moved']===1 && $r['summary']['changed']===1 && $r['summary']['unique_changed_subjects']===1,'multiple categories count one unique changed subject');
$row=array_column($r['comparison'],null,'subject_id')[$f['subjects'][0]];
comparisonCheck(count($row['differences'])===4 && (int)$row['before']['sequence_order']===4 && (int)$row['after']['sequence_order']===88,'year, term, ordering and required flag before/after');
// Synthetic direct SQL simulates shared-master drift forbidden through protected application mutation paths.
$db->query("UPDATE subjects SET subject_code='CHANGED-SYNTHETIC',subject_title='Changed synthetic title',units=4.25,lecture_units=3.25,laboratory_units=1.25,description='Changed description' WHERE id={$f['subjects'][0]}");
$r=$read();$row=array_column($r['comparison'],null,'subject_id')[$f['subjects'][0]];
comparisonCheck(count($row['academic_differences'])===6 && $row['before']['units']==='3.25' && $row['after']['units']==='4.25','all six captured subject attributes compared against present-day master');
comparisonCheck(in_array('unresolved',$row['categories'],true) && $r['summary']['unchanged']===3 && $r['partial'] && str_contains($row['warning'],'revision-specific'),'shared drift not falsely attributed to intentional revision or unchanged');
comparisonCheck($row['revision_clone_snapshot']['notice']!=='' && $row['after']['provenance']!==$row['before']['provenance'],'clone-time evidence distinguished from working state');
$db->query("DELETE FROM curriculum_version_subject_snapshots WHERE curriculum_version_subject_id={$f['placements'][1]}");
$db->query("UPDATE curriculum_version_subject_snapshots SET snapshot_complete=0 WHERE curriculum_version_subject_id={$f['placements'][2]}");
$r=$read();$rows=array_column($r['comparison'],null,'subject_id');
foreach ([1,2] as $i) comparisonCheck($rows[$f['subjects'][$i]]['warning']==='Historical subject details unavailable' && $rows[$f['subjects'][$i]]['before']['subject_code']===null,'missing/incomplete historical details never replaced by master');
comparisonCheck($r['summary']['unresolved']===3 && $r['summary']['unchanged']===1,'incomplete history cannot produce false zero-change/unchanged totals');
// Requirement type change plus independent addition/removal.
$db->query("UPDATE curriculum_subject_requirements SET requirement_type='corequisite' WHERE curriculum_version_id=$v AND subject_id={$f['subjects'][1]}");
$db->query("DELETE FROM curriculum_subject_requirements WHERE curriculum_version_id=$v AND subject_id={$f['subjects'][2]} AND required_subject_id={$f['subjects'][0]}");
$db->query("INSERT INTO curriculum_subject_requirements(curriculum_version_id,subject_id,required_subject_id,requirement_type) VALUES ($v,{$f['subjects'][3]},{$f['subjects'][0]},'prerequisite')");
$r=$read();$types=array_column($r['requirements']['changes'],'change');sort($types);
comparisonCheck($types===['added','removed','type_changed'],'requirement additions, removals and type changes by shared subject pair');
$group=$db->query("SELECT id FROM curriculum_elective_groups WHERE curriculum_version_id=$v")->fetch_row()[0];
$db->query("UPDATE curriculum_elective_groups SET group_name='Renamed synthetic elective',required_selections=2 WHERE id=$group");
$db->query("DELETE FROM curriculum_elective_group_subjects WHERE elective_group_id=$group AND subject_id={$f['subjects'][3]}");
$r=$read();comparisonCheck(count($r['electives']['changes'])===1 && $r['electives']['changes'][0]['matching']==='Recorded clone lineage' && (int)$r['electives']['changes'][0]['after']['required_selections']===2,'elective rename/selection/membership changes follow recorded lineage');
comparisonCheck(count($r['electives']['changes'][0]['before']['members'])===2 && count($r['electives']['changes'][0]['after']['members'])===1,'elective membership before/after uses shared identities');
$scheme=$db->query("SELECT s.id FROM grade_weighting_schemes s JOIN curriculum_version_subjects p ON p.id=s.curriculum_version_subject_id WHERE p.curriculum_version_id=$v")->fetch_row()[0];
$db->query("UPDATE grade_weighting_components SET weight_percentage=40.00,sequence_order=10 WHERE scheme_id=$scheme AND component_name='First'");
$db->query("DELETE FROM grade_weighting_components WHERE scheme_id=$scheme AND component_name='Second'");
$db->query("INSERT INTO grade_weighting_components(scheme_id,component_name,weight_percentage) VALUES ($scheme,'New component',60.00)");
$r=$read();$weight=$r['grade_weighting']['changes'][0];
comparisonCheck($weight['before']['components']['First']['weight_percentage']==='33.33' && $weight['after']['components']['First']['weight_percentage']==='40.00' && isset($weight['after']['components']['New component']) && !isset($weight['after']['components']['Second']),'weight, order, added/removed named components compared exactly');
$newOwner=$db->query("SELECT id FROM curriculum_version_subjects WHERE curriculum_version_id=$v AND subject_id={$f['subjects'][1]}")->fetch_row()[0];
$db->query("UPDATE grade_weighting_schemes SET curriculum_version_subject_id=$newOwner WHERE id=$scheme");
$r=$read();$schemeChanges=array_column($r['grade_weighting']['changes'],'change');sort($schemeChanges);
comparisonCheck($schemeChanges===['added','removed'],'changed applicable subject association shown as removed/added schemes, never compared by independent scheme ID');
$db->query("DELETE FROM curriculum_elective_group_subjects WHERE elective_group_id=$group");
$db->query("DELETE FROM curriculum_elective_groups WHERE id=$group");
$db->query("INSERT INTO curriculum_elective_groups(curriculum_version_id,group_name,required_selections) VALUES ($v,'New synthetic group',1)");
$r=$read();$groupChanges=array_column($r['electives']['changes'],'change');sort($groupChanges);
comparisonCheck($groupChanges===['added','removed'],'removed cloned group and new independent group remain distinct');
// Remove a placement after clearing its dependent structures, add unrelated subject with a similar title.
$removed=$f['subjects'][3];$db->query("DELETE FROM curriculum_subject_requirements WHERE curriculum_version_id=$v AND (subject_id=$removed OR required_subject_id=$removed)");
$db->query("DELETE FROM curriculum_version_subject_snapshots WHERE curriculum_version_subject_id IN (SELECT id FROM curriculum_version_subjects WHERE curriculum_version_id=$v AND subject_id=$removed)");
$db->query("DELETE FROM curriculum_version_subjects WHERE curriculum_version_id=$v AND subject_id=$removed");
$added=protectionInsert($db,"INSERT INTO subjects(subject_code,subject_title,units) VALUES ('NEW-SYNTHETIC','Synthetic subject 3',3.25)");
$db->query("INSERT INTO curriculum_version_subjects(curriculum_version_id,subject_id,year_level,semester,is_required) VALUES ($v,$added,'1st Year','1st Semester',1)");
$r=$read();comparisonCheck($r['summary']['added']===1 && $r['summary']['removed']===1,'similar titles never imply subject equivalency');
$db->query("UPDATE curriculum_versions SET status='archived',review_status='approved' WHERE id=$v");
comparisonCheck($read()['version_b']['status']==='archived','existing history visibility includes archived versions without adoption inference');
// Duplicate identities cannot exist under normal unique constraints; exercise defensive unresolved calculation only.
$repo=new SubjectOfferingHistoryRepository($db);$a=$repo->comparisonState($f['version']);$b=$repo->comparisonState($v);
$b['placements'][]=$b['placements'][0];$pure=CurriculumHistoryComparison::calculate($a,$b,[$f['sourceId']],$f['version']);
comparisonCheck($pure['comparison'][0]['categories']===['unresolved'],'ambiguous duplicate placement is unresolved, never guessed');
$a['clone_events']=[];$b['clone_events']=[];$pure=CurriculumHistoryComparison::calculate($a,$b,[$f['sourceId']],$f['version']);
comparisonCheck(!$pure['electives']['complete'],'without lineage group rename equivalence is explicitly unresolved');
// Exercise supported pagination sizes with enough synthetic records to span pages.
for($i=0;$i<12;$i++) {
    $s=protectionInsert($db,"INSERT INTO subjects(subject_code,subject_title,units) VALUES ('PAGE-SYNTHETIC-$i','Synthetic pagination subject',1)");
    $db->query("INSERT INTO curriculum_version_subjects(curriculum_version_id,subject_id) VALUES ($v,$s)");
}
$small=$service->compareRevision(array_replace($input,['per_page'=>10,'page'=>2]));
comparisonCheck(count($small['comparison'])===7 && $small['summary']['total_subjects']===17 && count($small['subject_labels'])===17,'pagination preserves global summaries and relationship labels');
echo json_encode(['passed'=>true,'checks'=>$checks,'suite'=>'Task 5 curriculum comparison'],JSON_THROW_ON_ERROR).PHP_EOL;
