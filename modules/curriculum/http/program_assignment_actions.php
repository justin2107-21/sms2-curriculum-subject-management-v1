<?php
require_once __DIR__ . '/support.php';
require_once __DIR__ . '/../validators/DomainValidator.php';
require_once __DIR__ . '/../services/ProgramSubjectAssignmentService.php';
$service = new ProgramSubjectAssignmentService($conn);
$action = curriculum_http_action();
try {
    switch ($action) {
        case 'search':
            curriculum_require_read('program_assignment.view');
            $type = is_string($_GET['type'] ?? null) ? $_GET['type'] : '';
            $query = is_string($_GET['search'] ?? null) ? trim($_GET['search']) : '';
            if (mb_strlen($query) > 100) throw new DomainValidationException('Search must be 100 characters or fewer.');
            $contextType = is_string($_GET['context_type'] ?? null) ? $_GET['context_type'] : '';
            $contextId = $contextType === '' ? 0 : DomainValidator::positiveId($_GET['context_id'] ?? null, ucfirst($contextType));
            json_response(true, 'Matching records loaded.', ['records' => $service->search($type, $query, $contextType, $contextId, ($_GET['available'] ?? '') === '1')]);
        case 'context':
            curriculum_require_read('program_assignment.view');
            $type = is_string($_GET['type'] ?? null) ? $_GET['type'] : '';
            $id = DomainValidator::positiveId($_GET['id'] ?? null, ucfirst($type));
            json_response(true, 'Assignment context loaded.', ['record' => $service->context($type, $id, $_GET['version_id'] ?? null)]);
        case 'list':
            curriculum_require_read('program_assignment.view');
            $type = is_string($_GET['type'] ?? null) ? $_GET['type'] : '';
            $id = DomainValidator::positiveId($_GET['id'] ?? null, ucfirst($type));
            json_response(true, 'Assignments loaded.', $service->list($id, $type, $_GET));
        case 'details':
            curriculum_require_read('program_assignment.view');
            $id = DomainValidator::positiveId($_GET['id'] ?? null, 'Subject assignment');
            json_response(true, 'Assignment details loaded.', ['assignment' => $service->details($id)]);
        case 'context_options': // the shared Program / Curriculum / Version bar (same lookup as Electives Manager)
            curriculum_require_read('program_assignment.view');
            require_once __DIR__ . '/../services/ElectivesWorkspaceService.php';
            json_response(true, 'Context options loaded.', (new ElectivesWorkspaceService($conn))->contextOptions($_GET));
        case 'usage':
            curriculum_require_read('program_assignment.view');
            $id = DomainValidator::positiveId($_GET['subject_id'] ?? null, 'Subject');
            json_response(true, 'Subject usage loaded.', ['usage' => $service->usage($id)]);
        case 'review':
            curriculum_require_read('program_assignment.view');
            $type = is_string($_GET['type'] ?? null) ? $_GET['type'] : '';
            $id = DomainValidator::positiveId($_GET['id'] ?? null, ucfirst($type));
            json_response(true, 'Deterministic assignment review facts loaded.', ['facts' => $service->reviewFacts($type, $id)]);
        case 'assign':
            curriculum_require_mutation('program_assignment.manage');
            $type = is_string($_POST['view'] ?? null) ? $_POST['view'] : '';
            $contextId = DomainValidator::positiveId($_POST['context_id'] ?? null, ucfirst($type));
            $raw = $_POST['record_ids'] ?? null;
            $ids = is_string($raw) ? json_decode($raw, true) : null;
            if (!is_array($ids) || array_values($ids) !== $ids) throw new DomainValidationException('Select one or more valid records.');
            $result = $service->assign($type, $contextId, $ids, curriculum_actor_id());
            $label = $type === 'program' ? $result['context']['program_code'] : $result['context']['subject_code'];
            json_response(true, $result['count'] . ' subject assignment' . ($result['count'] === 1 ? '' : 's') . ' saved for ' . $label . '.', ['count' => $result['count']], 201);
        case 'remove':
            curriculum_require_mutation('program_assignment.manage');
            $id = DomainValidator::positiveId($_POST['id'] ?? null, 'Subject assignment');
            $service->remove($id);
            json_response(true, 'Subject assignment archived.');
        default:
            json_response(false, 'Unknown action.', [], 400);
    }
} catch (Throwable $error) {
    curriculum_handle_error($error);
}
