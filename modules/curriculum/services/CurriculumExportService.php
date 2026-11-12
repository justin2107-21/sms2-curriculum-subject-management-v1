<?php

require_once __DIR__ . '/../validators/DomainValidator.php';
require_once __DIR__ . '/../repositories/AcademicProgramRepository.php';
require_once __DIR__ . '/../repositories/CurriculumRepository.php';
require_once __DIR__ . '/../repositories/CurriculumVersionRepository.php';
require_once __DIR__ . '/../repositories/CurriculumExportRepository.php';
require_once __DIR__ . '/../../../shared/audit.php';
require_once __DIR__ . '/CurriculumPdfRenderer.php';

class CurriculumExportService
{
    private const UNSPECIFIED_SCOPE = '__UNSPECIFIED__';
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
        $query = DomainValidator::listQuery($input, ['draft', 'active', 'archived']);
        $query['scope_sql'] = CurriculumReadScope::exportSql('curriculum_versions');
        $result = $this->versions->paginateByCurriculum($curriculumId, $query);
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
        $scopeOptions = $this->scopeOptionsForRows($subjects);
        $yearFilter = trim((string)($input['year_level'] ?? ''));
        $semesterFilter = trim((string)($input['semester'] ?? ''));
        if ($yearFilter !== '' && !in_array($yearFilter, $scopeOptions['year_values'], true)) {
            throw new DomainValidationException('The selected year level is not available in this curriculum version.');
        }
        $availableSemesters = $yearFilter === ''
            ? $scopeOptions['semester_values']
            : ($scopeOptions['semesters_by_year'][$yearFilter] ?? []);
        if ($semesterFilter !== '' && !in_array($semesterFilter, $availableSemesters, true)) {
            throw new DomainValidationException('The selected semester or term is not available for this export scope.');
        }
        if ($yearFilter !== '' || $semesterFilter !== '') {
            $subjects = array_values(array_filter($subjects, function (array $subject) use ($yearFilter, $semesterFilter): bool {
                $year = $this->scopeValue($subject['year_level'] ?? null);
                $semester = $this->scopeValue($subject['semester'] ?? null);
                return ($yearFilter === '' || $year === $yearFilter)
                    && ($semesterFilter === '' || $semester === $semesterFilter);
            }));
        }
        // Normalize the authoritative mapping rows once. Preview, CSV and print
        // all consume this exact structure; no browser-side sample data exists.
        usort($subjects, function (array $left, array $right): int {
            $year = strnatcasecmp((string)($left['year_level'] ?? ''), (string)($right['year_level'] ?? ''));
            if ($year !== 0) return $year;
            $semester = strnatcasecmp((string)($left['semester'] ?? ''), (string)($right['semester'] ?? ''));
            if ($semester !== 0) return $semester;
            $leftSequence = $left['sequence_order'] === null ? PHP_INT_MAX : (int)$left['sequence_order'];
            $rightSequence = $right['sequence_order'] === null ? PHP_INT_MAX : (int)$right['sequence_order'];
            return ($leftSequence <=> $rightSequence)
                ?: strnatcasecmp((string)$left['subject_code'], (string)$right['subject_code'])
                ?: ((int)$left['placement_id'] <=> (int)$right['placement_id']);
        });

        $years = [];
        $totalUnitsHundredths = 0;
        $totalSubjects = count($subjects);
        $electiveTypes = [];
        foreach ($this->exports->electiveRows($versionId) as $electiveRow) {
            if ($electiveRow['membership_id'] === null || $electiveRow['subject_id'] === null) continue;
            $subjectId = (int)$electiveRow['subject_id'];
            $groupName = trim((string)$electiveRow['group_name']);
            $selections = (int)$electiveRow['required_selections'];
            $label = $groupName . ($selections > 0 ? ' · Choose ' . $selections : '');
            $electiveTypes[$subjectId][] = $label;
        }
        foreach ($subjects as &$subject) {
            $yearLabel = (string)($subject['year_level'] ?: 'Not specified');
            $semesterLabel = (string)($subject['semester'] ?: 'Not specified');
            $subject['year_level'] = $yearLabel;
            $subject['semester'] = $semesterLabel;
            $yearKey = $yearLabel;
            $semesterKey = $semesterLabel;
            if (!isset($years[$yearKey])) $years[$yearKey] = ['year_level' => $yearLabel, 'semesters' => [], 'total_units' => '0'];
            if (!isset($years[$yearKey]['semesters'][$semesterKey])) {
                $years[$yearKey]['semesters'][$semesterKey] = ['semester' => $semesterLabel, 'subjects' => [], 'total_units' => '0'];
            }
            $units = $this->decimalHundredths((string)$subject['units']);
            $totalUnitsHundredths += $units;
            $subject['placement_type'] = isset($electiveTypes[(int)$subject['subject_id']])
                ? implode('; ', array_values(array_unique($electiveTypes[(int)$subject['subject_id']])))
                : ((int)$subject['is_required'] === 1 ? 'Required' : 'Optional');
            $years[$yearKey]['semesters'][$semesterKey]['subjects'][] = $subject;
            $years[$yearKey]['semesters'][$semesterKey]['total_units'] = $this->formatHundredths(
                $this->decimalHundredths($years[$yearKey]['semesters'][$semesterKey]['total_units']) + $units
            );
            $years[$yearKey]['total_units'] = $this->formatHundredths(
                $this->decimalHundredths($years[$yearKey]['total_units']) + $units
            );
        }
        unset($subject);
        foreach ($years as &$year) {
            $year['semesters'] = array_values($year['semesters']);
        }
        unset($year);
        $yearLabel = $yearFilter === '' ? '' : $this->scopeLabel($yearFilter);
        $semesterLabel = $semesterFilter === '' ? '' : $this->scopeLabel($semesterFilter);
        $scopeLabel = $yearLabel === ''
            ? ($semesterLabel === '' ? 'Entire Curriculum' : 'All Year Levels · ' . $semesterLabel)
            : ($yearLabel . ' · ' . ($semesterLabel === '' ? 'All Semesters / Terms' : $semesterLabel));
        $outcomeMappings = strtoupper((string)$context['program_code']) === 'BSIT'
            ? $this->exports->outcomeMappings($versionId) : [];
        $cloAssessment = $this->exports->cloAssessmentRecords($versionId);
        return [
            'context' => $context,
            'scope' => ['year_level' => $yearLabel, 'semester' => $semesterLabel, 'label' => $scopeLabel],
            'summary' => ['total_subjects' => $totalSubjects, 'total_units' => $this->formatHundredths($totalUnitsHundredths)],
            'subjects' => $subjects,
            'graduate_outcomes' => $outcomeMappings,
            'course_learning_outcomes' => $cloAssessment,
            'years' => array_values($years),
            // Retain explicitly requested legacy report sections for existing API
            // consumers. The Curriculum Export page does not select these by default.
            'options' => $options,
            'requirements' => $options['requirements'] ? $this->exports->requirements($versionId) : [],
            'elective_groups' => $options['electives'] ? $this->groupElectives($this->exports->electiveRows($versionId)) : [],
            'grade_weighting' => $options['grade_weighting'] ? $this->groupWeighting($this->exports->gradeWeightingRows($versionId)) : [],
            'latest_compliance_run' => $options['compliance'] ? $this->decorateComplianceRun($this->exports->latestComplianceRun($versionId)) : null,
            'generated_at' => (new DateTimeImmutable('now'))->format('Y-m-d H:i:s P'),
            'disclaimer' => 'This report is generated from stored curriculum data in the system and does not by itself constitute official CHED, DepEd, accreditation, or institutional approval. Curriculum mapping does not establish student outcome attainment, regulatory compliance, or institutional approval.',
        ];
    }

    public function csvFilename(array $dataset): string
    {
        $context = $dataset['context'];
        $parts = [$context['program_code'], $context['curriculum_code'], $context['version_label']];
        if (($dataset['scope']['year_level'] ?? '') !== '') $parts[] = $dataset['scope']['year_level'];
        if (($dataset['scope']['semester'] ?? '') !== '') $parts[] = $dataset['scope']['semester'];
        $filename = implode('_', array_map([$this, 'filenamePart'], $parts));
        return substr($filename, 0, 180) . '.csv';
    }

    public function scopeOptions(array $input): array
    {
        $programId = DomainValidator::positiveId($input['academic_program_id'] ?? null, 'Academic program');
        $curriculumId = DomainValidator::positiveId($input['curriculum_id'] ?? null, 'Curriculum');
        $versionId = DomainValidator::positiveId($input['curriculum_version_id'] ?? null, 'Curriculum version');
        $context = $this->exports->contextByVersion($versionId);
        if (!$context) throw new DomainNotFoundException('Curriculum version not found.');
        if ((int)$context['curriculum_id'] !== $curriculumId || (int)$context['academic_program_id'] !== $programId) {
            throw new DomainValidationException('The selected curriculum context is invalid.');
        }
        $rows = $this->exports->subjects($versionId);
        $options = $this->scopeOptionsForRows($rows);
        $year = trim((string)($input['year_level'] ?? ''));
        $semesters = $year === '' ? $options['semester_values'] : ($options['semesters_by_year'][$year] ?? []);
        return [
            'year_levels' => array_map(fn(string $value): array => ['value' => $value, 'label' => $this->scopeLabel($value)], $options['year_values']),
            'semesters' => array_map(fn(string $value): array => ['value' => $value, 'label' => $this->scopeLabel($value)], $semesters),
        ];
    }

    private function scopeOptionsForRows(array $rows): array
    {
        $years = $semesters = $byYear = [];
        foreach ($rows as $row) {
            $year = $this->scopeValue($row['year_level'] ?? null);
            $semester = $this->scopeValue($row['semester'] ?? null);
            $years[$year] = true;
            $semesters[$semester] = true;
            $byYear[$year][$semester] = true;
        }
        $sort = function (array $values): array {
            $values = array_keys($values);
            usort($values, fn(string $a, string $b): int => strnatcasecmp($this->scopeLabel($a), $this->scopeLabel($b)));
            return $values;
        };
        foreach ($byYear as $year => $values) $byYear[$year] = $sort($values);
        return ['year_values' => $sort($years), 'semester_values' => $sort($semesters), 'semesters_by_year' => $byYear];
    }

    private function scopeValue(mixed $value): string
    {
        return trim((string)($value ?? '')) === '' ? self::UNSPECIFIED_SCOPE : trim((string)$value);
    }

    private function scopeLabel(string $value): string
    {
        return $value === self::UNSPECIFIED_SCOPE ? 'Not specified' : $value;
    }

    private function formatHundredths(int $value): string
    {
        return intdiv($value, 100) . '.' . str_pad((string)($value % 100), 2, '0', STR_PAD_LEFT);
    }

    public const MIN_EXPORT_PASSWORD = 10;
    public const MAX_EXPORT_PASSWORD = 128;

    /** Validates a protection password and its confirmation. Messages never contain the password. */
    public static function validateExportPassword(mixed $password, mixed $confirmation): string
    {
        if (!is_string($password) || $password === '') throw new DomainValidationException('Enter a password to protect the export.');
        if (!is_string($confirmation) || $confirmation === '') throw new DomainValidationException('Confirm the password.');
        if (trim($password) === '') throw new DomainValidationException('The password cannot be only spaces.');
        if (mb_strlen($password, 'UTF-8') < self::MIN_EXPORT_PASSWORD) throw new DomainValidationException('The password must be at least ' . self::MIN_EXPORT_PASSWORD . ' characters.');
        if (mb_strlen($password, 'UTF-8') > self::MAX_EXPORT_PASSWORD) throw new DomainValidationException('The password must be at most ' . self::MAX_EXPORT_PASSWORD . ' characters.');
        if (!hash_equals($password, $confirmation)) throw new DomainValidationException('The password and its confirmation do not match.');
        return $password;
    }

    /**
     * AES-256 encrypted ZIP holding one file. The password is used once, in memory. The temporary archive has a random name
     * unrelated to the password and is deleted before returning, also on failure. Errors are generic and never include the password.
     */
    public function protectedZip(string $content, string $entryName, string $password): string
    {
        if (!class_exists('ZipArchive') || !method_exists('ZipArchive', 'setEncryptionName')) {
            throw new RuntimeException('Encrypted export is not available on this server.');
        }
        $tmp = tempnam(sys_get_temp_dir(), 'cx');
        if ($tmp === false) throw new RuntimeException('Unable to prepare the protected export.');
        try {
            $zip = new ZipArchive();
            if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new RuntimeException('Unable to prepare the protected export.');
            $ok = $zip->addFromString($entryName, $content) && $zip->setEncryptionName($entryName, ZipArchive::EM_AES_256, $password);
            $closed = $zip->close();
            if (!$ok || !$closed) throw new RuntimeException('Unable to encrypt the export.');
            $bytes = file_get_contents($tmp);
            if ($bytes === false || $bytes === '') throw new RuntimeException('Unable to read the protected export.');
            return $bytes;
        } finally {
            if (is_file($tmp)) @unlink($tmp);
        }
    }

    public function zipFilename(array $dataset, string $kind = 'csv'): string
    {
        return substr($this->csvFilename($dataset), 0, -4) . ($kind === 'pdf' ? '_pdf' : '') . '_protected.zip';
    }

    public function recordCsvGeneration(array $dataset): void
    {
        $this->recordGeneration($dataset, 'csv');
    }

    public function recordPrintGeneration(array $dataset): void
    {
        $this->recordGeneration($dataset, 'print_html');
    }

    /** Audit row for a password-protected export. Records that protection was used, never the password or anything derived from it. */
    public function recordProtectedGeneration(array $dataset, string $format = 'csv_zip_aes256'): void
    {
        $this->recordGeneration($dataset, $format, 'Password-protected curriculum export generated.', true);
    }

    public function recordPdfGeneration(array $dataset): void
    {
        $this->recordGeneration($dataset, 'pdf', 'Curriculum PDF export generated.', false);
    }

    /** PDF bytes drawn from the same dataset as the CSV and the preview. */
    public function pdf(array $dataset): string
    {
        return CurriculumPdfRenderer::render($dataset);
    }

    public function pdfFilename(array $dataset): string
    {
        return substr($this->csvFilename($dataset), 0, -4) . '.pdf';
    }

    private function recordGeneration(array $dataset, string $format, string $description = 'Curriculum CSV export generated.', bool $protected = false): void
    {
        $context = $dataset['context'];
        audit_log_required(
            'CURRICULUM_EXPORT_GENERATE',
            'curriculum_version',
            (int)$context['curriculum_version_id'],
            $description,
            [
                'academic_program_id' => (int)$context['academic_program_id'],
                'curriculum_id' => (int)$context['curriculum_id'],
                'curriculum_version_id' => (int)$context['curriculum_version_id'],
                'version_label' => (string)$context['version_label'],
                'review_status' => (string)$context['version_review_status'],
                'scope' => (string)($dataset['scope']['label'] ?? ''),
                'format' => $format,
                'protected' => $protected,
                'subject_count' => (int)$dataset['summary']['total_subjects'],
                'total_units' => (string)$dataset['summary']['total_units'],
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
        $this->csvRow($stream, [
            'Program Code', 'Program Name', 'Curriculum Code', 'Curriculum Name',
            'Curriculum Version', 'Year Level', 'Semester / Term', 'Subject Code',
            'Subject Title', 'Units', 'Placement Type',
        ]);
        foreach ($dataset['subjects'] as $subject) {
            $this->csvRow($stream, [
                $context['program_code'], $context['program_name'],
                $context['curriculum_code'], $context['curriculum_name'], $context['version_label'],
                $subject['year_level'] ?? '', $subject['semester'] ?? '',
                $subject['subject_code'], $subject['subject_title'], $subject['units'], $subject['placement_type'],
            ]);
        }
        if (!empty($dataset['graduate_outcomes'])) {
            $this->csvRow($stream, []);
            $this->csvRow($stream, ['CHED CMO 25 BSIT Graduate Outcome Mapping — source and coverage; not attainment or compliance evidence']);
            $this->csvRow($stream, ['Disclaimer','Curriculum mapping does not establish student outcome attainment, regulatory compliance, or institutional approval.']);
            $this->csvRow($stream, ['Outcome Code','Outcome Description','Source Document','Document Title','Section','Printed Page','PDF Page','Source SHA-256','Coverage Status','Subject Code','Subject Title','Year','Semester','Mapping Rationale','Mapping Origin']);
            foreach ($dataset['graduate_outcomes'] as $outcome) {
                if (!$outcome['mappings']) $this->csvRow($stream,[$outcome['outcome_code'],$outcome['outcome_text'],$outcome['document_code'],$outcome['document_title'],$outcome['source_section'],$outcome['printed_page'],$outcome['pdf_page_index'],$outcome['source_sha256'],$outcome['coverage_status']]);
                foreach($outcome['mappings'] as $mapping)$this->csvRow($stream,[$outcome['outcome_code'],$outcome['outcome_text'],$outcome['document_code'],$outcome['document_title'],$outcome['source_section'],$outcome['printed_page'],$outcome['pdf_page_index'],$outcome['source_sha256'],$outcome['coverage_status'],$mapping['subject_code'],$mapping['subject_title'],$mapping['year_level'],$mapping['semester'],$mapping['rationale'],$mapping['mapping_origin']]);
            }
        }
        if (!empty($dataset['course_learning_outcomes'])) {
            $this->csvRow($stream, []);
            $this->csvRow($stream, ['Course Learning Outcomes and Planned Assessment','Planning records only; no student attainment is represented.']);
            $this->csvRow($stream, ['Subject','CLO code','CLO statement','CLO status','Review state','Record type','Graduate outcome','Alignment rationale','Assessment method','Planned task','Evidence','Performance indicator','Rubric / standard','Record review state']);
            foreach ($dataset['course_learning_outcomes'] as $clo) {
                $identity=[$clo['subject_code'].' · '.$clo['subject_title'],$clo['clo_code'],$clo['clo_statement'],$clo['record_state'],$clo['review_state']];
                if (!$clo['alignments'] && !$clo['assessment_plans']) $this->csvRow($stream,array_merge($identity,['CLO','','','','','','','','']));
                foreach($clo['alignments'] as $alignment) $this->csvRow($stream,array_merge($identity,['Graduate outcome alignment',$alignment['outcome_code'],$alignment['rationale'],'','','','','',$alignment['review_state']]));
                foreach($clo['assessment_plans'] as $plan) $this->csvRow($stream,array_merge($identity,['Planned assessment','','',$plan['assessment_method'],$plan['planned_task'],$plan['evidence_description'],$plan['performance_indicator'],$plan['rubric_or_standard'],$plan['review_state']]));
            }
        }
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
        // Counts in the words used on the Compliance Validator page. There is no overall verdict.
        $run['met_count'] = (int)$run['passed_count'];
        $run['not_met_count'] = (int)$run['failed_count'];
        $run['cannot_determine_count'] = (int)$run['not_evaluated_count'];
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
