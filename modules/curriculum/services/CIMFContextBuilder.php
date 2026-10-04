<?php

require_once __DIR__ . '/../repositories/CurriculumSubjectRequirementRepository.php';

final class CIMFContextBuilder
{
    public function __construct(private mysqli $db) {}

    public function build(int $versionId, string $action, ?int $subjectId = null, ?int $otherId = null): array
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
            default => array_keys($allowed),
        };
        $subjects = [];
        foreach ($include as $id) {
            $p = $allowed[$id];
            $subjects[] = ['id' => $id, 'code' => $p['subject_code'], 'title' => $p['subject_title'],
                'year' => $p['year_level'], 'term' => $p['semester']];
        }
        $relations = [];
        foreach ($edges as $e) {
            if (in_array((int)$e['subject_id'], $include, true))
                $relations[] = [(int)$e['subject_id'], (int)$e['required_subject_id']];
        }
        $sent = array_intersect_key($allowed, array_flip($include));
        return [
            'context' => ['program' => $header['program_code'] . ' ' . $header['program_name'],
                'curriculum' => $header['curriculum_code'] . ' ' . $header['curriculum_name'],
                'version' => $header['version_label']],
            'subjects' => $subjects, 'prerequisites' => $relations,
            'allowed' => $sent,
            'demo' => stripos((string)$header['version_description'], 'demo') !== false || stripos((string)$header['version_description'], 'synthetic') !== false,
            'without_prerequisites' => array_values(array_diff($include, array_map(fn($e) => (int)$e['subject_id'], $edges))),
            'curriculum_subject_count' => count($all),
        ];
    }
}
