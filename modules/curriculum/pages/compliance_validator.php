<?php
$complianceView = $complianceView ?? null;

if ($complianceView === null) {
    $legacyQuery = $_GET;
    $legacyView = strtolower(trim((string)($legacyQuery['view'] ?? $legacyQuery['tab'] ?? 'validate')));
    unset($legacyQuery['view'], $legacyQuery['tab']);
    $legacyTargets = [
        'rules' => 'compliance_rules.php',
        'references' => 'compliance_references.php',
        'history' => 'validation_history.php',
    ];
    $legacyTarget = $legacyTargets[$legacyView] ?? 'validate_curriculum.php';
    $legacyQueryString = http_build_query($legacyQuery);
    header('Location: ' . $legacyTarget . ($legacyQueryString !== '' ? '?' . $legacyQueryString : ''), true, 302);
    exit;
}

if (!in_array($complianceView, ['validate', 'rules', 'references', 'history'], true)) {
    http_response_code(404);
    exit('Page not found.');
}

require_once __DIR__ . '/../../../shared/bootstrap.php';

if (!is_authenticated()) {
    header('Location: ../../../auth/signin.php');
    exit;
}
require_permission('compliance.view');

$complianceViewLabels = [
    'validate' => 'Validate Curriculum',
    'rules' => 'Compliance Rules',
    'references' => 'References',
    'history' => 'Validation History',
];
$complianceViewDescriptions = [
    'validate' => 'Run deterministic configured checks against a selected curriculum version.',
    'rules' => 'Manage the deterministic rules used by curriculum validation.',
    'references' => 'Manage traceable source records used by compliance rules.',
    'history' => 'Review append-only snapshots of previous curriculum validation runs.',
];
$viewLabel = $complianceViewLabels[$complianceView];
$viewDescription = $complianceViewDescriptions[$complianceView];
$userId = current_user_id();
$canRunCompliance = $userId !== null && user_can($userId, 'compliance.run');
$canManageCompliance = $userId !== null && user_can($userId, 'compliance.manage');
$canVerifyReferences = $userId !== null && user_can($userId, 'compliance.reference.verify');
$avatarInitial = strtoupper(substr((string)($_SESSION['first_name'] ?? 'U'), 0, 1));
$curriculumCssVersion = (int)filemtime(__DIR__ . '/../assets/curriculum.css');
$typographyCssVersion = (int)filemtime(__DIR__ . '/../../../css/csms-typography.css');
$mappingCssVersion = (int)filemtime(__DIR__ . '/../assets/subject-mapping.css');
$complianceCssVersion = (int)filemtime(__DIR__ . '/../assets/compliance-validator.css');
$commonJsVersion = (int)filemtime(__DIR__ . '/../assets/curriculum-common.js');
$complianceJsVersion = (int)filemtime(__DIR__ . '/../assets/compliance-validator.js');
$ruleColumns = $canManageCompliance ? 8 : 7;
$referenceColumns = ($canManageCompliance || $canVerifyReferences) ? 7 : 6;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <?php require __DIR__ . '/../../../shared/favicon.php'; ?>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <meta name="csrf-token" content="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>"/>
  <title><?= htmlspecialchars($viewLabel, ENT_QUOTES, 'UTF-8') ?> - BCP Student Management System</title>
  <link rel="stylesheet" href="../../../css/dashboard.css"/>
  <link rel="stylesheet" href="../assets/curriculum.css?v=<?= $curriculumCssVersion ?>"/>
  <link rel="stylesheet" href="../assets/subject-mapping.css?v=<?= $mappingCssVersion ?>"/>
  <link rel="stylesheet" href="../assets/compliance-validator.css?v=<?= $complianceCssVersion ?>"/>
  <link rel="stylesheet" href="../../../css/csms-typography.css?v=<?= $typographyCssVersion ?>"/>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"/>
</head>
<body>
<?php
$APP_ROOT = '../../../';
$ACTIVE_NAV = 'compliance-' . $complianceView;
require __DIR__ . '/../../../shared/sidebar.php';
?>

<div class="main">
  <?php require __DIR__ . '/../../../shared/topbar.php'; ?>

  <main class="content curriculum-content mapping-content compliance-content"
        id="complianceWorkspace"
        data-compliance-api="../http/compliance_actions.php"
        data-compliance-view="<?= htmlspecialchars($complianceView, ENT_QUOTES, 'UTF-8') ?>"
        data-can-run="<?= $canRunCompliance ? '1' : '0' ?>"
        data-can-manage="<?= $canManageCompliance ? '1' : '0' ?>"
        data-can-verify-references="<?= $canVerifyReferences ? '1' : '0' ?>"
        data-rule-columns="<?= $ruleColumns ?>"
        data-reference-columns="<?= $referenceColumns ?>">
    <div class="page-title-bar curriculum-title-bar">
      <div>
        <h1 class="page-title"><i class="fa-solid fa-shield-halved"></i> <?= htmlspecialchars($viewLabel, ENT_QUOTES, 'UTF-8') ?></h1>
        <p class="page-subtitle"><?= htmlspecialchars($viewDescription, ENT_QUOTES, 'UTF-8') ?></p>
      </div>
      <span class="read-only-pill"><i class="fa-solid fa-list-check"></i> Deterministic checks</span>
    </div>

    <p class="curriculum-notice compliance-disclaimer" role="note">
      <i class="fa-solid fa-circle-info"></i>
      Results are internal decision-support output only. They are not official CHED, DepEd, or Bestlink College approval, certification, or legal advice.
    </p>

    <?php if ($complianceView === 'validate'): ?>
    <section class="compliance-panel" id="validatePanel">
      <section class="domain-card mapping-filter-card" aria-labelledby="validationContextTitle">
        <div class="domain-card-header">
          <div><p class="eyebrow">Validation context</p><h2 id="validationContextTitle">Select a curriculum version</h2></div>
          <?php if ($canRunCompliance): ?><button type="button" class="primary-action-button" id="runComplianceValidation" disabled><i class="fa-solid fa-play"></i> Run Validation</button><?php endif; ?>
        </div>
        <div class="mapping-filter-grid compliance-context-grid">
          <label class="mapping-filter-field"><span>Academic Program</span><select id="complianceProgram"><option value="">Loading programs...</option></select></label>
          <label class="mapping-filter-field"><span>Curriculum</span><select id="complianceCurriculum" disabled><option value="">Select a program first</option></select></label>
          <label class="mapping-filter-field"><span>Curriculum Version</span><select id="complianceVersion" disabled><option value="">Select a curriculum first</option></select></label>
        </div>
      </section>

      <section class="domain-card compliance-results-card" aria-labelledby="validationResultsTitle">
        <div class="domain-card-header"><div><p class="eyebrow">Stored validation output</p><h2 id="validationResultsTitle">Validation Results</h2><p class="mapping-context-message" id="validationContextMessage">Select a curriculum version to run configured checks.</p></div></div>
        <div class="compliance-summary" id="complianceSummary" hidden>
          <div><span>Rules Evaluated</span><strong id="summaryEvaluated">0</strong></div>
          <div class="is-pass"><span>Pass</span><strong id="summaryPassed">0</strong></div>
          <div class="is-fail"><span>Fail</span><strong id="summaryFailed">0</strong></div>
          <div class="is-neutral"><span>Not Evaluated</span><strong id="summaryNotEvaluated">0</strong></div>
        </div>
        <div class="table-scroll" tabindex="0" aria-label="Scrollable compliance results">
          <table class="domain-table compliance-results-table"><thead><tr><th>Rule</th><th>Reference</th><th>Requirement</th><th>Actual</th><th>Result</th><th>Explanation</th></tr></thead><tbody id="complianceResultRows"><tr><td colspan="6" class="empty-cell">No validation has been run in this workspace.</td></tr></tbody></table>
        </div>
      </section>
    </section>

    <?php elseif ($complianceView === 'rules'): ?>
    <section class="compliance-panel" id="rulesPanel">
      <section class="domain-card compliance-list-card">
        <div class="domain-card-header"><div><p class="eyebrow">Configurable deterministic checks</p><h2>Compliance Rules</h2><p class="mapping-context-message">Only active rules linked to verified references are evaluated.</p></div><?php if ($canManageCompliance): ?><button type="button" class="primary-action-button" id="newComplianceRule"><i class="fa-solid fa-plus"></i> New Rule</button><?php endif; ?></div>
        <div class="list-toolbar compliance-toolbar">
          <label class="search-control"><span class="sr-only">Search rules</span><i class="fa-solid fa-magnifying-glass"></i><input type="search" id="ruleSearch" maxlength="100" placeholder="Rule or reference"/></label>
          <label class="select-control"><span>Type</span><select id="ruleTypeFilter"><option value="">All types</option><option value="MIN_TOTAL_UNITS">Minimum total units</option><option value="MAX_TOTAL_UNITS">Maximum total units</option><option value="REQUIRED_SUBJECT">Required subject</option><option value="MIN_SUBJECT_UNITS">Minimum subject units</option><option value="MAX_SUBJECT_UNITS">Maximum subject units</option></select></label>
          <label class="select-control"><span>Status</span><select id="ruleStatusFilter"><option value="">All</option><option value="active">Active</option><option value="inactive">Inactive</option></select></label>
          <label class="select-control compact-select"><span>Rows</span><select id="rulePerPage"><option value="10">10</option><option value="20" selected>20</option><option value="50">50</option></select></label>
        </div>
        <div class="table-scroll" tabindex="0" aria-label="Scrollable compliance rules"><table class="domain-table compliance-rules-table"><thead><tr><th>Rule</th><th>Reference</th><th>Type</th><th>Scope</th><th>Configured Parameter</th><th>Status</th><th>History</th><?php if ($canManageCompliance): ?><th>Actions</th><?php endif; ?></tr></thead><tbody id="complianceRuleRows"><tr><td colspan="<?= $ruleColumns ?>" class="empty-cell">Loading compliance rules...</td></tr></tbody></table></div>
        <div class="pagination-bar" id="rulePagination"></div>
      </section>
    </section>

    <?php elseif ($complianceView === 'references'): ?>
    <section class="compliance-panel" id="referencesPanel">
      <section class="domain-card compliance-list-card">
        <div class="domain-card-header"><div><p class="eyebrow">Traceable source registry</p><h2>Compliance References</h2><p class="mapping-context-message">“Verified” means internally confirmed as the intended source; it is not external regulatory certification.</p></div><?php if ($canManageCompliance): ?><button type="button" class="primary-action-button" id="newComplianceReference"><i class="fa-solid fa-plus"></i> New Reference</button><?php endif; ?></div>
        <div class="list-toolbar compliance-toolbar">
          <label class="search-control"><span class="sr-only">Search references</span><i class="fa-solid fa-magnifying-glass"></i><input type="search" id="referenceSearch" maxlength="100" placeholder="Code, title, or issuing body"/></label>
          <label class="select-control"><span>Category</span><select id="referenceTypeFilter"><option value="">All</option><option value="CHED">CHED</option><option value="DEPED">DepEd</option><option value="INSTITUTIONAL">Institutional</option><option value="OTHER">Other</option></select></label>
          <label class="select-control"><span>Status</span><select id="referenceStatusFilter"><option value="">All</option><option value="draft">Draft</option><option value="verified">Verified</option><option value="inactive">Inactive</option></select></label>
          <label class="select-control compact-select"><span>Rows</span><select id="referencePerPage"><option value="10">10</option><option value="20" selected>20</option><option value="50">50</option></select></label>
        </div>
        <div class="table-scroll" tabindex="0" aria-label="Scrollable compliance references"><table class="domain-table compliance-reference-table"><thead><tr><th>Reference</th><th>Category / source kind</th><th>Issuing Body</th><th>Status</th><th>Source</th><th>Rules</th><?php if ($canManageCompliance || $canVerifyReferences): ?><th>Actions</th><?php endif; ?></tr></thead><tbody id="complianceReferenceRows"><tr><td colspan="<?= $referenceColumns ?>" class="empty-cell">Loading compliance references...</td></tr></tbody></table></div>
        <div class="pagination-bar" id="referencePagination"></div>
      </section>
    </section>

    <?php else: ?>
    <section class="compliance-panel" id="historyPanel">
      <section class="domain-card compliance-list-card">
        <div class="domain-card-header"><div><p class="eyebrow">Append-only validation snapshots</p><h2>Validation History</h2><p class="mapping-context-message">Historical results retain the rule and reference wording used at run time.</p></div></div>
        <div class="list-toolbar compliance-toolbar"><label class="search-control"><span class="sr-only">Search validation history</span><i class="fa-solid fa-magnifying-glass"></i><input type="search" id="historySearch" maxlength="100" placeholder="Program, curriculum, or version"/></label><label class="select-control compact-select"><span>Rows</span><select id="historyPerPage"><option value="10">10</option><option value="20" selected>20</option><option value="50">50</option></select></label></div>
        <div class="table-scroll" tabindex="0" aria-label="Scrollable validation history"><table class="domain-table compliance-history-table"><thead><tr><th>Run Date</th><th>Program</th><th>Curriculum</th><th>Version</th><th>Evaluated</th><th>Pass</th><th>Fail</th><th>Not Evaluated</th><th>Run By</th><th>Results</th></tr></thead><tbody id="complianceHistoryRows"><tr><td colspan="10" class="empty-cell">Loading validation history...</td></tr></tbody></table></div>
        <div class="pagination-bar" id="historyPagination"></div>
      </section>
    </section>
    <?php endif; ?>
  </main>
</div>

<?php if ($canManageCompliance): ?>
<div class="curriculum-modal" id="complianceReferenceModal" hidden>
  <div class="modal-backdrop" data-modal-close></div>
  <section class="modal-dialog" role="dialog" aria-modal="true" aria-labelledby="complianceReferenceModalTitle">
    <div class="modal-header"><div><h2 id="complianceReferenceModalTitle">New Compliance Reference</h2><p class="modal-context">Register a traceable source without asserting official endorsement.</p></div><button type="button" class="modal-close-button" data-modal-close aria-label="Close reference form"><i class="fa-solid fa-xmark"></i></button></div>
    <form class="domain-form" id="complianceReferenceForm">
      <input type="hidden" name="id" id="complianceReferenceId"/><div class="form-error" role="alert" hidden></div>
      <div class="form-grid">
        <label class="form-field"><span>Reference Category <strong>*</strong></span><select name="reference_type" id="complianceReferenceType" required><option value="CHED">CHED</option><option value="DEPED">DepEd</option><option value="INSTITUTIONAL">Institutional</option><option value="OTHER">Other</option></select></label>
        <label class="form-field"><span>Document kind <strong>*</strong></span><select name="source_kind" id="complianceReferenceSourceKind" required><option value="unknown">Unknown / not classified</option><option value="regulatory_document">Regulatory document</option><option value="ched_sample_curriculum">CHED sample curriculum</option><option value="institutional_curriculum">Institution-provided curriculum</option><option value="institutional_policy">Institutional policy</option><option value="supporting_document">Supporting document</option><option value="other_document">Other document</option></select><small>Issuer category and document kind are separate facts.</small></label>
        <label class="form-field"><span>Supersedes reference ID</span><input type="number" name="supersedes_reference_id" id="complianceReferenceSupersedes" min="1" step="1" placeholder="Optional existing record ID"/></label>
        <label class="form-field"><span>Reference Code</span><input type="text" name="reference_code" id="complianceReferenceCode" maxlength="120" placeholder="Optional source identifier"/></label>
        <label class="form-field form-field-wide"><span>Reference Title</span><input type="text" name="title" id="complianceReferenceTitle" maxlength="255" placeholder="Required when no code is supplied"/></label>
        <label class="form-field"><span>Issuing Body <strong>*</strong></span><input type="text" name="issuing_body" id="complianceReferenceIssuer" maxlength="180" required/></label>
        <label class="form-field"><span>Effective Date</span><input type="date" name="effective_date" id="complianceReferenceDate"/></label>
        <label class="form-field form-field-wide"><span>Source URL</span><input type="url" name="source_url" id="complianceReferenceUrl" maxlength="1000" placeholder="https://..."/></label>
        <label class="form-field"><span>Internal Compliance Status <strong>*</strong></span><select name="verification_status" id="complianceReferenceStatus" required><option value="draft">Draft</option><option value="verified">Verified (confirmed identity evidence required)</option><option value="inactive">Inactive</option></select><small>This legacy status is not CHED certification, applicability, or BCP approval. Document identity must be confirmed in the separate evidence action.</small></label>
        <label class="form-field form-field-wide"><span>Notes</span><textarea name="notes" id="complianceReferenceNotes" rows="3" placeholder="Optional traceability notes"></textarea></label>
      </div>
      <div class="modal-actions"><button type="button" class="button-muted" data-modal-close>Cancel</button><button type="submit" class="button-primary" id="saveComplianceReference">Create Reference</button></div>
    </form>
  </section>
</div>
<?php endif; ?>

<?php if ($canVerifyReferences): ?>
<div class="curriculum-modal" id="complianceEvidenceModal" hidden>
  <div class="modal-backdrop" data-modal-close></div>
  <section class="modal-dialog" role="dialog" aria-modal="true" aria-labelledby="complianceEvidenceTitle">
    <div class="modal-header"><div><h2 id="complianceEvidenceTitle">Internal Source Assessment</h2><p class="modal-context">Record the evidence and scope of this internal assessment. It does not certify legal applicability or BCP adoption.</p></div><button type="button" class="modal-close-button" data-modal-close aria-label="Close assessment"><i class="fa-solid fa-xmark"></i></button></div>
    <form class="domain-form" id="complianceEvidenceForm"><input type="hidden" name="id" id="complianceEvidenceReferenceId"/><div class="form-error" role="alert" hidden></div>
      <p id="complianceEvidenceReferenceLabel" class="curriculum-notice"></p><div id="complianceEvidenceHistory" class="compliance-evidence-history" aria-live="polite"></div>
      <div class="form-grid"><label class="form-field"><span>Assessment subject <strong>*</strong></span><select name="verification_subject" required><option value="document_identity">Document identity</option><option value="declared_origin">Declared origin</option><option value="applicability">Applicability</option></select></label><label class="form-field"><span>Result <strong>*</strong></span><select name="result" required><option value="not_assessed">Not assessed</option><option value="confirmed">Confirmed</option><option value="not_confirmed">Not confirmed</option></select></label><label class="form-field form-field-wide"><span>Evidence locator</span><input name="evidence_locator" maxlength="1000" placeholder="Local document identifier or page/section; no server URL fetch"/></label><label class="form-field form-field-wide"><span>Evidence SHA-256</span><input name="evidence_sha256" maxlength="64" pattern="[a-fA-F0-9]{64}" placeholder="Optional 64-character digest"/></label><label class="form-field form-field-wide"><span>Rationale <strong>*</strong></span><textarea name="rationale" maxlength="2000" rows="3" required></textarea></label></div>
      <div class="modal-actions"><button type="button" class="button-muted" data-modal-close>Cancel</button><button type="submit" class="button-primary">Record Assessment</button></div>
    </form>
  </section>
</div>
<?php endif; ?>

<?php if ($canManageCompliance): ?>
<div class="curriculum-modal" id="complianceRuleModal" hidden>
  <div class="modal-backdrop" data-modal-close></div>
  <section class="modal-dialog modal-dialog-wide" role="dialog" aria-modal="true" aria-labelledby="complianceRuleModalTitle">
    <div class="modal-header"><div><h2 id="complianceRuleModalTitle">New Compliance Rule</h2><p class="modal-context">Configure one deterministic check from a registered source.</p></div><button type="button" class="modal-close-button" data-modal-close aria-label="Close rule form"><i class="fa-solid fa-xmark"></i></button></div>
    <form class="domain-form" id="complianceRuleForm">
      <input type="hidden" name="id" id="complianceRuleId"/><div class="form-error" role="alert" hidden></div>
      <p class="compliance-history-lock" id="complianceRuleHistoryNotice" hidden><i class="fa-solid fa-lock"></i> This rule has history. Its reference, scope, type, subject, and threshold are locked to preserve traceability.</p>
      <div class="form-grid">
        <label class="form-field form-field-wide"><span>Rule Name <strong>*</strong></span><input type="text" name="rule_name" id="complianceRuleName" maxlength="180" required/></label>
        <div class="form-field">
          <label for="complianceRuleReferenceInput">Reference <strong>*</strong></label>
          <div class="record-autocomplete">
            <input type="search" id="complianceRuleReferenceInput" maxlength="100" placeholder="Search reference code or title" required/>
            <input type="hidden" name="compliance_reference_id" id="complianceRuleReference"/>
            <button type="button" class="record-autocomplete-clear" data-autocomplete-clear aria-label="Clear compliance reference" title="Clear selection" hidden><i class="fa-solid fa-xmark"></i></button>
            <div class="record-autocomplete-options" id="complianceRuleReferenceOptions" role="listbox" aria-label="Compliance reference suggestions" hidden></div>
          </div>
        </div>
        <label class="form-field"><span>Technical Status <strong>*</strong></span><select name="technical_status" id="complianceRuleStatus" required><option value="active">Active</option><option value="inactive">Inactive</option></select></label>
        <label class="form-field"><span>Scope Type <strong>*</strong></span><select name="scope_type" id="complianceRuleScopeType" required><option value="program">Academic Program</option><option value="curriculum">Curriculum</option></select></label>
        <div class="form-field">
          <label for="complianceRuleScopeInput">Scope Record <strong>*</strong></label>
          <div class="record-autocomplete">
            <input type="search" id="complianceRuleScopeInput" maxlength="100" placeholder="Select scope type first" required disabled/>
            <input type="hidden" name="scope_id" id="complianceRuleScope"/>
            <button type="button" class="record-autocomplete-clear" data-autocomplete-clear aria-label="Clear scope record" title="Clear selection" hidden><i class="fa-solid fa-xmark"></i></button>
            <div class="record-autocomplete-options" id="complianceRuleScopeOptions" role="listbox" aria-label="Scope record suggestions" hidden></div>
          </div>
        </div>
        <label class="form-field"><span>Rule Type <strong>*</strong></span><select name="rule_type" id="complianceRuleType" required><option value="MIN_TOTAL_UNITS">Minimum total units</option><option value="MAX_TOTAL_UNITS">Maximum total units</option><option value="REQUIRED_SUBJECT">Required subject</option><option value="MIN_SUBJECT_UNITS">Minimum subject units</option><option value="MAX_SUBJECT_UNITS">Maximum subject units</option></select></label>
        <label class="form-field" id="complianceNumericField"><span>Numeric Threshold <strong>*</strong></span><input type="number" name="numeric_value" id="complianceRuleNumeric" min="0" step="0.01" inputmode="decimal" required/></label>
        <div class="form-field form-field-wide" id="complianceSubjectField" hidden>
          <label for="complianceRuleSubjectInput">Configured Subject <strong>*</strong></label>
          <div class="record-autocomplete">
            <input type="search" id="complianceRuleSubjectInput" maxlength="100" placeholder="Search all subjects by code or title"/>
            <input type="hidden" name="subject_id" id="complianceRuleSubject"/>
            <button type="button" class="record-autocomplete-clear" data-autocomplete-clear aria-label="Clear configured subject" title="Clear selection" hidden><i class="fa-solid fa-xmark"></i></button>
            <div class="record-autocomplete-options" id="complianceRuleSubjectOptions" role="listbox" aria-label="Configured subject suggestions" hidden></div>
          </div>
        </div>
        <label class="form-field form-field-wide"><span>Description</span><textarea name="description" id="complianceRuleDescription" rows="3" placeholder="Optional explanation of this configured check"></textarea></label>
      </div>
      <div class="modal-actions"><button type="button" class="button-muted" data-modal-close>Cancel</button><button type="submit" class="button-primary" id="saveComplianceRule">Create Rule</button></div>
    </form>
  </section>
</div>
<?php endif; ?>

<div class="curriculum-modal record-details-modal" id="complianceRunModal" hidden>
  <div class="modal-backdrop" data-modal-close></div>
  <section class="modal-dialog modal-dialog-wide" role="dialog" aria-modal="true" aria-labelledby="complianceRunModalTitle">
    <div class="modal-header"><div><p class="modal-kicker">Compliance Validation History</p><h2 id="complianceRunModalTitle">Validation Run Results</h2><p class="modal-context" id="complianceRunModalContext"></p></div><button type="button" class="modal-close-button" data-modal-close aria-label="Close validation results"><i class="fa-solid fa-xmark"></i></button></div>
    <div class="modal-body"><div class="compliance-run-detail"><div class="compliance-summary" id="historyRunSummary"></div><div class="table-scroll" tabindex="0" aria-label="Scrollable historical compliance results"><table class="domain-table compliance-results-table"><thead><tr><th>Rule</th><th>Reference</th><th>Requirement</th><th>Actual</th><th>Result</th><th>Explanation</th></tr></thead><tbody id="historyRunResultRows"></tbody></table></div></div></div>
    <div class="modal-footer"><button type="button" class="button-muted" data-modal-close>Close</button></div>
  </section>
</div>

<div class="sidebar-overlay" id="sidebarOverlay"></div>
<script src="../assets/curriculum-common.js?v=<?= $commonJsVersion ?>"></script>
<script src="../assets/compliance-validator.js?v=<?= $complianceJsVersion ?>"></script>
</body>
</html>
