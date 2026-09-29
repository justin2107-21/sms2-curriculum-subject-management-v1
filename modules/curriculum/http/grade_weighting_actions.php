<?php

require_once __DIR__ . '/support.php';
require_once __DIR__ . '/../services/GradeWeightingService.php';

$action = curriculum_http_action();
$service = new GradeWeightingService($conn);
require_http_method(['GET', 'POST']);

try {
    switch ($action) {
        case 'program_options':
            curriculum_require_read('grade_weighting.view');
            json_response(true, 'Academic program options loaded.', $service->programOptions($_GET));

        case 'curriculum_options':
            curriculum_require_read('grade_weighting.view');
            json_response(true, 'Curriculum options loaded.', $service->curriculumOptions($_GET));

        case 'version_options':
            curriculum_require_read('grade_weighting.view');
            json_response(true, 'Curriculum version options loaded.', $service->versionOptions($_GET));

        case 'placement_options':
            curriculum_require_read('grade_weighting.view');
            json_response(true, 'Curriculum subject placement options loaded.', $service->placementOptions($_GET));

        case 'scheme_get':
            curriculum_require_read('grade_weighting.view');
            $placementId = DomainValidator::positiveId(
                $_GET['curriculum_version_subject_id'] ?? null,
                'Curriculum subject placement'
            );
            json_response(true, 'Grade weighting configuration loaded.', $service->getSchemeForPlacement($placementId));

        case 'component_list':
            curriculum_require_read('grade_weighting.view');
            $schemeId = DomainValidator::positiveId($_GET['scheme_id'] ?? null, 'Weighting scheme');
            json_response(true, 'Grade weighting components loaded.', $service->listComponents($schemeId, $_GET));

        case 'scheme_create':
            curriculum_require_mutation('grade_weighting.manage');
            $scheme = $service->createScheme($_POST, curriculum_actor_id());
            json_response(true, 'Grade weighting scheme created.', ['scheme' => $scheme], 201);

        case 'scheme_update':
            curriculum_require_mutation('grade_weighting.manage');
            $schemeId = DomainValidator::positiveId($_POST['id'] ?? null, 'Weighting scheme');
            $scheme = $service->updateScheme($schemeId, $_POST);
            json_response(true, 'Grade weighting scheme updated.', ['scheme' => $scheme]);

        case 'scheme_remove':
            curriculum_require_mutation('grade_weighting.manage');
            $schemeId = DomainValidator::positiveId($_POST['id'] ?? null, 'Weighting scheme');
            $service->removeScheme($schemeId);
            json_response(true, 'Grade weighting scheme removed.');

        case 'component_create':
            curriculum_require_mutation('grade_weighting.manage');
            $result = $service->createComponent($_POST, curriculum_actor_id());
            json_response(true, 'Grade weighting component added.', $result, 201);

        case 'component_update':
            curriculum_require_mutation('grade_weighting.manage');
            $componentId = DomainValidator::positiveId(
                $_POST['id'] ?? null,
                'Weighting component'
            );
            $result = $service->updateComponent($componentId, $_POST);
            json_response(true, 'Grade weighting component updated.', $result);

        case 'component_remove':
            curriculum_require_mutation('grade_weighting.manage');
            $componentId = DomainValidator::positiveId(
                $_POST['id'] ?? null,
                'Weighting component'
            );
            $scheme = $service->removeComponent($componentId);
            json_response(true, 'Grade weighting component removed.', ['scheme' => $scheme]);

        default:
            json_response(false, 'Unknown action.', [], 400);
    }
} catch (Throwable $error) {
    curriculum_handle_error($error);
}
