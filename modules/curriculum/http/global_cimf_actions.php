<?php

require_once __DIR__ . '/support.php';
require_once __DIR__ . '/../services/CIMFProfileRegistry.php';
require_once __DIR__ . '/../services/CIMFGlobalContextBuilder.php';
require_once __DIR__ . '/../services/CIMFConfig.php';
require_once __DIR__ . '/../services/CIMFPromptBuilder.php';
require_once __DIR__ . '/../services/OpenAIClient.php';
require_once __DIR__ . '/../services/GroqProvider.php';
require_once __DIR__ . '/../services/CIMFAIProviderFactory.php';
require_once __DIR__ . '/../services/CIMFResponseValidator.php';
require_once __DIR__ . '/../services/CIMFService.php';

require_http_method(['POST']);
require_valid_csrf_token();
try {
    $string = static fn(string $key) => is_string($_POST[$key] ?? null) ? trim($_POST[$key]) : '';
    $operation = $string('operation');
    if (!in_array($operation, ['analyze', 'context'], true)) throw new InvalidArgumentException('Unknown CIMF operation.');
    $profile = CIMFProfileRegistry::validate($string('route'), $string('profile'), $string('action'), $string('page_token'));
    require_permission($profile['route_permission'], true);
    require_permission($profile['permission'], true);
    $raw = json_decode($string('context'), true);
    if (!is_array($raw)) throw new InvalidArgumentException('Invalid CIMF context.');
    $input = CIMFProfileRegistry::contextInput($profile, $raw);
    CurriculumReadScope::enforceInput($conn, $input);
    $question = $string('question');
    if ($string('action') === 'ask' && ($question === '' || mb_strlen($question) > 600) && $operation === 'analyze')
        throw new InvalidArgumentException('Enter a question about this page of up to 600 characters.');
    $context = (new CIMFGlobalContextBuilder($conn))->build($profile, $string('action'), $input, $question);
    if ($operation === 'context') json_response(true, 'Stored context ready.', ['display' => $context['display']]);
    if ($string('action') === 'ask' && preg_match('/^(?:hello|hi|hey|good (?:morning|afternoon|evening))[!.\s]*$/i', $question))
        json_response(true, 'Interface guidance.', ['guidance' => 'Ask about the current ' . $profile['title'] . ' page or choose one of its review actions.', 'source' => 'Interface guidance']);
    $config = CIMFConfig::load(); $status = CIMFConfig::status($config);
    $report = $context['deterministic'] ?? null;
    $findingsOnly = static fn(string $s) => json_response(true, 'CSMS findings ready. The AI explanation is unavailable.', ['deterministic' => $report, 'notice' => CIMFConfig::message($s), 'display' => $context['display'], 'source' => 'CSMS findings']);
    if ($status !== 'SUCCESS' && $report) $findingsOnly($status);
    if ($status !== 'SUCCESS') {
        error_log('[CIMF] profile=' . $profile['id'] . ' status=' . $status . ' category=CONFIGURATION');
        json_response(false, CIMFConfig::message($status), ['status' => $status], 503);
    }
    $prompt = CIMFPromptBuilder::global($profile, $string('action'), $context, $question);
    $validation = $context + ['profile' => $profile['id']];
    $actor = curriculum_actor_id();
    // Provider latency must not hold the PHP session lock and stall other CSMS requests.
    session_write_close();
    $result = CIMFService::forConfig($config)->analyze($config, $prompt, $context['allowed'], 'global_advisory', $validation, $question);
    $status = (string)($result['status'] ?? 'PROVIDER_ERROR');
    $usage = $result['usage'] ?? [];
    $logAction = CIMFProfileRegistry::usageAction($profile['id'], $string('action'));
    try {
        $stmt = $conn->prepare('INSERT INTO cimf_ai_logs (user_id, curriculum_version_id, action_name, status_name, http_status, input_tokens, output_tokens, total_tokens) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $version = $context['version_id']; $http = (int)($result['http_status'] ?? 0);
        $in = (int)($usage['input_tokens'] ?? 0); $out = (int)($usage['output_tokens'] ?? 0); $total = (int)($usage['total_tokens'] ?? 0);
        $stmt->bind_param('iissiiii', $actor, $version, $logAction, $status, $http, $in, $out, $total);
        $stmt->execute(); $stmt->close();
    } catch (Throwable $ignored) { error_log('[CIMF] category=USAGE_LOG_UNAVAILABLE'); }
    if ($status !== 'SUCCESS' && $report) $findingsOnly($status);
    if ($status !== 'SUCCESS') {
        $category = preg_replace('/[^A-Z0-9_]/', '', (string)($result['diagnostic']['category'] ?? $status));
        error_log('[CIMF] profile=' . $profile['id'] . ' status=' . $status . ' category=' . $category . ' http=' . (int)($result['http_status'] ?? 0));
        json_response(false, CIMFConfig::message($status), ['status' => $status], 503);
    }
    json_response(true, 'CIMF advisory ready.', ['analysis' => $result['analysis'], 'display' => $context['display'], 'source' => 'AI advisory', 'deterministic' => $report]);
} catch (InvalidArgumentException | DomainValidationException $error) {
    json_response(false, $error->getMessage(), [], 422);
} catch (DomainNotFoundException $error) {
    json_response(false, $error->getMessage(), [], 404);
} catch (Throwable $error) {
    error_log('[CIMF] category=INTERNAL_' . get_class($error));
    json_response(false, 'CIMF could not complete this request. Existing CSMS functions remain available.', ['status' => 'PROVIDER_ERROR'], 503);
}
