<?php
require_once __DIR__ . '/support.php';
require_once __DIR__ . '/../validators/DomainValidator.php';
require_once __DIR__ . '/../services/CurriculumCloAssessmentService.php';

$service = new CurriculumCloAssessmentService($conn);
try {
    $action = curriculum_http_action();
    if ($action === 'workspace') {
        curriculum_require_read('curriculum.view');
        $placementId = DomainValidator::positiveId($_GET['placement_id'] ?? null, 'Subject placement');
        json_response(true, 'CLO workspace loaded.', $service->workspace($placementId));
    }
    $permission = match ($action) {
        'clo_create','clo_update','clo_withdraw','alignment_add','alignment_update','alignment_withdraw' => 'curriculum.clo.manage',
        'plan_add','plan_update','plan_withdraw' => 'curriculum.assessment_plan.manage',
        default => null,
    };
    if ($permission === null) json_response(false, 'Unknown action.', [], 400);
    curriculum_require_mutation($permission);
    $actor = curriculum_actor_id();
    $placementId = DomainValidator::positiveId($_POST['placement_id'] ?? null, 'Subject placement');
    switch ($action) {
        case 'clo_create':
            $id = $service->createClo($placementId, DomainValidator::requiredString($_POST,'clo_code','CLO code',40), DomainValidator::requiredString($_POST,'clo_statement','CLO statement',12000), (int)($_POST['sequence_order'] ?? 0), $actor);
            json_response(true, 'Course learning outcome recorded.', ['clo_id'=>$id], 201);
        case 'clo_update':
            $service->updateClo($placementId, DomainValidator::positiveId($_POST['clo_id'] ?? null,'CLO'), DomainValidator::requiredString($_POST,'clo_code','CLO code',40), DomainValidator::requiredString($_POST,'clo_statement','CLO statement',12000), (int)($_POST['sequence_order'] ?? 0), $actor);
            json_response(true, 'Course learning outcome updated.');
        case 'clo_withdraw':
            $service->withdrawClo($placementId, DomainValidator::positiveId($_POST['clo_id'] ?? null,'CLO'), $actor);
            json_response(true, 'Course learning outcome withdrawn; history retained.');
        case 'alignment_add':
            $id = $service->align($placementId, DomainValidator::positiveId($_POST['clo_id'] ?? null,'CLO'), DomainValidator::positiveId($_POST['parent_mapping_id'] ?? null,'Graduate-outcome mapping'), (string)($_POST['rationale'] ?? ''), $actor);
            json_response(true, 'CLO-to-graduate-outcome alignment recorded.', ['alignment_id'=>$id], 201);
        case 'alignment_update':
            $service->updateAlignment($placementId, DomainValidator::positiveId($_POST['alignment_id'] ?? null,'Alignment'), (string)($_POST['rationale'] ?? ''), $actor);
            json_response(true, 'CLO alignment updated.');
        case 'alignment_withdraw':
            $service->withdrawAlignment($placementId, DomainValidator::positiveId($_POST['alignment_id'] ?? null,'Alignment'), $actor);
            json_response(true, 'CLO alignment withdrawn; history retained.');
        case 'plan_add':
            $id = $service->createPlan($placementId, DomainValidator::positiveId($_POST['clo_id'] ?? null,'CLO'), $_POST, $actor);
            json_response(true, 'Planned assessment recorded.', ['plan_id'=>$id], 201);
        case 'plan_update':
            $service->updatePlan($placementId, DomainValidator::positiveId($_POST['clo_id'] ?? null,'CLO'), DomainValidator::positiveId($_POST['plan_id'] ?? null,'Assessment plan'), $_POST, $actor);
            json_response(true, 'Planned assessment updated.');
        case 'plan_withdraw':
            $service->withdrawPlan($placementId, DomainValidator::positiveId($_POST['clo_id'] ?? null,'CLO'), DomainValidator::positiveId($_POST['plan_id'] ?? null,'Assessment plan'), $actor);
            json_response(true, 'Planned assessment withdrawn; history retained.');
        default: json_response(false, 'Unknown action.', [], 400);
    }
} catch (Throwable $error) { curriculum_handle_error($error); }
