<?php

/** Academic order comes only from stored placements, never subject codes. */
class AcademicSequenceValidator
{
    public static function position(?array $placement): ?int
    {
        $year = trim((string)($placement['year_level'] ?? ''));
        $term = trim((string)($placement['semester'] ?? ''));
        if (preg_match('/^grade\s*(11|12)$/i', $year, $m)) $yearNumber = (int)$m[1] - 10;
        elseif (preg_match('/^(?:(\d+)(?:st|nd|rd|th)?(?:\s+year)?|year\s+(\d+))$/i', $year, $m)) {
            $yearNumber = filter_var($m[1] !== '' ? $m[1] : $m[2], FILTER_VALIDATE_INT);
            if ($yearNumber === false || $yearNumber > intdiv(PHP_INT_MAX, 2)) return null;
        }
        else return null;
        if (!preg_match('/^(?:([12])(?:st|nd)?(?:\s+semester)?|semester\s+([12]))$/i', $term, $m)) return null;
        $semester = (int)($m[1] !== '' ? $m[1] : $m[2]);
        return $yearNumber > 0 ? ($yearNumber - 1) * 2 + $semester : null;
    }

    /** Optionally restrict to BOTH incoming and outgoing edges of an edited subject. */
    public static function validate(array $placements, array $edges, ?int $subjectId = null): array
    {
        $map = []; $conflicts = []; $affected = [];
        foreach ($placements as $p) $map[(int)$p['subject_id']] = $p;
        foreach ($edges as $edge) {
            if (($edge['requirement_type'] ?? 'prerequisite') !== 'prerequisite') continue;
            $r = (int)$edge['required_subject_id']; $t = (int)$edge['subject_id'];
            if ($subjectId !== null && $r !== $subjectId && $t !== $subjectId) continue;
            $required = $map[$r] ?? null; $target = $map[$t] ?? null;
            $a = self::position($required); $b = self::position($target);
            $detail = ['prerequisite_subject_id' => $r, 'target_subject_id' => $t];
            foreach (['prerequisite' => $required, 'target' => $target] as $prefix => $p) {
                $detail[$prefix . '_code'] = $p['subject_code'] ?? 'Subject #' . ($prefix === 'prerequisite' ? $r : $t);
                $detail[$prefix . '_title'] = $p['subject_title'] ?? '';
                $detail[$prefix . '_year'] = $p['year_level'] ?? '';
                $detail[$prefix . '_semester'] = $p['semester'] ?? '';
            }
            $affected[] = $detail;
            if ($r === $t) { $type = 'self_prerequisite'; $message = 'A subject cannot be its own prerequisite.'; }
            elseif ($a === null || $b === null) {
                $type = !$required || !$target || empty($required['year_level']) || empty($required['semester']) || empty($target['year_level']) || empty($target['semester']) ? 'missing_placement' : 'unresolved_sequence';
                $message = 'Sequence cannot be verified for ' . $detail['prerequisite_code'] . ' → ' . $detail['target_code'] . ': placement is missing, incomplete, or uses an unknown academic period. Review Subject Mapping.';
            } elseif ($a >= $b) {
                $type = $a === $b ? 'prerequisite_same_period' : 'prerequisite_after_target';
                $message = $detail['prerequisite_code'] . ' is placed in ' . $detail['prerequisite_year'] . ' / ' . $detail['prerequisite_semester'] . ' while ' . $detail['target_code'] . ' is placed in ' . $detail['target_year'] . ' / ' . $detail['target_semester'] . '. A prerequisite must be placed in an earlier academic period (' . ($a === $b ? 'same period' : 'prerequisite occurs after target subject') . ').';
            } else continue;
            $conflicts[] = $detail + ['type' => $type, 'message' => $message];
        }
        return ['valid' => !$conflicts, 'severity' => $conflicts ? 'block' : ($affected ? 'warning' : 'valid'), 'conflicts' => $conflicts, 'relationships' => $affected];
    }
}
