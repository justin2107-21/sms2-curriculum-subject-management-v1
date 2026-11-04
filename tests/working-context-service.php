<?php
// WorkingContextService against sms_db_uitest only (DB_NAME=sms_db_uitest). Everything runs in one transaction
// that is rolled back, so no audit rows or preferences stay behind.
require_once __DIR__ . '/../tools/uitest/guard.php';
require_once __DIR__ . '/../shared/database_config.php';
require_once __DIR__ . '/../modules/curriculum/validators/DomainExceptions.php';
require_once __DIR__ . '/../modules/curriculum/services/WorkingContextService.php';

$config = csms_database_config();
if (csms_uitest_database($config['database']) !== CSMS_UITEST_DATABASE) {
    fwrite(STDERR, "Run with DB_NAME=sms_db_uitest.\n");
    exit(1);
}
$db = csms_database_connect($config);
$failures = 0;
$check = static function (bool $ok, string $label) use (&$failures): void {
    echo ($ok ? 'ok   ' : 'FAIL ') . $label . PHP_EOL;
    if (!$ok) $failures++;
};
$throws = static function (callable $fn): bool {
    try { $fn(); return false; } catch (DomainValidationException $e) { return true; }
};
$one = static fn(string $sql) => $db->query($sql)->fetch_assoc();

$user = (int)$one("SELECT id FROM users WHERE username = 'uitest.viewer'")['id'];
$other = (int)$one("SELECT id FROM users WHERE username = 'uitest.staff'")['id'];
$chainSql = "SELECT v.id AS v, c.id AS c, c.academic_program_id AS p FROM curriculum_versions v JOIN curricula c ON c.id = v.curriculum_id ";
$first = $one($chainSql . "ORDER BY v.id LIMIT 1");
$second = $one($chainSql . "WHERE c.academic_program_id <> {$first['p']} ORDER BY v.id LIMIT 1");
$third = $one($chainSql . "WHERE v.id NOT IN ({$first['v']},{$second['v']}) ORDER BY v.id LIMIT 1");
$newest = $one("SELECT v.id FROM curriculum_versions v JOIN curricula c ON c.id = v.curriculum_id JOIN academic_programs p ON p.id = c.academic_program_id
                WHERE v.status = 'active' AND p.status = 'active' ORDER BY v.updated_at DESC, p.program_code ASC, v.id DESC LIMIT 1");

$allowed = true;
$service = new WorkingContextService($db, static function (int $id, string $permission) use (&$allowed): bool { return $allowed; });
$read = static fn(int $id, array $extra = []) => $service->resolve($id, ['module' => 'electives'] + $extra);
$ids = static fn(array $c): array => ['module' => 'electives', 'program_id' => $c['p'], 'curriculum_id' => $c['c'], 'version_id' => $c['v']];

$db->begin_transaction();
try {
    $db->query("DELETE FROM user_preferences WHERE user_id IN ($user, $other)");
    $db->query("DELETE FROM audit_logs WHERE user_id IN ($user, $other) AND action NOT LIKE 'AUTH%'");

    // (d)
    $r = $read($user);
    $check($r['rule'] === 'newest_active' && $r['context']['version']['id'] === (string)$newest['id'], 'no history: rule d, newest active version');

    // (c): content edits count, each kind leads to its version; viewing and exporting do not
    $audit = static function (int $id, string $action, string $type, ?int $entity, string $meta) use ($db): void {
        $stmt = $db->prepare('INSERT INTO audit_logs (user_id, action, entity_type, entity_id, description, metadata_json) VALUES (?, ?, ?, ?, ?, ?)');
        $text = 'test'; $stmt->bind_param('ississ', $id, $action, $type, $entity, $text, $meta); $stmt->execute(); $stmt->close();
    };
    $audit($user, 'CURRICULUM_EXPORT_GENERATE', 'curriculum_version', (int)$third['v'], '{}');
    $check($read($user)['rule'] === 'newest_active', 'an export is not an edit');
    $audit($user, 'SUBJECT_REQUIREMENT_CREATE', 'curriculum_subject_requirement', 999999, json_encode(['version_id' => (int)$first['v']]));
    $r = $read($user);
    $check($r['rule'] === 'last_edited' && $r['context']['version']['id'] === (string)$first['v'], 'rule c: prerequisite edit (metadata version_id)');
    $audit($user, 'ELECTIVE_GROUP_CREATE', 'curriculum_elective_group', 999999, json_encode(['curriculum_version_id' => (int)$second['v']]));
    $check($read($user)['context']['version']['id'] === (string)$second['v'], 'rule c: newest edit wins (elective, metadata curriculum_version_id)');
    $audit($user, 'CURRICULUM_VERSION_CREATE', 'curriculum_version', (int)$third['v'], '{}');
    $check($read($user)['context']['version']['id'] === (string)$third['v'], 'rule c: version create (entity id)');
    $audit($user, 'CURRICULUM_SUBJECT_UPDATE', 'curriculum_version_subject', 999999, json_encode(['curriculum_version_id' => 999999]));
    $check($read($user)['context']['version']['id'] === (string)$third['v'], 'rule c: an edit of a deleted version is skipped');
    $check($read($other)['rule'] === 'newest_active', "another user's edits do not count");

    // (b) beats (c)
    $service->save($user, $ids($first));
    $r = $read($user);
    $check($r['rule'] === 'saved' && $r['context']['version']['id'] === (string)$first['v'], 'rule b: saved beats last edited');
    $check($read($other)['rule'] === 'newest_active', "one user's saved context is not another's");

    // (a) beats (b); a bad link falls through
    $r = $read($user, ['program_id' => $second['p'], 'curriculum_id' => $second['c'], 'version_id' => $second['v']]);
    $check($r['rule'] === 'url' && $r['context']['version']['id'] === (string)$second['v'], 'rule a: link beats saved');
    $check($read($user, ['version_id' => 99999999])['rule'] === 'saved', 'missing version in a link falls to saved');
    $check($read($user, ['version_id' => $second['v'], 'program_id' => $first['p']])['rule'] === 'saved', 'inconsistent link falls to saved');
    $check($read($user, ['version_id' => "1 OR 1=1"])['rule'] === 'saved', 'non-numeric link id is ignored');

    // A saved version that was deleted falls to the next rule
    $db->query("UPDATE user_preferences SET pref_value = '{\"program_id\":1,\"curriculum_id\":1,\"version_id\":99999999}' WHERE user_id = $user");
    $check($read($user)['rule'] === 'last_edited', 'deleted saved version falls to rule c');

    // Saving
    $check($throws(fn() => $service->save($user, ['module' => 'electives', 'program_id' => $second['p'], 'curriculum_id' => $first['c'], 'version_id' => $first['v']])), 'save refuses a chain that does not belong together');
    $check($throws(fn() => $service->save($user, ['module' => 'nope'] + $ids($first))), 'save refuses an unknown module');
    $check($throws(fn() => $service->resolve($user, ['module' => 'nope'])), 'read refuses an unknown module');
    $check($throws(fn() => $service->save($user, ['module' => 'electives', 'version_id' => 'x'])), 'save refuses bad ids');

    // Recent: newest first, one per version, at most 3, only real records, survives clear
    $db->query("DELETE FROM user_preferences WHERE user_id = $user");
    $service->save($user, $ids($first)); $service->save($user, $ids($second)); $service->save($user, $ids($first));
    $recent = $read($user)['recent'];
    $check(array_column(array_column($recent, 'version'), 'id') === [(string)$first['v'], (string)$second['v']], 'recent is newest first with no duplicate');
    $fourth = $one($chainSql . "WHERE v.id NOT IN ({$first['v']},{$second['v']},{$third['v']}) ORDER BY v.id LIMIT 1");
    $service->save($user, $ids($third)); $service->save($user, $ids($fourth));
    $check(count($read($user)['recent']) === 3, 'recent is capped at 3');
    $service->save($user, ['module' => 'electives', 'clear' => '1']);
    $r = $read($user);
    $check($r['rule'] === 'last_edited' && count($r['recent']) === 3, 'clear removes the saved context and keeps recent');
    $stored = $one("SELECT pref_value FROM user_preferences WHERE user_id = $user")['pref_value'];
    $check(!preg_match('/[a-zA-Z]{2,}"?:"/', $stored) && !str_contains($stored, 'code') && strlen($stored) <= 2048, 'only integers are stored, within 2 KB');

    // Permission
    $allowed = false;
    $r = $read($user);
    $check($r['rule'] === null && $r['context'] === null && $r['recent'] === [], 'no module permission: nothing returned');
    $check($throws(fn() => $service->save($user, $ids($first))), 'no module permission: save refused');
    $allowed = true;
} finally {
    $db->rollback();
}
echo $failures === 0 ? "PASS: working context service\n" : "FAILED: $failures\n";
exit($failures === 0 ? 0 : 1);
