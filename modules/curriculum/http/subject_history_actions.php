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

        case 'context_options': // shared Program / Curriculum / Version bar
            curriculum_require_read('subject_history.view');
            json_response(true, 'Context options loaded.', $service->contextOptions($_GET));

        case 'context':
            curriculum_require_read('subject_history.view');
            json_response(true, 'Version context loaded.', $service->selectedContext($_GET));

        case 'version_subjects':
            curriculum_require_read('subject_history.view');
            json_response(true, 'Subjects in the version loaded.', $service->versionSubjects($_GET));

        case 'timeline':
            curriculum_require_read('subject_history.view');
            $placementId = DomainValidator::positiveId($_GET['placement_id'] ?? null, 'Stored placement');
            json_response(true, 'Subject version timeline loaded.', $service->subjectTimeline($placementId));

        case 'subject_options':
            curriculum_require_read('subject_history.view');
            json_response(true, 'Subjects loaded.', $service->listSubjects($_GET));

        case 'details':
            curriculum_require_read('subject_history.view');
            $placementId = DomainValidator::positiveId($_GET['placement_id'] ?? null, 'Stored placement');
            json_response(true, 'Stored placement details loaded.', $service->historyDetails($placementId));

        case 'program_options':
            curriculum_require_read('subject_history.view');
            json_response(true, 'Academic programs loaded.', $service->listPrograms($_GET));

        case 'curriculum_options':
            curriculum_require_read('subject_history.view');
            $programId = DomainValidator::optionalPositiveId($_GET['academic_program_id'] ?? null, 'Academic program');
            json_response(true, 'Curricula loaded.', $service->listCurricula($programId, $_GET));

        case 'version_options':
            curriculum_require_read('subject_history.view');
            $curriculumId = DomainValidator::positiveId($_GET['curriculum_id'] ?? null, 'Curriculum');
            json_response(true, 'Curriculum versions loaded.', $service->listVersions($curriculumId, $_GET));

        case 'compare_versions':
            curriculum_require_read('subject_history.view');
            json_response(true, 'Curriculum versions compared.', $service->compareVersions($_GET));

        case 'compare_revision':
            curriculum_require_read('subject_history.view');
            json_response(true, 'Reference and revision compared.', $service->compareRevision($_GET));

        default:
            json_response(false, 'Unknown action.', [], 400);
    }
} catch (Throwable $error) {
    curriculum_handle_error($error);
}
