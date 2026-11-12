<?php

require_once __DIR__ . '/../validators/DomainValidator.php';
require_once __DIR__ . '/../repositories/AcademicProgramRepository.php';
require_once __DIR__ . '/../repositories/CurriculumRepository.php';
require_once __DIR__ . '/../repositories/CurriculumVersionRepository.php';
require_once __DIR__ . '/../repositories/SubjectRepository.php';
require_once __DIR__ . '/../repositories/ComplianceRuleRepository.php';
require_once __DIR__ . '/../repositories/ComplianceValidationRepository.php';
require_once __DIR__ . '/../repositories/ComplianceLibraryRepository.php';
require_once __DIR__ . '/../../../shared/audit.php';

class ComplianceValidationService
{
    private AcademicProgramRepository $programs;
    private CurriculumRepository $curricula;
    private CurriculumVersionRepository $versions;
    private SubjectRepository $subjects;
    private ComplianceRuleRepository $rules;
    private ComplianceValidationRepository $validations;
    private ComplianceLibraryRepository $library;

    public function __construct(private mysqli $db)
    {
        $this->programs = new AcademicProgramRepository($db);
        $this->curricula = new CurriculumRepository($db);
        $this->versions = new CurriculumVersionRepository($db);
        $this->subjects = new SubjectRepository($db);
        $this->rules = new ComplianceRuleRepository($db);
        $this->validations = new ComplianceValidationRepository($db);
        $this->library = new ComplianceLibraryRepository($db);
    }

    /** Options for the shared Program > Curriculum > Version bar (versions respect the reader's scope). */
    public function contextOptions(array $input): array
    {
        require_once __DIR__ . '/ElectivesWorkspaceService.php';
        return (new ElectivesWorkspaceService($this->db))->contextOptions($input);
    }

    public function programOptions(array $input): array
    {
        $result = $this->programs->paginate(
            DomainValidator::listQuery($input, ['active', 'inactive'])
        );
        return ['programs' => $result['records'], 'pagination' => $result['pagination']];
    }

    public function curriculumOptions(array $input): array
    {
        $result = $this->curricula->paginate(
            DomainValidator::listQuery($input, ['active', 'inactive'], true)
        );
        return ['curricula' => $result['records'], 'pagination' => $result['pagination']];
    }

    public function versionOptions(array $input): array
    {
        $curriculumId = DomainValidator::positiveId(
            $input['curriculum_id'] ?? null,
            'Curriculum'
        );
        if (!$this->curricula->findById($curriculumId)) {
            throw new DomainNotFoundException('Curriculum not found.');
        }
        $result = $this->versions->paginateByCurriculum(
            $curriculumId,
            DomainValidator::listQuery($input, ['draft', 'active', 'archived'])
        );
        return ['versions' => $result['records'], 'pagination' => $result['pagination']];
    }

    public function subjectOptions(array $input): array
    {
        $result = $this->subjects->paginate(
            DomainValidator::listQuery($input, ['active', 'inactive'])
        );
        return ['subjects' => $result['records'], 'pagination' => $result['pagination']];
    }

    /** Words shown to people. The stored values (PASS / FAIL / NOT_EVALUATED / PENDING_BCP) are unchanged. */
    public const RESULT_LABELS = ['PASS' => 'Met', 'FAIL' => 'Not met', 'NOT_EVALUATED' => 'Cannot determine from stored data', 'PENDING_BCP' => 'Pending BCP confirmation'];
    private const MISSING_CATEGORY = 'Stored subjects have no category (general education, PE, NSTP), so these units cannot be counted from stored data.';
    public const NO_RULES_MESSAGE = 'No rules apply to this program. No CHED/DepEd source for it is recorded in this system yet.';
    public const ELECTIVE_NOTE = 'Elective units count toward the total only up to the required amount.';

    /** Adds the human labels and the counts in the same words (met / not met / cannot determine). Counts only: there is no overall verdict. */
    private function decorateRun(array $run): array
    {
        $run['met_count'] = (int)$run['passed_count'];
        $run['not_met_count'] = (int)$run['failed_count'];
        $run['cannot_determine_count'] = (int)$run['not_evaluated_count'];
        $results = [];
        foreach ($run['results'] ?? [] as $result) {
            $result['origin'] = 'custom';
            $results[] = $result;
        }
        // Library results (migration 032) come first and use the same keys as the custom ones; pending rules are counted apart.
        $pending = 0;
        if ($this->library->available() && isset($run['id'])) {
            $libraryResults = [];
            foreach ($this->library->resultsForRun((int)$run['id']) as $row) {
                if ($row['status'] === 'PENDING_BCP') $pending++;
                $libraryResults[] = [
                    'id' => 'L' . $row['id'], 'origin' => 'library', 'rule_name_snapshot' => $row['title'], 'rule_type_snapshot' => $row['computation_key'],
                    'reference_identifier_snapshot' => $row['reference_code'] ?? ($row['source_label'] ?? ''), 'requirement_snapshot' => $row['requirement_text'],
                    'actual_value_snapshot' => $row['actual_text'], 'result_status' => $row['status'], 'explanation' => $row['explanation'], 'missing_data_text' => $row['missing_data_text'],
                    'section_label' => $row['section_label'], 'source_page' => $row['source_page'], 'source_class' => $row['source_class'], 'source_label' => $row['source_label'],
                    'reference_code' => $row['reference_code'], 'issuing_body' => $row['issuing_body'], 'reference_year' => $row['reference_year'], 'reference_type' => $row['reference_type'],
                    'reference_status' => $row['reference_status'], 'source_kind' => $row['source_kind'], 'created_at' => $row['created_at'],
                ];
            }
            $results = array_merge($libraryResults, $results);
        }
        $run['pending_count'] = $pending;
        foreach ($results as $index => $result) {
            $results[$index]['result_label'] = self::RESULT_LABELS[$result['result_status']] ?? $result['result_status'];
        }
        $run['results'] = $results;
        return $run;
    }

    /** Library rules that are checked in a run: not display-only, and a public rule only when its source is confirmed. Pending rules are listed, never evaluated. */
    private function libraryRulesForRun(array $context): array
    {
        if (!$this->library->available()) return [];
        $out = [];
        foreach ($this->library->applicable((string)$context['program_code'], $context['program_type'] ?? null) as $rule) {
            if ($rule['evaluation_mode'] === 'display_only') continue;
            if ($rule['evaluation_mode'] === 'evaluate' && $rule['source_class'] === 'public_regulation' && $rule['verification_status'] !== 'verified') continue;
            $out[] = $rule;
        }
        return $out;
    }

    /** @return array{0:string,1:?string,2:string,3:?string} status, actual text, explanation, missing data */
    private function evaluateLibraryRule(array $rule, array $totals): array
    {
        if ($rule['evaluation_mode'] === 'pending_bcp') {
            return ['PENDING_BCP', null, 'Pending BCP confirmation: this rule is recorded from a public source, but whether it applies to BCP is not confirmed. It is not evaluated.', null];
        }
        $parameters = $rule['parameters_json'] === null ? [] : (json_decode((string)$rule['parameters_json'], true) ?: []);
        switch ($rule['computation_key']) {
            case 'MIN_TOTAL_UNITS':
                $minimum = (string)($parameters['min_units'] ?? '0');
                $met = $this->compareDecimal((string)$totals['total_units'], $minimum) >= 0;
                return [$met ? 'PASS' : 'FAIL', $this->unitsBoth($totals),
                    ($met ? 'The unit total of all placements meets the minimum.' : 'The unit total of all placements is below the minimum.') . ' ' . self::ELECTIVE_NOTE, null];
            case 'NSTP_PREREQUISITE':
                return ['NOT_EVALUATED', null, 'The prerequisite between NSTP subjects cannot be checked from stored data.', 'NSTP subjects cannot be identified: stored subjects have no category.'];
            default:
                return ['NOT_EVALUATED', null, 'This rule cannot be checked from stored data.', self::MISSING_CATEGORY];
        }
    }

    private function libraryDuplicatesCustom(array $custom, array $libraryRules, array $context): bool
    {
        if ($custom['rule_type'] !== 'MIN_TOTAL_UNITS' || (int)($custom['academic_program_id'] ?? 0) !== (int)$context['academic_program_id']) return false;
        foreach ($libraryRules as $rule) {
            $parameters = $rule['parameters_json'] === null ? [] : (json_decode((string)$rule['parameters_json'], true) ?: []);
            if ($rule['computation_key'] === 'MIN_TOTAL_UNITS' && (int)$rule['compliance_reference_id'] === (int)$custom['compliance_reference_id']
                && abs((float)($parameters['min_units'] ?? -1) - (float)$custom['numeric_value']) < 0.001) return true;
        }
        return false;
    }

    public function run(int $versionId, int $actorId, bool $includeDemo = false): array
    {
        $context = $this->validations->versionContext($versionId);
        if (!$context) {
            throw new DomainNotFoundException('Curriculum version not found.');
        }
        $rules = $this->rules->eligibleForContext(
            (int)$context['academic_program_id'],
            (int)$context['curriculum_id'],
            $includeDemo
        );
        $libraryRules = $this->libraryRulesForRun($context);
        // A custom rule that repeats a library total-units rule (same source, program and number) is not counted twice.
        $rules = array_values(array_filter($rules, fn($rule) => !$this->libraryDuplicatesCustom($rule, $libraryRules, $context)));
        if (!$rules && !$libraryRules) {
            return [
                'run' => null,
                'context' => $context,
                'summary' => $this->emptySummary(),
                'results' => [],
                'message' => self::NO_RULES_MESSAGE,
            ];
        }

        $totals = $this->validations->curriculumTotals($versionId);
        $summary = $this->emptySummary();
        $this->db->begin_transaction();
        try {
            $runId = $this->validations->createRun($versionId, $actorId);
            foreach ($libraryRules as $rule) {
                [$status, $actual, $explanation, $missing] = $this->evaluateLibraryRule($rule, $totals);
                $this->library->createResult($runId, (int)$rule['id'], $status, $actual, $explanation, $missing);
                if ($status === 'PENDING_BCP') {
                    $summary['pending']++;
                    continue;
                }
                $summary['rules_evaluated']++;
                if ($status === 'PASS') $summary['passed']++;
                elseif ($status === 'FAIL') $summary['failed']++;
                else $summary['not_evaluated']++;
            }
            foreach ($rules as $rule) {
                $result = $this->evaluateRule($rule, $versionId, $totals);
                $this->validations->createResult($runId, $result);
                $summary['rules_evaluated']++;
                if ($result['result'] === 'PASS') {
                    $summary['passed']++;
                } elseif ($result['result'] === 'FAIL') {
                    $summary['failed']++;
                } else {
                    $summary['not_evaluated']++;
                }
            }
            $this->validations->updateRunCounts($runId, $summary);
            audit_log_required(
                'COMPLIANCE_VALIDATION_RUN',
                'compliance_validation_run',
                $runId,
                'Deterministic curriculum compliance validation completed.',
                [
                    'curriculum_version_id' => $versionId,
                    'rules_evaluated' => $summary['rules_evaluated'],
                    'passed' => $summary['passed'],
                    'failed' => $summary['failed'],
                    'not_evaluated' => $summary['not_evaluated'],
                    'pending_bcp' => $summary['pending'],
                ]
            );
            $run = $this->validations->findRun($runId);
            $run = $run ? $this->decorateRun($run) : null;
            if (!$run) {
                throw new RuntimeException('Completed validation run could not be loaded.');
            }
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->rollback();
            throw $error;
        }

        return [
            'run' => $run,
            'context' => $context,
            'summary' => $summary,
            'results' => $run['results'],
            'message' => 'Checked ' . $summary['rules_evaluated'] . ($summary['rules_evaluated'] === 1 ? ' rule' : ' rules') . ' from confirmed sources.'
                . ($summary['pending'] > 0 ? ' ' . $summary['pending'] . ($summary['pending'] === 1 ? ' rule is' : ' rules are') . ' pending BCP confirmation and not evaluated.' : ''),
        ];
    }

    public function history(array $input): array
    {
        $result = $this->validations->paginateRuns(
            DomainValidator::complianceHistoryQuery($input)
        );
        return ['runs' => $result['records'], 'pagination' => $result['pagination']];
    }

    public function getRun(int $id): array
    {
        $run = $this->validations->findRun($id);
        if (!$run) {
            throw new DomainNotFoundException('Compliance validation run not found.');
        }
        // The version is derived from the run; a reader who may not open that version gets nothing.
        if (!CurriculumReadScope::allows(['status' => $run['version_status'] ?? null, 'review_status' => $run['version_review_status'] ?? null], current_user_id())) {
            throw new DomainNotFoundException('Compliance validation run not found.');
        }
        return $this->decorateRun($run);
    }

    private function evaluateRule(array $rule, int $versionId, array $totals): array
    {
        $type = (string)$rule['rule_type'];
        $threshold = $rule['numeric_value'] === null ? null : (string)$rule['numeric_value'];
        $subject = $rule['subject_id'] === null
            ? null
            : $this->validations->mappedSubject($versionId, (int)$rule['subject_id']);
        $result = 'NOT_EVALUATED';
        $requirement = '';
        $actual = '';
        $explanation = 'This supported rule could not be evaluated from the stored curriculum data.';

        switch ($type) {
            case 'MIN_TOTAL_UNITS':
                $requirement = 'Total mapped subject units must be at least ' . $this->formatDecimal($threshold) . '.';
                $actual = $this->unitsBoth($totals);
                $result = $this->compareDecimal((string)$totals['total_units'], $threshold) >= 0 ? 'PASS' : 'FAIL';
                $explanation = ($result === 'PASS'
                    ? 'The mapped-subject unit total meets the configured minimum.'
                    : 'The mapped-subject unit total is below the configured minimum.') . ' ' . self::ELECTIVE_NOTE;
                break;

            case 'MAX_TOTAL_UNITS':
                $requirement = 'Total mapped subject units must not exceed ' . $this->formatDecimal($threshold) . '.';
                $actual = $this->unitsBoth($totals);
                $result = $this->compareDecimal((string)$totals['total_units'], $threshold) <= 0 ? 'PASS' : 'FAIL';
                $explanation = ($result === 'PASS'
                    ? 'The mapped-subject unit total is within the configured maximum.'
                    : 'The mapped-subject unit total exceeds the configured maximum.') . ' ' . self::ELECTIVE_NOTE;
                break;

            case 'REQUIRED_SUBJECT':
                $label = $this->subjectLabel($rule);
                $requirement = $label . ' must be mapped to the curriculum version.';
                $actual = $subject ? 'Subject is mapped.' : 'Subject is not mapped.';
                $result = $subject ? 'PASS' : 'FAIL';
                $explanation = $subject
                    ? 'The configured required subject is present in this curriculum version.'
                    : 'The configured required subject is absent from this curriculum version.';
                break;

            case 'MIN_SUBJECT_UNITS':
            case 'MAX_SUBJECT_UNITS':
                $label = $this->subjectLabel($rule);
                $isMinimum = $type === 'MIN_SUBJECT_UNITS';
                $requirement = $label . ' units must ' . ($isMinimum ? 'be at least ' : 'not exceed ')
                    . $this->formatDecimal($threshold) . '.';
                if (!$subject) {
                    $actual = 'Configured subject is not mapped.';
                    $result = 'NOT_EVALUATED';
                    $explanation = 'Subject-unit comparison was not evaluated because the configured subject is absent from this curriculum version.';
                    break;
                }
                $actual = $this->formatDecimal((string)$subject['units']) . ' subject units';
                $comparison = $this->compareDecimal((string)$subject['units'], $threshold);
                $result = ($isMinimum ? $comparison >= 0 : $comparison <= 0) ? 'PASS' : 'FAIL';
                $explanation = $result === 'PASS'
                    ? 'The mapped subject units satisfy the configured threshold.'
                    : 'The mapped subject units do not satisfy the configured threshold.';
                break;
        }

        return [
            'compliance_rule_id' => (int)$rule['id'],
            'rule_name' => (string)$rule['rule_name'],
            'rule_type' => $type,
            'reference_identifier' => $this->referenceIdentifier($rule),
            'reference_url' => $rule['source_url'] ?: null,
            'requirement' => $requirement,
            'actual_value' => $actual,
            'result' => $result,
            'explanation' => $explanation,
        ];
    }

    private function unitsBoth(array $totals): string
    {
        return 'units of all placements: ' . $this->formatDecimal((string)$totals['total_units']) . '; units of required placements: ' . $this->formatDecimal((string)($totals['required_units'] ?? '0'));
    }

    private function referenceIdentifier(array $rule): string
    {
        $identifier = $rule['reference_code'] ?: $rule['reference_title'];
        return '[' . $rule['reference_type'] . '] ' . ($identifier ?: 'Reference')
            . ' — ' . $rule['issuing_body'];
    }

    private function subjectLabel(array $rule): string
    {
        $code = $rule['subject_code'] ?: 'Configured subject';
        return $code . ($rule['subject_title'] ? ' — ' . $rule['subject_title'] : '');
    }

    private function compareDecimal(string $left, string $right): int
    {
        return $this->decimalHundredths($left) <=> $this->decimalHundredths($right);
    }

    private function decimalHundredths(string $value): int
    {
        $parts = explode('.', $value, 2);
        return ((int)$parts[0] * 100) + (int)str_pad(substr($parts[1] ?? '', 0, 2), 2, '0');
    }

    private function formatDecimal(?string $value): string
    {
        if ($value === null) {
            return '0';
        }
        return rtrim(rtrim(number_format((float)$value, 2, '.', ''), '0'), '.');
    }

    private function emptySummary(): array
    {
        return [
            'rules_evaluated' => 0,
            'passed' => 0,
            'failed' => 0,
            'not_evaluated' => 0,
            'pending' => 0,
        ];
    }
}
