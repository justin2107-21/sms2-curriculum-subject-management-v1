<?php

require_once __DIR__ . '/support.php';
require_once __DIR__ . '/../services/CurriculumVersionSubjectService.php';

$action = curriculum_http_action();
$service = new CurriculumVersionSubjectService($conn);

try {
    switch ($action) {
        case 'list':
            curriculum_require_read('curriculum.view');
            $versionId = DomainValidator::positiveId(
                $_GET['curriculum_version_id'] ?? null,
                'Curriculum version'
            );
            json_response(
                true,
                'Curriculum subject assignments loaded.',
                $service->list($versionId, $_GET)
            );

        case 'mapping_list':
            curriculum_require_read('curriculum.view');
            json_response(
                true,
                'Subject mappings loaded.',
                $service->listMappings($_GET)
            );

        case 'assign':
            curriculum_require_mutation('curriculum.update');
            $assignment = $service->assign($_POST, curriculum_actor_id());
            json_response(true, 'Subject assigned to curriculum version.', ['assignment' => $assignment], 201);

        case 'update':
            curriculum_require_mutation('curriculum.update');
            $id = DomainValidator::positiveId($_POST['id'] ?? null, 'Subject mapping');
            $mapping = $service->updatePlacement($id, $_POST);
            json_response(true, 'Subject mapping updated.', ['mapping' => $mapping]);

        case 'remove':
            curriculum_require_mutation('curriculum.update');
            $id = DomainValidator::positiveId($_POST['id'] ?? null, 'Curriculum subject assignment');
            $service->remove($id);
            json_response(true, 'Subject removed from curriculum version.');

        default:
            json_response(false, 'Unknown action.', [], 400);
    }
} catch (Throwable $error) {
    curriculum_handle_error($error);
}
