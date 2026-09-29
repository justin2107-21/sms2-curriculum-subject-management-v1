<?php

require_once __DIR__ . '/../validators/DomainValidator.php';
require_once __DIR__ . '/../repositories/AcademicProgramRepository.php';
require_once __DIR__ . '/../repositories/CurriculumRepository.php';
require_once __DIR__ . '/../repositories/CurriculumVersionRepository.php';
require_once __DIR__ . '/../repositories/CurriculumExportRepository.php';
require_once __DIR__ . '/../../../shared/audit.php';

class CurriculumExportService
{
    private AcademicProgramRepository $programs;
    private CurriculumRepository $curricula;
    private CurriculumVersionRepository $versions;
    private CurriculumExportRepository $exports;

    public function __construct(private mysqli $db)
    {
        $this->programs = new AcademicProgramRepository($db);
        $this->curricula = new CurriculumRepository($db);
        $this->versions = new CurriculumVersionRepository($db);
        $this->exports = new CurriculumExportRepository($db);
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

    public function dataset(array $input): array
    {
        $programId = DomainValidator::positiveId(
            $input['academic_program_id'] ?? null,
            'Academic program'
        );
        $curriculumId = DomainValidator::positiveId(
            $input['curriculum_id'] ?? null,
            'Curriculum'
        );
        $versionId = DomainValidator::positiveId(
            $input['curriculum_version_id'] ?? null,
            'Curriculum version'
        );
        $context = $this->exports->contextByVersion($versionId);
        if (!$context) {
            throw new DomainNotFoundException('Curriculum version not found.');
        }
        if ((int)$context['curriculum_id'] !== $curriculumId) {
            throw new DomainValidationException(
                'The selected curriculum version does not belong to the selected curriculum.'
            );
        }
        if ((int)$context['academic_program_id'] !== $programId) {
            throw new DomainValidationException(
                'The selected curriculum does not belong to the selected academic program.'
            );
        }

        $options = $this->exportOptions($input);
        $subjects = $this->exports->subjects($versionId);
        $summary = $this->exports->unitSummary($versionId);

        return [
            'context' => $context,
            'summary' => [
                'total_subjects' => (int)$summary['total_subjects'],
                'total_units' => (string)$summary['total_units'],
                'total_lecture_units' => (string)$summary['total_lecture_units'],
                'total_laboratory_units' => (string)$summary['total_laboratory_units'],
            ],
            'subjects' => $subjects,
            'options' => $options,
            'requirements' => $options['requirements']
                ? $this->exports->requirements($versionId) : [],
            'elective_groups' => $options['electives']
                ? $this->groupElectives($this->exports->electiveRows($versionId)) : [],
            'grade_weighting' => $options['grade_weighting']
                ? $this->groupWeighting($this->exports->gradeWeightingRows($versionId)) : [],
            'latest_compliance_run' => $options['compliance']
                ? $this->decorateComplianceRun($this->exports->latestComplianceRun($versionId))
                : null,
            'generated_at' => (new DateTimeImmutable('now'))->format('Y-m-d H:i:s P'),
            'disclaimer' => 'This report is generated from stored curriculum data in the system and does not by itself constitute official CHED, DepEd, accreditation, or institutional approval.',
        ];
    }

    public function csvFilename(array $dataset): string
    {
        $context = $dataset['context'];
        $parts = [
            'curriculum', $context['program_code'], $context['curriculum_code'],
            $context['version_label'],
        ];
        $filename = implode('_', array_map([$this, 'filenamePart'], $parts));
        return substr($filename, 0, 180) . '.csv';
    }

    public function recordCsvGeneration(array $dataset): void
    {
        $context = $dataset['context'];
        $included = ['curriculum_subjects'];
        foreach ($dataset['options'] as $section => $enabled) {
            if ($enabled) {
                $included[] = $section;
            }
        }
        audit_log_required(
            'CURRICULUM_EXPORT_GENERATE',
            'curriculum_version',
            (int)$context['curriculum_version_id'],
            'Generic curriculum CSV export generated.',
            [
                'academic_program_id' => (int)$context['academic_program_id'],
                'curriculum_id' => (int)$context['curriculum_id'],
                'curriculum_version_id' => (int)$context['curriculum_version_id'],
                'format' => 'csv',
                'included_sections' => $included,
            ]
        );
    }

    public function writeCsv($stream, array $dataset): void
    {
        if (!is_resource($stream)) {
            throw new InvalidArgumentException('CSV output stream is unavailable.');
        }
        fwrite($stream, "\xEF\xBB\xBF");
        $context = $dataset['context'];
        $this->csvRow($stream, ['Generic SMS2 Curriculum Export']);
        $this->csvRow($stream, ['Generated At', $dataset['generated_at']]);
        $this->csvRow($stream, ['Academic Program Code', $context['program_code']]);
        $this->csvRow($stream, ['Academic Program Name', $context['program_name']]);
        $this->csvRow($stream, ['Program Type', $context['program_type'] ?? '']);
        $this->csvRow($stream, ['Curriculum Code', $context['curriculum_code']]);
        $this->csvRow($stream, ['Curriculum Name', $context['curriculum_name']]);
        $this->csvRow($stream, ['Curriculum Version', $context['version_label']]);
        $this->csvRow($stream, ['Effective Academic Year', $context['effective_academic_year'] ?? '']);
        $this->csvRow($stream, ['Technical Version Status', $context['version_status']]);
        $this->csvRow($stream, []);

        $this->csvRow($stream, ['Section', 'Curriculum Subjects']);
        $this->csvRow($stream, [
            'Academic Program Code', 'Academic Program Name', 'Program Type',
            'Curriculum Code', 'Curriculum Name', 'Curriculum Version',
            'Effective Academic Year', 'Technical Version Status',
            'Subject Code', 'Subject Title', 'Description', 'Total Units',
            'Lecture Units', 'Laboratory Units', 'Year Level', 'Semester / Term',
            'Sequence Order', 'Placement',
        ]);
        foreach ($dataset['subjects'] as $subject) {
            $this->csvRow($stream, [
                $context['program_code'], $context['program_name'], $context['program_type'] ?? '',
                $context['curriculum_code'], $context['curriculum_name'], $context['version_label'],
                $context['effective_academic_year'] ?? '', $context['version_status'],
                $subject['subject_code'], $subject['subject_title'], $subject['description'] ?? '',
                $subject['units'], $subject['lecture_units'] ?? '',
                $subject['laboratory_units'] ?? '', $subject['year_level'] ?? '',
                $subject['semester'] ?? '', $subject['sequence_order'] ?? '',
                (int)$subject['is_required'] === 1 ? 'Required Placement' : 'Optional Placement',
            ]);
        }

        if ($dataset['options']['requirements']) {
            $this->csvRow($stream, []);
            $this->csvRow($stream, ['Section', 'Prerequisite / Corequisite Relationships']);
            $this->csvRow($stream, [
                'Target Subject Code', 'Target Subject Title', 'Relationship Type',
                'Required Subject Code', 'Required Subject Title',
            ]);
            foreach ($dataset['requirements'] as $item) {
                $this->csvRow($stream, [
                    $item['subject_code'], $item['subject_title'], ucfirst($item['requirement_type']),
                    $item['required_subject_code'], $item['required_subject_title'],
                ]);
            }
        }

        if ($dataset['options']['electives']) {
            $this->csvRow($stream, []);
            $this->csvRow($stream, ['Section', 'Elective Groups']);
            $this->csvRow($stream, ['Group Name', 'Description', 'Member Subject Code', 'Member Subject Title']);
            foreach ($dataset['elective_groups'] as $group) {
                if (!$group['subjects']) {
                    $this->csvRow($stream, [$group['group_name'], $group['description'] ?? '', '', '']);
                }
                foreach ($group['subjects'] as $subject) {
                    $this->csvRow($stream, [
                        $group['group_name'], $group['description'] ?? '',
                        $subject['subject_code'], $subject['subject_title'],
                    ]);
                }
            }
        }

        if ($dataset['options']['grade_weighting']) {
            $this->csvRow($stream, []);
            $this->csvRow($stream, ['Section', 'Grade Weighting Configuration Summary']);
            $this->csvRow($stream, [
                'Subject Code', 'Subject Title', 'Component', 'Weight Percentage',
                'Total Configuration Weight', 'Configuration Status',
            ]);
            foreach ($dataset['grade_weighting'] as $scheme) {
                if (!$scheme['components']) {
                    $this->csvRow($stream, [
                        $scheme['subject_code'], $scheme['subject_title'], '', '',
                        $scheme['total_weight'], ucfirst($scheme['completion_status']),
                    ]);
                }
                foreach ($scheme['components'] as $component) {
                    $this->csvRow($stream, [
                        $scheme['subject_code'], $scheme['subject_title'],
                        $component['component_name'], $component['weight_percentage'],
                        $scheme['total_weight'], ucfirst($scheme['completion_status']),
                    ]);
                }
            }
        }

        if ($dataset['options']['compliance']) {
            $this->csvRow($stream, []);
            $this->csvRow($stream, ['Section', 'Latest Stored Compliance Validation Summary']);
            $run = $dataset['latest_compliance_run'];
            if ($run === null) {
                $this->csvRow($stream, ['Status', 'No stored validation run available.']);
            } else {
                $this->csvRow($stream, [
                    'Run Date', 'Rules Evaluated', 'Passed', 'Failed',
                    'Not Evaluated', 'Run By',
                ]);
                $this->csvRow($stream, [
                    $run['created_at'], $run['rules_evaluated'], $run['passed_count'],
                    $run['failed_count'], $run['not_evaluated_count'], $run['run_by_name'],
                ]);
            }
        }

        $this->csvRow($stream, []);
        $this->csvRow($stream, ['Notice', $dataset['disclaimer']]);
    }

    private function exportOptions(array $input): array
    {
        return [
            'requirements' => $this->booleanOption($input, 'include_requirements'),
            'electives' => $this->booleanOption($input, 'include_electives'),
            'grade_weighting' => $this->booleanOption($input, 'include_grade_weighting'),
            'compliance' => $this->booleanOption($input, 'include_compliance'),
        ];
    }

    private function booleanOption(array $input, string $key): bool
    {
        $value = $input[$key] ?? '0';
        if (in_array($value, [1, '1', true], true)) {
            return true;
        }
        if (in_array($value, [0, '0', false, '', null], true)) {
            return false;
        }
        throw new DomainValidationException('Invalid export section selection.');
    }

    private function groupElectives(array $rows): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $id = (int)$row['elective_group_id'];
            if (!isset($groups[$id])) {
                $groups[$id] = [
                    'id' => $id,
                    'group_name' => $row['group_name'],
                    'description' => $row['description'],
                    'subjects' => [],
                ];
            }
            if ($row['membership_id'] !== null) {
                $groups[$id]['subjects'][] = [
                    'id' => (int)$row['subject_id'],
                    'subject_code' => $row['subject_code'],
                    'subject_title' => $row['subject_title'],
                ];
            }
        }
        return array_values($groups);
    }

    private function groupWeighting(array $rows): array
    {
        $schemes = [];
        foreach ($rows as $row) {
            $id = (int)$row['scheme_id'];
            if (!isset($schemes[$id])) {
                $total = $row['total_weight'] === null ? '0.00' : (string)$row['total_weight'];
                $schemes[$id] = [
                    'id' => $id,
                    'subject_id' => (int)$row['subject_id'],
                    'subject_code' => $row['subject_code'],
                    'subject_title' => $row['subject_title'],
                    'description' => $row['scheme_description'],
                    'total_weight' => $total,
                    'completion_status' => $this->decimalHundredths($total) === 10000
                        ? 'complete' : 'incomplete',
                    'components' => [],
                ];
            }
            if ($row['component_id'] !== null) {
                $schemes[$id]['components'][] = [
                    'id' => (int)$row['component_id'],
                    'component_name' => $row['component_name'],
                    'weight_percentage' => (string)$row['weight_percentage'],
                    'sequence_order' => $row['sequence_order'] === null
                        ? null : (int)$row['sequence_order'],
                ];
            }
        }
        return array_values($schemes);
    }

    private function decorateComplianceRun(?array $run): ?array
    {
        if ($run === null) {
            return null;
        }
        $name = trim((string)$run['run_by_first_name'] . ' ' . (string)$run['run_by_last_name']);
        $run['run_by_name'] = $name !== '' ? $name : ($run['run_by_username'] ?: 'Unknown user');
        return $run;
    }

    private function decimalHundredths(string $value): int
    {
        $parts = explode('.', $value, 2);
        return ((int)$parts[0] * 100) + (int)str_pad(substr($parts[1] ?? '', 0, 2), 2, '0');
    }

    private function filenamePart(mixed $value): string
    {
        $text = trim((string)$value);
        if (function_exists('iconv')) {
            $converted = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
            if ($converted !== false) {
                $text = $converted;
            }
        }
        $text = preg_replace('/[^A-Za-z0-9._-]+/', '-', $text) ?? '';
        $text = trim($text, '.-_');
        return $text !== '' ? $text : 'record';
    }

    private function csvRow($stream, array $cells): void
    {
        $safe = array_map([$this, 'csvSafeCell'], $cells);
        if (PHP_VERSION_ID >= 80100) {
            if (fputcsv($stream, $safe, ',', '"', '', "\r\n") === false) {
                throw new RuntimeException('Unable to generate CSV output.');
            }
            return;
        }
        // PHP 8.0 has no fputcsv EOL argument. Convert only the record terminator,
        // never embedded newlines/quotes, and do not require a seekable output.
        $rowStream = fopen('php://temp', 'w+b');
        if ($rowStream === false) {
            throw new RuntimeException('Unable to prepare CSV row.');
        }
        try {
            if (fputcsv($rowStream, $safe, ',', '"', '') === false) {
                throw new RuntimeException('Unable to generate CSV row.');
            }
            rewind($rowStream);
            $row = stream_get_contents($rowStream);
            if ($row === false) {
                throw new RuntimeException('Unable to read CSV row.');
            }
            $row = substr($row, 0, -1) . "\r\n";
            if (fwrite($stream, $row) !== strlen($row)) {
                throw new RuntimeException('Unable to generate CSV output.');
            }
        } finally {
            fclose($rowStream);
        }
    }

    private function csvSafeCell(mixed $value): string
    {
        $text = $value === null ? '' : (string)$value;
        if (preg_match('/^[\x00-\x20]*[=+\-@]/', $text) === 1) {
            return "'" . $text;
        }
        return $text;
    }
}
