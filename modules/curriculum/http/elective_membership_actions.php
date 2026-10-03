<?php

require_once __DIR__ . '/support.php';
require_once __DIR__ . '/../services/CurriculumElectiveGroupSubjectService.php';

$action = curriculum_http_action();
// Membership is now saved atomically with the group and selection rule.
if (in_array($action, ['add', 'remove'], true)) {
    curriculum_require_mutation('elective.manage');
    json_response(false, 'Edit the elective group to change eligible subjects and validate its selection rule.', [], 422);
}
$service = new CurriculumElectiveGroupSubjectService($conn);

try {
    switch ($action) {
        case 'list':
            curriculum_require_read('elective.view');
            $groupId = DomainValidator::positiveId(
                $_GET['elective_group_id'] ?? null,
                'Elective group'
            );
            json_response(
                true,
                'Elective group subjects loaded.',
                $service->list($groupId, $_GET)
            );

        case 'placement_options':
            curriculum_require_read('elective.view');
            $groupId = DomainValidator::positiveId(
                $_GET['elective_group_id'] ?? null,
                'Elective group'
            );
            json_response(
                true,
                'Available curriculum subject placements loaded.',
                $service->placementOptions($groupId, $_GET)
            );

        case 'add':
            curriculum_require_mutation('elective.manage');
            $membership = $service->add($_POST, curriculum_actor_id());
            json_response(
                true,
                'Subject added to elective group.',
                ['membership' => $membership],
                201
            );

        case 'remove':
            curriculum_require_mutation('elective.manage');
            $id = DomainValidator::positiveId(
                $_POST['id'] ?? null,
                'Elective group membership'
            );
            $service->remove($id);
            json_response(true, 'Subject removed from elective group.');

        default:
            json_response(false, 'Unknown action.', [], 400);
    }
} catch (Throwable $error) {
    curriculum_handle_error($error);
}
