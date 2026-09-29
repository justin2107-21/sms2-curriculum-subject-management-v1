<?php

require_once __DIR__ . '/support.php';
require_once __DIR__ . '/../services/CurriculumElectiveGroupService.php';

$action = curriculum_http_action();
$service = new CurriculumElectiveGroupService($conn);

try {
    switch ($action) {
        case 'list':
            curriculum_require_read('elective.view');
            $versionId = DomainValidator::positiveId(
                $_GET['curriculum_version_id'] ?? null,
                'Curriculum version'
            );
            json_response(
                true,
                'Elective groups loaded.',
                $service->list($versionId, $_GET)
            );

        case 'create':
            curriculum_require_mutation('elective.manage');
            $group = $service->create($_POST, curriculum_actor_id());
            json_response(
                true,
                'Elective group created.',
                ['group' => $group],
                201
            );

        case 'update':
            curriculum_require_mutation('elective.manage');
            $id = DomainValidator::positiveId($_POST['id'] ?? null, 'Elective group');
            $group = $service->update($id, $_POST);
            json_response(true, 'Elective group updated.', ['group' => $group]);

        case 'remove':
            curriculum_require_mutation('elective.manage');
            $id = DomainValidator::positiveId($_POST['id'] ?? null, 'Elective group');
            $service->remove($id);
            json_response(true, 'Empty elective group removed.');

        default:
            json_response(false, 'Unknown action.', [], 400);
    }
} catch (Throwable $error) {
    curriculum_handle_error($error);
}
