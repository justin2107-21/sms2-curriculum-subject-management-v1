<?php
require_once __DIR__ . '/../../../shared/bootstrap.php';

if (!is_authenticated()) {
    header('Location: ../../../auth/signin.php');
    exit;
}
require_permission('curriculum_export.view');

$curriculumCssVersion = (int)filemtime(__DIR__ . '/../assets/curriculum.css');
$mappingCssVersion = (int)filemtime(__DIR__ . '/../assets/subject-mapping.css');
$mappingFinalCssVersion = (int)filemtime(__DIR__ . '/../assets/subject-mapping-final.css');
$typographyCssVersion = (int)filemtime(__DIR__ . '/../../../css/csms-typography.css');
$exportCssVersion = (int)filemtime(__DIR__ . '/../assets/curriculum-export.css');
$commonJsVersion = (int)filemtime(__DIR__ . '/../assets/curriculum-common.js');
$exportJsVersion = (int)filemtime(__DIR__ . '/../assets/curriculum-export.js');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require __DIR__ . '/../../../shared/favicon.php'; ?>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <meta name="csrf-token" content="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>"/>
  <title>Curriculum Export - BCP Student Management System</title>
  <link rel="stylesheet" href="../../../css/dashboard.css"/>
  <link rel="stylesheet" href="../assets/curriculum.css?v=<?= $curriculumCssVersion ?>"/>
  <link rel="stylesheet" href="../assets/subject-mapping.css?v=<?= $mappingCssVersion ?>"/>
  <link rel="stylesheet" href="../../../css/csms-typography.css?v=<?= $typographyCssVersion ?>"/>
  <link rel="stylesheet" href="../assets/subject-mapping-final.css?v=<?= $mappingFinalCssVersion ?>"/>
  <link rel="stylesheet" href="../assets/curriculum-export.css?v=<?= $exportCssVersion ?>"/>
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
  <main class="content curriculum-content mapping-content export-content" id="curriculumExportWorkspace"
        data-export-api="../http/export_actions.php" data-print-page="curriculum_print.php">
    <div class="page-title-bar curriculum-title-bar">
      <div><h1 class="page-title">Curriculum Export</h1><p class="page-subtitle">Generate a clean curriculum document for review or sharing.</p></div>
    </div>

    <section class="mapping-workspace-shell export-workspace">
    <section class="mapping-context-bar export-context-picker" id="exportContextPicker" aria-label="Select curriculum context">
      <label class="mapping-context-field"><span>Program / Strand</span><div class="record-autocomplete">
        <input type="search" id="exportProgramSearch" placeholder="Search code or program name"/><input type="hidden" id="exportProgram"/>
        <button type="button" class="record-autocomplete-clear" data-autocomplete-clear aria-label="Clear program" hidden><i class="fa-solid fa-xmark" aria-hidden="true"></i></button><div class="record-autocomplete-options" id="exportProgramOptions" role="listbox" aria-label="Program suggestions" hidden></div>
      </div></label>
      <span class="mapping-context-separator" aria-hidden="true"><i class="fa-solid fa-chevron-right" aria-hidden="true"></i></span>
      <label class="mapping-context-field"><span>Curriculum</span><div class="record-autocomplete">
        <input type="search" id="exportCurriculumSearch" placeholder="Select a program first" disabled/><input type="hidden" id="exportCurriculum"/>
        <button type="button" class="record-autocomplete-clear" data-autocomplete-clear aria-label="Clear curriculum" hidden><i class="fa-solid fa-xmark" aria-hidden="true"></i></button><div class="record-autocomplete-options" id="exportCurriculumOptions" role="listbox" aria-label="Curriculum suggestions" hidden></div>
      </div></label>
      <span class="mapping-context-separator" aria-hidden="true"><i class="fa-solid fa-chevron-right" aria-hidden="true"></i></span>
      <label class="mapping-context-field"><span>Curriculum Version</span><div class="record-autocomplete">
        <input type="search" id="exportVersionSearch" placeholder="Select a curriculum first" disabled/><input type="hidden" id="exportVersion"/>
        <button type="button" class="record-autocomplete-clear" data-autocomplete-clear aria-label="Clear curriculum version" hidden><i class="fa-solid fa-xmark" aria-hidden="true"></i></button><div class="record-autocomplete-options" id="exportVersionOptions" role="listbox" aria-label="Version suggestions" hidden></div>
      </div></label>
    </section>
    <section class="domain-card export-scope-card" aria-label="Export scope">
      <div class="export-scope-heading"><h2>Export Scope</h2><span class="status-badge status-inactive" id="exportScopeSummary">Entire Curriculum</span></div>
      <div class="export-scope-grid">
        <label class="mapping-context-field" for="exportYear"><span>Year Level / Grade Level</span><span class="select-control"><select id="exportYear" disabled><option value="">All Year Levels</option></select></span></label>
        <label class="mapping-context-field" for="exportSemester"><span>Semester / Term</span><span class="select-control"><select id="exportSemester" disabled><option value="">All Semesters / Terms</option></select></span></label>
      </div>
    </section>
    <div class="mapping-workspace-tabs export-tabs" role="tablist" aria-label="Curriculum export format">
      <button type="button" class="export-tab is-active" role="tab" id="exportTabPreview" aria-selected="true" aria-controls="exportPanelPreview" data-export-tab="preview">Preview</button>
      <button type="button" class="export-tab" role="tab" id="exportTabCsv" aria-selected="false" aria-controls="exportPanelCsv" data-export-tab="csv">CSV</button>
      <button type="button" class="export-tab" role="tab" id="exportTabPrint" aria-selected="false" aria-controls="exportPanelPrint" data-export-tab="print">Print / PDF</button>
    </div>

    <section class="export-tab-panel" id="exportPanelPreview" role="tabpanel" aria-labelledby="exportTabPreview">
      <div class="domain-card export-preview-card">
        <div class="export-preview-heading"><div><p class="eyebrow">Document Preview</p><h2 id="exportPreviewTitle">Curriculum Structure</h2><p id="exportPreviewSummary" class="export-preview-summary"></p></div>
          <button type="button" class="primary-action-button" id="generateCurriculumPreview" disabled><i class="fa-solid fa-rotate" aria-hidden="true"></i> Generate</button></div>
        <div id="curriculumExportPreview" class="export-report-host"><div class="export-empty-state"><p>Select a Program / Strand, Curriculum, and Curriculum Version to preview an export.</p></div></div>
      </div>
    </section>

    <section class="export-tab-panel" id="exportPanelCsv" role="tabpanel" aria-labelledby="exportTabCsv" hidden>
      <div class="domain-card export-format-card"><div><h2>CSV Export</h2><p>Download the selected curriculum scope's stored subjects, placements, and units as a UTF-8 CSV file.</p><p class="export-format-scope">Current scope: <strong data-scope-label>Entire Curriculum</strong></p></div><button type="button" class="primary-action-button" id="downloadCurriculumCsv" disabled><i class="fa-solid fa-download" aria-hidden="true"></i> Download CSV</button></div>
    </section>

    <section class="export-tab-panel" id="exportPanelPrint" role="tabpanel" aria-labelledby="exportTabPrint" hidden>
      <div class="domain-card export-format-card"><div><h2>Print / Save as PDF</h2><p>Open a print-ready version of the currently selected curriculum scope.</p><p class="export-format-scope">Current scope: <strong data-scope-label>Entire Curriculum</strong></p></div><button type="button" class="primary-action-button" id="printCurriculumExport" disabled><i class="fa-solid fa-print" aria-hidden="true"></i> Print Curriculum</button></div>
    </section>
    <p class="export-action-status" id="exportActionStatus" role="status" aria-live="polite">Select a Program / Strand, Curriculum, and Curriculum Version to preview an export.</p>
    </section>
  </main>
</div>
<div class="sidebar-overlay" id="sidebarOverlay"></div>
<script src="../assets/curriculum-common.js?v=<?= $commonJsVersion ?>"></script>
<script src="../assets/curriculum-export.js?v=<?= $exportJsVersion ?>"></script>
</body>
</html>
