<?php

require_once __DIR__ . '/support.php';
require_once __DIR__ . '/../services/CurriculumVersionService.php';

$action = curriculum_http_action();
$service = new CurriculumVersionService($conn);

try {
    switch ($action) {
        case 'list':
            curriculum_require_read('curriculum.view');
            $curriculumId = DomainValidator::positiveId($_GET['curriculum_id'] ?? null, 'Curriculum');
            json_response(true, 'Curriculum versions loaded.', $service->list($curriculumId, $_GET));

        case 'get':
            curriculum_require_read('curriculum.view');
            $id = DomainValidator::positiveId($_GET['id'] ?? null, 'Curriculum version');
            json_response(true, 'Curriculum version loaded.', ['version' => $service->get($id)]);

        case 'create':
            curriculum_require_mutation('curriculum.version.create');
            $version = $service->create($_POST, curriculum_actor_id());
            json_response(true, 'Curriculum version created.', ['version' => $version], 201);

        case 'rename':
            curriculum_require_mutation('curriculum.update');
            $id = DomainValidator::positiveId($_POST['id'] ?? null, 'Curriculum version');
            $version = $service->rename($id, $_POST, curriculum_actor_id());
            json_response(true, 'Curriculum version renamed.', ['version' => $version]);

        default:
            json_response(false, 'Unknown action.', [], 400);
    }
} catch (Throwable $error) {
    curriculum_handle_error($error);
}
