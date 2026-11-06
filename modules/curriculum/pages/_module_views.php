<?php
// Links between the views of one module, shown under the page title: the sidebar has one link per module, so the
// other views are reached from here. Set $moduleViews (a key below) and $ACTIVE_NAV before including.
$moduleViewSets = [
    'assignment' => [['program-assignment-program', 'Program-centric view', 'program_centric_assignment.php'], ['program-assignment-subject', 'Subject-centric view', 'subject_centric_assignment.php']],
    'history' => [['subject-history-subject', 'Subject history', 'subject_history_view.php'], ['subject-history-curriculum', 'Curriculum version history', 'curriculum_history_view.php']],
    'equivalency' => [['subject-equivalency-comparison', 'Subject comparison', 'subject_comparison.php'], ['subject-equivalency-records', 'Equivalency records', 'equivalency_records.php']],
    'compliance' => [['compliance-validate', 'Validate curriculum', 'validate_curriculum.php'], ['compliance-rules', 'Compliance rules', 'compliance_rules.php'], ['compliance-references', 'References', 'compliance_references.php'], ['compliance-history', 'Validation history', 'validation_history.php']],
];
$moduleViewItems = $moduleViewSets[$moduleViews ?? ''] ?? [];
if (count($moduleViewItems) > 1): ?>
<nav class="csms-segmented module-views" aria-label="Views in this module">
<?php foreach ($moduleViewItems as [$viewKey, $viewLabelText, $viewFile]): $viewActive = ($ACTIVE_NAV ?? '') === $viewKey; ?>
  <a href="<?= htmlspecialchars($viewFile, ENT_QUOTES, 'UTF-8') ?>" class="<?= $viewActive ? 'is-active' : '' ?>"<?= $viewActive ? ' aria-current="page"' : '' ?>><?= htmlspecialchars($viewLabelText, ENT_QUOTES, 'UTF-8') ?></a>
<?php endforeach; ?>
</nav>
<?php endif;
