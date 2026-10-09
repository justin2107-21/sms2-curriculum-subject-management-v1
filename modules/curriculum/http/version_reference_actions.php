<?php

require_once __DIR__ . '/support.php';
require_once __DIR__ . '/../services/CurriculumVersionReferenceService.php';

$action = curriculum_http_action();
$service = new CurriculumVersionReferenceService($conn);
try {
    switch ($action) {
        case 'workspace':
            curriculum_require_read('curriculum.view');
            $id = DomainValidator::positiveId($_GET['curriculum_version_id'] ?? null, 'Curriculum version');
            json_response(true, 'Version references and baseline details loaded.', ['workspace' => $service->workspace($id)]);

        case 'attach':
            curriculum_require_mutation('curriculum.reference.attach');
            $result = $service->attach($_POST, curriculum_actor_id());
            json_response(true, 'Documentary source attached.', ['workspace' => $result], 201);

        case 'classify_reference':
            curriculum_require_mutation('curriculum.reference.classify');
            $versionId = DomainValidator::positiveId($_POST['curriculum_version_id'] ?? null, 'Curriculum version');
            $sourceId = DomainValidator::positiveId($_POST['compliance_reference_id'] ?? null, 'Documentary source');
            $basis = is_string($_POST['classification_basis'] ?? null) ? $_POST['classification_basis'] : '';
            $confirmed = DomainValidator::boolean($_POST['confirm_reference_protection'] ?? null, 'Reference protection confirmation') === 1;
            $result = $service->classifyReference($versionId, $sourceId, $basis, $confirmed, curriculum_actor_id());
            json_response(true, 'Curriculum version classified as a protected reference.', ['workspace' => $result]);

        default:
            json_response(false, 'Unknown action.', [], 400);
    }
} catch (Throwable $error) {
    curriculum_handle_error($error);
}
