<?php

require_once __DIR__ . '/DomainExceptions.php';

class DomainValidator
{
    public static function requiredString(array $input, string $key, string $label, int $maxLength): string
    {
        $rawValue = $input[$key] ?? '';
        if (!is_scalar($rawValue) && $rawValue !== null) {
            throw new DomainValidationException($label . ' must be text.');
        }
        $value = trim((string)$rawValue);
        if ($value === '') {
            throw new DomainValidationException($label . ' is required.');
        }
        if (strlen($value) > $maxLength) {
            throw new DomainValidationException($label . ' is too long.');
        }
        return $value;
    }

    public static function optionalString(array $input, string $key, string $label, int $maxLength): ?string
    {
        $rawValue = $input[$key] ?? '';
        if (!is_scalar($rawValue) && $rawValue !== null) {
            throw new DomainValidationException($label . ' must be text.');
        }
        $value = trim((string)$rawValue);
        if ($value === '') {
            return null;
        }
        if (strlen($value) > $maxLength) {
            throw new DomainValidationException($label . ' is too long.');
        }
        return $value;
    }

    public static function positiveId(mixed $value, string $label): int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            throw new DomainValidationException($label . ' must be a valid positive identifier.');
        }
        return (int)$id;
    }

    public static function optionalPositiveId(mixed $value, string $label): ?int
    {
        if (is_string($value)) {
            $value = trim($value);
        }
        if ($value === null || $value === '') {
            return null;
        }
        return self::positiveId($value, $label);
    }

    public static function nonNegativeDecimal(
        mixed $value,
        string $label,
        bool $nullable = false
    ): ?float {
        if (($value === null || $value === '') && $nullable) {
            return null;
        }
        if ($value === null || $value === '' || !is_numeric($value)) {
            throw new DomainValidationException($label . ' must be numeric.');
        }
        $number = (float)$value;
        if (!is_finite($number) || $number < 0 || $number > 9999.99) {
            throw new DomainValidationException($label . ' must be between 0 and 9999.99.');
        }
        return round($number, 2);
    }

    public static function optionalSequence(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $sequence = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($sequence === false) {
            throw new DomainValidationException('Sequence order must be a non-negative integer.');
        }
        return (int)$sequence;
    }

    public static function boolean(mixed $value, string $label): int
    {
        if (in_array($value, [1, '1', true], true)) {
            return 1;
        }
        if (in_array($value, [0, '0', false], true)) {
            return 0;
        }
        throw new DomainValidationException($label . ' must be true or false.');
    }

    public static function status(array $input, array $allowed, string $default): string
    {
        $rawStatus = $input['status'] ?? $default;
        if (!is_scalar($rawStatus) && $rawStatus !== null) {
            throw new DomainValidationException('Invalid technical record status.');
        }
        $status = trim((string)$rawStatus);
        if (!in_array($status, $allowed, true)) {
            throw new DomainValidationException('Invalid technical record status.');
        }
        return $status;
    }

    public static function listQuery(
        array $input,
        array $allowedStatuses = [],
        bool $allowAcademicProgramFilter = false
    ): array {
        $pageValue = $input['page'] ?? 1;
        $perPageValue = $input['per_page'] ?? 20;

        $page = filter_var($pageValue, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($page === false) {
            throw new DomainValidationException('Page must be a positive integer.');
        }

        $perPage = filter_var($perPageValue, FILTER_VALIDATE_INT);
        if ($perPage === false || !in_array((int)$perPage, [10, 20, 50], true)) {
            throw new DomainValidationException('Per page must be 10, 20, or 50.');
        }

        $search = self::optionalString($input, 'search', 'Search', 100);
        $status = self::optionalString($input, 'status', 'Status', 30);
        if ($status !== null && !in_array($status, $allowedStatuses, true)) {
            throw new DomainValidationException('Invalid technical record status filter.');
        }

        $academicProgramId = null;
        $academicProgramValue = $input['academic_program_id'] ?? null;
        if (is_string($academicProgramValue)) {
            $academicProgramValue = trim($academicProgramValue);
        }
        if ($allowAcademicProgramFilter
            && $academicProgramValue !== null
            && $academicProgramValue !== '') {
            $academicProgramId = self::positiveId($academicProgramValue, 'Academic program');
        }

        return [
            'page' => (int)$page,
            'per_page' => (int)$perPage,
            'search' => $search,
            'status' => $status,
            'academic_program_id' => $academicProgramId,
        ];
    }

    public static function academicProgram(array $input): array
    {
        return [
            'program_code' => self::requiredString($input, 'program_code', 'Program code', 60),
            'program_name' => self::requiredString($input, 'program_name', 'Program name', 255),
            'program_type' => self::optionalString($input, 'program_type', 'Program type', 100),
            'status' => self::status($input, ['active', 'inactive'], 'active'),
        ];
    }

    public static function subject(array $input): array
    {
        return [
            'subject_code' => self::requiredString($input, 'subject_code', 'Subject code', 60),
            'subject_title' => self::requiredString($input, 'subject_title', 'Subject title', 255),
            'description' => self::optionalString($input, 'description', 'Description', 60000),
            'units' => self::nonNegativeDecimal($input['units'] ?? null, 'Units'),
            'lecture_units' => self::nonNegativeDecimal($input['lecture_units'] ?? null, 'Lecture units', true),
            'laboratory_units' => self::nonNegativeDecimal($input['laboratory_units'] ?? null, 'Laboratory units', true),
            'status' => self::status($input, ['active', 'inactive'], 'active'),
        ];
    }

    public static function curriculum(array $input): array
    {
        return [
            'curriculum_code' => self::requiredString($input, 'curriculum_code', 'Curriculum code', 80),
            'curriculum_name' => self::requiredString($input, 'curriculum_name', 'Curriculum name', 255),
            'academic_program_id' => self::positiveId($input['academic_program_id'] ?? null, 'Academic program'),
            'status' => self::status($input, ['active', 'inactive'], 'active'),
        ];
    }

    public static function curriculumVersion(array $input): array
    {
        return [
            'curriculum_id' => self::positiveId($input['curriculum_id'] ?? null, 'Curriculum'),
            'version_label' => self::requiredString($input, 'version_label', 'Version label', 100),
            'effective_academic_year' => self::optionalString(
                $input,
                'effective_academic_year',
                'Effective academic year',
                50
            ),
            'description' => self::optionalString($input, 'description', 'Description', 60000),
            // Technical record lifecycle only; not an institutional approval state.
            'status' => self::status($input, ['draft', 'active', 'archived'], 'draft'),
        ];
    }

    public static function curriculumVersionSubject(array $input): array
    {
        return [
            'curriculum_version_id' => self::positiveId(
                $input['curriculum_version_id'] ?? null,
                'Curriculum version'
            ),
            'subject_id' => self::positiveId($input['subject_id'] ?? null, 'Subject'),
            'year_level' => self::optionalString($input, 'year_level', 'Year level', 50),
            'semester' => self::optionalString($input, 'semester', 'Semester', 50),
            'sequence_order' => self::optionalSequence($input['sequence_order'] ?? null),
            'is_required' => self::boolean($input['is_required'] ?? 1, 'Required indicator'),
        ];
    }

    public static function curriculumVersionSubjectPlacement(array $input): array
    {
        return [
            'year_level' => self::optionalString($input, 'year_level', 'Year level', 50),
            'semester' => self::optionalString($input, 'semester', 'Semester', 50),
            'sequence_order' => self::optionalSequence($input['sequence_order'] ?? null),
            'is_required' => self::boolean($input['is_required'] ?? 1, 'Required indicator'),
        ];
    }

    public static function subjectMappingQuery(array $input): array
    {
        $query = self::listQuery($input);
        $query['academic_program_id'] = self::optionalPositiveId(
            $input['academic_program_id'] ?? null,
            'Academic program'
        );
        $query['curriculum_id'] = self::optionalPositiveId(
            $input['curriculum_id'] ?? null,
            'Curriculum'
        );
        $query['curriculum_version_id'] = self::optionalPositiveId(
            $input['curriculum_version_id'] ?? null,
            'Curriculum version'
        );
        $query['year_level'] = self::optionalString($input, 'year_level', 'Year level', 50);
        $query['semester'] = self::optionalString($input, 'semester', 'Semester', 50);
        return $query;
    }

    public static function academicProgramAssignmentQuery(array $input): array
    {
        // Program-assignment views use the same confirmed placement filters as
        // Subject Mapping. This deliberately introduces no institutional
        // classification or program-specific academic rule.
        return self::subjectMappingQuery($input);
    }

    public static function subjectHistoryQuery(array $input): array
    {
        $query = self::subjectMappingQuery($input);
        $versionStatus = self::optionalString(
            $input,
            'version_status',
            'Version status',
            20
        );
        if ($versionStatus !== null
            && !in_array($versionStatus, ['draft', 'active', 'archived'], true)) {
            throw new DomainValidationException('Invalid technical version status filter.');
        }
        $query['version_status'] = $versionStatus;
        return $query;
    }

    public static function subjectHistoryComparisonQuery(array $input): array
    {
        $query = self::listQuery($input);
        $query['curriculum_id'] = self::positiveId(
            $input['curriculum_id'] ?? null,
            'Curriculum'
        );
        $query['version_a_id'] = self::positiveId(
            $input['version_a_id'] ?? null,
            'Version A'
        );
        $query['version_b_id'] = self::positiveId(
            $input['version_b_id'] ?? null,
            'Version B'
        );
        return $query;
    }

    public static function subjectEquivalencyPair(array $input): array
    {
        $subjectAId = self::positiveId($input['subject_a_id'] ?? null, 'Subject A');
        $subjectBId = self::positiveId($input['subject_b_id'] ?? null, 'Subject B');
        if ($subjectAId === $subjectBId) {
            throw new DomainValidationException(
                'A subject cannot be compared with itself for equivalency.'
            );
        }
        return ['subject_a_id' => $subjectAId, 'subject_b_id' => $subjectBId];
    }

    public static function subjectEquivalencyReview(array $input): array
    {
        $decision = self::requiredString(
            $input,
            'decision_status',
            'Decision',
            20
        );
        if (!in_array($decision, ['pending', 'equivalent', 'not_equivalent'], true)) {
            throw new DomainValidationException('Invalid equivalency review decision.');
        }
        $remarks = self::optionalString($input, 'remarks', 'Remarks', 4000);
        if ($decision !== 'pending' && $remarks === null) {
            throw new DomainValidationException(
                'Remarks are required for Equivalent or Not Equivalent decisions.'
            );
        }
        return ['decision_status' => $decision, 'remarks' => $remarks];
    }

    public static function subjectEquivalencyQuery(array $input): array
    {
        $query = self::listQuery($input);
        $decision = self::optionalString(
            $input,
            'decision_status',
            'Decision status',
            20
        );
        if ($decision !== null
            && !in_array($decision, ['pending', 'equivalent', 'not_equivalent'], true)) {
            throw new DomainValidationException('Invalid equivalency decision filter.');
        }
        $query['decision_status'] = $decision;
        return $query;
    }

    public static function subjectRequirement(array $input): array
    {
        $rawType = $input['requirement_type'] ?? '';
        if (!is_scalar($rawType) && $rawType !== null) {
            throw new DomainValidationException('Invalid relationship type.');
        }
        $type = trim((string)$rawType);
        if (!in_array($type, ['prerequisite', 'corequisite'], true)) {
            throw new DomainValidationException('Relationship type must be prerequisite or corequisite.');
        }

        return [
            'curriculum_version_id' => self::positiveId(
                $input['curriculum_version_id'] ?? null,
                'Curriculum version'
            ),
            'subject_id' => self::positiveId($input['subject_id'] ?? null, 'Target subject'),
            'required_subject_id' => self::positiveId(
                $input['required_subject_id'] ?? null,
                'Required subject'
            ),
            'requirement_type' => $type,
        ];
    }

    public static function gradeWeightingScheme(array $input): array
    {
        return [
            'curriculum_version_subject_id' => self::positiveId(
                $input['curriculum_version_subject_id'] ?? null,
                'Curriculum subject placement'
            ),
            'description' => self::optionalString(
                $input,
                'description',
                'Scheme description',
                4000
            ),
        ];
    }

    public static function gradeWeightingSchemeUpdate(array $input): array
    {
        return [
            'description' => self::optionalString(
                $input,
                'description',
                'Scheme description',
                4000
            ),
        ];
    }

    public static function gradeWeightingComponent(array $input): array
    {
        return self::gradeWeightingComponentFields($input) + [
            'scheme_id' => self::positiveId($input['scheme_id'] ?? null, 'Weighting scheme'),
        ];
    }

    public static function gradeWeightingComponentFields(array $input): array
    {
        return [
            'component_name' => self::requiredString(
                $input,
                'component_name',
                'Component name',
                150
            ),
            'weight_percentage' => self::weightPercentage(
                $input['weight_percentage'] ?? null
            ),
            'sequence_order' => self::optionalSequence($input['sequence_order'] ?? null),
        ];
    }

    public static function weightPercentage(mixed $value): string
    {
        $text = is_scalar($value) ? trim((string)$value) : '';
        if (!preg_match('/^\d{1,3}(?:\.\d{1,2})?$/D', $text)) {
            throw new DomainValidationException(
                'Weight percentage must be a number with no more than two decimal places.'
            );
        }
        $parts = explode('.', $text, 2);
        $whole = (int)$parts[0];
        $fraction = str_pad($parts[1] ?? '', 2, '0');
        $hundredths = ($whole * 100) + (int)$fraction;
        if ($hundredths <= 0 || $hundredths > 10000) {
            throw new DomainValidationException(
                'Weight percentage must be greater than 0 and no more than 100.'
            );
        }
        return intdiv($hundredths, 100) . '.' . str_pad(
            (string)($hundredths % 100),
            2,
            '0',
            STR_PAD_LEFT
        );
    }

    public static function complianceReference(array $input): array
    {
        $referenceType = strtoupper((string)self::requiredString(
            $input,
            'reference_type',
            'Reference type',
            30
        ));
        if (!in_array($referenceType, ['CHED', 'DEPED', 'INSTITUTIONAL', 'OTHER'], true)) {
            throw new DomainValidationException('Invalid compliance reference type.');
        }
        $referenceCode = self::optionalString($input, 'reference_code', 'Reference code', 120);
        $title = self::optionalString($input, 'title', 'Reference title', 255);
        if ($referenceCode === null && $title === null) {
            throw new DomainValidationException('Reference code or title is required.');
        }
        $sourceUrl = self::optionalString($input, 'source_url', 'Source URL', 1000);
        if ($sourceUrl !== null) {
            $parts = parse_url($sourceUrl);
            if (!filter_var($sourceUrl, FILTER_VALIDATE_URL)
                || !is_array($parts)
                || !in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true)) {
                throw new DomainValidationException('Source URL must be a valid HTTP or HTTPS URL.');
            }
        }
        $effectiveDate = self::optionalString($input, 'effective_date', 'Effective date', 10);
        if ($effectiveDate !== null) {
            $date = DateTime::createFromFormat('!Y-m-d', $effectiveDate);
            if (!$date || $date->format('Y-m-d') !== $effectiveDate) {
                throw new DomainValidationException('Effective date must be a valid date.');
            }
        }
        $verificationStatus = strtolower((string)self::requiredString(
            $input,
            'verification_status',
            'Verification status',
            20
        ));
        if (!in_array($verificationStatus, ['draft', 'verified', 'inactive'], true)) {
            throw new DomainValidationException('Invalid reference verification status.');
        }

        return [
            'reference_type' => $referenceType,
            'reference_code' => $referenceCode,
            'title' => $title,
            'issuing_body' => self::requiredString($input, 'issuing_body', 'Issuing body', 180),
            'source_url' => $sourceUrl,
            'effective_date' => $effectiveDate,
            'notes' => self::optionalString($input, 'notes', 'Reference notes', 60000),
            'verification_status' => $verificationStatus,
        ];
    }

    public static function complianceReferenceQuery(array $input): array
    {
        $query = self::listQuery($input);
        $type = self::optionalString($input, 'reference_type', 'Reference type', 30);
        if ($type !== null) {
            $type = strtoupper($type);
            if (!in_array($type, ['CHED', 'DEPED', 'INSTITUTIONAL', 'OTHER'], true)) {
                throw new DomainValidationException('Invalid reference type filter.');
            }
        }
        $status = self::optionalString($input, 'verification_status', 'Verification status', 20);
        if ($status !== null && !in_array($status, ['draft', 'verified', 'inactive'], true)) {
            throw new DomainValidationException('Invalid verification status filter.');
        }
        $query['reference_type'] = $type;
        $query['verification_status'] = $status;
        return $query;
    }

    public static function complianceRule(array $input): array
    {
        $ruleType = strtoupper((string)self::requiredString($input, 'rule_type', 'Rule type', 40));
        $allowed = [
            'MIN_TOTAL_UNITS',
            'MAX_TOTAL_UNITS',
            'REQUIRED_SUBJECT',
            'MIN_SUBJECT_UNITS',
            'MAX_SUBJECT_UNITS',
        ];
        if (!in_array($ruleType, $allowed, true)) {
            throw new DomainValidationException('Unsupported compliance rule type.');
        }

        $scopeType = strtolower((string)self::requiredString($input, 'scope_type', 'Scope type', 20));
        if (!in_array($scopeType, ['program', 'curriculum'], true)) {
            throw new DomainValidationException('Rule scope must be an academic program or curriculum.');
        }
        $scopeId = self::positiveId($input['scope_id'] ?? null, 'Rule scope');

        $numericTypes = [
            'MIN_TOTAL_UNITS', 'MAX_TOTAL_UNITS',
            'MIN_SUBJECT_UNITS', 'MAX_SUBJECT_UNITS',
        ];
        $subjectTypes = ['REQUIRED_SUBJECT', 'MIN_SUBJECT_UNITS', 'MAX_SUBJECT_UNITS'];
        $numericValue = in_array($ruleType, $numericTypes, true)
            ? self::complianceThreshold($input['numeric_value'] ?? null)
            : null;
        $subjectId = in_array($ruleType, $subjectTypes, true)
            ? self::positiveId($input['subject_id'] ?? null, 'Configured subject')
            : null;

        $technicalStatus = strtolower((string)self::requiredString(
            $input,
            'technical_status',
            'Technical status',
            20
        ));
        if (!in_array($technicalStatus, ['active', 'inactive'], true)) {
            throw new DomainValidationException('Invalid compliance rule technical status.');
        }

        return [
            'compliance_reference_id' => self::positiveId(
                $input['compliance_reference_id'] ?? null,
                'Compliance reference'
            ),
            'rule_name' => self::requiredString($input, 'rule_name', 'Rule name', 180),
            'rule_type' => $ruleType,
            'description' => self::optionalString($input, 'description', 'Rule description', 60000),
            'numeric_value' => $numericValue,
            'subject_id' => $subjectId,
            'academic_program_id' => $scopeType === 'program' ? $scopeId : null,
            'curriculum_id' => $scopeType === 'curriculum' ? $scopeId : null,
            'technical_status' => $technicalStatus,
        ];
    }

    public static function complianceThreshold(mixed $value): string
    {
        $text = is_scalar($value) ? trim((string)$value) : '';
        if (!preg_match('/^\d{1,8}(?:\.\d{1,2})?$/D', $text)) {
            throw new DomainValidationException(
                'Numeric threshold must be non-negative with no more than two decimal places.'
            );
        }
        $parts = explode('.', $text, 2);
        return (string)(int)$parts[0] . '.' . str_pad($parts[1] ?? '', 2, '0');
    }

    public static function complianceRuleQuery(array $input): array
    {
        $query = self::listQuery($input);
        $query['compliance_reference_id'] = self::optionalPositiveId(
            $input['compliance_reference_id'] ?? null,
            'Compliance reference'
        );
        $query['academic_program_id'] = self::optionalPositiveId(
            $input['academic_program_id'] ?? null,
            'Academic program'
        );
        $query['curriculum_id'] = self::optionalPositiveId(
            $input['curriculum_id'] ?? null,
            'Curriculum'
        );
        $ruleType = self::optionalString($input, 'rule_type', 'Rule type', 40);
        if ($ruleType !== null) {
            $ruleType = strtoupper($ruleType);
            if (!in_array($ruleType, [
                'MIN_TOTAL_UNITS', 'MAX_TOTAL_UNITS', 'REQUIRED_SUBJECT',
                'MIN_SUBJECT_UNITS', 'MAX_SUBJECT_UNITS',
            ], true)) {
                throw new DomainValidationException('Invalid rule type filter.');
            }
        }
        $technicalStatus = self::optionalString(
            $input,
            'technical_status',
            'Technical status',
            20
        );
        if ($technicalStatus !== null
            && !in_array($technicalStatus, ['active', 'inactive'], true)) {
            throw new DomainValidationException('Invalid rule technical status filter.');
        }
        $query['rule_type'] = $ruleType;
        $query['technical_status'] = $technicalStatus;
        return $query;
    }

    public static function complianceHistoryQuery(array $input): array
    {
        $query = self::listQuery($input);
        $query['academic_program_id'] = self::optionalPositiveId(
            $input['academic_program_id'] ?? null,
            'Academic program'
        );
        $query['curriculum_id'] = self::optionalPositiveId(
            $input['curriculum_id'] ?? null,
            'Curriculum'
        );
        $query['curriculum_version_id'] = self::optionalPositiveId(
            $input['curriculum_version_id'] ?? null,
            'Curriculum version'
        );
        return $query;
    }

    public static function subjectRequirementQuery(array $input): array
    {
        $query = self::listQuery($input);
        $query['academic_program_id'] = self::optionalPositiveId(
            $input['academic_program_id'] ?? null,
            'Academic program'
        );
        $query['curriculum_id'] = self::optionalPositiveId(
            $input['curriculum_id'] ?? null,
            'Curriculum'
        );
        $query['curriculum_version_id'] = self::optionalPositiveId(
            $input['curriculum_version_id'] ?? null,
            'Curriculum version'
        );
        $query['subject_id'] = self::optionalPositiveId(
            $input['subject_id'] ?? null,
            'Target subject'
        );

        $type = self::optionalString($input, 'requirement_type', 'Relationship type', 20);
        if ($type !== null && !in_array($type, ['prerequisite', 'corequisite'], true)) {
            throw new DomainValidationException('Invalid relationship type filter.');
        }
        $query['requirement_type'] = $type;
        return $query;
    }

    public static function electiveGroup(array $input): array
    {
        return [
            'curriculum_version_id' => self::positiveId(
                $input['curriculum_version_id'] ?? null,
                'Curriculum version'
            ),
            'group_name' => self::requiredString(
                $input,
                'group_name',
                'Elective group name',
                150
            ),
            'description' => self::optionalString(
                $input,
                'description',
                'Description',
                60000
            ),
        ];
    }

    public static function electiveGroupUpdate(array $input): array
    {
        return [
            'group_name' => self::requiredString(
                $input,
                'group_name',
                'Elective group name',
                150
            ),
            'description' => self::optionalString(
                $input,
                'description',
                'Description',
                60000
            ),
        ];
    }

    public static function electiveMembership(array $input): array
    {
        return [
            'elective_group_id' => self::positiveId(
                $input['elective_group_id'] ?? null,
                'Elective group'
            ),
            'subject_id' => self::positiveId($input['subject_id'] ?? null, 'Subject'),
        ];
    }
}
