<?php
require_once __DIR__ . '/../../../shared/bootstrap.php';

if (!is_authenticated()) {
    header('Location: ../../../auth/signin.php');
    exit;
}
require_permission('curriculum_export.view');

$avatarInitial = strtoupper(substr((string)($_SESSION['first_name'] ?? 'U'), 0, 1));
$curriculumCssVersion = (int)filemtime(__DIR__ . '/../assets/curriculum.css');
$typographyCssVersion = (int)filemtime(__DIR__ . '/../../../css/csms-typography.css');
$exportCssVersion = (int)filemtime(__DIR__ . '/../assets/curriculum-export.css');
$commonJsVersion = substr(hash_file('sha256', __DIR__ . '/../assets/curriculum-common.js'), 0, 16);
$exportJsVersion = (int)filemtime(__DIR__ . '/../assets/curriculum-export.js');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require __DIR__ . '/../../../shared/favicon.php'; ?>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <meta name="csrf-token" content="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>"/>
  <title>Curriculum Export Tool - BCP Student Management System</title>
  <link rel="stylesheet" href="../../../css/dashboard.css"/>
  <link rel="stylesheet" href="../assets/curriculum.css?v=<?= $curriculumCssVersion ?>"/>
  <link rel="stylesheet" href="../assets/curriculum-export.css?v=<?= $exportCssVersion ?>"/>
  <link rel="stylesheet" href="../../../css/csms-typography.css?v=<?= $typographyCssVersion ?>"/>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"/>
</head>
<body>
<?php
$APP_ROOT = '../../../';
$ACTIVE_NAV = 'curriculum-export';
require __DIR__ . '/../../../shared/sidebar.php';
?>

<div class="main">
  <?php require __DIR__ . '/../../../shared/topbar.php'; ?>

  <main class="content curriculum-content export-content"
        id="curriculumExportWorkspace"
        data-export-api="../http/export_actions.php"
        data-print-page="curriculum_print.php">
    <div class="page-title-bar curriculum-title-bar">
      <div>
        <h1 class="page-title"><i class="fa-solid fa-file-export"></i> Curriculum Export Tool</h1>
        <p class="page-subtitle">Preview and generate generic reports from stored curriculum-version data.</p>
      </div>
      <span class="read-only-pill"><i class="fa-solid fa-database"></i> Stored data only</span>
    </div>

    <p class="curriculum-notice export-disclaimer" role="note">
      <i class="fa-solid fa-circle-info"></i>
      Generated output is a generic system report. It is not an official CHED, DepEd, accreditation, or institutional approval document.
    </p>

    <section class="domain-card export-context-card" aria-labelledby="exportContextTitle">
      <div class="domain-card-header">
        <div><p class="eyebrow">Report context</p><h2 id="exportContextTitle">Select a curriculum version</h2></div>
      </div>
      <div class="export-context-grid">
        <div class="mapping-filter-field"><label for="exportProgramSearch">Academic Program</label><div class="record-autocomplete"><input type="search" id="exportProgramSearch" placeholder="Search code or program name"/><input type="hidden" id="exportProgram"/><button type="button" class="record-autocomplete-clear" data-autocomplete-clear aria-label="Clear academic program" title="Clear selection" hidden><i class="fa-solid fa-xmark"></i></button><div class="record-autocomplete-options" id="exportProgramOptions" role="listbox" aria-label="Academic program suggestions" hidden></div></div></div>
        <div class="mapping-filter-field"><label for="exportCurriculumSearch">Curriculum</label><div class="record-autocomplete"><input type="search" id="exportCurriculumSearch" placeholder="Select a program first" disabled/><input type="hidden" id="exportCurriculum"/><button type="button" class="record-autocomplete-clear" data-autocomplete-clear aria-label="Clear curriculum" title="Clear selection" hidden><i class="fa-solid fa-xmark"></i></button><div class="record-autocomplete-options" id="exportCurriculumOptions" role="listbox" aria-label="Curriculum suggestions" hidden></div></div></div>
        <div class="mapping-filter-field"><label for="exportVersionSearch">Curriculum Version</label><div class="record-autocomplete"><input type="search" id="exportVersionSearch" placeholder="Select a curriculum first" disabled/><input type="hidden" id="exportVersion"/><button type="button" class="record-autocomplete-clear" data-autocomplete-clear aria-label="Clear curriculum version" title="Clear selection" hidden><i class="fa-solid fa-xmark"></i></button><div class="record-autocomplete-options" id="exportVersionOptions" role="listbox" aria-label="Curriculum version suggestions" hidden></div></div></div>
      </div>
    </section>

    <section class="domain-card export-options-card" aria-labelledby="exportOptionsTitle">
      <div class="domain-card-header">
        <div><p class="eyebrow">Report contents</p><h2 id="exportOptionsTitle">Choose optional sections</h2></div>
      </div>
      <div class="export-option-grid">
        <label class="export-option is-required"><input type="checkbox" checked disabled/><span><strong>Curriculum subjects</strong><small>Always included with placement and unit information.</small></span></label>
        <label class="export-option"><input type="checkbox" id="includeRequirements"/><span><strong>Prerequisites &amp; corequisites</strong><small>Configured relationships for this version.</small></span></label>
        <label class="export-option"><input type="checkbox" id="includeElectives"/><span><strong>Elective groups</strong><small>Stored group membership only.</small></span></label>
        <label class="export-option"><input type="checkbox" id="includeGradeWeighting"/><span><strong>Grade weighting</strong><small>Stored components and configuration totals.</small></span></label>
        <label class="export-option"><input type="checkbox" id="includeCompliance"/><span><strong>Latest validation summary</strong><small>Most recent stored deterministic run, if available.</small></span></label>
      </div>
      <div class="export-actions">
        <span class="export-action-status" id="exportActionStatus" role="status" aria-live="polite">Select a curriculum version to begin.</span>
        <div class="export-action-buttons">
          <button type="button" class="secondary-action-button" id="previewCurriculumExport" disabled><i class="fa-solid fa-eye"></i> Preview</button>
          <button type="button" class="secondary-action-button" id="printCurriculumExport" disabled><i class="fa-solid fa-print"></i> Print / Save as PDF</button>
          <button type="button" class="primary-action-button" id="downloadCurriculumCsv" disabled><i class="fa-solid fa-file-csv"></i> Download CSV</button>
        </div>
      </div>
    </section>

    <section class="export-preview-shell" aria-labelledby="exportPreviewTitle">
      <div class="export-preview-heading"><div><p class="eyebrow">Generic report preview</p><h2 id="exportPreviewTitle">Curriculum Report</h2></div></div>
      <div id="curriculumExportPreview" class="export-report-host">
        <div class="export-empty-state"><i class="fa-regular fa-file-lines"></i><h3>No report preview yet</h3><p>Select a curriculum version, choose any optional sections, then click Preview.</p></div>
      </div>
    </section>
  </main>
</div>

<div class="sidebar-overlay" id="sidebarOverlay"></div>
<script src="../assets/curriculum-common.js?v=<?= $commonJsVersion ?>"></script>
<script src="../assets/curriculum-export.js?v=<?= $exportJsVersion ?>"></script>
</body>
</html>
