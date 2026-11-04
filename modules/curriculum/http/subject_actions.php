<?php

require_once __DIR__ . '/support.php';
require_once __DIR__ . '/../services/SubjectService.php';
require_once __DIR__ . '/../services/ArchiveCenterService.php';

$action = curriculum_http_action();
$service = new SubjectService($conn);

try {
    switch ($action) {
        case 'list':
            curriculum_require_read('subject.view');
            foreach (['academic_program_id', 'curriculum_id', 'curriculum_version_id', 'year_level', 'semester'] as $filter) {
                if (isset($_GET[$filter]) && $_GET[$filter] !== '') {
                    curriculum_require_read('curriculum.view');
                    break;
                }
            }
            $input = $_GET;
            $input['_include_placement_context'] = user_can(current_user_id(), 'curriculum.view') && !CurriculumReadScope::restricted(current_user_id());
            json_response(true, 'Subjects loaded.', $service->list($input));

        case 'contexts':
            curriculum_require_read('subject.view');
            curriculum_require_read('curriculum.view');
            json_response(true, 'Placement contexts loaded.', $service->contexts($_GET));

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

        case 'archive':
            curriculum_require_mutation('subject.update');
            $id = DomainValidator::positiveId($_POST['id'] ?? null, 'Subject');
            (new ArchiveCenterService($conn))->archive('subject', $id, curriculum_actor_id());
            json_response(true, 'Subject archived.');

        default:
            json_response(false, 'Unknown action.', [], 400);
    }
} catch (Throwable $error) {
    curriculum_handle_error($error);
}
