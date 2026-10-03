<?php
require_once __DIR__ . '/../shared/bootstrap.php';
require_once __DIR__ . '/../shared/db.php';
require_once __DIR__ . '/../modules/curriculum/services/ArchiveCenterService.php';

require_authenticated_user(true);
$service = new ArchiveCenterService($conn);
$action = (string)(request_method() === 'POST' ? ($_POST['action'] ?? '') : ($_GET['action'] ?? ''));

try {
    if (request_method() === 'GET') {
        require_permission('archive.view', true);
        if ($action === 'list') json_response(true, 'Archives loaded.', $service->list($_GET));
        if ($action === 'details') {
            $id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
            if ($id === false || $id === null) throw new InvalidArgumentException('Invalid record ID.');
            json_response(true, 'Archive details loaded.', ['record'=>$service->details((string)($_GET['module'] ?? ''), $id)]);
        }
    } elseif (request_method() === 'POST') {
        require_valid_csrf_token();
        if ($action === 'restore') {
            require_permission('archive.restore', true);
            $module = (string)($_POST['module'] ?? '');
            $permission = ['program'=>'program.update', 'curriculum'=>'curriculum.update',
                'subject'=>'subject.update', 'elective_group'=>'elective.manage'][$module] ?? null;
            if ($permission === null) throw new InvalidArgumentException('Unsupported archive module.');
            require_permission($permission, true);
            $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
            if ($id === false || $id === null) throw new InvalidArgumentException('Invalid record ID.');
            $service->restore($module, $id);
            json_response(true, 'Record restored.');
        }
    }
    json_response(false, 'Unknown archive action.', [], 400);
} catch (InvalidArgumentException $error) {
    json_response(false, $error->getMessage(), [], 400);
} catch (OutOfBoundsException $error) {
    json_response(false, $error->getMessage(), [], 404);
} catch (DomainException $error) {
    json_response(false, $error->getMessage(), [], 409);
} catch (Throwable $error) {
    log_application_error('Archive center request failed', $error);
    json_response(false, 'Unable to complete the archive request.', [], 500);
}
