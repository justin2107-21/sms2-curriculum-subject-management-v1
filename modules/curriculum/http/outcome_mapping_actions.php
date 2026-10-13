<?php
require_once __DIR__ . '/support.php';
require_once __DIR__ . '/../validators/DomainValidator.php';
require_once __DIR__ . '/../services/CurriculumOutcomeMappingService.php';

$service = new CurriculumOutcomeMappingService($conn);
try {
    switch (curriculum_http_action()) {
        case 'workspace':
            curriculum_require_read('curriculum.view');
            $versionId=DomainValidator::positiveId($_GET['curriculum_version_id']??null,'Curriculum version');
            json_response(true,'Graduate outcome workspace loaded.',$service->workspace($versionId));
        case 'add':
            curriculum_require_mutation('curriculum.outcome_mapping.manage');
            $versionId=DomainValidator::positiveId($_POST['curriculum_version_id']??null,'Curriculum version');
            $placementId=DomainValidator::positiveId($_POST['placement_id']??null,'Subject placement');
            $outcomeId=DomainValidator::positiveId($_POST['outcome_id']??null,'Graduate outcome');
            $rationale=DomainValidator::requiredString($_POST,'rationale','Academic mapping rationale',3000);
            $id=$service->add($versionId,$placementId,$outcomeId,$rationale,curriculum_actor_id());
            json_response(true,'Subject-to-outcome mapping recorded.',['mapping_id'=>$id],201);
        case 'remove':
            curriculum_require_mutation('curriculum.outcome_mapping.manage');
            $versionId=DomainValidator::positiveId($_POST['curriculum_version_id']??null,'Curriculum version');
            $mappingId=DomainValidator::positiveId($_POST['mapping_id']??null,'Outcome mapping');
            $service->remove($versionId,$mappingId,curriculum_actor_id());
            json_response(true,'Subject-to-outcome mapping removed.');
        case 'assess':
            curriculum_require_mutation('curriculum.outcome_mapping.manage');
            $versionId=DomainValidator::positiveId($_POST['curriculum_version_id']??null,'Curriculum version');
            $outcomeId=DomainValidator::positiveId($_POST['outcome_id']??null,'Graduate outcome');
            $state=DomainValidator::requiredString($_POST,'assessment_state','Coverage assessment',12);
            $note=isset($_POST['rationale'])?(string)$_POST['rationale']:null;
            $service->assess($versionId,$outcomeId,$state,$note,curriculum_actor_id());
            json_response(true,'Outcome coverage assessment saved.');
        default: json_response(false,'Unknown action.',[],400);
    }
} catch (Throwable $error) { curriculum_handle_error($error); }
