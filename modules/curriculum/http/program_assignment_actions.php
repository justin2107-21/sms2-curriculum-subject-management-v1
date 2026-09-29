<?php

require_once __DIR__ . '/support.php';
require_once __DIR__ . '/../services/AcademicProgramAssignmentService.php';

$action = curriculum_http_action();
$service = new AcademicProgramAssignmentService($conn);

try {
    switch ($action) {
        case 'list':
            curriculum_require_read('program_assignment.view');
            json_response(true, 'Academic program subject assignments loaded.', $service->listAssignments($_GET));

        case 'program_options':
            curriculum_require_read('program_assignment.view');
            json_response(true, 'Academic programs loaded.', $service->listPrograms($_GET));

        case 'curriculum_options':
            curriculum_require_read('program_assignment.view');
            $programId = DomainValidator::positiveId(
                $_GET['academic_program_id'] ?? null,
                'Academic program'
            );
            json_response(true, 'Curricula loaded.', $service->listCurricula($programId, $_GET));

        case 'version_options':
            curriculum_require_read('program_assignment.view');
            $curriculumId = DomainValidator::positiveId($_GET['curriculum_id'] ?? null, 'Curriculum');
            json_response(true, 'Curriculum versions loaded.', $service->listVersions($curriculumId, $_GET));

        case 'subject_options':
            curriculum_require_read('program_assignment.manage');
            $versionId = DomainValidator::positiveId(
                $_GET['curriculum_version_id'] ?? null,
                'Curriculum version'
            );
            json_response(
                true,
                'Available Subject Master records loaded.',
                $service->availableSubjects($versionId, $_GET)
            );

        case 'assign':
            curriculum_require_mutation('program_assignment.manage');
            $assignment = $service->assign($_POST, curriculum_actor_id());
            json_response(true, 'Subject assigned to curriculum version.', ['assignment' => $assignment], 201);

        case 'update':
            curriculum_require_mutation('program_assignment.manage');
            $id = DomainValidator::positiveId($_POST['id'] ?? null, 'Subject assignment');
            $assignment = $service->update($id, $_POST);
            json_response(true, 'Subject assignment updated.', ['assignment' => $assignment]);

        case 'remove':
            curriculum_require_mutation('program_assignment.manage');
            $id = DomainValidator::positiveId($_POST['id'] ?? null, 'Subject assignment');
            $service->remove($id);
            json_response(true, 'Subject assignment removed from curriculum version.');

        default:
            json_response(false, 'Unknown action.', [], 400);
    }
} catch (Throwable $error) {
    curriculum_handle_error($error);
}
