<?php

require_once __DIR__ . '/support.php';
require_once __DIR__ . '/../services/SubjectService.php';
require_once __DIR__ . '/../services/ArchiveCenterService.php';
require_once __DIR__ . '/../services/CurriculumVersionSubjectService.php';
require_once __DIR__ . '/../services/ElectivesWorkspaceService.php';
require_once __DIR__ . '/../services/SubjectEquivalencyService.php';

$action = curriculum_http_action();
$service = new SubjectService($conn);

try {
    switch ($action) {
        case 'list':
            curriculum_require_read('subject.view');
            foreach (['academic_program_id', 'curriculum_id', 'curriculum_version_id', 'year_level', 'semester', 'placed'] as $filter) {
                if (isset($_GET[$filter]) && $_GET[$filter] !== '') {
                    curriculum_require_read('curriculum.view');
                    break;
                }
            }
            $input = $_GET;
            $input['_include_placement_context'] = user_can(current_user_id(), 'curriculum.view') && !CurriculumReadScope::restricted(current_user_id());
            $input['_include_usage'] = user_can(current_user_id(), 'curriculum.view');
            json_response(true, 'Subjects loaded.', $service->list($input));

        case 'program_filter_options':
            curriculum_require_read('subject.view');
            curriculum_require_read('curriculum.view');
            json_response(true, 'Programs loaded.', ['programs' => (new SubjectEquivalencyService($conn))->filterOptions()['programs']]);

        case 'context_options':
            curriculum_require_read('subject.view');
            curriculum_require_read('curriculum.view');
            json_response(true, 'Context options loaded.', (new ElectivesWorkspaceService($conn))->contextOptions($_GET));

        case 'version_placements':
            curriculum_require_read('subject.view');
            curriculum_require_read('curriculum.view');
            json_response(true, 'Version placements loaded.', $service->versionPlacements($_GET));

        case 'contexts':
            curriculum_require_read('subject.view');
            curriculum_require_read('curriculum.view');
            json_response(true, 'Placement contexts loaded.', $service->contexts($_GET));

        case 'get':
            curriculum_require_read('subject.view');
            $id = DomainValidator::positiveId($_GET['id'] ?? null, 'Subject');
            json_response(true, 'Subject loaded.', ['subject' => $service->detail($id, user_can(current_user_id(), 'curriculum.view'))]);

        case 'create':
            curriculum_require_mutation('subject.create');
            // Optional placement in the same request: only for a role that may browse programs and edit curricula, validated before anything is written.
            $placement = null;
            $placementInput = null;
            if (trim((string)($_POST['curriculum_version_id'] ?? '')) !== '') {
                foreach (['program.view', 'curriculum.view', 'curriculum.update'] as $needed) require_permission($needed, true);
                $placementInput = [
                    'curriculum_version_id' => DomainValidator::positiveId($_POST['curriculum_version_id'], 'Curriculum version'),
                    'year_level' => DomainValidator::requiredString($_POST, 'year_level', 'Year / Grade level', 50),
                    'semester' => DomainValidator::requiredString($_POST, 'semester', 'Semester', 50),
                    'is_required' => in_array((string)($_POST['is_required'] ?? '1'), ['0', '1'], true) ? (string)($_POST['is_required'] ?? '1') : '1',
                ];
                if (!(new CurriculumVersionRepository($conn))->findById($placementInput['curriculum_version_id'])) throw new DomainNotFoundException('Curriculum version not found.');
                if (!CurriculumVersionEditPolicy::isEditable((new CurriculumVersionEditPolicy($conn))->loadVersion($placementInput['curriculum_version_id']))) {
                    throw new DomainConflictException('That curriculum version cannot be edited, so the subject was not created. Choose an editable version or create the subject without a placement.');
                }
            }
            $subject = $service->create($_POST, curriculum_actor_id());
            $placementError = null;
            if ($placementInput !== null) {
                try {
                    $placement = (new CurriculumVersionSubjectService($conn))->assign($placementInput + ['subject_id' => (string)$subject['id']], curriculum_actor_id(), false);
                } catch (Throwable $error) {
                    $placementError = $error instanceof DomainValidationException || $error instanceof DomainConflictException || $error instanceof DomainNotFoundException ? $error->getMessage() : 'The placement could not be saved.';
                }
            }
            json_response(true, $placementError === null ? 'Subject created.' : 'Subject created, but its placement was not saved.', ['subject' => $subject, 'placement' => $placement, 'placement_error' => $placementError], 201);

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
