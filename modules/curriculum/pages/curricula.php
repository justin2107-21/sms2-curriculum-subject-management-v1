<?php
// The classic Curricula list is retired (Curriculum Builder, slice 5). Old addresses keep working: they go to the
// Builder with whatever context they carried (program_id, curriculum_id or id, version_id). The Builder checks
// permissions and resolves a partial address through the working context.
require_once __DIR__ . '/../../../shared/bootstrap.php';

$ids = [];
foreach (['program_id' => 'program_id', 'curriculum_id' => 'curriculum_id', 'id' => 'curriculum_id', 'version_id' => 'version_id'] as $from => $to) {
    $value = filter_var($_GET[$from] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($value !== false && !isset($ids[$to])) $ids[$to] = $value;
}
header('Location: curriculum_builder.php' . ($ids ? '?' . http_build_query($ids) : ''), true, 302);
exit;
