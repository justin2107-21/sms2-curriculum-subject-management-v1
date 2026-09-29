<?php
// Shared, permission-aware Curriculum & Subject Management navigation.
// $APP_ROOT is the relative path from the current page to the application root.
// $ACTIVE_NAV identifies the active data-nav value below.

$APP_ROOT = $APP_ROOT ?? '../';
$ACTIVE_NAV = $ACTIVE_NAV ?? '';
$sidebarUserId = current_user_id();

$sidebarCanViewDashboard = $sidebarUserId !== null && user_can($sidebarUserId, 'dashboard.view');
$sidebarCanViewPrograms = $sidebarUserId !== null && user_can($sidebarUserId, 'program.view');
$sidebarCanViewCurriculum = $sidebarUserId !== null && user_can($sidebarUserId, 'curriculum.view');
$sidebarCanViewSubjects = $sidebarUserId !== null && user_can($sidebarUserId, 'subject.view');
$sidebarCanViewPrerequisites = $sidebarUserId !== null && user_can($sidebarUserId, 'prerequisite.view');
$sidebarCanViewElectives = $sidebarUserId !== null && user_can($sidebarUserId, 'elective.view');
$sidebarCanViewProgramAssignments = $sidebarUserId !== null && user_can($sidebarUserId, 'program_assignment.view');
$sidebarCanViewSubjectHistory = $sidebarUserId !== null && user_can($sidebarUserId, 'subject_history.view');
$sidebarCanViewEquivalencies = $sidebarUserId !== null && user_can($sidebarUserId, 'equivalency.view');
$sidebarCanViewGradeWeighting = $sidebarUserId !== null && user_can($sidebarUserId, 'grade_weighting.view');
$sidebarCanViewCompliance = $sidebarUserId !== null && user_can($sidebarUserId, 'compliance.view');
$sidebarCanViewCurriculumExport = $sidebarUserId !== null && user_can($sidebarUserId, 'curriculum_export.view');
$sidebarCanManageRbac = $sidebarUserId !== null && user_can($sidebarUserId, 'rbac.manage');
$sidebarCanViewAudit = $sidebarUserId !== null && user_can($sidebarUserId, 'audit.view');
$sidebarCanViewSettings = $sidebarUserId !== null && user_can($sidebarUserId, 'account.view');

$sidebarHasBuilderItems = $sidebarCanViewPrograms || $sidebarCanViewCurriculum;
$sidebarBuilderActive = in_array($ACTIVE_NAV, ['academic-programs', 'curricula'], true);
$sidebarProgramAssignmentActive = in_array($ACTIVE_NAV, ['program-assignment-program', 'program-assignment-subject'], true);
$sidebarSubjectHistoryActive = in_array($ACTIVE_NAV, ['subject-history-subject', 'subject-history-curriculum'], true);
$sidebarEquivalencyActive = in_array($ACTIVE_NAV, ['subject-equivalency-comparison', 'subject-equivalency-records'], true);
$sidebarComplianceActive = in_array($ACTIVE_NAV, ['compliance-validate', 'compliance-rules', 'compliance-references', 'compliance-history'], true);
$sidebarHasCurriculumItems = $sidebarHasBuilderItems
    || $sidebarCanViewSubjects
    || $sidebarCanViewPrerequisites
    || $sidebarCanViewElectives
    || $sidebarCanViewProgramAssignments
    || $sidebarCanViewSubjectHistory
    || $sidebarCanViewEquivalencies
    || $sidebarCanViewGradeWeighting
    || $sidebarCanViewCompliance
    || $sidebarCanViewCurriculumExport;
$sidebarHasSystemItems = $sidebarCanManageRbac || $sidebarCanViewAudit || $sidebarCanViewSettings;
?>
<aside class="sidebar" id="sidebar">
  <div class="sidebar-header">
    <div class="sidebar-logo">
      <a href="<?= $APP_ROOT ?>dashboard/dashboard.php" title="Go to Dashboard">
        <img src="<?= $APP_ROOT ?>images/BCP_LOGO.png" alt="BCP Logo" class="sidebar-logo-img"/>
      </a>
    </div>
  </div>

  <nav class="sidebar-nav" aria-label="Primary navigation">
    <?php if ($sidebarCanViewDashboard): ?>
    <div class="sidebar-brand sidebar-brand-2"><div class="brand-title">MAIN</div></div>
    <div class="nav-group">
      <a href="<?= $APP_ROOT ?>dashboard/dashboard.php" class="sidebar-item <?= $ACTIVE_NAV === 'dashboard' ? 'active' : '' ?>">
        <i class="fa-solid fa-gauge"></i><span>Dashboard</span>
      </a>
    </div>
    <?php endif; ?>

    <?php if ($sidebarHasCurriculumItems): ?>
    <div class="sidebar-divider"></div>
    <div class="sidebar-brand sidebar-brand-2">
      <div class="brand-title sidebar-section-title-long">CURRICULUM &amp; SUBJECT MANAGEMENT</div>
    </div>

    <?php if ($sidebarHasBuilderItems): ?>
    <div class="sidebar-builder-group <?= $sidebarBuilderActive ? 'is-open is-current' : '' ?>"
         data-sidebar-menu-group data-sidebar-menu-storage-key="sms2_curriculum_builder_menu_open">
      <button type="button" class="sidebar-item sidebar-builder-toggle"
              aria-expanded="<?= $sidebarBuilderActive ? 'true' : 'false' ?>"
              aria-controls="curriculumBuilderSubmenu"
              data-sidebar-menu-toggle>
        <i class="fa-solid fa-table-list"></i><span>Curriculum Builder</span>
        <i class="fa-solid fa-chevron-down sidebar-submenu-chevron" aria-hidden="true"></i>
      </button>
      <div class="sidebar-builder-submenu <?= $sidebarBuilderActive ? 'open' : '' ?>"
           id="curriculumBuilderSubmenu"
           aria-hidden="<?= $sidebarBuilderActive ? 'false' : 'true' ?>"
           <?= $sidebarBuilderActive ? '' : 'inert' ?>
           data-sidebar-menu-panel>
        <div class="sidebar-builder-submenu-inner">
          <?php if ($sidebarCanViewPrograms): ?>
          <a href="<?= $APP_ROOT ?>modules/curriculum/pages/academic_programs.php"
             class="sidebar-submenu-item <?= $ACTIVE_NAV === 'academic-programs' ? 'active' : '' ?>">Academic Programs</a>
          <?php endif; ?>
          <?php if ($sidebarCanViewCurriculum): ?>
          <a href="<?= $APP_ROOT ?>modules/curriculum/pages/curricula.php"
             class="sidebar-submenu-item <?= $ACTIVE_NAV === 'curricula' ? 'active' : '' ?>">Curricula</a>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <?php endif; ?>
    <?php if ($sidebarCanViewSubjects): ?>
    <div class="nav-group">
      <a href="<?= $APP_ROOT ?>modules/curriculum/pages/subjects.php" class="sidebar-item <?= $ACTIVE_NAV === 'subjects' ? 'active' : '' ?>">
        <i class="fa-solid fa-book"></i><span>Subjects</span>
      </a>
    </div>
    <?php endif; ?>
    <?php if ($sidebarCanViewCurriculum): ?>
    <div class="nav-group">
      <a href="<?= $APP_ROOT ?>modules/curriculum/pages/subject_mapping.php" class="sidebar-item <?= $ACTIVE_NAV === 'subject-mapping' ? 'active' : '' ?>">
        <i class="fa-solid fa-diagram-project"></i><span>Subject Mapping</span>
      </a>
    </div>
    <?php endif; ?>
    <?php if ($sidebarCanViewPrerequisites): ?>
    <div class="nav-group">
      <a href="<?= $APP_ROOT ?>modules/curriculum/pages/prerequisite_configuration.php" class="sidebar-item <?= $ACTIVE_NAV === 'prerequisite-configuration' ? 'active' : '' ?>">
        <i class="fa-solid fa-code-branch"></i><span class="sidebar-long-label">Pre-requisite Configuration</span>
      </a>
    </div>
    <?php endif; ?>
    <?php if ($sidebarCanViewElectives): ?>
    <div class="nav-group">
      <a href="<?= $APP_ROOT ?>modules/curriculum/pages/electives_manager.php" class="sidebar-item <?= $ACTIVE_NAV === 'electives-manager' ? 'active' : '' ?>">
        <i class="fa-solid fa-layer-group"></i><span class="sidebar-long-label">Electives Manager</span>
      </a>
    </div>
    <?php endif; ?>
    <?php if ($sidebarCanViewProgramAssignments): ?>
    <div class="sidebar-builder-group <?= $sidebarProgramAssignmentActive ? 'is-open is-current' : '' ?>"
         data-sidebar-menu-group data-sidebar-menu-storage-key="sms2_program_assignment_menu_open">
      <button type="button" class="sidebar-item sidebar-builder-toggle"
              aria-expanded="<?= $sidebarProgramAssignmentActive ? 'true' : 'false' ?>"
              aria-controls="programAssignmentSubmenu"
              data-sidebar-menu-toggle>
        <i class="fa-solid fa-share-nodes"></i><span class="sidebar-long-label"><span class="sidebar-no-break">Academic Strand</span>/Course Assignment</span>
        <i class="fa-solid fa-chevron-down sidebar-submenu-chevron" aria-hidden="true"></i>
      </button>
      <div class="sidebar-builder-submenu <?= $sidebarProgramAssignmentActive ? 'open' : '' ?>"
           id="programAssignmentSubmenu"
           aria-hidden="<?= $sidebarProgramAssignmentActive ? 'false' : 'true' ?>"
           <?= $sidebarProgramAssignmentActive ? '' : 'inert' ?>
           data-sidebar-menu-panel>
        <div class="sidebar-builder-submenu-inner">
          <a href="<?= $APP_ROOT ?>modules/curriculum/pages/program_centric_assignment.php"
             class="sidebar-submenu-item <?= $ACTIVE_NAV === 'program-assignment-program' ? 'active' : '' ?>">Program-Centric View</a>
          <a href="<?= $APP_ROOT ?>modules/curriculum/pages/subject_centric_assignment.php"
             class="sidebar-submenu-item <?= $ACTIVE_NAV === 'program-assignment-subject' ? 'active' : '' ?>">Subject-Centric View</a>
        </div>
      </div>
    </div>
    <?php endif; ?>
    <?php if ($sidebarCanViewSubjectHistory): ?>
    <div class="sidebar-builder-group <?= $sidebarSubjectHistoryActive ? 'is-open is-current' : '' ?>"
         data-sidebar-menu-group data-sidebar-menu-storage-key="sms2_subject_history_menu_open">
      <button type="button" class="sidebar-item sidebar-builder-toggle"
              aria-expanded="<?= $sidebarSubjectHistoryActive ? 'true' : 'false' ?>"
              aria-controls="subjectHistorySubmenu"
              data-sidebar-menu-toggle>
        <i class="fa-solid fa-clock-rotate-left"></i><span class="sidebar-long-label">Subject Offering History</span>
        <i class="fa-solid fa-chevron-down sidebar-submenu-chevron" aria-hidden="true"></i>
      </button>
      <div class="sidebar-builder-submenu <?= $sidebarSubjectHistoryActive ? 'open' : '' ?>"
           id="subjectHistorySubmenu" aria-hidden="<?= $sidebarSubjectHistoryActive ? 'false' : 'true' ?>"
           <?= $sidebarSubjectHistoryActive ? '' : 'inert' ?> data-sidebar-menu-panel>
        <div class="sidebar-builder-submenu-inner">
          <a href="<?= $APP_ROOT ?>modules/curriculum/pages/subject_history_view.php"
             class="sidebar-submenu-item <?= $ACTIVE_NAV === 'subject-history-subject' ? 'active' : '' ?>">Subject History View</a>
          <a href="<?= $APP_ROOT ?>modules/curriculum/pages/curriculum_history_view.php"
             class="sidebar-submenu-item <?= $ACTIVE_NAV === 'subject-history-curriculum' ? 'active' : '' ?>">Curriculum History View</a>
        </div>
      </div>
    </div>
    <?php endif; ?>
    <?php if ($sidebarCanViewEquivalencies): ?>
    <div class="sidebar-builder-group <?= $sidebarEquivalencyActive ? 'is-open is-current' : '' ?>"
         data-sidebar-menu-group data-sidebar-menu-storage-key="sms2_subject_equivalency_menu_open">
      <button type="button" class="sidebar-item sidebar-builder-toggle"
              aria-expanded="<?= $sidebarEquivalencyActive ? 'true' : 'false' ?>"
              aria-controls="subjectEquivalencySubmenu"
              data-sidebar-menu-toggle>
        <i class="fa-solid fa-right-left"></i><span class="sidebar-long-label">Subject Equivalency Tool</span>
        <i class="fa-solid fa-chevron-down sidebar-submenu-chevron" aria-hidden="true"></i>
      </button>
      <div class="sidebar-builder-submenu <?= $sidebarEquivalencyActive ? 'open' : '' ?>"
           id="subjectEquivalencySubmenu" aria-hidden="<?= $sidebarEquivalencyActive ? 'false' : 'true' ?>"
           <?= $sidebarEquivalencyActive ? '' : 'inert' ?> data-sidebar-menu-panel>
        <div class="sidebar-builder-submenu-inner">
          <a href="<?= $APP_ROOT ?>modules/curriculum/pages/subject_comparison.php"
             class="sidebar-submenu-item <?= $ACTIVE_NAV === 'subject-equivalency-comparison' ? 'active' : '' ?>">Subject Comparison</a>
          <a href="<?= $APP_ROOT ?>modules/curriculum/pages/equivalency_records.php"
             class="sidebar-submenu-item <?= $ACTIVE_NAV === 'subject-equivalency-records' ? 'active' : '' ?>">Equivalency Records</a>
        </div>
      </div>
    </div>
    <?php endif; ?>
    <?php if ($sidebarCanViewGradeWeighting): ?>
    <div class="nav-group">
      <a href="<?= $APP_ROOT ?>modules/curriculum/pages/grade_weighting_setup.php" class="sidebar-item <?= $ACTIVE_NAV === 'grade-weighting' ? 'active' : '' ?>">
        <i class="fa-solid fa-chart-pie"></i><span class="sidebar-long-label">Grade Weighting Setup</span>
      </a>
    </div>
    <?php endif; ?>
    <?php if ($sidebarCanViewCompliance): ?>
    <div class="sidebar-builder-group <?= $sidebarComplianceActive ? 'is-open is-current' : '' ?>"
         data-sidebar-menu-group data-sidebar-menu-storage-key="sms2_compliance_validator_menu_open">
      <button type="button" class="sidebar-item sidebar-builder-toggle"
              aria-expanded="<?= $sidebarComplianceActive ? 'true' : 'false' ?>"
              aria-controls="complianceValidatorSubmenu"
              data-sidebar-menu-toggle>
        <i class="fa-solid fa-shield-halved"></i><span class="sidebar-long-label">CHED / DepEd Compliance Validator</span>
        <i class="fa-solid fa-chevron-down sidebar-submenu-chevron" aria-hidden="true"></i>
      </button>
      <div class="sidebar-builder-submenu <?= $sidebarComplianceActive ? 'open' : '' ?>"
           id="complianceValidatorSubmenu" aria-hidden="<?= $sidebarComplianceActive ? 'false' : 'true' ?>"
           <?= $sidebarComplianceActive ? '' : 'inert' ?> data-sidebar-menu-panel>
        <div class="sidebar-builder-submenu-inner">
          <a href="<?= $APP_ROOT ?>modules/curriculum/pages/validate_curriculum.php"
             class="sidebar-submenu-item <?= $ACTIVE_NAV === 'compliance-validate' ? 'active' : '' ?>">Validate Curriculum</a>
          <a href="<?= $APP_ROOT ?>modules/curriculum/pages/compliance_rules.php"
             class="sidebar-submenu-item <?= $ACTIVE_NAV === 'compliance-rules' ? 'active' : '' ?>">Compliance Rules</a>
          <a href="<?= $APP_ROOT ?>modules/curriculum/pages/compliance_references.php"
             class="sidebar-submenu-item <?= $ACTIVE_NAV === 'compliance-references' ? 'active' : '' ?>">References</a>
          <a href="<?= $APP_ROOT ?>modules/curriculum/pages/validation_history.php"
             class="sidebar-submenu-item <?= $ACTIVE_NAV === 'compliance-history' ? 'active' : '' ?>">Validation History</a>
        </div>
      </div>
    </div>
    <?php endif; ?>
    <?php if ($sidebarCanViewCurriculumExport): ?>
    <div class="nav-group">
      <a href="<?= $APP_ROOT ?>modules/curriculum/pages/curriculum_export.php" class="sidebar-item <?= $ACTIVE_NAV === 'curriculum-export' ? 'active' : '' ?>">
        <i class="fa-solid fa-file-export"></i><span class="sidebar-long-label">Curriculum Export Tool</span>
      </a>
    </div>
    <?php endif; ?>
    <?php endif; ?>

    <?php if ($sidebarHasSystemItems): ?>
    <div class="sidebar-divider"></div>
    <div class="sidebar-brand sidebar-brand-2"><div class="brand-title">SYSTEM</div></div>
    <?php if ($sidebarCanManageRbac): ?>
    <div class="nav-group">
      <a href="<?= $APP_ROOT ?>admin/users.php" class="sidebar-item <?= $ACTIVE_NAV === 'users' ? 'active' : '' ?>">
        <i class="fa-solid fa-users"></i><span>Users</span>
      </a>
    </div>
    <div class="nav-group">
      <a href="<?= $APP_ROOT ?>admin/permissions.php" class="sidebar-item <?= $ACTIVE_NAV === 'permissions' ? 'active' : '' ?>">
        <i class="fa-solid fa-user-shield"></i><span>Permissions</span>
      </a>
    </div>
    <?php endif; ?>
    <?php if ($sidebarCanViewAudit): ?>
    <div class="nav-group">
      <a href="<?= $APP_ROOT ?>admin/audit_logs.php" class="sidebar-item <?= $ACTIVE_NAV === 'audit' ? 'active' : '' ?>">
        <i class="fa-solid fa-clock-rotate-left"></i><span>Audit Logs</span>
      </a>
    </div>
    <?php endif; ?>
    <?php if ($sidebarCanViewSettings): ?>
    <div class="nav-group">
      <a href="<?= $APP_ROOT ?>dashboard/account.php" class="sidebar-item <?= $ACTIVE_NAV === 'settings' ? 'active' : '' ?>">
        <i class="fa-solid fa-gear"></i><span>Settings</span>
      </a>
    </div>
    <?php endif; ?>
    <?php endif; ?>
  </nav>
</aside>
<?php
$sidebarStateScriptPath = __DIR__ . '/../js/sidebar-state.js';
$sidebarStateScriptVersion = is_file($sidebarStateScriptPath) ? (string)filemtime($sidebarStateScriptPath) : '1';
?>
<script src="<?= $APP_ROOT ?>js/sidebar-state.js?v=<?= $sidebarStateScriptVersion ?>"></script>
