<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../modules/curriculum/services/PrerequisiteGraph.php';
$checks=0;
function sequenceCheck($ok, $label) { global $checks; if (!$ok) throw new RuntimeException($label); $checks++; }
$p=fn($id,$year,$term='1st Semester')=>['subject_id'=>$id,'subject_code'=>'SYN'.$id,'year_level'=>$year,'semester'=>$term];
$e=fn($r,$t)=>['required_subject_id'=>$r,'subject_id'=>$t];
$validate=fn($placements,$edges,$subject=null)=>AcademicSequenceValidator::validate($placements,$edges,$subject);
sequenceCheck($validate([$p(1,'3rd Year'),$p(2,'4th Year')],[$e(1,2)])['valid'],'Earlier valid');
sequenceCheck(!$validate([$p(1,'4th Year'),$p(2,'4th Year')],[$e(1,2)])['valid'],'Same period blocked');
sequenceCheck(!$validate([$p(1,'4th Year','2nd Semester'),$p(2,'4th Year')],[$e(1,2)])['valid'],'Later blocked');
$chain=[$e(1,3),$e(2,3),$e(3,4)];
$placements=[$p(1,'3rd Year'),$p(2,'3rd Year','2nd Semester'),$p(3,'4th Year'),$p(4,'4th Year','2nd Semester')];
sequenceCheck($validate($placements,$chain,3)['valid'],'Ordered chain');
$placements[2]['semester']='2nd Semester';
$result=$validate($placements,$chain,3);
sequenceCheck(!$result['valid'] && count($result['relationships'])===3 && count($result['conflicts'])===1,'Middle subject outgoing edge blocks chain');
sequenceCheck($validate([$p(9,'Unordered')],$chain,9)['valid'],'No relationships need no invented ordering');
sequenceCheck($validate([$p(1,'Grade 11','Semester 1'),$p(2,'Grade 12','Semester 1')],[$e(1,2)])['valid'],'SHS earlier');
sequenceCheck(!$validate([$p(1,'Grade 12'),$p(2,'Grade 11')],[$e(1,2)])['valid'],'SHS later blocked');
foreach (['Summer','Trimester 1','3rd Semester','Unknown'] as $term) {
 $result=$validate([$p(1,'1st Year',$term),$p(2,'2nd Year')],[$e(1,2)]);
 sequenceCheck(!$result['valid'] && $result['conflicts'][0]['type']==='unresolved_sequence','Unknown term cannot be ordered even across years');
}
sequenceCheck(AcademicSequenceValidator::position($p(1,'5th Year','2nd Semester'))===10,'Flexible college year');
sequenceCheck(AcademicSequenceValidator::position($p(1,'Year 6','Semester 1'))===11,'Prefix year and semester labels');
sequenceCheck(AcademicSequenceValidator::position($p(1,'Grade 13'))===null,'Unknown SHS grade');
sequenceCheck(!$validate([$p(1,'1st Year')],[$e(1,1)])['valid'],'Self prerequisite');
$review=PrerequisiteGraph::review([$p(1,'1st Year'),$p(2,'1st Year'),$p(3,'Custom')],[$e(1,2),$e(2,1),$e(1,2),$e(3,1),$e(4,1)]);
sequenceCheck(count($review['sequencing'])===4 && count($review['missing'])===1 && count($review['duplicates'])===1 && count($review['cycles'])===1,'Legacy review categories include equal and unresolved periods');
echo "PASS: $checks shared academic sequence checks.\n";
