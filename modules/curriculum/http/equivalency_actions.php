<?php

require_once __DIR__ . '/support.php';
require_once __DIR__ . '/../services/SubjectEquivalencyService.php';

$action = curriculum_http_action();
$service = new SubjectEquivalencyService($conn);
require_http_method(['GET', 'POST']);

try {
    switch ($action) {
        case 'list':
            curriculum_require_read('equivalency.view');
            json_response(true, 'Subject equivalency records loaded.', $service->list($_GET));

        case 'filter_options':
            curriculum_require_read('equivalency.view');
            json_response(true, 'Equivalency filter options loaded.', $service->filterOptions($_GET));

        case 'reviewer_search':
            curriculum_require_read('equivalency.view');
            json_response(true, 'Reviewers loaded.', $service->reviewerSearch($_GET));

        case 'get':
            curriculum_require_read('equivalency.view');
            $id = DomainValidator::positiveId($_GET['id'] ?? null, 'Subject equivalency');
            json_response(true, 'Subject equivalency record loaded.', ['equivalency' => $service->get($id)]);

        case 'subject_search':
            curriculum_require_read('equivalency.view');
            json_response(true, 'Subject Master records loaded.', $service->searchSubjects($_GET));

        case 'compare':
            curriculum_require_read('equivalency.view');
            json_response(true, 'Subject Master records compared.', $service->compare($_GET));

        case 'create':
            curriculum_require_mutation('equivalency.manage');
            $record = $service->create($_POST, curriculum_actor_id());
            json_response(true, 'Subject equivalency record created.', ['equivalency' => $record], 201);

        case 'update':
            curriculum_require_mutation('equivalency.manage');
            $id = DomainValidator::positiveId($_POST['id'] ?? null, 'Subject equivalency');
            $record = $service->update($id, $_POST, curriculum_actor_id());
            json_response(true, 'Subject equivalency decision updated.', ['equivalency' => $record]);

        case 'archive':
            curriculum_require_mutation('equivalency.manage');
            $id = DomainValidator::positiveId($_POST['id'] ?? null, 'Subject equivalency');
            $service->archive($id, curriculum_actor_id());
            json_response(true, 'Equivalency record archived. Historical data was retained.');

        case 'restore':
            curriculum_require_mutation('equivalency.manage');
            $id = DomainValidator::positiveId($_POST['id'] ?? null, 'Subject equivalency');
            $record = $service->restore($id, curriculum_actor_id());
            json_response(true, 'Equivalency record restored.', ['equivalency' => $record]);

        default:
            json_response(false, 'Unknown action.', [], 400);
    }
} catch (Throwable $error) {
    curriculum_handle_error($error);
}
