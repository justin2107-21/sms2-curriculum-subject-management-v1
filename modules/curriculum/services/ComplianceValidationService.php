<?php

require_once __DIR__ . '/../validators/DomainValidator.php';
require_once __DIR__ . '/../repositories/AcademicProgramRepository.php';
require_once __DIR__ . '/../repositories/CurriculumRepository.php';
require_once __DIR__ . '/../repositories/CurriculumVersionRepository.php';
require_once __DIR__ . '/../repositories/SubjectRepository.php';
require_once __DIR__ . '/../repositories/ComplianceRuleRepository.php';
require_once __DIR__ . '/../repositories/ComplianceValidationRepository.php';
require_once __DIR__ . '/../../../shared/audit.php';

class ComplianceValidationService
{
    private AcademicProgramRepository $programs;
    private CurriculumRepository $curricula;
    private CurriculumVersionRepository $versions;
    private SubjectRepository $subjects;
    private ComplianceRuleRepository $rules;
    private ComplianceValidationRepository $validations;

    public function __construct(private mysqli $db)
    {
        $this->programs = new AcademicProgramRepository($db);
        $this->curricula = new CurriculumRepository($db);
        $this->versions = new CurriculumVersionRepository($db);
        $this->subjects = new SubjectRepository($db);
        $this->rules = new ComplianceRuleRepository($db);
        $this->validations = new ComplianceValidationRepository($db);
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

    public function run(int $versionId, int $actorId): array
    {
        $context = $this->validations->versionContext($versionId);
        if (!$context) {
            throw new DomainNotFoundException('Curriculum version not found.');
        }
        $rules = $this->rules->eligibleForContext(
            (int)$context['academic_program_id'],
            (int)$context['curriculum_id']
        );
        if (!$rules) {
            return [
                'run' => null,
                'context' => $context,
                'summary' => $this->emptySummary(),
                'results' => [],
                'message' => 'No active rules from verified references apply to this curriculum version.',
            ];
        }

        $totals = $this->validations->curriculumTotals($versionId);
        $summary = $this->emptySummary();
        $this->db->begin_transaction();
        try {
            $runId = $this->validations->createRun($versionId, $actorId);
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
                ]
            );
            $run = $this->validations->findRun($runId);
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
            'message' => 'Validation completed using active rules from verified references.',
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
        return $run;
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
                $actual = $this->formatDecimal((string)$totals['total_units']) . ' total units';
                $result = $this->compareDecimal((string)$totals['total_units'], $threshold) >= 0 ? 'PASS' : 'FAIL';
                $explanation = $result === 'PASS'
                    ? 'The mapped-subject unit total meets the configured minimum.'
                    : 'The mapped-subject unit total is below the configured minimum.';
                break;

            case 'MAX_TOTAL_UNITS':
                $requirement = 'Total mapped subject units must not exceed ' . $this->formatDecimal($threshold) . '.';
                $actual = $this->formatDecimal((string)$totals['total_units']) . ' total units';
                $result = $this->compareDecimal((string)$totals['total_units'], $threshold) <= 0 ? 'PASS' : 'FAIL';
                $explanation = $result === 'PASS'
                    ? 'The mapped-subject unit total is within the configured maximum.'
                    : 'The mapped-subject unit total exceeds the configured maximum.';
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
        ];
    }
}
