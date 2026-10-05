<?php
require_once __DIR__ . '/../shared/bootstrap.php';
require_permission('dashboard.view', true);
require_permission('curriculum.view', true);
header('Content-Type: application/json; charset=utf-8');
$id = filter_input(INPUT_GET, 'version_id', FILTER_VALIDATE_INT);
if (!$id || $id < 1) { http_response_code(400); echo json_encode(['success'=>false,'message'=>'Select a curriculum version.']); exit; }
$db = authorization_connection();
$version = $db->prepare('SELECT v.id,v.version_label,v.status,c.id curriculum_id,c.curriculum_code,c.curriculum_name,p.id program_id,p.program_code,p.program_name FROM curriculum_versions v JOIN curricula c ON c.id=v.curriculum_id JOIN academic_programs p ON p.id=c.academic_program_id WHERE v.id=?');
$version->bind_param('i',$id); $version->execute(); $context=$version->get_result()->fetch_assoc(); $version->close();
if (!$context) { http_response_code(404); echo json_encode(['success'=>false,'message'=>'Curriculum version not found.']); exit; }
$q=$db->prepare('SELECT m.id,m.subject_id,m.year_level,m.semester,m.is_required,s.units FROM curriculum_version_subjects m JOIN subjects s ON s.id=m.subject_id WHERE m.curriculum_version_id=? ORDER BY m.year_level,m.semester,m.sequence_order,m.id');
$q->bind_param('i',$id);$q->execute();$rows=$q->get_result()->fetch_all(MYSQLI_ASSOC);$q->close();
$years=[];$terms=[];$units=0;$required=0;
foreach($rows as $r){$y=(string)($r['year_level']??'Unspecified');$t=(string)($r['semester']??'Unspecified');$years[$y]=($years[$y]??['subjects'=>0,'units'=>0]);$years[$y]['subjects']++;$u=(float)$r['units'];$years[$y]['units']+=$u;$units+=$u;$terms[$t]=($terms[$t]??0)+1;$required+=(int)$r['is_required'];}
$req=$db->prepare('SELECT COUNT(*) total,COUNT(DISTINCT subject_id) subjects FROM curriculum_subject_requirements WHERE curriculum_version_id=?');$req->bind_param('i',$id);$req->execute();$requirements=$req->get_result()->fetch_assoc();$req->close();
$pre=$db->prepare("SELECT COUNT(DISTINCT subject_id) subjects FROM curriculum_subject_requirements WHERE curriculum_version_id=? AND requirement_type='prerequisite'");$pre->bind_param('i',$id);$pre->execute();$prerequisiteSubjects=(int)$pre->get_result()->fetch_assoc()['subjects'];$pre->close();
$eg=$db->prepare('SELECT COUNT(*) total FROM curriculum_elective_groups WHERE curriculum_version_id=?');$eg->bind_param('i',$id);$eg->execute();$electives=(int)$eg->get_result()->fetch_assoc()['total'];$eg->close();
$gw=$db->prepare('SELECT COUNT(DISTINCT g.id) configured, SUM(CASE WHEN (SELECT COALESCE(SUM(c.weight_percentage),0) FROM grade_weighting_components c WHERE c.scheme_id=g.id)=100 THEN 1 ELSE 0 END) complete FROM curriculum_version_subjects m LEFT JOIN grade_weighting_schemes g ON g.curriculum_version_subject_id=m.id WHERE m.curriculum_version_id=?');$gw->bind_param('i',$id);$gw->execute();$weight=$gw->get_result()->fetch_assoc();$gw->close();
$count=count($rows);$conf=(int)$weight['configured'];$complete=(int)$weight['complete'];$status=$count===0?'Not Configured':($complete===$count?'Configured':($conf===0?'Not Configured':($conf<$count?'Partially Configured':'Incomplete')));
echo json_encode(['success'=>true,'context'=>$context,'metrics'=>['mapped_subjects'=>$count,'units'=>$units,'year_levels'=>count($years),'requirements'=>(int)$requirements['total'],'subjects_with_requirements'=>(int)$requirements['subjects'],'subjects_with_prerequisites'=>$prerequisiteSubjects,'elective_groups'=>$electives,'grade_status'=>$status,'weight_summary'=>"$complete Complete  /  ".max(0,$count-$complete).' Not fully configured'],'years'=>$years,'terms'=>$terms,'required'=>$required,'optional'=>$count-$required,'total'=>$count],JSON_UNESCAPED_UNICODE);
