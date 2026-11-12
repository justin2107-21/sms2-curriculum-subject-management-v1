<?php

/**
 * The rule library as data: values checked by the project reviewer against the original documents. Nothing is guessed and nothing here
 * is applied as a default anywhere else (the DepEd grading weights, for example, are NOT default weights for Grade Weighting).
 * Used by scripts/compliance-seed-references.php (idempotent upsert by rule_code) and by tests.
 * Each rule names its source by the reference code the seed script creates; a client reply has no reference.
 */
final class ComplianceLibraryCatalog
{
    public const CLIENT_REPLY_LABEL = 'Provided by the AA office in a signed questionnaire, Nov 2026; not a published policy';
    public const TWO_FIGURES_NOTE = 'The CMO 25 table figure for general education (54 units) and the CMO 20 minimum (36 units) are two different figures. The system gives no verdict on which one governs.';
    public const INTERPRETED_NOTE = 'Applies to: all college programs (from the source\'s scope). This applicability is interpreted from the source\'s title and scope; it is not stated for one program.';
    public const PENDING_NOTE = 'Pending BCP confirmation: it is not recorded here whether BCP offers Senior High School. DO 015 paras 4 and 6 say non-DepEd schools are encouraged to follow it and that modifications need DepEd Regional Office approval. Not evaluated.';

    /** @return list<array<string,mixed>> */
    public static function rules(): array
    {
        $college = ['applies_to_kind' => 'all_college', 'applies_to_value' => null];
        $shs = ['applies_to_kind' => 'senior_high', 'applies_to_value' => null];
        $public = ['source_class' => 'public_regulation'];
        return [
            [
                'rule_code' => 'CMO25-BSIT-MIN-UNITS', 'reference_code' => 'CMO 25 s.2015', 'section_label' => 'Art. V Sec. 8.1', 'source_page' => '10',
                'title' => 'BSIT minimum total units (CMO 25 s.2015 Art. V Sec. 8.1)',
                'requirement_text' => 'The BSIT program has at least 146 units in total.',
                'applies_to_kind' => 'program_code', 'applies_to_value' => 'BSIT', 'computation_key' => 'MIN_TOTAL_UNITS', 'parameters' => ['min_units' => 146],
                'evaluation_mode' => 'evaluate', 'note' => 'The total sums all placements of the version. Elective units count toward the total only up to the required amount.',
            ] + $public,
            [
                'rule_code' => 'CMO25-BSIT-COMPONENTS', 'reference_code' => 'CMO 25 s.2015', 'section_label' => 'Art. V Sec. 8.1 table (BSIT components)', 'source_page' => null,
                'title' => 'BSIT units by component (CMO 25 s.2015 Sec. 8.1 table)',
                'requirement_text' => 'General Education 54; common courses 18; professional courses 48; professional electives 12; additional math 0; PE 8; NSTP 6 (146 in total).',
                'applies_to_kind' => 'program_code', 'applies_to_value' => 'BSIT', 'computation_key' => 'COMPONENT_UNITS',
                'parameters' => ['components' => ['General Education' => 54, 'Common courses' => 18, 'Professional courses' => 48, 'Professional electives' => 12, 'Additional math' => 0, 'PE' => 8, 'NSTP' => 6], 'total' => 146],
                'evaluation_mode' => 'display_only', 'note' => 'Shown for reference. Stored subjects have no category (general education, professional, PE, NSTP), so the units per component cannot be counted from stored data. Elective units would count only up to the required amount.',
            ] + $public,
            [
                'rule_code' => 'CMO20-GE-MIN-UNITS', 'reference_code' => 'CMO 20 s.2013', 'section_label' => 'Sec. 3 and Sec. 4; Advisory on CMO 20 (9 Dec 2019)', 'source_page' => '5-6',
                'title' => 'General education minimum units (CMO 20 s.2013 Sec. 3)',
                'requirement_text' => 'General education has a minimum of 36 units: 24 core + 9 electives + 3 Rizal (mandated by law). Sec. 4: 9 units of general education electives. The Advisory on CMO 20 (9 Dec 2019) states 24 core + 9 electives from AY 2019-2020.',
                'computation_key' => 'GE_UNITS', 'parameters' => ['min_units' => 36, 'core' => 24, 'electives' => 9, 'rizal' => 3],
                'evaluation_mode' => 'evaluate', 'note' => self::INTERPRETED_NOTE . ' ' . self::TWO_FIGURES_NOTE,
            ] + $college + $public,
            [
                'rule_code' => 'CMO39-PE', 'reference_code' => 'CMO 39 s.2021', 'section_label' => 'Sec. 6.1, 6.7, 6.9', 'source_page' => null,
                'title' => 'Physical education: 8 units (PATHFIT) (CMO 39 s.2021)',
                'requirement_text' => 'Physical education is 8 units (PATHFIT). PE 1-2 are the foundation for PE 3-4. No substitution. It is a graduation requirement.',
                'computation_key' => 'PE_UNITS', 'parameters' => ['units' => 8],
                'evaluation_mode' => 'evaluate', 'note' => self::INTERPRETED_NOTE,
            ] + $college + $public,
            [
                'rule_code' => 'RA9163-NSTP', 'reference_code' => 'RA 9163', 'section_label' => 'Secs. 4-6; Revised IRR of RA 9163 (2021)', 'source_page' => null,
                'title' => 'NSTP is a graduation requirement (RA 9163)',
                'requirement_text' => 'NSTP is a graduation requirement, with two semesters per component. RA 9163 is not recorded here with a unit value; the 6 units come only from the CMO 25 table (BSIT components).',
                'computation_key' => 'NSTP_REQUIREMENT', 'parameters' => ['semesters_per_component' => 2],
                'evaluation_mode' => 'evaluate', 'note' => self::INTERPRETED_NOTE,
            ] + $college + $public,
            [
                'rule_code' => 'DO017-T9-ACADEMIC', 'reference_code' => 'DO 017 s.2026', 'section_label' => 'Table 9 (Academic track)', 'source_page' => '16',
                'title' => 'Senior High School Academic track hours (DO 017 s.2026 Table 9)',
                'requirement_text' => 'Academic track: 800 hours core, at least 1,200 hours electives, 2,000 hours in total, 17 to 20 subjects, at most 40 hours weekly.',
                'computation_key' => 'SHS_HOURS', 'parameters' => ['core_hours' => 800, 'min_elective_hours' => 1200, 'total_hours' => 2000, 'min_subjects' => 17, 'max_subjects' => 20, 'max_weekly_hours' => 40],
                'evaluation_mode' => 'pending_bcp', 'note' => self::PENDING_NOTE,
            ] + $shs + $public,
            [
                'rule_code' => 'DO017-T10-TECHPRO', 'reference_code' => 'DO 017 s.2026', 'section_label' => 'Table 10 (TechPro)', 'source_page' => '17',
                'title' => 'Senior High School TechPro hours (DO 017 s.2026 Table 10)',
                'requirement_text' => 'TechPro: 2,080 hours, at least 1,280 hours electives, 8 to 9 subjects.',
                'computation_key' => 'SHS_HOURS', 'parameters' => ['total_hours' => 2080, 'min_elective_hours' => 1280, 'min_subjects' => 8, 'max_subjects' => 9],
                'evaluation_mode' => 'pending_bcp', 'note' => self::PENDING_NOTE,
            ] + $shs + $public,
            [
                'rule_code' => 'DO015-T10-GRADING', 'reference_code' => 'DO 015 s.2026', 'section_label' => 'Table 10 (grading weights)', 'source_page' => '14',
                'title' => 'Senior High School grading weights (DO 015 s.2026 Table 10)',
                'requirement_text' => 'Written work / performance tasks / exam, per group: core and other academic electives 20/50/30; Field Exposure, Arts Apprenticeship, Creative Production 15/70/15; Arts, Sports, Health and Wellness 20/60/20; Research, Design and Innovation 40/60/-; TechPro 15/65/20; Work Immersion 20/80/-.',
                'computation_key' => 'GRADING_WEIGHTS', 'parameters' => ['groups' => [
                    'Core and other academic electives' => [20, 50, 30], 'Field Exposure, Arts Apprenticeship, Creative Production' => [15, 70, 15], 'Arts, Sports, Health and Wellness' => [20, 60, 20],
                    'Research, Design and Innovation' => [40, 60, null], 'TechPro' => [15, 65, 20], 'Work Immersion' => [20, 80, null]]],
                'evaluation_mode' => 'pending_bcp', 'note' => self::PENDING_NOTE . ' These are not default weights for Grade Weighting.',
            ] + $shs + $public,
            [
                'rule_code' => 'BCP-NSTP1-PREREQ-NSTP2', 'reference_code' => null, 'source_label' => self::CLIENT_REPLY_LABEL, 'section_label' => 'Questionnaire answer: NSTP', 'source_page' => null,
                'title' => 'NSTP 1 is a prerequisite of NSTP 2 (BCP reply)',
                'requirement_text' => 'NSTP 1 is a prerequisite of NSTP 2.',
                'computation_key' => 'NSTP_PREREQUISITE', 'parameters' => [],
                'evaluation_mode' => 'evaluate', 'note' => 'Checked only when NSTP subjects can be identified from stored data. Otherwise it cannot be determined.',
                'source_class' => 'client_reply',
            ] + $college,
            [
                'rule_code' => 'BCP-NSTP-COMPONENTS', 'reference_code' => null, 'source_label' => self::CLIENT_REPLY_LABEL, 'section_label' => 'Questionnaire answer: NSTP', 'source_page' => null,
                'title' => 'NSTP components offered: ROTC and CWTS (BCP reply)',
                'requirement_text' => 'The NSTP components offered are ROTC and CWTS.',
                'computation_key' => 'NSTP_COMPONENTS', 'parameters' => ['components' => ['ROTC', 'CWTS']],
                'evaluation_mode' => 'display_only', 'note' => 'Shown for reference.',
                'source_class' => 'client_reply',
            ] + $college,
        ];
    }
}
