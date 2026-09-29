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
    echo 'Unable to prepare the curriculum report at this time.';
    exit;
}

function export_html(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function export_display(mixed $value): string
{
    return $value === null || $value === '' ? '&mdash;' : export_html($value);
}

function export_units(mixed $value): string
{
    if ($value === null || $value === '') {
        return '&mdash;';
    }
    $formatted = number_format((float)$value, 2, '.', '');
    return export_html(preg_replace('/\.00$/', '', $formatted));
}

function export_subject_groups(array $subjects): array
{
    $groups = [];
    foreach ($subjects as $subject) {
        $year = $subject['year_level'] ?: 'Not specified';
        $term = $subject['semester'] ?: 'Not specified';
        $key = $year . "\0" . $term;
        if (!isset($groups[$key])) {
            $groups[$key] = ['year' => $year, 'term' => $term, 'subjects' => []];
        }
        $groups[$key]['subjects'][] = $subject;
    }
    return array_values($groups);
}

$context = $report['context'];
$subjectGroups = export_subject_groups($report['subjects']);
$exportCssVersion = (int)filemtime(__DIR__ . '/../assets/curriculum-export.css');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require __DIR__ . '/../../../shared/favicon.php'; ?>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title><?= export_html($context['curriculum_code']) ?> Curriculum Report</title>
  <link rel="stylesheet" href="../assets/curriculum-export.css?v=<?= $exportCssVersion ?>"/>
</head>
<body class="export-print-body">
  <nav class="export-screen-toolbar" aria-label="Print controls">
    <a href="curriculum_export.php">Back to Curriculum Export</a>
    <button type="button" onclick="window.print()">Print / Save as PDF</button>
  </nav>

  <article class="export-report">
    <header class="export-report-header">
      <p class="export-report-kicker">Generic system report</p>
      <h1 class="export-report-title"><?= export_display($context['curriculum_name']) ?></h1>
      <p class="export-report-subtitle">Curriculum &amp; Subject Management</p>
    </header>

    <div class="export-context-summary">
      <div class="export-context-item"><span>Academic Program</span><strong><?= export_html($context['program_code']) ?> &mdash; <?= export_html($context['program_name']) ?></strong></div>
      <div class="export-context-item"><span>Program Type</span><strong><?= export_display($context['program_type']) ?></strong></div>
      <div class="export-context-item"><span>Curriculum</span><strong><?= export_html($context['curriculum_code']) ?> &mdash; <?= export_html($context['curriculum_name']) ?></strong></div>
      <div class="export-context-item"><span>Curriculum Version</span><strong><?= export_display($context['version_label']) ?></strong></div>
      <div class="export-context-item"><span>Effective Academic Year</span><strong><?= export_display($context['effective_academic_year']) ?></strong></div>
      <div class="export-context-item"><span>Technical Version Status</span><strong><?= export_display($context['version_status']) ?></strong></div>
    </div>

    <div class="export-unit-summary">
      <div><span>Subjects</span><strong><?= export_html($report['summary']['total_subjects']) ?></strong></div>
      <div><span>Total Units</span><strong><?= export_units($report['summary']['total_units']) ?></strong></div>
      <div><span>Lecture Units</span><strong><?= export_units($report['summary']['total_lecture_units']) ?></strong></div>
      <div><span>Laboratory Units</span><strong><?= export_units($report['summary']['total_laboratory_units']) ?></strong></div>
    </div>

    <section class="export-report-section">
      <h2>Curriculum Subjects</h2>
      <p class="export-section-note">Year-level and semester/term groups use the stored text values and lexical record order.</p>
      <?php if (!$subjectGroups): ?>
        <div class="export-table-scroll"><table class="export-report-table"><tbody><tr><td class="empty-report-cell">No subjects are mapped to this curriculum version.</td></tr></tbody></table></div>
      <?php else: ?>
        <?php foreach ($subjectGroups as $group): ?>
          <h3>Year Level: <?= export_html($group['year']) ?> &nbsp;&bull;&nbsp; Semester / Term: <?= export_html($group['term']) ?></h3>
          <div class="export-table-scroll">
            <table class="export-report-table">
              <thead><tr><th>Subject Code</th><th>Subject Title</th><th>Total Units</th><th>Lecture</th><th>Laboratory</th><th>Sequence</th><th>Placement</th></tr></thead>
              <tbody>
              <?php foreach ($group['subjects'] as $subject): ?>
                <tr><td><strong><?= export_display($subject['subject_code']) ?></strong></td><td><?= export_display($subject['subject_title']) ?></td><td class="number-cell"><?= export_units($subject['units']) ?></td><td class="number-cell"><?= export_units($subject['lecture_units']) ?></td><td class="number-cell"><?= export_units($subject['laboratory_units']) ?></td><td class="number-cell"><?= export_display($subject['sequence_order']) ?></td><td><?= (int)$subject['is_required'] === 1 ? 'Required Placement' : 'Optional Placement' ?></td></tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </section>

    <?php if ($report['options']['requirements']): ?>
    <section class="export-report-section">
      <h2>Prerequisite &amp; Corequisite Relationships</h2>
      <div class="export-table-scroll"><table class="export-report-table"><thead><tr><th>Target Subject</th><th>Target Title</th><th>Relationship</th><th>Required Subject</th><th>Required Title</th></tr></thead><tbody>
      <?php if (!$report['requirements']): ?><tr><td colspan="5" class="empty-report-cell">No prerequisite or corequisite relationships are configured.</td></tr><?php endif; ?>
      <?php foreach ($report['requirements'] as $item): ?><tr><td><?= export_display($item['subject_code']) ?></td><td><?= export_display($item['subject_title']) ?></td><td><?= $item['requirement_type'] === 'corequisite' ? 'Corequisite' : 'Prerequisite' ?></td><td><?= export_display($item['required_subject_code']) ?></td><td><?= export_display($item['required_subject_title']) ?></td></tr><?php endforeach; ?>
      </tbody></table></div>
    </section>
    <?php endif; ?>

    <?php if ($report['options']['electives']): ?>
    <section class="export-report-section">
      <h2>Elective Groups</h2><p class="export-section-note">This section reports stored elective-group membership only.</p>
      <div class="export-table-scroll"><table class="export-report-table"><thead><tr><th>Group</th><th>Description</th><th>Subject Code</th><th>Subject Title</th></tr></thead><tbody>
      <?php if (!$report['elective_groups']): ?><tr><td colspan="4" class="empty-report-cell">No elective groups are configured for this curriculum version.</td></tr><?php endif; ?>
      <?php foreach ($report['elective_groups'] as $group): ?>
        <?php if (!$group['subjects']): ?><tr><td><?= export_display($group['group_name']) ?></td><td><?= export_display($group['description']) ?></td><td>&mdash;</td><td>&mdash;</td></tr><?php endif; ?>
        <?php foreach ($group['subjects'] as $subject): ?><tr><td><?= export_display($group['group_name']) ?></td><td><?= export_display($group['description']) ?></td><td><?= export_display($subject['subject_code']) ?></td><td><?= export_display($subject['subject_title']) ?></td></tr><?php endforeach; ?>
      <?php endforeach; ?>
      </tbody></table></div>
    </section>
    <?php endif; ?>

    <?php if ($report['options']['grade_weighting']): ?>
    <section class="export-report-section">
      <h2>Grade Weighting Configuration</h2>
      <div class="export-table-scroll"><table class="export-report-table"><thead><tr><th>Subject Code</th><th>Subject Title</th><th>Component</th><th>Weight</th><th>Total</th><th>Technical Status</th></tr></thead><tbody>
      <?php if (!$report['grade_weighting']): ?><tr><td colspan="6" class="empty-report-cell">No grade-weighting configurations are stored for this curriculum version.</td></tr><?php endif; ?>
      <?php foreach ($report['grade_weighting'] as $scheme): ?>
        <?php if (!$scheme['components']): ?><tr><td><?= export_display($scheme['subject_code']) ?></td><td><?= export_display($scheme['subject_title']) ?></td><td>&mdash;</td><td>&mdash;</td><td class="number-cell"><?= export_units($scheme['total_weight']) ?>%</td><td><?= export_html(ucfirst($scheme['completion_status'])) ?></td></tr><?php endif; ?>
        <?php foreach ($scheme['components'] as $component): ?><tr><td><?= export_display($scheme['subject_code']) ?></td><td><?= export_display($scheme['subject_title']) ?></td><td><?= export_display($component['component_name']) ?></td><td class="number-cell"><?= export_units($component['weight_percentage']) ?>%</td><td class="number-cell"><?= export_units($scheme['total_weight']) ?>%</td><td><?= export_html(ucfirst($scheme['completion_status'])) ?></td></tr><?php endforeach; ?>
      <?php endforeach; ?>
      </tbody></table></div>
    </section>
    <?php endif; ?>

    <?php if ($report['options']['compliance']): ?>
    <section class="export-report-section">
      <h2>Latest Stored Compliance Validation Summary</h2>
      <?php if ($report['latest_compliance_run'] === null): ?>
        <p class="export-section-note">No stored validation run is available for this curriculum version.</p>
      <?php else: $run = $report['latest_compliance_run']; ?>
        <p class="export-section-note">This is stored internal decision-support output and is not an official regulatory determination.</p>
        <div class="export-table-scroll"><table class="export-report-table"><thead><tr><th>Run Date</th><th>Rules Evaluated</th><th>Passed</th><th>Failed</th><th>Not Evaluated</th><th>Run By</th></tr></thead><tbody><tr><td><?= export_display($run['created_at']) ?></td><td class="number-cell"><?= export_display($run['rules_evaluated']) ?></td><td class="number-cell"><?= export_display($run['passed_count']) ?></td><td class="number-cell"><?= export_display($run['failed_count']) ?></td><td class="number-cell"><?= export_display($run['not_evaluated_count']) ?></td><td><?= export_display($run['run_by_name']) ?></td></tr></tbody></table></div>
      <?php endif; ?>
    </section>
    <?php endif; ?>

    <p class="export-report-notice"><?= export_html($report['disclaimer']) ?></p>
    <p class="export-generated-at">Generated <?= export_html($report['generated_at']) ?></p>
  </article>
</body>
</html>
