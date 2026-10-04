<?php

require_once __DIR__ . '/support.php';
require_once __DIR__ . '/../services/CIMFConfig.php';
require_once __DIR__ . '/../services/CIMFContextBuilder.php';
require_once __DIR__ . '/../services/CIMFPromptBuilder.php';
require_once __DIR__ . '/../services/OpenAIClient.php';
require_once __DIR__ . '/../services/CIMFResponseValidator.php';

require_http_method(['POST']);
require_valid_csrf_token();
require_permission('prerequisite.view', true);

$action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
$actions = ['prerequisite_review', 'prerequisite_subject_review', 'compare_subjects', 'ask_cimf'];
if (!in_array($action, $actions, true)) json_response(false, 'Unknown CIMF action.', [], 400);
$versionId = filter_var($_POST['curriculum_version_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$versionId) json_response(false, 'Select a curriculum version.', [], 422);
$subjectId = filter_var($_POST['subject_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
$otherId = filter_var($_POST['other_subject_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
if (($action === 'prerequisite_subject_review' || $action === 'compare_subjects') && !$subjectId)
    json_response(false, 'Select a subject.', [], 422);
if ($action === 'compare_subjects' && !$otherId) json_response(false, 'Select two subjects.', [], 422);
$question = $action === 'ask_cimf' && is_string($_POST['question'] ?? null) ? trim($_POST['question']) : '';
if ($action === 'ask_cimf' && ($question === '' || mb_strlen($question) > 600))
    json_response(false, 'Enter a curriculum question of up to 600 characters.', [], 422);

$config = CIMFConfig::load();
$status = CIMFConfig::status($config);
if ($status !== 'SUCCESS') json_response(false, CIMFConfig::message($status), ['status' => $status], 503);

try {
    $context = (new CIMFContextBuilder($conn))->build((int)$versionId, $action, $subjectId, $otherId);
    $prompt = CIMFPromptBuilder::build($action, $context, $question);
    $result = (new OpenAIClient())->request($config, $prompt);
    $status = $result['status'];
    $normalized = $status === 'SUCCESS' ? CIMFResponseValidator::normalize($result['body'], $context['allowed']) : null;
    if ($status === 'SUCCESS' && $normalized === null) $status = 'INVALID_RESPONSE';
    // Usage-only diagnostics: no prompt, answer, credentials, or academic record text is stored.
    $usage = is_array($result['usage'] ?? null) ? $result['usage'] : [];
    try {
        $stmt = $conn->prepare('INSERT INTO cimf_ai_logs (user_id, curriculum_version_id, action_name, status_name, http_status, input_tokens, output_tokens, total_tokens) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $actor = curriculum_actor_id(); $http = (int)($result['http_status'] ?? 0);
        $input = (int)($usage['input_tokens'] ?? 0); $output = (int)($usage['output_tokens'] ?? 0); $total = (int)($usage['total_tokens'] ?? 0);
        $stmt->bind_param('iissiiii', $actor, $versionId, $action, $status, $http, $input, $output, $total);
        $stmt->execute(); $stmt->close();
    } catch (Throwable $logError) { error_log('[CIMF] Usage log unavailable.'); }
    if ($status !== 'SUCCESS') {
        error_log('[CIMF] Request status=' . $status . ' http=' . (int)($result['http_status'] ?? 0));
        json_response(false, CIMFConfig::message($status), ['status' => $status], 503);
    }
    json_response(true, 'CIMF analysis ready.', ['analysis' => $normalized, 'demo_data' => $context['demo']]);
} catch (InvalidArgumentException $error) {
    json_response(false, $error->getMessage(), [], 422);
} catch (Throwable $error) {
    error_log('[CIMF] Internal request failure: ' . get_class($error));
    json_response(false, CIMFConfig::message('OPENAI_SERVER_ERROR'), ['status' => 'OPENAI_SERVER_ERROR'], 503);
}
