<?php

require_once __DIR__ . '/support.php';
require_once __DIR__ . '/../services/CurriculumVersionSubjectService.php';

$action = curriculum_http_action();
$service = new CurriculumVersionSubjectService($conn);

try {
    switch ($action) {
        case 'placement_subject_options':
            curriculum_require_read('curriculum.update');
            require_permission('subject.view', true);
            json_response(true, 'Available subjects loaded.', $service->placementSubjectOptions($_GET));

        case 'preview_placement':
            curriculum_require_read('curriculum.update');
            $id = DomainValidator::positiveId($_GET['id'] ?? null, 'Subject mapping');
            json_response(true, 'Placement sequence checked.', ['validation' => $service->previewPlacement($id, $_GET)]);

        case 'list':
            curriculum_require_read('curriculum.view');
            $versionId = DomainValidator::positiveId(
                $_GET['curriculum_version_id'] ?? null,
                'Curriculum version'
            );
            json_response(
                true,
                'Curriculum subject assignments loaded.',
                $service->list($versionId, $_GET)
            );

        case 'mapping_list':
            curriculum_require_read('curriculum.view');
            json_response(
                true,
                'Subject mappings loaded.',
                $service->listMappings($_GET)
            );

        case 'map_subject':
        case 'assign':
            curriculum_require_mutation('curriculum.update');
            if ($action === 'map_subject') {
                require_permission('subject.view', true);
                DomainValidator::requiredString($_POST, 'year_level', 'Year / Grade level', 50);
                DomainValidator::requiredString($_POST, 'semester', 'Semester', 50);
                DomainValidator::requiredString($_POST, 'is_required', 'Placement', 1);
            }
            $assignment = $service->assign($_POST, curriculum_actor_id(), $action === 'map_subject');
            json_response(true, 'Subject assigned to curriculum version.', ['assignment' => $assignment], 201);

        case 'update_mapping':
        case 'update':
            curriculum_require_mutation('curriculum.update');
            if ($action === 'update_mapping') {
                DomainValidator::requiredString($_POST, 'year_level', 'Year / Grade level', 50);
                DomainValidator::requiredString($_POST, 'semester', 'Semester', 50);
                DomainValidator::requiredString($_POST, 'is_required', 'Placement', 1);
            }
            $id = DomainValidator::positiveId($_POST['id'] ?? null, 'Subject mapping');
            $mapping = $service->updatePlacement($id, $_POST, $action === 'update_mapping');
            json_response(true, 'Subject mapping updated.', ['mapping' => $mapping]);

        case 'remove':
            curriculum_require_mutation('curriculum.update');
            $id = DomainValidator::positiveId($_POST['id'] ?? null, 'Curriculum subject assignment');
            $service->remove($id);
            json_response(true, 'Subject removed from curriculum version.');

        default:
            json_response(false, 'Unknown action.', [], 400);
    }
} catch (Throwable $error) {
    curriculum_handle_error($error);
}
