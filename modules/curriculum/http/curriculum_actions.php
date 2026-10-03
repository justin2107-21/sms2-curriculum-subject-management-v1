<?php

require_once __DIR__ . '/support.php';
require_once __DIR__ . '/../services/CurriculumService.php';
require_once __DIR__ . '/../services/ArchiveCenterService.php';

$action = curriculum_http_action();
$service = new CurriculumService($conn);

try {
    switch ($action) {
        case 'list':
            curriculum_require_read('curriculum.view');
            json_response(true, 'Curricula loaded.', $service->list($_GET));

        case 'get':
            curriculum_require_read('curriculum.view');
            $id = DomainValidator::positiveId($_GET['id'] ?? null, 'Curriculum');
            json_response(true, 'Curriculum loaded.', ['curriculum' => $service->get($id)]);

        case 'create':
            curriculum_require_mutation('curriculum.create');
            $curriculum = $service->create($_POST, curriculum_actor_id());
            json_response(true, 'Curriculum created.', ['curriculum' => $curriculum], 201);

        case 'update':
            curriculum_require_mutation('curriculum.update');
            $id = DomainValidator::positiveId($_POST['id'] ?? null, 'Curriculum');
            $curriculum = $service->update($id, $_POST);
            json_response(true, 'Curriculum updated.', ['curriculum' => $curriculum]);

        case 'archive':
            curriculum_require_mutation('curriculum.update');
            $id = DomainValidator::positiveId($_POST['id'] ?? null, 'Curriculum');
            (new ArchiveCenterService($conn))->archive('curriculum', $id, curriculum_actor_id());
            json_response(true, 'Curriculum archived.');

        default:
            json_response(false, 'Unknown action.', [], 400);
    }
} catch (Throwable $error) {
    curriculum_handle_error($error);
}
