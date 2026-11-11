<?php

require_once __DIR__ . '/CIMFContextBuilder.php';
require_once __DIR__ . '/CurriculumExportService.php';
require_once __DIR__ . '/SubjectEquivalencyService.php';
require_once __DIR__ . '/GradeWeightingService.php';
require_once __DIR__ . '/SubjectOfferingHistoryService.php';
require_once __DIR__ . '/ProgramSubjectAssignmentService.php';
require_once __DIR__ . '/../repositories/ElectivesWorkspaceRepository.php';
require_once __DIR__ . '/ComplianceValidationService.php';
require_once __DIR__ . '/ComplianceRuleService.php';
require_once __DIR__ . '/ComplianceReferenceService.php';

/** Read-only adapters over existing deterministic services. No academic writes. */
final class CIMFGlobalContextBuilder
{
    public function __construct(private mysqli $db) {}

    private static function pick(array $record, array $keys): array
    {
        return array_intersect_key($record, array_flip($keys));
    }

    private function rows(string $sql, string $types = '', array $values = []): array
    {
        $statement = RepositoryQuery::execute($this->db, $sql, $types, $values);
        $rows = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
        $statement->close();
        return $rows;
    }

    private function subject(int $id): array
    {
        $row = $this->rows('SELECT id, subject_code, subject_title, units, description, status FROM subjects WHERE id=? AND archived_at IS NULL', 'i', [$id])[0] ?? null;
        if (!$row) throw new InvalidArgumentException('Select an available Subject Master record.');
        return $row;
    }

    private function header(array $input): array
    {
        $version = (int)($input['curriculum_version_id'] ?? 0);
        if (!$version) throw new InvalidArgumentException('Select a curriculum version for this action.');
        $header = (new CurriculumExportRepository($this->db))->contextByVersion($version);
        if (!$header) throw new InvalidArgumentException('Curriculum version not found.');
        foreach (['academic_program_id', 'curriculum_id'] as $key) {
            if (isset($input[$key]) && (int)$input[$key] !== (int)$header[$key]) throw new InvalidArgumentException('The selected academic context does not match this version.');
        }
        return self::pick($header, ['academic_program_id', 'program_code', 'program_name', 'curriculum_id', 'curriculum_code', 'curriculum_name', 'curriculum_version_id', 'version_label', 'version_status']);
    }

    public function build(array $profile, string $action, array $input, string $question = ''): array
    {
        $id = $profile['id'];
        $facts = ['module' => $profile['title']];
        $header = [];
        $subjects = [];
        $allowed = [];
        $versionId = null;
        $deterministic = null;
        if (empty($input['curriculum_version_id'])) {
            if (!empty($input['academic_program_id']) && !$this->rows('SELECT id FROM academic_programs WHERE id=?', 'i', [(int)$input['academic_program_id']]))
                throw new InvalidArgumentException('Academic program not found.');
            if (!empty($input['curriculum_id'])) {
                $curriculum = $this->rows('SELECT id, academic_program_id FROM curricula WHERE id=?', 'i', [(int)$input['curriculum_id']])[0] ?? null;
                if (!$curriculum || (!empty($input['academic_program_id']) && (int)$curriculum['academic_program_id'] !== (int)$input['academic_program_id']))
                    throw new InvalidArgumentException('Curriculum does not match the selected program.');
            }
        }
        if ($action === 'ask' && !in_array($id, ['dashboard', 'admin_help'], true)) {
            $selected = match ($id) {
                'subject_master', 'subject_history' => !empty($input['subject_id']),
                'subject_equivalency' => !empty($input['subject_a_id']) && !empty($input['subject_b_id']),
                'curriculum_version_history' => !empty($input['version_a_id']) && !empty($input['version_b_id']),
                'academic_assignment' => !empty($input['academic_program_id']) || !empty($input['subject_id']),
                'compliance_validator' => !empty($input['run_id']) || !empty($input['rule_id']) || !empty($input['reference_id']),
                default => !empty($input['curriculum_version_id']),
            };
            if (!$selected) return ['facts' => $facts + ['help' => 'No academic context selected. Explain this module only, and ask the user to select records for academic review.'],
                'allowed' => [], 'version_id' => null, 'display' => ['module' => $profile['title'], 'academic_context' => [], 'selected_subjects' => []]];
        }
        if (str_ends_with($profile['route'] ?? '', '/academic_programs.php') && !empty($input['curriculum_version_id'])
            && !user_can((int)current_user_id(), 'curriculum.view')) throw new InvalidArgumentException('Curriculum access is required to review a curriculum version.');
        if ($id === 'admin_help') {
            $facts['help'] = 'Static interface help only. User and permission administration requires authorized operators. Audit logs describe recorded system events. Never access account, audit, permission, authentication or environment values.';
        } elseif ($id === 'dashboard') {
            $counts = [];
            foreach (['program.view' => 'academic_programs', 'subject.view' => 'subjects', 'curriculum.view' => 'curricula'] as $permission => $table) {
                if (user_can((int)current_user_id(), $permission)) $counts[$table] = (int)$this->db->query('SELECT COUNT(*) FROM ' . $table)->fetch_row()[0];
            }
            $facts['visible_counts'] = $counts;
            $facts['navigation'] = 'Use the CSMS sidebar to open permitted academic modules. Counts summarize stored records; they do not indicate approval.';
        } elseif ($id === 'subject_master') {
            if (empty($input['subject_id'])) {
                if ($action !== 'ask') throw new InvalidArgumentException('Select a subject record for this action.');
                $facts['help'] = 'Subject Master stores codes, titles, units, status and descriptions. Select a subject to review its stored information.';
            } else {
                $subjects[] = $this->subject((int)$input['subject_id']);
                if ($action === 'compare') {
                    if (empty($input['other_subject_id']) || $input['other_subject_id'] == $input['subject_id']) throw new InvalidArgumentException('Select two different Subject Master records.');
                    $subjects[] = $this->subject((int)$input['other_subject_id']);
                }
                $facts['subjects'] = $subjects;
                if ($action === 'usage' && user_can((int)current_user_id(), 'curriculum.view')) {
                    $facts['stored_usage'] = (new SubjectEquivalencyRepository($this->db))->usageContext((int)$input['subject_id']);
                }
            }
        } elseif ($id === 'subject_equivalency') {
            $pair = (new SubjectEquivalencyService($this->db))->compare($input);
            $subjects = [$pair['subject_a'], $pair['subject_b']];
            $facts['subjects'] = array_map(fn($s) => self::pick($s, ['id', 'subject_code', 'subject_title', 'units', 'description', 'status']), $subjects);
            $facts['objective_comparison'] = $pair['differences'];
            $facts['stored_usage'] = $pair['usage'];
            if ($pair['existing_equivalency']) $facts['stored_human_decision'] = self::pick($pair['existing_equivalency'], ['decision_status', 'remarks', 'reviewed_at', 'status', 'archived_at']);
            $facts['authority'] = 'Only an authorized human can decide equivalency. Explain stored similarities and differences without deciding equivalent or not equivalent.';
        } elseif ($id === 'academic_assignment') {
            $service = new ProgramSubjectAssignmentService($this->db);
            if (!empty($input['assignment_id'])) {
                $record = $service->details((int)$input['assignment_id']);
                $contextId = ($input['view'] ?? 'program') === 'subject' ? ($input['subject_id'] ?? null) : ($input['academic_program_id'] ?? null);
                if (!$contextId || (int)$record[($input['view'] ?? 'program') === 'subject' ? 'subject_id' : 'academic_program_id'] !== (int)$contextId)
                    throw new InvalidArgumentException('The assignment does not belong to the selected record.');
                $facts['assignment'] = self::pick($record, ['subject_id', 'subject_code', 'subject_title', 'units', 'academic_program_id', 'program_code', 'program_name', 'assignment_status', 'placements']);
                $subjects = [$record];
            } else {
                $view = ($input['view'] ?? 'program') === 'subject' ? 'subject' : 'program';
                $contextId = (int)($input[$view === 'subject' ? 'subject_id' : 'academic_program_id'] ?? 0);
                if (!$contextId) throw new InvalidArgumentException('Select a Program / Strand or subject.');
                if ($view === 'program') {
                    // Counts and lists come from the server (read only). The AI only explains and prioritizes them, from a compact payload.
                    if (!empty($input['curriculum_version_id'])) $header = $this->header($input);
                    $deterministic = $service->reviewFindings($contextId, $input['curriculum_version_id'] ?? null);
                    $facts['program'] = $deterministic['program']; $facts['version'] = $deterministic['version']; $facts['totals'] = $deterministic['totals'];
                    $facts['notice'] = $deterministic['notice'];
                    $budget = 30; $facts['findings'] = [];
                    foreach ($deterministic['findings'] as $f) {
                        $take = min(8, $budget, count($f['subjects'])); $budget -= $take;
                        $facts['findings'][] = ['label' => $f['label'], 'count' => $f['count'], 'examples' => array_map(fn($s) => ['code' => $s['code'], 'title' => $s['title']], array_slice($f['subjects'], 0, $take))];
                        foreach (array_slice($f['subjects'], 0, $take) as $s) $subjects[] = ['subject_id' => $s['id'], 'subject_code' => $s['code'], 'subject_title' => $s['title'], 'units' => null];
                    }
                    $facts['authority'] = 'Counts and subject lists are calculated by PHP from stored records and are authoritative. Explain them in plain words and say which to look at first. Suggest only human actions, such as reviewing a subject in Subjects or using "Assign placed subjects" after review. Never say anything was assigned, unassigned or changed.';
                } else {
                $facts['selected_record'] = self::pick($service->context($view, $contextId), ['id', 'subject_code', 'subject_title', 'program_code', 'program_name', 'units', 'status']);
                $result = $service->list($contextId, $view, ['status' => 'active', 'per_page' => 50]);
                $facts['assignments'] = array_map(fn($r) => self::pick($r, ['subject_id', 'subject_code', 'subject_title', 'units', 'program_code', 'program_name', 'assignment_status', 'placements']), $result['assignments']);
                $facts['visible_page'] = $result['pagination'];
                $subjects = $result['assignments'];
                }
            }
        } elseif ($id === 'subject_history') {
            if (empty($input['subject_id'])) throw new InvalidArgumentException('Select a subject to review stored history.');
            $subjectRow = $this->subject((int)$input['subject_id']);
            if (!empty($input['curriculum_version_id'])) $header = $this->header($input);
            // PHP computes every count from stored placements (read-scoped); the AI only explains them.
            $deterministic = (new SubjectOfferingHistoryService($this->db))->subjectFindings((int)$subjectRow['id'], !empty($input['curriculum_version_id']) ? (int)$input['curriculum_version_id'] : null);
            $facts['subject'] = self::pick($subjectRow, ['subject_code', 'subject_title', 'units']);
            $facts['scope'] = $deterministic['scope'];
            $facts['version_count'] = $deterministic['version_count'];
            $facts['notice'] = $deterministic['notice'];
            $facts['findings'] = array_map(fn($f) => ['label' => $f['label'], 'count' => $f['count'], 'examples' => array_map(fn($s) => ['code' => $s['code'], 'title' => $s['title']], array_slice($f['subjects'], 0, 8))], $deterministic['findings']);
            $facts['authority'] = 'Counts and version lists are calculated by PHP from stored curriculum placements and are authoritative. Explain them in plain words and say which to review first. Never say the subject was offered, taught or taken in any year.';
            $facts['date_limitations'] = 'Dates are technical database record dates. Stored placements do not establish actual class offerings.';
            $subjects = [['subject_id' => (int)$subjectRow['id'], 'subject_code' => $subjectRow['subject_code'], 'subject_title' => $subjectRow['subject_title'], 'units' => $subjectRow['units']]];
        } elseif ($id === 'curriculum_version_history' || ($id === 'curriculum_builder' && $action === 'compare_versions')) {
            $comparison = (new SubjectOfferingHistoryService($this->db))->compareVersions($input + ['per_page' => 50]);
            if (!empty($input['academic_program_id'])) {
                $a = $input; $a['curriculum_version_id'] = $input['version_a_id']; $this->header($a);
            }
            $subjects = $comparison['comparison'];
            if (in_array($action, ['added', 'removed', 'moved'], true)) $comparison['comparison'] = array_values(array_filter($subjects, fn($s) => $s['change_type'] === $action));
            $facts['deterministic_comparison'] = $comparison;
            $versionId = (int)$input['version_b_id'];
            $facts['authority'] = 'Added, removed, moved and unchanged labels and totals were calculated by PHP. Do not reclassify them.';
        } else {
            if (empty($input['curriculum_version_id']) && $action === 'ask' && empty($input['run_id']) && empty($input['rule_id']) && empty($input['reference_id'])) {
                $facts['help'] = 'No academic context selected. Provide interface guidance for this module only; ask the user to select its context for academic analysis.';
            } else {
                if (!empty($input['curriculum_version_id'])) { $header = $this->header($input); $versionId = (int)$header['curriculum_version_id']; }
                if ($id === 'grade_weighting') {
                    if (!$versionId || empty($input['curriculum_version_subject_id'])) throw new InvalidArgumentException('Select a mapped subject for weighting review.');
                    $configuration = (new GradeWeightingService($this->db))->getSchemeForPlacement((int)$input['curriculum_version_subject_id']);
                    $placement = $configuration['placement'];
                    if ((int)$placement['curriculum_version_id'] !== $versionId) throw new InvalidArgumentException('This placement does not belong to the selected version.');
                    $subjects = [$placement];
                    $facts['subject'] = self::pick($placement, ['subject_id', 'subject_code', 'subject_title', 'units']);
                    $components = $input['components'] ?? $this->rows('SELECT component_name, weight_percentage FROM grade_weighting_components WHERE scheme_id=? ORDER BY sequence_order, id', 'i', [(int)($configuration['scheme']['id'] ?? 0)]);
                    $facts['configuration_source'] = isset($input['components']) ? 'User draft, not yet saved' : 'Stored configuration';
                    $sum = 0; $names = []; $clean = [];
                    foreach ($components as $component) {
                        if (!is_array($component)) throw new InvalidArgumentException('Invalid weighting component.');
                        $name = is_string($component['component_name'] ?? null) ? trim($component['component_name']) : '';
                        $weight = $component['weight_percentage'] ?? null;
                        if ($name === '' || mb_strlen($name) > 150 || !is_scalar($weight) || !preg_match('/^\d{1,3}(?:\.\d{1,2})?$/D', (string)$weight)) throw new InvalidArgumentException('Invalid weighting component.');
                        $key = mb_strtolower($name); $cents = (int)round((float)$weight * 100);
                        if (isset($names[$key]) || $cents < 1 || $cents > 10000) throw new InvalidArgumentException('Invalid or duplicate weighting component.');
                        $names[$key] = true; $sum += $cents;
                        $clean[] = ['component_name' => $name, 'weight_percentage' => number_format($cents / 100, 2, '.', '')];
                    }
                    $facts['components'] = $clean;
                    $facts['deterministic_totals'] = ['total' => $sum / 100, 'remaining' => max(0, 10000 - $sum) / 100, 'exceeded' => max(0, $sum - 10000) / 100, 'is_100_percent' => $sum === 10000];
                    $facts['authority'] = 'PHP calculates the totals. No official grading policy is supplied. Do not change weights or approve the configuration.';
                } elseif ($id === 'prerequisite_configuration') {
                    if (!$versionId) throw new InvalidArgumentException('Select a curriculum version.');
                    $legacyAction = match ($action) { 'prerequisite_subject_review' => 'prerequisite_subject_review', 'compare_subjects' => 'compare_subjects', default => 'prerequisite_review' };
                    if ($legacyAction !== 'prerequisite_review' && empty($input['subject_id'])) throw new InvalidArgumentException('Select a subject in this curriculum version.');
                    if ($legacyAction === 'compare_subjects' && empty($input['other_subject_id'])) throw new InvalidArgumentException('Select two subjects in this version.');
                    $context = (new CIMFContextBuilder($this->db))->build($versionId, $legacyAction, isset($input['subject_id']) ? (int)$input['subject_id'] : null, isset($input['other_subject_id']) ? (int)$input['other_subject_id'] : null);
                    $facts['subjects'] = $context['subjects']; $facts['stored_prerequisites'] = $context['prerequisites'];
                    $facts['authority'] = 'Only supplied stored prerequisite edges are authoritative. Possible relationships need human review.';
                    $allowed = $context['allowed'];
                } elseif ($id === 'electives_manager') {
                    if (!$versionId) throw new InvalidArgumentException('Select a curriculum version.');
                    $groups = (new ElectivesWorkspaceRepository($this->db))->groups($versionId);
                    if (!empty($input['group_id'])) {
                        $groups = array_values(array_filter($groups, fn($g) => (int)$g['id'] === (int)$input['group_id']));
                        if (!$groups) throw new InvalidArgumentException('This elective group is not in the selected version.');
                    } elseif (in_array($action, ['review', 'compare'], true)) throw new InvalidArgumentException('Select an elective group.');
                    $facts['groups'] = array_map(function ($g) {
                        $clean = self::pick($g, ['id', 'group_name', 'required_selections', 'year_level', 'semester', 'status']);
                        $clean['members'] = array_map(fn($m) => self::pick($m, ['subject_id', 'subject_code', 'subject_title', 'units', 'subject_status', 'year_level', 'semester']), $g['members']);
                        return $clean;
                    }, $groups);
                    foreach ($groups as $group) foreach ($group['members'] ?? [] as $member) $subjects[] = $member;
                    if ($action === 'compare') {
                        $wanted = [(int)($input['subject_id'] ?? 0), (int)($input['other_subject_id'] ?? 0)];
                        $members = array_map('intval', array_column($subjects, 'subject_id'));
                        if (!$wanted[0] || $wanted[0] === $wanted[1] || count(array_intersect($wanted, $members)) !== 2) throw new InvalidArgumentException('Select two different members of this elective group.');
                        $subjects = array_values(array_filter($subjects, fn($s) => in_array((int)$s['subject_id'], $wanted, true)));
                        $facts['compared_subjects'] = $subjects;
                    }
                    $facts['authority'] = 'Optional placement alone does not mean elective membership. Only stored group memberships are supplied.';
                } elseif ($id === 'compliance_validator') {
                    if (!empty($input['run_id'])) {
                        $run = (new ComplianceValidationService($this->db))->getRun((int)$input['run_id']);
                        if ($versionId && (int)$run['curriculum_version_id'] !== $versionId) throw new InvalidArgumentException('Validation run does not match the selected version.');
                        $facts['validation'] = self::pick($run, ['curriculum_version_id', 'version_label', 'passed_count', 'failed_count', 'needs_review_count', 'results']);
                        if (!empty($input['finding_id'])) {
                            $facts['validation']['results'] = array_values(array_filter($run['results'], fn($r) => (int)$r['id'] === (int)$input['finding_id']));
                            if (!$facts['validation']['results']) throw new InvalidArgumentException('Finding does not belong to the selected run.');
                        }
                        $versionId = (int)$run['curriculum_version_id'];
                    } elseif (!empty($input['rule_id'])) $facts['configured_rule'] = self::pick((new ComplianceRuleService($this->db))->get((int)$input['rule_id']), ['id', 'rule_name', 'rule_type', 'authority', 'requirement_value', 'description', 'reference_id', 'status', 'reference_code', 'reference_title']);
                    elseif (!empty($input['reference_id'])) $facts['reference'] = self::pick((new ComplianceReferenceService($this->db))->get((int)$input['reference_id']), ['id', 'title', 'reference_code', 'source_url', 'issuing_body', 'reference_type', 'description', 'status']);
                    else throw new InvalidArgumentException('Select a stored validation result, rule or reference. CIMF does not run or change validation.');
                    $facts['authority'] = 'Based on configured validation rules only. No official CHED or DepEd approval may be concluded.';
                } else {
                    if (!$versionId) throw new InvalidArgumentException('Select a curriculum version.');
                    $dataset = (new CurriculumExportService($this->db))->dataset($input + $header);
                    $subjects = $dataset['subjects'];
                    if ($id === 'subject_mapping' && !empty($input['subject_id'])) {
                        $wanted = [(int)$input['subject_id']];
                        if ($action === 'compare') {
                            if (empty($input['other_subject_id']) || $input['subject_id'] == $input['other_subject_id']) throw new InvalidArgumentException('Select two different mapped subjects.');
                            $wanted[] = (int)$input['other_subject_id'];
                        }
                        $subjects = array_values(array_filter($subjects, fn($s) => in_array((int)$s['subject_id'], $wanted, true)));
                        if (count($subjects) !== count($wanted)) throw new InvalidArgumentException('Select subjects inside this curriculum scope.');
                    } elseif ($id === 'subject_mapping' && $action === 'compare') throw new InvalidArgumentException('Select two mapped subjects.');
                    $facts['scope'] = $dataset['scope']; $facts['totals'] = $dataset['summary'];
                    $facts['placements'] = array_map(fn($r) => self::pick($r, ['subject_id', 'subject_code', 'subject_title', 'units', 'year_level', 'semester', 'sequence_order', 'is_required', 'placement_type']), $subjects);
                    if ($id === 'curriculum_export') $facts['authority'] = 'These rows and totals come from the authoritative shared export service. AI cannot change Preview, CSV or Print/PDF contents.';
                }
            }
        }
        foreach ($subjects as $subject) {
            $subjectId = (int)($subject['subject_id'] ?? $subject['id'] ?? 0);
            if ($subjectId && isset($subject['subject_code'])) $allowed[$subjectId] = ['subject_id' => $subjectId] + $subject;
        }
        if ($header) { $facts['academic_context'] = $header; $versionId = (int)$header['curriculum_version_id']; }
        // Context limits fail visibly rather than silently sending a partial academic dataset.
        $encoded = json_encode($facts, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if (strlen((string)$encoded) > 90000) throw new InvalidArgumentException('This context is too large. Select a narrower scope or record.');
        return ['facts' => $facts, 'allowed' => $allowed, 'version_id' => $versionId, 'deterministic' => $deterministic,
            'display' => ['module' => $profile['title'], 'academic_context' => $header,
                'selected_subjects' => array_map(fn($s) => ['id' => (int)$s['subject_id'], 'code' => $s['subject_code'], 'title' => $s['subject_title']], array_values($allowed))]];
    }
}
