<?php

require_once __DIR__ . '/support.php';
require_once __DIR__ . '/../services/GradeWeightingService.php';

$action = curriculum_http_action();
$service = new GradeWeightingService($conn);
require_http_method(['GET', 'POST']);

try {
    switch ($action) {
        case 'context_options': // shared Program / Curriculum / Version bar
            curriculum_require_read('grade_weighting.view');
            json_response(true, 'Context options loaded.', $service->contextOptions($_GET));

        case 'context':
            curriculum_require_read('grade_weighting.view');
            json_response(true, 'Version context loaded.', $service->versionContext($_GET));

        case 'overview': // server-side paged subjects table of one version
            curriculum_require_read('grade_weighting.view');
            json_response(true, 'Grade weighting overview loaded.', $service->overview($_GET));

        case 'scheme_get': // one placement for the editor dialog (scheme, components, edit state)
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

        case 'configuration_save':
            curriculum_require_mutation('grade_weighting.manage');
            $placementId = DomainValidator::positiveId(
                $_POST['curriculum_version_subject_id'] ?? null,
                'Curriculum subject placement'
            );
            $versionId = DomainValidator::positiveId(
                $_POST['curriculum_version_id'] ?? null,
                'Curriculum version'
            );
            $result = $service->saveConfiguration($placementId, $versionId, $_POST, curriculum_actor_id());
            json_response(true, 'Grade weighting configuration saved.', $result);

        case 'configuration_remove':
            curriculum_require_mutation('grade_weighting.manage');
            $placementId = DomainValidator::positiveId($_POST['curriculum_version_subject_id'] ?? null, 'Curriculum subject placement');
            $versionId = DomainValidator::positiveId($_POST['curriculum_version_id'] ?? null, 'Curriculum version');
            $service->removeConfiguration($placementId, $versionId, curriculum_actor_id());
            json_response(true, 'Grade weighting configuration removed.');

        default:
            json_response(false, 'Unknown action.', [], 400);
    }
} catch (Throwable $error) {
    curriculum_handle_error($error);
}