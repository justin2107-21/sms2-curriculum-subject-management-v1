<?php
require_once __DIR__ . '/../../../shared/bootstrap.php';
require_once __DIR__ . '/../services/CurriculumExportService.php';

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
        data-export-api="../http/export_actions.php">
    <div class="page-title-bar curriculum-title-bar">
      <div><h1 class="page-title">Curriculum Export</h1><p class="page-subtitle">Download the stored curriculum as CSV or PDF, or print it. Generated from stored curriculum data.</p></div>
    </div>

    <section class="mapping-workspace-shell export-workspace">
    <div class="export-top">
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
    <div class="export-actions">
        <div class="csms-filter-row export-toolbar">
          <select class="csms-select" id="exportYear" aria-label="Year level" disabled><option value="">All year levels</option></select>
          <select class="csms-select" id="exportSemester" aria-label="Semester or term" disabled><option value="">All semesters / terms</option></select>
          <button type="button" class="primary-action-button" id="downloadCurriculumCsv" disabled><i class="fa-solid fa-file-csv" aria-hidden="true"></i> Download CSV</button>
          <button type="button" class="primary-action-button" id="downloadCurriculumPdf" disabled><i class="fa-solid fa-file-pdf" aria-hidden="true"></i> Download PDF</button>
          <button type="button" class="button-muted" id="printCurriculumExport" disabled><i class="fa-solid fa-print" aria-hidden="true"></i> Print</button>
        </div>
        <div class="export-protect">
          <p class="export-protect-row"><label><input type="checkbox" id="exportProtect"/> Protect with password</label> <span class="csms-info"><button type="button" class="csms-info__btn" aria-label="About password protection" aria-describedby="exportProtectInfoTip"><i class="fa-solid fa-circle-info" aria-hidden="true"></i></button><span class="csms-info__tip" role="tooltip" id="exportProtectInfoTip">Applies to Download CSV and Download PDF: you get a ZIP encrypted with AES-256 that contains the file. Open it with 7-Zip, WinRAR or WinZip. As far as we know, Windows File Explorer cannot open AES-encrypted ZIPs, so 7-Zip is the safe choice. The password is used once and is not stored: if it is lost the file cannot be opened and the export must be generated again. Use at least <?= (int)CurriculumExportService::MIN_EXPORT_PASSWORD ?> characters. Print is not affected.</span></span></p>
          <div class="export-protect-fields" id="exportProtectFields" hidden>
            <label class="form-field" for="exportPassword"><span>Password</span><span class="export-pw"><input type="password" id="exportPassword" name="csms_export_secret" autocomplete="new-password" data-lpignore="true" data-1p-ignore data-form-type="other" aria-describedby="exportPasswordHint"/><button type="button" class="export-pw__toggle" data-pw-toggle="exportPassword" aria-label="Show password" aria-pressed="false"><i class="fa-solid fa-eye" aria-hidden="true"></i></button></span></label>
            <label class="form-field" for="exportPasswordConfirm"><span>Confirm password</span><span class="export-pw"><input type="password" id="exportPasswordConfirm" name="csms_export_secret_confirm" autocomplete="new-password" data-lpignore="true" data-1p-ignore data-form-type="other"/><button type="button" class="export-pw__toggle" data-pw-toggle="exportPasswordConfirm" aria-label="Show confirmation" aria-pressed="false"><i class="fa-solid fa-eye" aria-hidden="true"></i></button></span></label>
            <small class="export-pw-hint" id="exportPasswordHint"><?= (int)CurriculumExportService::MIN_EXPORT_PASSWORD ?>-<?= (int)CurriculumExportService::MAX_EXPORT_PASSWORD ?> characters</small>
          </div>
        </div>
        <p class="export-error" id="exportPasswordError" role="alert" hidden></p>
        <p class="export-action-status" id="exportActionStatus" role="status" aria-live="polite"></p>
    </div>
    </div>
    <section class="mapping-workspace-panel csms-panel" aria-label="Curriculum export">
      <div class="csms-empty" id="exportEmpty" role="status"><i class="fa-solid fa-bullseye" aria-hidden="true"></i><strong>No curriculum version selected</strong><span>Use the context bar above to pick a curriculum version.</span></div>
      <div id="exportPanel" hidden>
        <div class="export-preview">
          <div class="export-preview-head">
            <div class="export-preview-head__main"><h2 id="exportPreviewTitle">Curriculum Structure</h2><p id="exportPreviewSummary" class="export-preview-summary" role="status"></p></div>
            <div class="export-preview-head__scope"><span id="exportPreviewScope"></span> <span class="csms-chip" id="exportPreviewStatus" hidden></span></div>
          <div id="curriculumExportPreview" class="export-report-host"></div>
        </div>
      </div>
    </section>
    </section>
  </main>
</div>
<div class="sidebar-overlay" id="sidebarOverlay"></div>
<script src="../assets/curriculum-common.js?v=<?= $commonJsVersion ?>"></script>
<script src="../assets/curriculum-export.js?v=<?= $exportJsVersion ?>"></script>
</body>
</html>
