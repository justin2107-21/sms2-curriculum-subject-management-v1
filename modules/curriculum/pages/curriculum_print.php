<?php
require_once __DIR__ . '/../../../shared/bootstrap.php';

if (!is_authenticated()) {
    header('Location: ../../../auth/signin.php');
    exit;
}
require_permission('curriculum_export.view');
require_once __DIR__ . '/../services/CurriculumExportService.php';

try {
    $service = new CurriculumExportService(authorization_connection());
    $report = $service->dataset($_GET);
    if ((int)$report['summary']['total_subjects'] > 0) {
        $service->recordPrintGeneration($report);
    }
} catch (DomainValidationException $error) {
    http_response_code(422);
    echo 'The selected curriculum export context is invalid.';
    exit;
} catch (DomainNotFoundException $error) {
    http_response_code(404);
    echo 'The selected curriculum version was not found.';
    exit;
} catch (Throwable $error) {
    log_application_error('Curriculum print view failed', $error);
    http_response_code(500);
    echo 'Unable to prepare the curriculum document at this time.';
    exit;
}

function curriculum_export_html(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function curriculum_export_units(mixed $value): string
{
    $formatted = number_format((float)$value, 2, '.', '');
    return curriculum_export_html(preg_replace('/\.00$/', '', $formatted));
}

$context = $report['context'];
$exportCssVersion = (int)filemtime(__DIR__ . '/../assets/curriculum-export.css');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require __DIR__ . '/../../../shared/favicon.php'; ?>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title><?= curriculum_export_html($context['curriculum_code']) ?> Curriculum</title>
  <link rel="stylesheet" href="../../../css/csms-typography.css"/>
  <link rel="stylesheet" href="../assets/curriculum-export.css?v=<?= $exportCssVersion ?>"/>
</head>
<body class="export-print-body">
  <nav class="export-screen-toolbar" aria-label="Print controls">
    <a href="curriculum_export.php">Back to Curriculum Export</a>
    <button type="button" onclick="window.print()">Print / Save as PDF</button>
  </nav>
  <?php if (!$report['summary']['total_subjects']): ?>
    <div class="export-empty-state"><p>No mapped subjects were found for the selected export scope.</p></div>
  <?php else: ?>
  <article class="export-report export-print-report">
    <header class="export-document-header">
      <p><?= curriculum_export_html(function_exists('mb_strtoupper') ? mb_strtoupper((string)$context['program_name'], 'UTF-8') : strtoupper((string)$context['program_name'])) ?></p>
      <h1>Curriculum Structure</h1>
      <div><?= curriculum_export_html($context['curriculum_code']) ?> · <?= curriculum_export_html($context['version_label']) ?></div>
      <h2><?= curriculum_export_html($context['curriculum_name']) ?></h2>
      <p class="export-print-summary">Export scope: <?= curriculum_export_html($report['scope']['label']) ?></p>
    </header>
    <?php foreach ($report['years'] as $year): ?>
      <section class="export-year">
        <h2><?= curriculum_export_html($year['year_level']) ?></h2>
        <?php foreach ($year['semesters'] as $semester): ?>
          <section class="export-semester">
            <h3><?= curriculum_export_html($semester['semester']) ?></h3>
            <div class="export-table-scroll"><table class="export-report-table">
              <thead><tr><th>Subject Code</th><th>Subject Title</th><th>Units</th><th>Type / Placement</th></tr></thead>
              <tbody><?php foreach ($semester['subjects'] as $subject): ?>
                <tr><td><strong><?= curriculum_export_html($subject['subject_code']) ?></strong></td><td><?= curriculum_export_html($subject['subject_title']) ?></td><td class="number-cell"><?= curriculum_export_units($subject['units']) ?></td><td><?= curriculum_export_html($subject['placement_type']) ?></td></tr>
              <?php endforeach; ?></tbody>
            </table></div>
            <p class="export-subtotal">Semester Units: <strong><?= curriculum_export_units($semester['total_units']) ?></strong></p>
          </section>
        <?php endforeach; ?>
        <p class="export-year-total">Year Total Units: <strong><?= curriculum_export_units($year['total_units']) ?></strong></p>
      </section>
    <?php endforeach; ?>
    <footer class="export-document-footer"><strong>Total Subjects: <?= (int)$report['summary']['total_subjects'] ?></strong><strong>Total Units: <?= curriculum_export_units($report['summary']['total_units']) ?></strong><p><?= curriculum_export_html($report['disclaimer']) ?></p><small>Generated from CSMS · <?= curriculum_export_html($report['generated_at']) ?></small></footer>
  </article>
  <?php endif; ?>
</body>
</html>
