<?php

require_once __DIR__ . '/support.php';
require_once __DIR__ . '/../services/SubjectService.php';

$action = curriculum_http_action();
$service = new SubjectService($conn);

try {
    switch ($action) {
        case 'list':
            curriculum_require_read('subject.view');
            json_response(true, 'Subjects loaded.', $service->list($_GET));

        case 'get':
            curriculum_require_read('subject.view');
            $id = DomainValidator::positiveId($_GET['id'] ?? null, 'Subject');
            json_response(true, 'Subject loaded.', ['subject' => $service->get($id)]);

        case 'create':
            curriculum_require_mutation('subject.create');
            $subject = $service->create($_POST, curriculum_actor_id());
            json_response(true, 'Subject created.', ['subject' => $subject], 201);

        case 'update':
            curriculum_require_mutation('subject.update');
            $id = DomainValidator::positiveId($_POST['id'] ?? null, 'Subject');
            $subject = $service->update($id, $_POST);
            json_response(true, 'Subject updated.', ['subject' => $subject]);

        default:
            json_response(false, 'Unknown action.', [], 400);
    }
} catch (Throwable $error) {
    curriculum_handle_error($error);
}
