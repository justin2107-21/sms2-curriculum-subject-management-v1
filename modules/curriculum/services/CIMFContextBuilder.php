<?php

require_once __DIR__ . '/../repositories/CurriculumSubjectRequirementRepository.php';
require_once __DIR__ . '/CIMFIntentClassifier.php';

final class CIMFContextBuilder
{
    public function __construct(private mysqli $db) {}

    public function build(int $versionId, string $action, ?int $subjectId = null, ?int $otherId = null, string $intent = '', string $question = ''): array
    {
        $stmt = $this->db->prepare('SELECT v.id, v.version_label, v.description AS version_description,
            c.curriculum_code, c.curriculum_name, p.program_code, p.program_name
            FROM curriculum_versions v JOIN curricula c ON c.id = v.curriculum_id
            JOIN academic_programs p ON p.id = c.academic_program_id WHERE v.id = ? LIMIT 1');
        $stmt->bind_param('i', $versionId);
        $stmt->execute();
        $header = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$header) throw new InvalidArgumentException('Curriculum version not found.');

        $headerContext = ['program' => $header['program_code'] . ' ' . $header['program_name'],
            'curriculum' => $header['curriculum_code'] . ' ' . $header['curriculum_name'], 'version' => $header['version_label']];
        $demo = stripos((string)$header['version_description'], 'demo') !== false || stripos((string)$header['version_description'], 'synthetic') !== false;
        if ($action === 'ask_cimf' && $intent !== CIMFIntentClassifier::SPECIFIC_CURRICULUM) {
            return ['context' => $headerContext, 'subjects' => [], 'prerequisites' => [], 'allowed' => [], 'display_subjects' => [],
                'demo' => $demo, 'without_prerequisites' => [], 'curriculum_subject_count' => null,
                'intent' => $intent, 'module' => 'Pre-requisite Configuration'];
        }

        $repository = new CurriculumSubjectRequirementRepository($this->db);
        $all = $repository->allPlacements($versionId);
        $allowed = [];
        foreach ($all as $row) $allowed[(int)$row['subject_id']] = $row;
        if ($subjectId !== null && !isset($allowed[$subjectId])) throw new InvalidArgumentException('Select a subject in this curriculum version.');
        if ($otherId !== null && !isset($allowed[$otherId])) throw new InvalidArgumentException('Select subjects in this curriculum version.');
        if ($action === 'compare_subjects' && $subjectId === $otherId) throw new InvalidArgumentException('Select two different subjects.');

        $edges = $repository->allPrerequisites($versionId);
        $include = match ($action) {
            'prerequisite_subject_review' => array_values(array_unique(array_merge([$subjectId], array_map('intval', array_column(array_filter($edges, fn($e) => (int)$e['subject_id'] === $subjectId), 'required_subject_id'))))),
            'compare_subjects' => [$subjectId, $otherId],
            'ask_cimf' => self::relevantAskSubjects($all, $edges, $question),
            default => array_keys($allowed),
        };
        $subjects = [];
        foreach ($include as $id) {
            $p = $allowed[$id];
            $subjects[] = ['id' => $id, 'code' => $p['subject_code'], 'title' => $p['subject_title'],
                'year' => $p['year_level'], 'term' => $p['semester'], 'units' => $p['units'] ?? null];
        }
        $relations = [];
        foreach ($edges as $e) {
            if (in_array((int)$e['subject_id'], $include, true)
                && isset($allowed[(int)$e['subject_id']], $allowed[(int)$e['required_subject_id']]))
                $relations[] = ['subject' => $allowed[(int)$e['subject_id']]['subject_code'],
                    'requires' => $allowed[(int)$e['required_subject_id']]['subject_code']];
        }
        $sent = array_intersect_key($allowed, array_flip($include));
        return [
            'context' => $headerContext,
            'subjects' => $subjects, 'prerequisites' => $relations,
            'allowed' => $sent,
            'display_subjects' => array_map(static fn($p) => ['id' => (int)$p['subject_id'], 'code' => $p['subject_code'],
                'title' => $p['subject_title'], 'year' => $p['year_level'], 'term' => $p['semester'], 'units' => $p['units'] ?? null],
                array_values(array_intersect_key($allowed, array_flip($include)))),
            'demo' => $demo, 'intent' => $intent,
            'without_prerequisites' => array_values(array_diff($include, array_map(fn($e) => (int)$e['subject_id'], $edges))),
            'curriculum_subject_count' => count($all),
        ];
    }

    private static function relevantAskSubjects(array $placements, array $edges, string $question): array
    {
        $q = mb_strtolower($question);
        $scored = [];
        foreach ($placements as $index => $row) {
            $code = mb_strtolower((string)$row['subject_code']);
            $title = mb_strtolower((string)$row['subject_title']);
            $score = 0;
            if (preg_match('/(?<![a-z0-9])' . preg_quote($code, '/') . '(?![a-z0-9])/i', $question)) $score += 100;
            foreach (preg_split('/[^\pL\pN]+/u', $title, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word)
                if (mb_strlen($word) >= 3 && mb_strpos($q, $word) !== false) $score += 10;
            $scored[] = ['id' => (int)$row['subject_id'], 'score' => $score, 'index' => $index];
        }
        usort($scored, static fn($a, $b) => ($b['score'] <=> $a['score']) ?: ($a['index'] <=> $b['index']));
        $matched = array_values(array_filter($scored, static fn($item) => $item['score'] > 0));
        $selected = array_slice($matched ?: $scored, 0, 3);
        $ids = array_map(static fn($item) => $item['id'], $selected);
        foreach ($edges as $edge) {
            if (count($ids) >= 3) break;
            $subjectId = (int)$edge['subject_id']; $requiredId = (int)$edge['required_subject_id'];
            if (in_array($subjectId, $ids, true) && !in_array($requiredId, $ids, true)) $ids[] = $requiredId;
            elseif (in_array($requiredId, $ids, true) && !in_array($subjectId, $ids, true)) $ids[] = $subjectId;
        }
        return array_slice($ids, 0, 3);
    }
}
