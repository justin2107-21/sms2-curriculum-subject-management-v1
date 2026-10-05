<?php

final class CIMFResponseValidator
{
    public static function normalize(string $text, array $allowed, string $action = 'prerequisite_review', array $context = [], string $question = ''): ?array
    {
        $data = json_decode($text, true);
        if (!is_array($data)) return null;
        if ($action === 'global_advisory') return self::globalAdvisory($data, $allowed, $context);
        $byCode = [];
        foreach ($allowed as $row) if (isset($row['subject_code'])) $byCode[(string)$row['subject_code']] = $row;
        $limitations = self::short(is_string($data['limitations'] ?? null) ? $data['limitations'] : '', 220);
        if ($action === 'equivalency_review') {
            if (!is_array($data['similarities'] ?? null) || !is_array($data['differences'] ?? null)
                || !is_string($data['review_note'] ?? null)) return null;
            $clean = static function (mixed $value): string {
                if (!is_string($value)) return '';
                $value = self::short($value, 220);
                if (preg_match('/\b(equivalent|equivalency|approve|approved|not equivalent)\b/i', $value))
                    return 'This point requires authorized academic human review.';
                return self::safeReason($value);
            };
            $similarities = array_values(array_filter(array_map($clean, array_slice($data['similarities'], 0, 4))));
            $differences = array_values(array_filter(array_map($clean, array_slice($data['differences'], 0, 4))));
            return ['action' => $action, 'similarities' => $similarities, 'differences' => $differences,
                'review_note' => $clean($data['review_note']) ?: 'Authorized academic human review is required.',
                'status' => 'Needs Human Review'];
        }
        if ($action === 'grade_weighting_review') {
            if (!is_string($data['summary'] ?? null) || trim($data['summary']) === ''
                || !is_array($data['review_points'] ?? null)) return null;
            $clean = static function (string $value): string {
                $value = self::short($value, 300);
                if (preg_match('/\b(?:approved?|compliant|compliance|official (?:BCP )?policy|BCP grading policy|institutional policy)\b/i', $value)) {
                    return 'This distribution requires human academic review; no policy or compliance conclusion is made.';
                }
                return self::safeReason($value);
            };
            $allowedPercentages = [];
            foreach ($context['components'] ?? [] as $component) {
                if (isset($component['weight_percentage'])) $allowedPercentages[] = number_format((float)$component['weight_percentage'], 2, '.', '');
            }
            foreach ($context['calculated_facts'] ?? [] as $fact) {
                if (is_numeric($fact)) $allowedPercentages[] = number_format((float)$fact, 2, '.', '');
            }
            $cleanNumeric = static function (string $value) use ($allowedPercentages): string {
                preg_match_all('/(?<![\w.])(\d+(?:\.\d+)?)\s*%/', $value, $matches);
                foreach ($matches[1] as $number) {
                    if (!in_array(number_format((float)$number, 2, '.', ''), $allowedPercentages, true)) {
                        return 'Numerical summaries are provided by the system; this point requires human review.';
                    }
                }
                return $value;
            };
            $points = [];
            foreach (array_slice($data['review_points'], 0, 4) as $point) {
                if (is_string($point) && trim($point) !== '') $points[] = $clean($cleanNumeric($point));
            }
            return ['action' => $action, 'summary' => $clean($cleanNumeric($data['summary'])),
                'review_points' => $points, 'status' => 'Human Review'];
        }
        if (in_array($action, ['subject_history_summary', 'version_history_explain'], true)) {
            if (!is_string($data['summary'] ?? null) || !is_array($data['observations'] ?? null)) return null;
            $observations = [];
            foreach (array_slice($data['observations'], 0, 3) as $item) {
                if (!is_array($item) || !is_string($item['observation'] ?? null)) continue;
                $code = $item['subject_code'] ?? null;
                if ($code !== null && (!is_string($code) || !isset($byCode[$code]))) continue;
                $observations[] = ['subject_code' => $code, 'observation' => self::safeReason(self::short($item['observation'], 220))];
            }
            return ['action' => $action, 'summary' => self::safeReason(self::short($data['summary'], 460)),
                'observations' => $observations, 'limitations' => self::safeReason($limitations)];
        }
        if ($action === 'compare_subjects') {
            if (!is_string($data['relationship'] ?? null) || !is_string($data['reason'] ?? null)) return null;
            $subjectIds = $context['selected_ids'] ?? [];
            $a = $allowed[$subjectIds[0] ?? 0] ?? null; $b = $allowed[$subjectIds[1] ?? 0] ?? null;
            if (!$a || !$b || !in_array($data['status'] ?? '', ['needs_human_review', 'no_immediate_concern'], true)) return null;
        $stored = false;
            foreach ($context['prerequisites'] ?? [] as $edge)
                if (($edge['subject'] === $a['subject_code'] && $edge['requires'] === $b['subject_code'])
                    || ($edge['subject'] === $b['subject_code'] && $edge['requires'] === $a['subject_code'])) $stored = true;
            $needsReview = ($data['status'] ?? '') === 'needs_human_review';
            $sameTerm = $a['year_level'] === $b['year_level'] && $a['semester'] === $b['semester'];
            return ['action' => $action, 'subject_a' => self::subject($a), 'subject_b' => self::subject($b),
                'system_fact' => $stored ? 'Stored prerequisite relationship exists' : 'No stored prerequisite relationship',
                'same_term' => $sameTerm,
                'assessment' => ['relationship' => $needsReview ? 'Possible relationship for review' : 'No clear prerequisite relationship indicated',
                    'reason' => self::safeReason(self::short($data['reason'], 260)),
                    'status' => $needsReview ? 'needs_human_review' : 'no_immediate_concern'],
                'limitations' => $limitations];
        }
        if ($action === 'ask_cimf') {
            if (!is_string($data['direct_answer'] ?? null) || !is_array($data['relevant_subjects'] ?? null) || !is_array($data['review_notes'] ?? null)) return null;
            $intent = (string)($context['intent'] ?? CIMFIntentClassifier::SPECIFIC_CURRICULUM);
            if (in_array($intent, [CIMFIntentClassifier::GREETING, CIMFIntentClassifier::CAPABILITY, CIMFIntentClassifier::VAGUE_CURRICULUM, CIMFIntentClassifier::OUT_OF_SCOPE], true)) {
                return ['action' => $action, 'intent' => $intent, 'direct_answer' => self::safeReason(self::short($data['direct_answer'], 460)),
                    'relevant_subjects' => [], 'review_notes' => [], 'limitations' => ''];
            }
            return ['action' => $action, 'direct_answer' => self::safeReason(self::short($data['direct_answer'], 460)),
                'relevant_subjects' => self::subjectFindings($data['relevant_subjects'], $byCode, 3, $context['prerequisites'] ?? []),
                'review_notes' => array_map(fn($s) => self::safeReason(self::short($s, 220)), array_filter(array_slice($data['review_notes'], 0, 3), 'is_string')),
                'limitations' => $limitations];
        }
        if (!is_string($data['summary'] ?? null) || !is_array($data['findings'] ?? null)) return null;
        $findings = self::subjectFindings($data['findings'], $byCode, 5, $context['prerequisites'] ?? []);
        return ['action' => $action, 'summary' => ['title' => 'Prerequisite Review',
            'message' => count($findings) . ' possible item' . (count($findings) === 1 ? '' : 's') . ' returned for human review.'],
            'findings' => $findings, 'limitations' => $limitations];
    }

    private static function subjectFindings(array $items, array $byCode, int $limit, array $edges): array
    {
        $out = [];
        foreach (array_slice($items, 0, $limit) as $item) {
            if (!is_array($item) || !is_string($item['subject_code'] ?? null) || !isset($byCode[$item['subject_code']])
                || !is_string($item['reason'] ?? null)) continue;
            $subject = $byCode[$item['subject_code']]; $relatedCode = $item['related_subject_code'] ?? null;
            $related = is_string($relatedCode) ? ($byCode[$relatedCode] ?? null) : null;
            if (is_string($relatedCode) && $related === null) continue;
            $stored = false;
            if ($related) foreach ($edges as $edge)
                if (($edge['subject'] === $subject['subject_code'] && $edge['requires'] === $related['subject_code'])
                    || ($edge['subject'] === $related['subject_code'] && $edge['requires'] === $subject['subject_code'])) $stored = true;
            $out[] = ['subject' => self::subject($subject), 'related_subject' => $related ? self::subject($related) : null,
                'relationship_type' => 'possible_review', 'system_fact' => $related ? ($stored ? 'Stored prerequisite relationship exists' : 'No stored prerequisite relationship') : null,
                'reason' => self::safeReason(self::short($item['reason'], 220)), 'status' => 'needs_human_review'];
        }
        return $out;
    }

    private static function subject(array $row): array
    {
        return ['id' => (int)$row['subject_id'], 'code' => (string)$row['subject_code'], 'title' => (string)$row['subject_title'],
            'year' => (string)($row['year_level'] ?? ''), 'semester' => (string)($row['semester'] ?? ''), 'units' => $row['units'] ?? null];
    }

    private static function safeReason(string $value): string
    {
        if (preg_match('/\b(?:official (?:subject )?offering|official adoption|officially adopted|CHED[- ]approved|BCP[- ]approved|approved by (?:BCP|CHED))\b/i', $value))
            return 'This review describes stored curriculum records only and does not establish official offerings, adoption, or approval.';
        if (preg_match('/\b(required prerequisite|official prerequisite|(?:is|are) (?:an? )?(?:required|official) prerequisite|must be taken before|must take before|is required before|[A-Z0-9_-]+ requires [A-Z0-9_-]+)\b/i', $value))
            return 'The supplied sequence may warrant human review; no unstored prerequisite is asserted.';
        return $value;
    }

    private static function short(string $value, int $limit): string { return mb_substr(trim($value), 0, $limit); }

    private static function globalAdvisory(array $data, array $allowed, array $context): ?array
    {
        $statuses = ['Needs Human Review', 'Review Suggested', 'Possible Issue', 'No Immediate Concern', 'Informational'];
        if (!in_array($data['status'] ?? null, $statuses, true)) return null;
        foreach (['summary', 'why_it_matters', 'limitations'] as $key) if (!is_string($data[$key] ?? null)) return null;
        if (trim($data['summary']) === '') return null;
        foreach (['findings', 'similarities', 'differences', 'suggested_review'] as $key) if (!is_array($data[$key] ?? null)) return null;
        $byCode = [];
        foreach ($allowed as $row) if (isset($row['subject_code'])) $byCode[$row['subject_code']] = $row;
        $output = ['status' => $data['status']];
        foreach (['summary', 'why_it_matters', 'limitations'] as $key) $output[$key] = self::short($data[$key], $key === 'summary' ? 700 : 350);
        foreach (['similarities', 'differences', 'suggested_review'] as $key) {
            $output[$key] = [];
            foreach (array_slice($data[$key], 0, 4) as $item) {
                if (!is_string($item)) return null;
                $output[$key][] = self::short($item, 350);
            }
        }
        $output['findings'] = [];
        foreach (array_slice($data['findings'], 0, 5) as $finding) {
            if (!is_array($finding) || !is_string($finding['observation'] ?? null)) return null;
            $code = $finding['subject_code'] ?? null;
            if ($code !== null && (!is_string($code) || !isset($byCode[$code]))) continue;
            $output['findings'][] = ['subject' => $code !== null ? self::subject($byCode[$code]) : null,
                'observation' => self::short($finding['observation'], 350)];
        }
        $text = json_encode($output, JSON_UNESCAPED_UNICODE);
        if (preg_match('/\b(?:CHED|DepEd)[ -](?:approved|compliant)|\b(?:I|CIMF|AI) (?:approve|approved|saved|updated|deleted|archived)\b/i', $text)) return null;
        if (preg_match('/\b(?:subjects?|pair|they|these) (?:are|is) (?:not )?equivalent\b/i', $text)) return null;
        if (preg_match('/\b\d+(?:\.\d+)?\s*%\s*(?:confidence|similarity|equivalency)/i', $text)) return null;
        if (($context['profile'] ?? '') === 'subject_equivalency' && preg_match('/\b\d+(?:\.\d+)?\s*%/', $text)) return null;
        if (($context['profile'] ?? '') === 'grade_weighting') {
            $numbers = [];
            foreach ($context['facts']['components'] ?? [] as $item) $numbers[] = (float)$item['weight_percentage'];
            foreach ($context['facts']['deterministic_totals'] ?? [] as $item) if (is_numeric($item)) $numbers[] = (float)$item;
            preg_match_all('/(?<![\w.])(\d+(?:\.\d+)?)\s*%/', $text, $matches);
            foreach ($matches[1] as $number) if (!in_array((float)$number, $numbers, true)) return null;
        }
        return $output;
    }
}
