<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/../modules/curriculum/repositories/RepositoryQuery.php';
foreach (['AcademicProgramService','CurriculumService','CurriculumVersionService','SubjectService','CurriculumVersionSubjectService','CurriculumOutcomeMappingService','CurriculumCloAssessmentService','CurriculumRevisionService','ComplianceReferenceService'] as $service) require_once __DIR__.'/../modules/curriculum/services/'.$service.'.php';

/** Synthetic data only. The caller must first verify the disposable server/datadir. */
function task10_preview_fixture(mysqli $db, string $password, string $viewerPassword): array
{
    $name=(string)$db->query('SELECT DATABASE()')->fetch_row()[0];
    if (!preg_match('/^csms_docker_test_task(?:2|7)_run_[0-9]+_[a-f0-9]+$/D',$name)
        || getenv('SMS_TEST_CONFIRM_DATABASE')!==$name || getenv('DB_HOST')!=='127.0.0.1'
        || (int)getenv('DB_PORT')===3306 || getenv('APP_ENV')!=='development') throw new RuntimeException('Unsafe preview fixture target.');
    if ((int)$db->query('SELECT COUNT(*) FROM users')->fetch_row()[0]!==0 || (int)$db->query('SELECT COUNT(*) FROM academic_programs')->fetch_row()[0]!==0) throw new RuntimeException('Preview fixture requires empty academic tables.');
    $read=['dashboard.view','account.view','program.view','curriculum.view','subject.view','prerequisite.view','elective.view','program_assignment.view','subject_history.view','equivalency.view','grade_weighting.view','compliance.view','curriculum_export.view'];
    $manage=['program.create','program.update','curriculum.create','curriculum.update','curriculum.version.create','subject.create','subject.update','prerequisite.manage','elective.manage','program_assignment.manage','grade_weighting.manage','compliance.run','compliance.manage','equivalency.manage','curriculum.reference.attach','compliance.reference.verify','curriculum.reference.classify','curriculum.version.clone','curriculum.outcome_mapping.manage','curriculum.clo.manage','curriculum.assessment_plan.manage'];
    $actors=[];
    foreach ([['task10_preview',$password,array_merge($read,$manage)],['task10_viewer',$viewerPassword,$read]] as [$username,$secret,$permissions]) {
        $stmt=RepositoryQuery::execute($db,"INSERT INTO users(username,email,first_name,last_name,password_hash) VALUES (?,?,'Synthetic','Preview',?)",'sss',[$username,$username.'@example.invalid',password_hash($secret,PASSWORD_DEFAULT)]);$stmt->close();$id=(int)$db->insert_id;
        $stmt=RepositoryQuery::execute($db,'INSERT INTO roles(name,display_name,description) VALUES (?,?,?)','sss',[$username,$username.' (synthetic only)','Disposable preview capabilities; not institutional policy.']);$stmt->close();$role=(int)$db->insert_id;
        foreach ($permissions as $permission) { $stmt=RepositoryQuery::execute($db,'INSERT INTO role_permissions(role_id,permission_id) SELECT ?,id FROM permissions WHERE name=?','is',[$role,$permission]);if($stmt->affected_rows!==1)throw new RuntimeException('Preview permission unavailable.');$stmt->close(); }
        $stmt=RepositoryQuery::execute($db,'INSERT INTO user_roles(user_id,role_id) VALUES (?,?)','ii',[$id,$role]);$stmt->close();$actors[$username]=$id;
        audit_log_required('RBAC_PERMISSION_CHANGE','role',$role,'Explicit synthetic preview role; no existing role grants changed.',['fixture'=>'task10','permissions'=>$permissions]);
    }
    $actor=$actors['task10_preview'];
    $program=(new AcademicProgramService($db))->create(['program_code'=>'BSIT','program_name'=>'SYNTHETIC BSIT Preview - not BCP academic data','program_type'=>"Bachelor's Degree"],$actor);
    $curriculum=(new CurriculumService($db))->create(['academic_program_id'=>$program['id'],'curriculum_code'=>'SYN-T9-BSIT','curriculum_name'=>'SYNTHETIC Curriculum for Runtime Verification'],$actor);
    $version=(new CurriculumVersionService($db))->create(['curriculum_id'=>$curriculum['id'],'version_label'=>'SYNTHETIC Reference Fixture'],$actor);
    $placements=[];
    foreach (['Synthetic computing subject','Synthetic project subject'] as $i=>$title) {
        $subject=(new SubjectService($db))->create(['subject_code'=>'SYN-T9-'.($i+1),'subject_title'=>$title,'units'=>3,'lecture_units'=>3,'laboratory_units'=>0,'description'=>'Synthetic testing content; not transcribed from CMO curriculum tables.'],$actor);
        $placements[]=(new CurriculumVersionSubjectService($db))->assign(['curriculum_version_id'=>$version['id'],'subject_id'=>$subject['id'],'year_level'=>'1st Year','semester'=>'1st Semester','sequence_order'=>$i+1,'is_required'=>1],$actor)['id'];
    }
    $outcome=(int)$db->query("SELECT id FROM curriculum_outcome_catalog WHERE source_key='CHED-CMO-25-2015-BSIT' AND outcome_code='IT01'")->fetch_row()[0];
    if($outcome<1)throw new RuntimeException('Verified outcome catalog is required.');
    $mapping=(new CurriculumOutcomeMappingService($db))->add((int)$version['id'],(int)$placements[0],$outcome,'SYNTHETIC manual mapping decision; no attainment is asserted.',$actor);
    $clos=new CurriculumCloAssessmentService($db);$clo=$clos->createClo((int)$placements[0],'SYN-CLO1','SYNTHETIC user-authored CLO for testing only.',1,$actor);
    $clos->align((int)$placements[0],$clo,$mapping,'SYNTHETIC manual alignment rationale.',$actor);
    $clos->createPlan((int)$placements[0],$clo,['assessment_method'=>'Synthetic demonstration method','planned_task'=>'Synthetic planned task','performance_indicator'=>'Synthetic criterion; no measured attainment.'],$actor);
    $sources=new ComplianceReferenceService($db);
    $source=$sources->create(['reference_type'=>'Other','source_kind'=>'other_document','reference_code'=>'SYN-T9-SOURCE','title'=>'SYNTHETIC source fixture - no official curriculum claim','issuing_body'=>'Synthetic test fixture','verification_status'=>'draft','notes'=>'Demonstration only; institutional adoption/approval not verified.'],$actor);
    $sources->recordEvidence((int)$source['id'],['verification_subject'=>'document_identity','result'=>'not_assessed','evidence_locator'=>'fixture://task10/synthetic-source','rationale'=>'Synthetic locator; authenticity not assessed.'],$actor);
    $refs=new CurriculumVersionReferenceService($db);$refs->attach(['curriculum_version_id'=>$version['id'],'compliance_reference_id'=>$source['id'],'reference_purpose'=>'school_curriculum'],$actor);
    $refs->classifyReference((int)$version['id'],(int)$source['id'],'other_document',true,$actor);
    $revision=(new CurriculumRevisionService($db))->create(['source_version_id'=>$version['id'],'curriculum_id'=>$curriculum['id'],'version_label'=>'SYNTHETIC Working Revision','confirm_reference_unchanged'=>1],$actor)['version'];
    $stmt=RepositoryQuery::execute($db,'SELECT id FROM curriculum_version_subjects WHERE curriculum_version_id=? ORDER BY sequence_order','i',[(int)$revision['id']]);$revisionPlacements=array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC),'id');$stmt->close();
    $other=(new AcademicProgramService($db))->create(['program_code'=>'SYN-OTHER','program_name'=>'SYNTHETIC Other Program'],$actor);
    $empty=(new CurriculumService($db))->create(['academic_program_id'=>$other['id'],'curriculum_code'=>'SYN-EMPTY','curriculum_name'=>'SYNTHETIC Empty Curriculum'],$actor);
    return ['program_id'=>(int)$program['id'],'curriculum_id'=>(int)$curriculum['id'],'reference_version_id'=>(int)$version['id'],'revision_version_id'=>(int)$revision['id'],'reference_placement_id'=>(int)$placements[0],'revision_placement_id'=>(int)$revisionPlacements[0],'other_program_id'=>(int)$other['id'],'empty_curriculum_id'=>(int)$empty['id'],'actor_id'=>$actor];
}
