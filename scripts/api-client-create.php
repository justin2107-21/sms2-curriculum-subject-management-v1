<?php
// Manage API clients from the command line. CLI only (404 over the web; /scripts is also blocked in .htaccess).
//   php scripts/api-client-create.php create --name="Class Scheduling" --source=class_scheduling --scope=write --operator=<username>
//   php scripts/api-client-create.php rotate --name="Class Scheduling" --operator=<username>
//   php scripts/api-client-create.php revoke --name="Class Scheduling" --operator=<username>
//   php scripts/api-client-create.php list
// Needs DB_NAME (and DB_* if not default) and API_KEY_PEPPER in the environment. The operator must hold api_client.manage (Super Admin).
// The plain key is printed ONCE on stdout by create and rotate. It is not stored, logged or audited; only an HMAC hash is kept.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/../shared/database_config.php';
require_once __DIR__ . '/../shared/db.php';
require_once __DIR__ . '/../shared/authorization.php';

$operatorId = null;
// Audit rows take their actor from current_user_id(); in the CLI that is the named operator.
function current_user_id(): ?int { global $operatorId; return $operatorId; }
require_once __DIR__ . '/../shared/audit.php';
require_once __DIR__ . '/../modules/curriculum/services/ApiClientService.php';

$command = $argv[1] ?? '';
$options = [];
foreach (array_slice($argv, 2) as $arg) if (preg_match('/^--([a-z]+)=(.*)$/s', $arg, $m)) $options[$m[1]] = $m[2];
$fail = static function (string $message, int $code = 1): void { fwrite(STDERR, $message . "\n"); exit($code); };

if (!in_array($command, ['create', 'rotate', 'revoke', 'list'], true)) $fail('Usage: api-client-create.php create|rotate|revoke|list [--name=] [--source=] [--scope=write|read|read_write] [--operator=]');
if (strlen((string)(getenv('API_KEY_PEPPER') ?: '')) < 32) $fail('API_KEY_PEPPER must be set in the environment (at least 32 characters).');
$service = new ApiClientService($conn);

if ($command === 'list') {
    foreach ($service->listClients() as $c) echo implode("\t", [$c['name'], $c['source_system'], $c['scope'], $c['key_prefix'], $c['is_active'] ? 'active' : 'revoked', $c['last_used_at'] ?? 'never']) . "\n";
    exit(0);
}

$stmt = $conn->prepare('SELECT id FROM users WHERE username = ? AND is_active = 1');
$username = (string)($options['operator'] ?? '');
$stmt->bind_param('s', $username);
$stmt->execute();
$operator = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$operator) $fail('--operator must be an active user.');
$operatorId = (int)$operator['id'];
if (!user_can($operatorId, 'api_client.manage')) $fail('The operator does not hold api_client.manage (Super Admin only).');

try {
    $name = (string)($options['name'] ?? '');
    if ($command === 'create') $result = $service->create($name, (string)($options['source'] ?? ''), (string)($options['scope'] ?? 'write'), $operatorId);
    elseif ($command === 'rotate') $result = $service->rotate($name, $operatorId);
    else $result = $service->revoke($name, $operatorId);
} catch (InvalidArgumentException $e) {
    $fail($e->getMessage());
}
if (isset($result['key'])) {
    echo "API client: {$result['name']} (source {$result['source_system']}, scope {$result['scope']})\n";
    echo "Key (shown once; store it now, it cannot be recovered):\n{$result['key']}\n";
} else {
    echo "API client {$result['name']} revoked.\n";
}
