<?php

require_once __DIR__ . '/support.php';
require_once __DIR__ . '/../services/SubjectOfferingHistoryService.php';

$action = curriculum_http_action();
$service = new SubjectOfferingHistoryService($conn);
require_http_method(['GET']);

try {
    switch ($action) {
        case 'list':
            curriculum_require_read('subject_history.view');
            json_response(true, 'Stored subject history loaded.', $service->listHistory($_GET));

        case 'program_options':
            curriculum_require_read('subject_history.view');
            json_response(true, 'Academic programs loaded.', $service->listPrograms($_GET));

        case 'curriculum_options':
            curriculum_require_read('subject_history.view');
            $programId = DomainValidator::positiveId(
                $_GET['academic_program_id'] ?? null,
                'Academic program'
            );
            json_response(true, 'Curricula loaded.', $service->listCurricula($programId, $_GET));

        case 'version_options':
            curriculum_require_read('subject_history.view');
            $curriculumId = DomainValidator::positiveId($_GET['curriculum_id'] ?? null, 'Curriculum');
            json_response(true, 'Curriculum versions loaded.', $service->listVersions($curriculumId, $_GET));

        case 'compare_versions':
            curriculum_require_read('subject_history.view');
            json_response(true, 'Curriculum versions compared.', $service->compareVersions($_GET));

        default:
            json_response(false, 'Unknown action.', [], 400);
    }
} catch (Throwable $error) {
    curriculum_handle_error($error);
}
