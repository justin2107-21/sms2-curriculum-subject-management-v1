<?php

require_once __DIR__ . '/support.php';
require_once __DIR__ . '/../services/ComplianceReferenceService.php';
require_once __DIR__ . '/../services/ComplianceRuleService.php';
require_once __DIR__ . '/../services/ComplianceValidationService.php';

$action = curriculum_http_action();
$referenceService = new ComplianceReferenceService($conn);
$ruleService = new ComplianceRuleService($conn);
$validationService = new ComplianceValidationService($conn);
require_http_method(['GET', 'POST']);

try {
    switch ($action) {
        case 'program_options':
            curriculum_require_read('compliance.view');
            json_response(true, 'Academic program options loaded.', $validationService->programOptions($_GET));

        case 'curriculum_options':
            curriculum_require_read('compliance.view');
            json_response(true, 'Curriculum options loaded.', $validationService->curriculumOptions($_GET));

        case 'version_options':
            curriculum_require_read('compliance.view');
            json_response(true, 'Curriculum version options loaded.', $validationService->versionOptions($_GET));

        case 'subject_options':
            curriculum_require_read('compliance.view');
            json_response(true, 'Subject options loaded.', $validationService->subjectOptions($_GET));

        case 'reference_list':
            curriculum_require_read('compliance.view');
            json_response(true, 'Compliance references loaded.', $referenceService->list($_GET));

        case 'reference_get':
            curriculum_require_read('compliance.view');
            $id = DomainValidator::positiveId($_GET['id'] ?? null, 'Compliance reference');
            json_response(true, 'Compliance reference loaded.', ['reference' => $referenceService->get($id)]);

        case 'rule_list':
            curriculum_require_read('compliance.view');
            json_response(true, 'Compliance rules loaded.', $ruleService->list($_GET));

        case 'rule_get':
            curriculum_require_read('compliance.view');
            $id = DomainValidator::positiveId($_GET['id'] ?? null, 'Compliance rule');
            json_response(true, 'Compliance rule loaded.', ['rule' => $ruleService->get($id)]);

        case 'history_list':
            curriculum_require_read('compliance.view');
            json_response(true, 'Compliance validation history loaded.', $validationService->history($_GET));

        case 'run_get':
            curriculum_require_read('compliance.view');
            $id = DomainValidator::positiveId($_GET['id'] ?? null, 'Validation run');
            json_response(true, 'Compliance validation run loaded.', ['run' => $validationService->getRun($id)]);

        case 'reference_create':
            curriculum_require_mutation('compliance.manage');
            $reference = $referenceService->create($_POST, curriculum_actor_id());
            json_response(true, 'Compliance reference created.', ['reference' => $reference], 201);

        case 'reference_update':
            curriculum_require_mutation('compliance.manage');
            $id = DomainValidator::positiveId($_POST['id'] ?? null, 'Compliance reference');
            $reference = $referenceService->update($id, $_POST);
            json_response(true, 'Compliance reference updated.', ['reference' => $reference]);

        case 'reference_evidence':
            curriculum_require_mutation('compliance.reference.verify');
            $id = DomainValidator::positiveId($_POST['id'] ?? null, 'Compliance reference');
            $reference = $referenceService->recordEvidence($id, $_POST, curriculum_actor_id());
            json_response(true, 'Internal source assessment recorded.', ['reference' => $reference], 201);

        case 'rule_create':
            curriculum_require_mutation('compliance.manage');
            $rule = $ruleService->create($_POST, curriculum_actor_id());
            json_response(true, 'Compliance rule created.', ['rule' => $rule], 201);

        case 'rule_update':
            curriculum_require_mutation('compliance.manage');
            $id = DomainValidator::positiveId($_POST['id'] ?? null, 'Compliance rule');
            $rule = $ruleService->update($id, $_POST);
            json_response(true, 'Compliance rule updated.', ['rule' => $rule]);

        case 'validation_run':
            curriculum_require_mutation('compliance.run');
            $versionId = DomainValidator::positiveId(
                $_POST['curriculum_version_id'] ?? null,
                'Curriculum version'
            );
            $result = $validationService->run($versionId, curriculum_actor_id());
            json_response(true, $result['message'], $result, $result['run'] ? 201 : 200);

        default:
            json_response(false, 'Unknown action.', [], 400);
    }
} catch (Throwable $error) {
    curriculum_handle_error($error);
}
