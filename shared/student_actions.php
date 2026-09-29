<?php
// Existing SMS2 student-management endpoint.
// This is intentionally isolated from the future Curriculum and Subject
// Management domain. RBAC guards here validate the authorization engine only;
// they do not make student CRUD part of the curriculum subsystem.

require_once __DIR__ . '/bootstrap.php';
require_authenticated_user(true);

$method = request_method();
$source = $method === 'POST' ? $_POST : $_GET;
$action = is_string($source['action'] ?? null) ? $source['action'] : '';
$mutatingActions = ['add', 'edit', 'delete', 'bulk_delete', 'bulk_status'];

if ($action === 'get') {
    require_http_method(['GET']);
} elseif (in_array($action, $mutatingActions, true)) {
    require_http_method(['POST']);
    require_valid_csrf_token();
} else {
    json_response(false, 'Unknown action.', [], 400);
}

$permissionByAction = [
    'get' => 'student.view',
    'add' => 'student.create',
    'edit' => 'student.update',
    'delete' => 'student.delete',
    'bulk_delete' => 'student.delete',
    'bulk_status' => 'student.status.update',
];
require_permission($permissionByAction[$action], true);

require_once __DIR__ . '/db.php';

function student_response(bool $success, string $message, array $extra = [], int $status = 200): void
{
    json_response($success, $message, $extra, $status);
}

try {
    switch ($action) {
        case 'add': {
            $firstName = trim((string)($_POST['first_name'] ?? ''));
            $lastName = trim((string)($_POST['last_name'] ?? ''));
            $birthday = trim((string)($_POST['birthday'] ?? ''));
            $course = trim((string)($_POST['course'] ?? ''));
            $yearLevel = trim((string)($_POST['year_level'] ?? ''));
            $section = trim((string)($_POST['section'] ?? ''));
            $phone = trim((string)($_POST['phone'] ?? ''));
            $status = ($_POST['status'] ?? 'Active') === 'Inactive' ? 'Inactive' : 'Active';

            if (!$firstName || !$lastName || !$birthday || !$course || !$yearLevel || !$section || !$phone) {
                student_response(false, 'All fields are required.', [], 422);
            }

            $stmt = $conn->prepare(
                'INSERT INTO students (first_name, last_name, birthday, course, year_level, section, phone, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->bind_param(
                'ssssssss',
                $firstName,
                $lastName,
                $birthday,
                $course,
                $yearLevel,
                $section,
                $phone,
                $status
            );
            $conn->begin_transaction();
            try {
                $stmt->execute();
                $studentId = (int)$conn->insert_id;
                $stmt->close();
                audit_log_required(
                    'STUDENT_CREATE',
                    'student',
                    $studentId,
                    'Legacy template student record created.',
                    ['status' => $status]
                );
                $conn->commit();
            } catch (Throwable $error) {
                $conn->rollback();
                throw $error;
            }
            student_response(true, 'Student added.', ['id' => $studentId], 201);
        }

        case 'get': {
            $id = (int)($_GET['id'] ?? 0);
            if ($id <= 0) {
                student_response(false, 'Invalid ID.', [], 422);
            }

            $stmt = $conn->prepare('SELECT * FROM students WHERE id = ? LIMIT 1');
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $student = $stmt->get_result()->fetch_assoc();

            if (!$student) {
                student_response(false, 'Student not found.', [], 404);
            }
            student_response(true, 'Student loaded.', ['student' => $student]);
        }

        case 'edit': {
            $id = (int)($_POST['id'] ?? 0);
            $firstName = trim((string)($_POST['first_name'] ?? ''));
            $lastName = trim((string)($_POST['last_name'] ?? ''));
            $birthday = trim((string)($_POST['birthday'] ?? ''));
            $course = trim((string)($_POST['course'] ?? ''));
            $yearLevel = trim((string)($_POST['year_level'] ?? ''));
            $section = trim((string)($_POST['section'] ?? ''));
            $phone = trim((string)($_POST['phone'] ?? ''));
            $status = ($_POST['status'] ?? 'Active') === 'Inactive' ? 'Inactive' : 'Active';

            if ($id <= 0 || !$firstName || !$lastName || !$birthday || !$course || !$yearLevel || !$section || !$phone) {
                student_response(false, 'All fields are required.', [], 422);
            }

            $stmt = $conn->prepare(
                'UPDATE students
                 SET first_name = ?, last_name = ?, birthday = ?, course = ?, year_level = ?, section = ?, phone = ?, status = ?
                 WHERE id = ?'
            );
            $stmt->bind_param(
                'ssssssssi',
                $firstName,
                $lastName,
                $birthday,
                $course,
                $yearLevel,
                $section,
                $phone,
                $status,
                $id
            );
            $conn->begin_transaction();
            try {
                $stmt->execute();
                $affectedRows = $stmt->affected_rows;
                $stmt->close();
                audit_log_required(
                    'STUDENT_UPDATE',
                    'student',
                    $id,
                    'Legacy template student record updated.',
                    [
                        'changed_fields' => [
                            'first_name', 'last_name', 'birthday', 'course',
                            'year_level', 'section', 'phone', 'status'
                        ],
                        'affected_rows' => $affectedRows,
                    ]
                );
                $conn->commit();
            } catch (Throwable $error) {
                $conn->rollback();
                throw $error;
            }
            student_response(true, 'Student updated.');
        }

        case 'delete': {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                student_response(false, 'Invalid ID.', [], 422);
            }

            $stmt = $conn->prepare('DELETE FROM students WHERE id = ?');
            $conn->begin_transaction();
            try {
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $affectedRows = $stmt->affected_rows;
                $stmt->close();
                audit_log_required(
                    'STUDENT_DELETE',
                    'student',
                    $id,
                    'Legacy template student record deleted.',
                    ['affected_rows' => $affectedRows]
                );
                $conn->commit();
            } catch (Throwable $error) {
                $conn->rollback();
                throw $error;
            }
            student_response(true, 'Student deleted.');
        }

        case 'bulk_delete': {
            $ids = array_values(array_filter(array_map('intval', explode(',', (string)($_POST['ids'] ?? '')))));
            if (!$ids) {
                student_response(false, 'No IDs provided.', [], 422);
            }

            $list = implode(',', $ids);
            $conn->begin_transaction();
            try {
                $conn->query("DELETE FROM students WHERE id IN ($list)");
                $affectedRows = $conn->affected_rows;
                audit_log_required(
                    'STUDENT_BULK_DELETE',
                    'student',
                    null,
                    'Legacy template student records bulk deleted.',
                    ['entity_ids' => $ids, 'affected_rows' => $affectedRows]
                );
                $conn->commit();
            } catch (Throwable $error) {
                $conn->rollback();
                throw $error;
            }
            student_response(true, count($ids) . ' student(s) deleted.');
        }

        case 'bulk_status': {
            $ids = array_values(array_filter(array_map('intval', explode(',', (string)($_POST['ids'] ?? '')))));
            $status = ($_POST['status'] ?? 'Active') === 'Inactive' ? 'Inactive' : 'Active';
            if (!$ids) {
                student_response(false, 'No IDs provided.', [], 422);
            }

            $list = implode(',', $ids);
            $stmt = $conn->prepare("UPDATE students SET status = ? WHERE id IN ($list)");
            $conn->begin_transaction();
            try {
                $stmt->bind_param('s', $status);
                $stmt->execute();
                $affectedRows = $stmt->affected_rows;
                $stmt->close();
                audit_log_required(
                    'STUDENT_STATUS_UPDATE',
                    'student',
                    count($ids) === 1 ? $ids[0] : null,
                    'Legacy template student status updated.',
                    ['entity_ids' => $ids, 'status' => $status, 'affected_rows' => $affectedRows]
                );
                $conn->commit();
            } catch (Throwable $error) {
                $conn->rollback();
                throw $error;
            }
            student_response(true, count($ids) . " student(s) set to $status.");
        }
    }
} catch (Throwable $error) {
    log_application_error('Student action failed', $error);
    student_response(false, 'Unable to complete the student request at this time.', [], 500);
}
