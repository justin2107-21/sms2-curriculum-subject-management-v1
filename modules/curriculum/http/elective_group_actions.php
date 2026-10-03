<?php
require_once __DIR__ . '/support.php';
require_once __DIR__ . '/../services/ElectivesWorkspaceService.php';
$service = new ElectivesWorkspaceService($conn);
try {
    switch (curriculum_http_action()) {
        case 'list':
            curriculum_require_read('elective.view');
            require_once __DIR__ . '/../services/CurriculumElectiveGroupService.php';
            $versionId = DomainValidator::positiveId($_GET['curriculum_version_id'] ?? null, 'Curriculum version');
            json_response(true, 'Elective groups loaded.', (new CurriculumElectiveGroupService($conn))->list($versionId, $_GET));
        case 'context_options':
            curriculum_require_read('elective.view');
            json_response(true, 'Context options loaded.', $service->contextOptions($_GET));
        case 'workspace':
            curriculum_require_read('elective.view');
            json_response(true, 'Elective groups loaded.', $service->workspace($_GET));
        case 'create':
        case 'update':
            curriculum_require_mutation('elective.manage');
            $id = curriculum_http_action() === 'update' ? DomainValidator::positiveId($_POST['id'] ?? null, 'Elective group') : null;
            json_response(true, 'Elective group saved.', ['group'=>$service->save($_POST, curriculum_actor_id(), $id)], $id ? 200 : 201);
        case 'archive':
            curriculum_require_mutation('elective.manage');
            $service->archive($_POST);
            json_response(true, 'Elective group archived.');
        case 'remove':
            curriculum_require_mutation('elective.manage');
            throw new DomainValidationException('Elective groups must be archived, not deleted.');
        default:
            json_response(false, 'Unknown action.', [], 400);
    }
} catch (Throwable $error) { curriculum_handle_error($error); }
