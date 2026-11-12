<?php

require_once __DIR__ . '/CurriculumVersionEditPolicy.php';
require_once __DIR__ . '/CurriculumReadScope.php';
require_once __DIR__ . '/ElectivesWorkspaceService.php';

require_once __DIR__ . '/../validators/DomainValidator.php';
require_once __DIR__ . '/../repositories/AcademicProgramRepository.php';
require_once __DIR__ . '/../repositories/CurriculumRepository.php';
require_once __DIR__ . '/../repositories/CurriculumVersionRepository.php';
require_once __DIR__ . '/../repositories/CurriculumVersionSubjectRepository.php';
require_once __DIR__ . '/../repositories/GradeWeightingRepository.php';
require_once __DIR__ . '/../../../shared/audit.php';

class GradeWeightingService
{
    private AcademicProgramRepository $programs;
    private CurriculumRepository $curricula;
    private CurriculumVersionRepository $versions;
    private CurriculumVersionSubjectRepository $placements;
    private GradeWeightingRepository $weighting;

    public function __construct(private mysqli $db)
    {
        $this->programs = new AcademicProgramRepository($db);
        $this->curricula = new CurriculumRepository($db);
        $this->versions = new CurriculumVersionRepository($db);
        $this->placements = new CurriculumVersionSubjectRepository($db);
        $this->weighting = new GradeWeightingRepository($db);
    }

    /** Shared Program / Curriculum / Version bar lookup (same read-scoped query as Electives Manager and Offering History). */
    public function contextOptions(array $input): array
    {
        return (new ElectivesWorkspaceService($this->db))->contextOptions($input);
    }

    private const REASON_TEXT = [
        'role' => 'Your role can view grade weighting but cannot change it.',
        'archived' => 'This version is archived and cannot be changed.',
        'approved' => 'This version is approved and cannot be changed. Create a new revision to change weights.',
        'under_review' => 'This version is under review and cannot be changed.',
        'reference' => 'This is a reference version and cannot be changed.',
        'protected' => 'This version is protected and cannot be changed.',
    ];

    /** Whether the current user can change weights in this version, and why not. Mirrors CurriculumVersionEditPolicy (which still decides on save). */
    private function editState(array $version): array
    {
        $userId = current_user_id();
        $reason = null;
        if ($userId === null || !user_can($userId, 'grade_weighting.manage')) $reason = 'role';
        elseif (($version['status'] ?? '') === 'archived') $reason = 'archived';
        elseif (($version['review_status'] ?? '') === 'approved') $reason = 'approved';
        elseif (($version['review_status'] ?? '') === 'under_review') $reason = 'under_review';
        elseif (($version['version_role'] ?? '') === 'reference') $reason = 'reference';
        elseif (!CurriculumVersionEditPolicy::isEditable($version)) $reason = 'protected';
        return ['editable' => $reason === null, 'reason_code' => $reason, 'reason_text' => $reason === null ? null : self::REASON_TEXT[$reason]];
    }

    private function assertVersionReadable(int $versionId): void
    {
        $version = $this->weighting->versionById($versionId);
        if (!$version || !CurriculumReadScope::allows($version, current_user_id())) {
            throw new DomainNotFoundException('Curriculum version not found.');
        }
    }

    /** The selected version: labels, lifecycle, edit state, terms for the filters and whole-version coverage. */
    public function versionContext(array $input): array
    {
        $record = $this->weighting->versionContext(
            DomainValidator::positiveId($input['program_id'] ?? null, 'Program / Strand'),
            DomainValidator::positiveId($input['curriculum_id'] ?? null, 'Curriculum'),
            DomainValidator::positiveId($input['curriculum_version_id'] ?? null, 'Curriculum version')
        );
        if (!$record || !CurriculumReadScope::allows($record, current_user_id())) throw new DomainNotFoundException('Curriculum version not found.');
        $terms = $this->weighting->versionTerms((int)$record['version_id']);
        $years = array_values(array_unique(array_filter(array_column($terms, 'year_level'))));
        $semesters = array_values(array_unique(array_filter(array_column($terms, 'semester'))));
        usort($years, [ElectivesWorkspaceService::class, 'academicOrder']);
        usort($semesters, [ElectivesWorkspaceService::class, 'academicOrder']);
        $userId = current_user_id();
        $canManage = $userId !== null && user_can($userId, 'grade_weighting.manage');
        return [
            'record' => $record + $this->editState($record),
            'years' => $years, 'semesters' => $semesters,
            'coverage' => $this->weighting->coverage((int)$record['version_id']),
            // For roles that can edit weights: is there any version they may open and change? (page message when not)
            'can_manage' => $canManage,
            'editable_version_exists' => !$canManage || $this->weighting->editableVersionExists(CurriculumReadScope::sql('versions')),
        ];
    }

    /** Server-side paged subjects table of one version. */
    public function overview(array $input): array
    {
        $query = DomainValidator::gradeWeightingOverviewQuery($input);
        $this->assertVersionReadable($query['version_id']);
        $result = $this->weighting->overview($query['version_id'], $query);
        $rows = array_map(function (array $row): array {
            $total = $this->decimalToHundredths((string)$row['total_weight']);
            $row['component_count'] = (int)$row['component_count'];
            $row['total_weight'] = $this->formatHundredths($total);
            $row['status'] = $row['component_count'] === 0 ? 'not_configured' : ($total === 10000 ? 'configured' : 'incomplete');
            return $row;
        }, $result['records']);
        return ['placements' => $rows, 'pagination' => $result['pagination']];
    }

    /** One placement for the editor dialog: placement, version edit state, scheme and its components (at most 100). */
    public function getSchemeForPlacement(int $placementId): array
    {
        $placement = $this->placements->findDetailedById($placementId);
        if (!$placement) {
            throw new DomainNotFoundException('Curriculum subject placement not found.');
        }
        $this->assertVersionReadable((int)$placement['curriculum_version_id']);
        $scheme = $this->weighting->findSchemeByPlacement($placementId);
        $version = $this->weighting->versionById((int)$placement['curriculum_version_id']) ?? [];
        return [
            'placement' => $placement,
            'scheme' => $scheme ? $this->decorateScheme($scheme) : null,
            'components' => $scheme ? $this->weighting->allComponents((int)$scheme['id']) : [],
            'version' => $this->editState($version),
        ];
    }

    /** Components of a scheme. The version is derived from the scheme and read scope applies to it. */
    public function listComponents(int $schemeId, array $input): array
    {
        $scheme = $this->weighting->findDetailedSchemeById($schemeId);
        if (!$scheme) {
            throw new DomainNotFoundException('Grade weighting scheme not found.');
        }
        $this->assertVersionReadable((int)$scheme['curriculum_version_id']);
        $result = $this->weighting->paginateComponents(
            $schemeId,
            DomainValidator::listQuery($input)
        );
        return [
            'scheme' => $this->decorateScheme($scheme),
            'components' => $result['records'],
            'pagination' => $result['pagination'],
        ];
    }

    /**
     * Removes a subject's whole weighting configuration (components and scheme) in one transaction, so the placement can be removed
     * again. Same permission and the same editable-version rules as saving. The audit row keeps the old components and weights.
     */
    public function removeConfiguration(int $placementId, int $versionId, int $actorId): void
    {
        $this->db->begin_transaction();
        try {
            (new CurriculumVersionEditPolicy($this->db))->assertChildEditable('placement', $placementId);
            $placement = $this->placements->findDetailedById($placementId);
            if (!$placement) throw new DomainNotFoundException('Curriculum subject placement not found.');
            if ((int)$placement['curriculum_version_id'] !== $versionId) {
                throw new DomainValidationException('The selected subject is not mapped to this curriculum version.');
            }
            $scheme = $this->weighting->findSchemeByPlacement($placementId, true);
            if (!$scheme) throw new DomainConflictException('This subject has no grade weighting configuration to remove.');
            $schemeId = (int)$scheme['id'];
            $old = $this->weighting->allComponents($schemeId, true);
            foreach ($old as $component) $this->weighting->deleteComponent((int)$component['id']);
            $this->weighting->deleteScheme($schemeId);
            audit_log_required(
                'GRADE_WEIGHTING_CONFIGURATION_REMOVE',
                'grade_weighting_scheme',
                $schemeId,
                'Grade weighting configuration removed (components and scheme).',
                $this->weightingAuditContext($placement) + [
                    'old_components' => $this->auditComponents($old),
                    'old_total' => $this->formatHundredths(array_sum(array_map(fn(array $c): int => $this->decimalToHundredths((string)$c['weight_percentage']), $old))),
                    'component_count' => count($old),
                ]
            );
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->rollback();
            throw $error;
        }
    }

    /** Names and weights only (what an audit reader needs), in saved order. */
    private function auditComponents(array $components): array
    {
        return array_map(static fn(array $c): array => [
            'component_name' => trim((string)$c['component_name']), 'weight_percentage' => (string)$c['weight_percentage'],
        ], $components);
    }
    public function createScheme(array $input, int $actorId): array
    {
        $data = DomainValidator::gradeWeightingScheme($input);
        $this->db->begin_transaction();
        try {
            (new CurriculumVersionEditPolicy($this->db))->assertChildEditable('placement', $data['curriculum_version_subject_id']);
            if (!$this->placements->findDetailedById($data['curriculum_version_subject_id'])) {
                throw new DomainNotFoundException('Curriculum subject placement not found.');
            }
            if ($this->weighting->placementHasScheme($data['curriculum_version_subject_id'])) {
                throw new DomainConflictException(
                    'A grade weighting scheme already exists for this curriculum subject placement.'
                );
            }
            $id = $this->weighting->createScheme($data, $actorId);
            audit_log_required(
                'GRADE_WEIGHTING_SCHEME_CREATE',
                'grade_weighting_scheme',
                $id,
                'Placement-scoped grade weighting scheme created.',
                ['curriculum_version_subject_id' => $data['curriculum_version_subject_id']]
            );
            $scheme = $this->weighting->findDetailedSchemeById($id);
            if (!$scheme) {
                throw new RuntimeException('Created grade weighting scheme could not be loaded.');
            }
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->rollback();
            if ($error instanceof mysqli_sql_exception && (int)$error->getCode() === 1062) {
                throw new DomainConflictException(
                    'A grade weighting scheme already exists for this curriculum subject placement.'
                );
            }
            throw $error;
        }
        return $this->decorateScheme($scheme);
    }

    public function updateScheme(int $id, array $input): array
    {
        $data = DomainValidator::gradeWeightingSchemeUpdate($input);
        $this->db->begin_transaction();
        try {
            (new CurriculumVersionEditPolicy($this->db))->assertChildEditable('scheme', $id);
            $existing = $this->weighting->findSchemeById($id, true);
            if (!$existing) {
                throw new DomainNotFoundException('Grade weighting scheme not found.');
            }
            $this->weighting->updateScheme($id, $data['description']);
            audit_log_required(
                'GRADE_WEIGHTING_SCHEME_UPDATE',
                'grade_weighting_scheme',
                $id,
                'Grade weighting scheme description updated.',
                ['curriculum_version_subject_id' => (int)$existing['curriculum_version_subject_id']]
            );
            $scheme = $this->weighting->findDetailedSchemeById($id);
            if (!$scheme) {
                throw new RuntimeException('Updated grade weighting scheme could not be loaded.');
            }
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->rollback();
            throw $error;
        }
        return $this->decorateScheme($scheme);
    }

    public function removeScheme(int $id): void
    {
        $this->db->begin_transaction();
        try {
            (new CurriculumVersionEditPolicy($this->db))->assertChildEditable('scheme', $id);
            $scheme = $this->weighting->findSchemeById($id, true);
            if (!$scheme) {
                throw new DomainNotFoundException('Grade weighting scheme not found.');
            }
            if ($this->weighting->componentCount($id) > 0) {
                throw new DomainConflictException(
                    'Remove all weighting components before removing this scheme.'
                );
            }
            $this->weighting->deleteScheme($id);
            audit_log_required(
                'GRADE_WEIGHTING_SCHEME_REMOVE',
                'grade_weighting_scheme',
                $id,
                'Empty grade weighting scheme removed.',
                ['curriculum_version_subject_id' => (int)$scheme['curriculum_version_subject_id']]
            );
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->rollback();
            throw $error;
        }
    }

    public function createComponent(array $input, int $actorId): array
    {
        $data = DomainValidator::gradeWeightingComponent($input);
        $this->db->begin_transaction();
        try {
            (new CurriculumVersionEditPolicy($this->db))->assertChildEditable('scheme', $data['scheme_id']);
            if (!$this->weighting->findSchemeById($data['scheme_id'], true)) {
                throw new DomainNotFoundException('Grade weighting scheme not found.');
            }
            if ($this->weighting->componentNameExists(
                $data['component_name'],
                $data['scheme_id']
            )) {
                throw new DomainConflictException(
                    'A component with this name already exists in this weighting scheme.'
                );
            }
            $components = $this->weighting->allComponents($data['scheme_id'], true);
            $existingHundredths = array_sum(array_map(
                fn(array $component): int => $this->decimalToHundredths((string)$component['weight_percentage']),
                $components
            ));
            $this->assertProjectedTotal(
                $this->formatHundredths($existingHundredths),
                $data['weight_percentage']
            );
            $id = $this->weighting->createComponent($data, $actorId);
            audit_log_required(
                'GRADE_WEIGHTING_COMPONENT_CREATE',
                'grade_weighting_component',
                $id,
                'Grade weighting component created.',
                [
                    'scheme_id' => $data['scheme_id'],
                    'component_name' => $data['component_name'],
                    'weight_percentage' => $data['weight_percentage'],
                ]
            );
            $component = $this->weighting->findComponentById($id);
            $scheme = $this->weighting->findDetailedSchemeById($data['scheme_id']);
            if (!$component || !$scheme) {
                throw new RuntimeException('Created grade weighting component could not be loaded.');
            }
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->rollback();
            if ($error instanceof mysqli_sql_exception && (int)$error->getCode() === 1062) {
                throw new DomainConflictException(
                    'A component with this name already exists in this weighting scheme.'
                );
            }
            throw $error;
        }
        return ['component' => $component, 'scheme' => $this->decorateScheme($scheme)];
    }

    public function updateComponent(int $id, array $input): array
    {
        $data = DomainValidator::gradeWeightingComponentFields($input);
        $this->db->begin_transaction();
        try {
            (new CurriculumVersionEditPolicy($this->db))->assertChildEditable('component', $id);
            $component = $this->weighting->findComponentById($id, true);
            if (!$component) {
                throw new DomainNotFoundException('Grade weighting component not found.');
            }
            $schemeId = (int)$component['scheme_id'];
            if (!$this->weighting->findSchemeById($schemeId, true)) {
                throw new DomainNotFoundException('Grade weighting scheme not found.');
            }
            if ($this->weighting->componentNameExists(
                $data['component_name'],
                $schemeId,
                $id
            )) {
                throw new DomainConflictException(
                    'A component with this name already exists in this weighting scheme.'
                );
            }
            $components = $this->weighting->allComponents($schemeId, true);
            $existingHundredths = 0;
            foreach ($components as $row) {
                if ((int)$row['id'] !== $id) {
                    $existingHundredths += $this->decimalToHundredths((string)$row['weight_percentage']);
                }
            }
            $this->assertProjectedTotal($this->formatHundredths($existingHundredths), $data['weight_percentage']);
            $this->weighting->updateComponent($id, $data);
            audit_log_required(
                'GRADE_WEIGHTING_COMPONENT_UPDATE',
                'grade_weighting_component',
                $id,
                'Grade weighting component updated.',
                [
                    'scheme_id' => $schemeId,
                    'component_name' => $data['component_name'],
                    'weight_percentage' => $data['weight_percentage'],
                ]
            );
            $updated = $this->weighting->findComponentById($id);
            $scheme = $this->weighting->findDetailedSchemeById($schemeId);
            if (!$updated || !$scheme) {
                throw new RuntimeException('Updated grade weighting component could not be loaded.');
            }
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->rollback();
            if ($error instanceof mysqli_sql_exception && (int)$error->getCode() === 1062) {
                throw new DomainConflictException(
                    'A component with this name already exists in this weighting scheme.'
                );
            }
            throw $error;
        }
        return ['component' => $updated, 'scheme' => $this->decorateScheme($scheme)];
    }

    public function removeComponent(int $id): array
    {
        $this->db->begin_transaction();
        try {
            (new CurriculumVersionEditPolicy($this->db))->assertChildEditable('component', $id);
            $component = $this->weighting->findComponentById($id, true);
            if (!$component) {
                throw new DomainNotFoundException('Grade weighting component not found.');
            }
            $schemeId = (int)$component['scheme_id'];
            if (!$this->weighting->findSchemeById($schemeId, true)) {
                throw new DomainNotFoundException('Grade weighting scheme not found.');
            }
            $this->weighting->deleteComponent($id);
            audit_log_required(
                'GRADE_WEIGHTING_COMPONENT_REMOVE',
                'grade_weighting_component',
                $id,
                'Grade weighting component removed.',
                [
                    'scheme_id' => $schemeId,
                    'component_name' => (string)$component['component_name'],
                    'weight_percentage' => (string)$component['weight_percentage'],
                ]
            );
            $scheme = $this->weighting->findDetailedSchemeById($schemeId);
            if (!$scheme) {
                throw new RuntimeException('Grade weighting scheme could not be reloaded.');
            }
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->rollback();
            throw $error;
        }
        return $this->decorateScheme($scheme);
    }

    public function saveConfiguration(int $placementId, int $versionId, array $input, int $actorId): array
    {
        $components = $this->validatedConfigurationComponents($input['components'] ?? null);
        $total = array_sum(array_column($components, '_weight_hundredths'));
        if ($total !== 10000) {
            throw new DomainValidationException('Component weights must total exactly 100%.');
        }

        $this->db->begin_transaction();
        try {
            (new CurriculumVersionEditPolicy($this->db))->assertChildEditable('placement', $placementId);
            $placement = $this->placements->findDetailedById($placementId);
            if (!$placement) {
                throw new DomainNotFoundException('Curriculum subject placement not found.');
            }
            if ((int)$placement['curriculum_version_id'] !== $versionId) {
                throw new DomainValidationException('The selected subject is not mapped to this curriculum version.');
            }

            $scheme = $this->weighting->findSchemeByPlacement($placementId, true);
            if (!$scheme) {
                $schemeId = $this->weighting->createScheme([
                    'curriculum_version_subject_id' => $placementId,
                    'description' => null,
                ], $actorId);
                audit_log_required(
                    'GRADE_WEIGHTING_SCHEME_CREATE',
                    'grade_weighting_scheme',
                    $schemeId,
                    'Grade weighting configuration created.',
                    $this->weightingAuditContext($placement)
                );
                $existing = [];
            } else {
                $schemeId = (int)$scheme['id'];
                $scheme = $this->weighting->findSchemeById($schemeId, true);
                if (!$scheme) {
                    throw new DomainNotFoundException('Grade weighting scheme not found.');
                }
                $existing = $this->weighting->allComponents($schemeId, true);
            }

            $oldList = $this->auditComponents($existing);
            $byId = [];
            foreach ($existing as $component) $byId[(int)$component['id']] = $component;
            $incomingIds = [];
            foreach ($components as $component) {
                if ($component['id'] === null) continue;
                if (!isset($byId[$component['id']])) {
                    throw new DomainValidationException('A component does not belong to this configuration.');
                }
                $incomingIds[$component['id']] = true;
            }

            // Remove omitted rows first so their unique names can be reused in this save.
            foreach ($byId as $componentId => $old) {
                if (isset($incomingIds[$componentId])) continue;
                $this->weighting->deleteComponent($componentId);
                audit_log_required(
                    'GRADE_WEIGHTING_COMPONENT_REMOVE',
                    'grade_weighting_component',
                    $componentId,
                    'Grade weighting component removed.',
                    $this->weightingAuditContext($placement) + [
                        'component_name' => (string)$old['component_name'],
                        'weight_percentage' => (string)$old['weight_percentage'],
                    ]
                );
            }

            // Temporary names allow two existing component names to be swapped atomically.
            foreach ($components as $index => $component) {
                $componentId = $component['id'];
                if ($componentId === null) continue;
                $old = $byId[$componentId];
                if (trim((string)$old['component_name']) === $component['component_name']) continue;
                $temporary = '__csms_tmp_' . $componentId . '_' . bin2hex(random_bytes(8));
                $this->weighting->updateComponent($componentId, [
                    'component_name' => $temporary,
                    'weight_percentage' => (string)$old['weight_percentage'],
                    'sequence_order' => (int)($old['sequence_order'] ?? ($index + 1)),
                ]);
            }

            foreach ($components as $index => $component) {
                $componentId = $component['id'];
                $weight = $component['weight_percentage'];
                unset($component['_weight_hundredths'], $component['id']);
                $component['scheme_id'] = $schemeId;
                $component['sequence_order'] = $index + 1;
                if ($componentId !== null) {
                    $old = $byId[$componentId];
                    $changed = trim((string)$old['component_name']) !== $component['component_name']
                        || $this->decimalToHundredths((string)$old['weight_percentage']) !== $this->decimalToHundredths($weight)
                        || (int)($old['sequence_order'] ?? 0) !== $component['sequence_order'];
                    if ($changed) {
                        $this->weighting->updateComponent($componentId, $component);
                        audit_log_required(
                            'GRADE_WEIGHTING_COMPONENT_UPDATE',
                            'grade_weighting_component',
                            $componentId,
                            'Grade weighting component updated.',
                            $this->weightingAuditContext($placement) + [
                                'component_name' => $component['component_name'],
                                'weight_percentage' => $component['weight_percentage'],
                                'old_component_name' => trim((string)$old['component_name']),
                                'old_weight_percentage' => (string)$old['weight_percentage'],
                            ]
                        );
                    }
                } else {
                    $componentId = $this->weighting->createComponent($component, $actorId);
                    audit_log_required(
                        'GRADE_WEIGHTING_COMPONENT_CREATE',
                        'grade_weighting_component',
                        $componentId,
                        'Grade weighting component added.',
                        $this->weightingAuditContext($placement) + [
                            'component_name' => $component['component_name'],
                            'weight_percentage' => $component['weight_percentage'],
                        ]
                    );
                }
            }

            // Touch the configuration row so its last-updated timestamp tracks each save.
            $this->weighting->updateScheme($schemeId, $scheme['description'] ?? null);
            audit_log_required(
                'GRADE_WEIGHTING_CONFIGURATION_SAVE',
                'grade_weighting_scheme',
                $schemeId,
                'Grade weighting configuration saved at exactly 100%.',
                $this->weightingAuditContext($placement) + [
                    'component_count' => count($components),
                    // Old and new lists (names and weights) and totals, so a reader can see exactly what changed.
                    'old_components' => $oldList,
                    'old_total' => $this->formatHundredths(array_sum(array_map(fn(array $c): int => $this->decimalToHundredths((string)$c['weight_percentage']), $existing))),
                    'new_components' => array_map(static fn(array $c): array => ['component_name' => $c['component_name'], 'weight_percentage' => $c['weight_percentage']], $components),
                    'new_total' => $this->formatHundredths($total),
                ]
            );
            $savedScheme = $this->weighting->findDetailedSchemeById($schemeId);
            $savedComponents = $this->weighting->allComponents($schemeId);
            if (!$savedScheme) throw new RuntimeException('Saved weighting configuration could not be loaded.');
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->rollback();
            if ($error instanceof mysqli_sql_exception && (int)$error->getCode() === 1062) {
                throw new DomainConflictException('A component with this name already exists in this configuration.');
            }
            throw $error;
        }
        return [
            'scheme' => $this->decorateScheme($savedScheme),
            'components' => $savedComponents,
        ];
    }

    private function validatedConfigurationComponents(mixed $raw): array
    {
        if (is_string($raw)) $raw = json_decode($raw, true);
        if (!is_array($raw) || !$raw || count($raw) > 100) {
            throw new DomainValidationException('Add between 1 and 100 weighting components.');
        }
        $seen = [];
        $seenIds = [];
        $normalized = [];
        foreach ($raw as $item) {
            if (!is_array($item)) throw new DomainValidationException('Invalid weighting component data.');
            $name = trim((string)($item['component_name'] ?? ''));
            if ($name === '' || mb_strlen($name) > 150) {
                throw new DomainValidationException('Component names are required and must be 150 characters or fewer.');
            }
            $key = mb_strtolower($name, 'UTF-8');
            if (isset($seen[$key])) {
                throw new DomainValidationException('Component names must be unique without regard to letter case.');
            }
            $seen[$key] = true;
            $weight = $item['weight_percentage'] ?? null;
            if (!is_scalar($weight) || !preg_match('/^(?:\d{1,3})(?:\.\d{1,2})?$/', (string)$weight)) {
                throw new DomainValidationException('Component weight must be a number with at most two decimal places.');
            }
            $weightHundredths = $this->decimalToHundredths((string)$weight);
            if ($weightHundredths < 1 || $weightHundredths > 10000) {
                throw new DomainValidationException('Component weight must be greater than 0% and no more than 100%.');
            }
            $idRaw = $item['id'] ?? null;
            $componentId = $idRaw === null || $idRaw === '' ? null : DomainValidator::positiveId($idRaw, 'Weighting component');
            if ($componentId !== null) {
                if (isset($seenIds[$componentId])) {
                    throw new DomainValidationException('A component can appear only once in a configuration.');
                }
                $seenIds[$componentId] = true;
            }
            $normalized[] = [
                'id' => $componentId,
                'component_name' => $name,
                'weight_percentage' => $this->formatHundredths($weightHundredths),
                'sequence_order' => count($normalized) + 1,
                '_weight_hundredths' => $weightHundredths,
            ];
        }
        return $normalized;
    }

    private function weightingAuditContext(array $placement): array
    {
        return [
            'curriculum_version_subject_id' => (int)$placement['id'],
            'subject_id' => (int)$placement['subject_id'],
            'subject_code' => (string)$placement['subject_code'],
            'curriculum_version_id' => (int)$placement['curriculum_version_id'],
            'version_label' => (string)$placement['version_label'],
        ];
    }

    private function assertProjectedTotal(string $existingTotal, string $newWeight): void
    {
        if ($this->decimalToHundredths($existingTotal)
            + $this->decimalToHundredths($newWeight) > 10000) {
            throw new DomainValidationException(
                'Total component weight cannot exceed 100%.'
            );
        }
    }

    private function decorateScheme(array $scheme): array
    {
        $total = $this->decimalToHundredths((string)$scheme['total_weight']);
        $scheme['component_count'] = (int)$scheme['component_count'];
        $scheme['total_weight'] = $this->formatHundredths($total);
        $scheme['remaining_weight'] = $this->formatHundredths(max(0, 10000 - $total));
        $scheme['completion_status'] = $total === 10000 ? 'complete' : 'incomplete';
        return $scheme;
    }

    private function decimalToHundredths(string $value): int
    {
        $parts = explode('.', $value, 2);
        return ((int)$parts[0] * 100) + (int)str_pad($parts[1] ?? '', 2, '0');
    }

    private function formatHundredths(int $value): string
    {
        return intdiv($value, 100) . '.' . str_pad(
            (string)($value % 100),
            2,
            '0',
            STR_PAD_LEFT
        );
    }
}
