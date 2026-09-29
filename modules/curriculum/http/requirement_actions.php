<?php

require_once __DIR__ . '/support.php';
require_once __DIR__ . '/../services/CurriculumSubjectRequirementService.php';

$action = curriculum_http_action();
$service = new CurriculumSubjectRequirementService($conn);

try {
    switch ($action) {
        case 'list':
            curriculum_require_read('prerequisite.view');
            json_response(
                true,
                'Subject requirements loaded.',
                $service->list($_GET)
            );

        case 'placement_options':
            curriculum_require_read('prerequisite.view');
            $versionId = DomainValidator::positiveId(
                $_GET['curriculum_version_id'] ?? null,
                'Curriculum version'
            );
            json_response(
                true,
                'Curriculum subject placements loaded.',
                $service->placementOptions($versionId, $_GET)
            );

        case 'create':
            curriculum_require_mutation('prerequisite.manage');
            $relationship = $service->create($_POST, curriculum_actor_id());
            json_response(
                true,
                'Subject requirement created.',
                ['relationship' => $relationship],
                201
            );

        case 'remove':
            curriculum_require_mutation('prerequisite.manage');
            $id = DomainValidator::positiveId($_POST['id'] ?? null, 'Subject requirement');
            $service->remove($id);
            json_response(true, 'Subject requirement removed.');

        default:
            json_response(false, 'Unknown action.', [], 400);
    }
} catch (Throwable $error) {
    curriculum_handle_error($error);
}
