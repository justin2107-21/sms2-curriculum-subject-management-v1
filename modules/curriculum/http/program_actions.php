<?php

require_once __DIR__ . '/support.php';
require_once __DIR__ . '/../services/AcademicProgramService.php';

$action = curriculum_http_action();
$service = new AcademicProgramService($conn);

try {
    switch ($action) {
        case 'list':
            curriculum_require_read('program.view');
            json_response(true, 'Academic programs loaded.', $service->list($_GET));

        case 'get':
            curriculum_require_read('program.view');
            $id = DomainValidator::positiveId($_GET['id'] ?? null, 'Academic program');
            json_response(true, 'Academic program loaded.', ['program' => $service->get($id)]);

        case 'create':
            curriculum_require_mutation('program.create');
            $program = $service->create($_POST, curriculum_actor_id());
            json_response(true, 'Academic program created.', ['program' => $program], 201);

        case 'update':
            curriculum_require_mutation('program.update');
            $id = DomainValidator::positiveId($_POST['id'] ?? null, 'Academic program');
            $program = $service->update($id, $_POST);
            json_response(true, 'Academic program updated.', ['program' => $program]);

        default:
            json_response(false, 'Unknown action.', [], 400);
    }
} catch (Throwable $error) {
    curriculum_handle_error($error);
}
