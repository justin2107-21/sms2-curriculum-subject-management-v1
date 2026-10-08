# Task 2 mutation inventory (before implementation)

Base: fc8f7b2a2f4d3e462219c35b709d05b8bbd4e7f1.
Inspected service bodies, repositories, validators, HTTP support, authorization,
CSRF and audit helpers before changing application code.

All curriculum routes below live in modules/curriculum/http. Mutation routes
authenticate through support.php, require POST, CSRF and the named normalized
permission. Services remain internal domain boundaries; HTTP authorization is
not replaced by the edit policy. Direct service calls must still enforce locks.

| Module / route | Actual action / service mutation | Permission | Protected resource / plan |
|---|---|---|---|
| version_actions.php | rename / CurriculumVersionService::rename | curriculum.update | stored version |
| version_actions.php | create | curriculum.version.create | preserve neutral creation; no lifecycle input accepted |
| assignment_actions.php | map_subject, assign / assign | curriculum.update (+ context permissions for map_subject) | submitted version; authoritative subject |
| assignment_actions.php | update_mapping, update / updatePlacement; remove | curriculum.update | stored placement owner |
| AcademicProgramAssignmentService | assign, update, remove | caller's existing authorization | delegates to placement service |
| requirement_actions.php | create, remove, update_rule / create, remove, updateRule | prerequisite.manage | submitted version or stored relationship owner |
| elective_group_actions.php | create, update / ElectivesWorkspaceService::save; archive | elective.manage | stored group owner on edit/archive, submitted owner on create |
| elective_group_actions.php | remove | elective.manage | currently refuses with 422; retain behavior |
| elective_membership_actions.php | add, remove | elective.manage | currently refuses with 422; retain behavior |
| legacy elective services | CurriculumElectiveGroupService::create/update/remove; CurriculumElectiveGroupSubjectService::add/remove | internal caller | stored group/membership owner; cover direct calls |
| grade_weighting_actions.php | configuration_save / saveConfiguration | grade_weighting.manage | stored placement owner, reject forged submitted context |
| GradeWeightingService | createScheme/updateScheme/removeScheme/createComponent/updateComponent/removeComponent | internal caller | stored placement/scheme/component chain |
| subject_actions.php | update, archive | subject.update | shared subject and all mapped versions |
| subject_actions.php | create | subject.create | preserve unrelated creation |
| curriculum_actions.php | update, archive | curriculum.update | versions in stored curriculum |
| curriculum_actions.php | create | curriculum.create | preserve unrelated creation |
| program_actions.php | update, archive | program.update | versions under program |
| program_actions.php | create | program.create | preserve unrelated creation |
| ArchiveCenterService | archive, restore: program/curriculum/subject/elective_group | existing archive routes | all four entity types; same policy |
| compliance_actions.php | reference_update / ComplianceReferenceService::update | compliance.manage | direct lifecycle reference links and scoped rules affecting protected versions |
| compliance_actions.php | reference_create | compliance.manage | preserve independent sources |
| compliance_actions.php | rule_create, rule_update / ComplianceRuleService | compliance.manage | both old and new program/curriculum scope; reference links |
| compliance_actions.php | validation_run | compliance.run | retain append-only diagnostic runs/results |
| program_assignment_actions.php | assign, remove / ProgramSubjectAssignmentService | program_assignment.manage | global association only; no placement edits; existing removal rejects any placements |
| equivalency_actions.php | create, update, archive / SubjectEquivalencyService | equivalency.manage | global comparison decisions only; no placement edits or automatic credit; preserve |
| export_actions.php | download_csv | curriculum_export.view | read dataset plus audit; preserve |
| CIMF routes | analysis, usage logging | existing per-profile permissions + CSRF | context reads and append-only diagnostic log; preserve |

Read-only history, comparison, previews, export and context builders remain allowed.
Repository DML is called by these services. Setup/seed/migration CLI tools are
separate maintenance paths, not public curriculum mutation APIs; no changes
to their workflow or historical migrations are authorized.

Baseline validation adds a domain helper only, with no assignment endpoint.
Placement removal must preserve legacy snapshots and protected snapshots;
only an editable proposed placement may remove its own snapshot transactionally
with required audit evidence.
