<?php

require_once __DIR__ . '/support.php';
require_once __DIR__ . '/../validators/DomainValidator.php';
require_once __DIR__ . '/../services/CurriculumReviewService.php';

$service = new CurriculumReviewService($conn);
try {
    switch (curriculum_http_action()) {
        case 'history':
            curriculum_require_read('curriculum.view');
            $id = DomainValidator::positiveId($_GET['curriculum_version_id'] ?? null, 'Curriculum version');
            json_response(true, 'Review history loaded.', $service->history($id));

        case 'submit':
            curriculum_require_mutation('curriculum.review.submit');
            $id = DomainValidator::positiveId($_POST['curriculum_version_id'] ?? null, 'Curriculum version');
            $status = $service->submit($id, (string)($_POST['comment'] ?? ''), curriculum_actor_id());
            json_response(true, 'Submitted for review.', ['review_status' => $status]);

        case 'decide':
            curriculum_require_mutation('curriculum.review.decide');
            $id = DomainValidator::positiveId($_POST['curriculum_version_id'] ?? null, 'Curriculum version');
            $status = $service->decide($id, (string)($_POST['decision'] ?? ''), (string)($_POST['reason'] ?? ''), curriculum_actor_id());
            json_response(true, $status === 'approved' ? 'Version approved.' : 'Version returned.', ['review_status' => $status]);

        default:
            json_response(false, 'Unknown action.', [], 400);
    }
} catch (Throwable $error) {
    curriculum_handle_error($error);
}
