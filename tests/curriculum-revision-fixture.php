<?php
// CLI-only synthetic fixtures. The caller must first verify its disposable schema.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/version-protection-fixture.php';
require_once __DIR__.'/../modules/curriculum/services/CurriculumRevisionService.php';
require_once __DIR__.'/../modules/curriculum/services/ComplianceReferenceService.php';

function revisionFixture(mysqli $db,int $actor,string $suffix,bool $full=true,string $programCode=''): array
{
    $programCode=$programCode!==''?$programCode:'T4-'.$suffix;
    $program=protectionInsert($db,"INSERT INTO academic_programs(program_code,program_name) VALUES ('".$db->real_escape_string($programCode)."','Synthetic revision test program')");
    $curriculum=protectionInsert($db,"INSERT INTO curricula(curriculum_code,curriculum_name,academic_program_id) VALUES ('T4-$suffix','Synthetic revision test curriculum',$program)");
    $version=protectionInsert($db,"INSERT INTO curriculum_versions(curriculum_id,version_label) VALUES ($curriculum,'Synthetic reference $suffix')");
    $subjects=[];$placements=[];
    foreach ([['1st Year','1st Semester'],['1st Year','2nd Semester'],['2nd Year','1st Semester'],['2nd Year','1st Semester']] as $i=>[$year,$term]) {
        if (!$full && $i>0) break;
        $subject=protectionInsert($db,"INSERT INTO subjects(subject_code,subject_title,units,lecture_units,laboratory_units,description) VALUES ('T4-$suffix-$i','Synthetic subject $i',3.25,2.25,1.00,'Synthetic historical description')");
        $subjects[]=$subject;$required=$i<2?1:0;$sequence=$i+4;
        $placements[]=protectionInsert($db,"INSERT INTO curriculum_version_subjects(curriculum_version_id,subject_id,year_level,semester,sequence_order,is_required) VALUES ($version,$subject,'$year','$term',$sequence,$required)");
    }
    $sourceService=new ComplianceReferenceService($db);
    $source=$sourceService->create(['reference_type'=>'CHED','source_kind'=>'ched_sample_curriculum','reference_code'=>'SYNTHETIC-'.$suffix,
        'title'=>'Synthetic document title for revision tests','issuing_body'=>'Synthetic test issuer',
        'source_url'=>'','verification_status'=>'draft','notes'=>'Synthetic sample fixture; not an official document.'],$actor);
    $sourceId=(int)$source['id'];
    $sourceService->recordEvidence($sourceId,['verification_subject'=>'document_identity','result'=>'not_assessed',
        'evidence_locator'=>'fixture://revision/'.$suffix,'rationale'=>'Synthetic document location; authenticity not assessed.'],$actor);
    $referenceService=new CurriculumVersionReferenceService($db);
    $referenceService->attach(['curriculum_version_id'=>$version,'compliance_reference_id'=>$sourceId,'reference_purpose'=>'school_curriculum'],$actor);
    $group=$scheme=null;
    if ($full) {
        foreach ([[1,0,'prerequisite'],[2,0,'prerequisite'],[2,1,'prerequisite'],[3,2,'corequisite']] as [$a,$b,$type]) {
            $db->query("INSERT INTO curriculum_subject_requirements(curriculum_version_id,subject_id,required_subject_id,requirement_type) VALUES ($version,{$subjects[$a]},{$subjects[$b]},'$type')");
        }
        $group=protectionInsert($db,"INSERT INTO curriculum_elective_groups(curriculum_version_id,group_name,description,year_level,semester,required_selections,status) VALUES ($version,'Synthetic elective','Preserved group description','2nd Year','1st Semester',1,'active')");
        foreach ([$subjects[2],$subjects[3]] as $subject) $db->query("INSERT INTO curriculum_elective_group_subjects(elective_group_id,curriculum_version_id,subject_id) VALUES ($group,$version,$subject)");
        $scheme=protectionInsert($db,"INSERT INTO grade_weighting_schemes(curriculum_version_subject_id,description) VALUES ({$placements[0]},'Exact decimal test')");
        $db->query("INSERT INTO grade_weighting_components(scheme_id,component_name,weight_percentage,sequence_order) VALUES ($scheme,'First',33.33,4),($scheme,'Second',66.67,9)");
    }
    $referenceService->classifyReference($version,$sourceId,'ched_sample',true,$actor);
    return compact('program','curriculum','version','subjects','placements','sourceId','group','scheme');
}
function revisionInput(array $f,string $label): array
{
    return ['source_version_id'=>$f['version'],'curriculum_id'=>$f['curriculum'],'version_label'=>$label,
        'effective_academic_year'=>'','description'=>'Synthetic revision','confirm_reference_unchanged'=>1];
}
function revisionSourceDigest(mysqli $db,array $f): string
{
    $v=$f['version'];$source=$f['sourceId'];$ids=implode(',',$f['subjects']);
    $queries=["SELECT * FROM academic_programs","SELECT * FROM curricula","SELECT * FROM subjects",
        "SELECT * FROM program_subject_assignments","SELECT * FROM compliance_rules","SELECT * FROM subject_equivalencies",
        "SELECT * FROM curriculum_versions WHERE id=$v","SELECT * FROM curriculum_version_subjects WHERE curriculum_version_id=$v",
        "SELECT * FROM curriculum_subject_requirements WHERE curriculum_version_id=$v","SELECT * FROM curriculum_elective_groups WHERE curriculum_version_id=$v",
        "SELECT * FROM curriculum_elective_group_subjects WHERE curriculum_version_id=$v",
        "SELECT s.* FROM grade_weighting_schemes s JOIN curriculum_version_subjects p ON p.id=s.curriculum_version_subject_id WHERE p.curriculum_version_id=$v",
        "SELECT c.* FROM grade_weighting_components c JOIN grade_weighting_schemes s ON s.id=c.scheme_id JOIN curriculum_version_subjects p ON p.id=s.curriculum_version_subject_id WHERE p.curriculum_version_id=$v",
        "SELECT s.* FROM curriculum_version_subject_snapshots s JOIN curriculum_version_subjects p ON p.id=s.curriculum_version_subject_id WHERE p.curriculum_version_id=$v",
        "SELECT * FROM curriculum_version_references WHERE curriculum_version_id=$v",
        "SELECT * FROM compliance_references WHERE id=$source","SELECT * FROM compliance_reference_evidence_events WHERE compliance_reference_id=$source"];
    $rows=[];foreach ($queries as $sql) $rows[]=$db->query($sql)->fetch_all(MYSQLI_ASSOC);
    return hash('sha256',json_encode($rows,JSON_THROW_ON_ERROR));
}
