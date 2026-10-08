<?php

require_once __DIR__ . '/CurriculumVersionEditPolicy.php';

require_once __DIR__ . '/../../../shared/audit.php';

/** The only tables the archive center is allowed to mutate. */
final class ArchiveCenterService
{
    private const MODULES = [
        'program' => ['academic_programs', 'program_code', 'program_name', 'Academic Programs', 'program.update', 'academic_program'],
        'curriculum' => ['curricula', 'curriculum_code', 'curriculum_name', 'Curricula', 'curriculum.update', 'curriculum'],
        'subject' => ['subjects', 'subject_code', 'subject_title', 'Subjects', 'subject.update', 'subject'],
        'elective_group' => ['curriculum_elective_groups', 'group_name', 'description', 'Elective Groups', 'elective.manage', 'curriculum_elective_group'],
    ];

    public function __construct(private mysqli $db) {}

    public static function modules(): array
    {
        return array_map(static fn($module) => $module[3], self::MODULES);
    }

    private function config(string $module): array
    {
        if (!isset(self::MODULES[$module])) throw new InvalidArgumentException('Unsupported archive module.');
        return self::MODULES[$module];
    }

    private function one(string $sql, string $types = '', array $params = []): ?array
    {
        $stmt = $this->db->prepare($sql);
        if ($types !== '') $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        return $row;
    }

    private function rows(string $sql, string $types = '', array $params = []): array
    {
        $stmt = $this->db->prepare($sql);
        if ($types !== '') $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }

    private function execute(string $sql, string $types, array $params): void
    {
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $stmt->close();
    }

    private function union(): string
    {
        return "SELECT 'program' module_key, p.id record_id, p.program_code identifier, p.program_name title,
                    '' context, p.archived_at, p.archived_by, p.archive_previous_status original_status
                FROM academic_programs p WHERE p.status='inactive' AND p.archived_at IS NOT NULL
                UNION ALL
                SELECT 'curriculum', c.id, c.curriculum_code, c.curriculum_name,
                    COALESCE(p.program_code,''), c.archived_at, c.archived_by, c.archive_previous_status
                FROM curricula c JOIN academic_programs p ON p.id=c.academic_program_id
                WHERE c.status='inactive' AND c.archived_at IS NOT NULL
                UNION ALL
                SELECT 'subject', s.id, s.subject_code, s.subject_title, COALESCE(sc.context,''), s.archived_at, s.archived_by, s.archive_previous_status
                FROM subjects s LEFT JOIN (
                    SELECT placement.subject_id, GROUP_CONCAT(DISTINCT CONCAT(p.program_code, ' / ', c.curriculum_code, ' / ', v.version_label) SEPARATOR '; ') context
                    FROM curriculum_version_subjects placement
                    JOIN subjects archived_subject ON archived_subject.id=placement.subject_id AND archived_subject.status='inactive' AND archived_subject.archived_at IS NOT NULL
                    JOIN curriculum_versions v ON v.id=placement.curriculum_version_id
                    JOIN curricula c ON c.id=v.curriculum_id JOIN academic_programs p ON p.id=c.academic_program_id
                    GROUP BY placement.subject_id
                ) sc ON sc.subject_id=s.id WHERE s.status='inactive' AND s.archived_at IS NOT NULL
                UNION ALL
                SELECT 'elective_group', g.id, g.group_name, g.description,
                    CONCAT(p.program_code, ' / ', c.curriculum_code, ' / ', v.version_label),
                    g.archived_at, g.archived_by, g.archive_previous_status
                FROM curriculum_elective_groups g
                JOIN curriculum_versions v ON v.id=g.curriculum_version_id
                JOIN curricula c ON c.id=v.curriculum_id
                JOIN academic_programs p ON p.id=c.academic_program_id
                WHERE g.status='archived'";
    }

    public function list(array $input): array
    {
        $module = trim((string)($input['module'] ?? ''));
        if ($module !== '') $this->config($module);
        $search = trim((string)($input['search'] ?? ''));
        if (mb_strlen($search) > 100) throw new InvalidArgumentException('Search is too long.');
        $date = trim((string)($input['date'] ?? 'all'));
        if (!in_array($date, ['all', 'today', '7', '30', 'custom'], true)) throw new InvalidArgumentException('Invalid date filter.');
        $page = filter_var($input['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 1;
        $size = (int)($input['per_page'] ?? 20);
        if (!in_array($size, [20, 50, 100], true)) $size = 20;
        $by = trim((string)($input['archived_by'] ?? ''));
        if ($by !== '' && $by !== 'unknown' && !ctype_digit($by)) throw new InvalidArgumentException('Invalid archive actor.');
        $status = trim((string)($input['original_status'] ?? ''));
        if ($status !== '' && !in_array($status, ['active', 'inactive', 'draft'], true)) throw new InvalidArgumentException('Invalid previous status.');
        $program = trim((string)($input['program'] ?? ''));
        if (mb_strlen($program) > 80) throw new InvalidArgumentException('Program filter is too long.');
        $where = ['1=1']; $types = ''; $params = [];
        if ($module !== '') { $where[] = 'a.module_key=?'; $types .= 's'; $params[] = $module; }
        if ($search !== '') {
            $where[] = '(LOWER(a.identifier) LIKE ? OR LOWER(a.title) LIKE ?)';
            $term = '%' . addcslashes(mb_strtolower($search), '%_\\') . '%';
            $types .= 'ss'; array_push($params, $term, $term);
        }
        if ($by === 'unknown') $where[] = 'a.archived_by IS NULL';
        elseif ($by !== '') { $where[] = 'a.archived_by=?'; $types .= 'i'; $params[] = (int)$by; }
        if ($status !== '') { $where[] = 'a.original_status=?'; $types .= 's'; $params[] = $status; }
        if ($program !== '') { $where[] = '(LOWER(a.context) LIKE ? OR (a.module_key=\'program\' AND LOWER(a.identifier) LIKE ?))'; $types .= 'ss'; $pattern = '%' . addcslashes(mb_strtolower($program), '%_\\') . '%'; array_push($params, $pattern, $pattern); }
        if ($date === 'today') {
            $midnight = new DateTimeImmutable('today', new DateTimeZone('Asia/Manila'));
            $where[] = 'a.archived_at >= ? AND a.archived_at < ?';
            $types .= 'ss';
            array_push($params, $midnight->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
                $midnight->modify('+1 day')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'));
        }
        if ($date === '7' || $date === '30') $where[] = 'a.archived_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL ' . (int)$date . ' DAY)';
        if ($date === 'custom') {
            if ((string)($input['from'] ?? '') > (string)($input['to'] ?? '')) throw new InvalidArgumentException('The start date must be on or before the end date.');
            foreach (['from', 'to'] as $key) {
                $value = (string)($input[$key] ?? '');
                $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
                if (!$parsed || $parsed->format('Y-m-d') !== $value) throw new InvalidArgumentException('Enter a valid date range.');
                $where[] = 'a.archived_at ' . ($key === 'from' ? '>=' : '<') . ' ?';
                $types .= 's';
                $manila = new DateTimeImmutable($key === 'from' ? $value : $parsed->modify('+1 day')->format('Y-m-d'), new DateTimeZone('Asia/Manila'));
                $params[] = $manila->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            }
        }
        $source = '(' . $this->union() . ') a';
        $condition = implode(' AND ', $where);
        $total = (int)($this->one("SELECT COUNT(*) n FROM $source WHERE $condition", $types, $params)['n'] ?? 0);
        $pages = max(1, (int)ceil($total / $size)); $page = min($page, $pages);
        $order = 'a.archived_at DESC, a.module_key, a.record_id DESC';
        $rankTypes = ''; $rankParams = [];
        if ($search !== '') {
            $order = 'CASE WHEN LOWER(a.identifier)=? THEN 0 WHEN LOWER(a.identifier) LIKE ? THEN 1 WHEN LOWER(a.title) LIKE ? THEN 2 WHEN LOWER(a.identifier) LIKE ? THEN 3 ELSE 4 END, ' . $order;
            $rankTypes = 'ssss'; $needle = mb_strtolower($search);
            $prefix = addcslashes($needle, '%_\\') . '%';
            $rankParams = [$needle, $prefix, $prefix, '%' . addcslashes($needle, '%_\\') . '%'];
        }
        $rows = $this->rows("SELECT a.*, CONCAT_WS(' ', u.first_name, u.last_name) actor_name
            FROM $source LEFT JOIN users u ON u.id=a.archived_by WHERE $condition
            ORDER BY $order LIMIT ? OFFSET ?", $types . $rankTypes . 'ii', [...$params, ...$rankParams, $size, ($page - 1) * $size]);
        $counts = array_fill_keys(array_keys(self::MODULES), 0);
        foreach ($this->rows("SELECT module_key, COUNT(*) n FROM $source GROUP BY module_key") as $row) $counts[$row['module_key']] = (int)$row['n'];
        $actors = $this->rows("SELECT DISTINCT u.id, CONCAT_WS(' ',u.first_name,u.last_name) name FROM $source
            JOIN users u ON u.id=a.archived_by ORDER BY name");
        return ['records'=>$rows, 'total'=>$total, 'page'=>$page, 'pages'=>$pages, 'per_page'=>$size,
            'counts'=>$counts, 'actors'=>$actors, 'modules'=>self::modules()];
    }

    public function details(string $module, int $id): array
    {
        [$table, $code, $title, $label] = $this->config($module);
        if ($id < 1) throw new InvalidArgumentException('Invalid record ID.');
        $row = $this->one("SELECT * FROM $table WHERE id=?", 'i', [$id]);
        if (!$row) throw new OutOfBoundsException('Archive record not found.');
        $archived = $module === 'elective_group' ? $row['status'] === 'archived' : $row['status'] === 'inactive' && $row['archived_at'] !== null;
        if (!$archived) throw new DomainException('This record is no longer archived.');
        $context = []; $issues = [];
        if ($module === 'elective_group' && $row['archive_previous_status'] === null) {
            $issues[] = 'Previous status is unknown for this older archive. An administrator must review it before restoration.';
        }
        if ($module === 'curriculum') {
            $p = $this->one('SELECT id, program_code, status, archived_at FROM academic_programs WHERE id=?', 'i', [(int)$row['academic_program_id']]);
            if (!$p || $p['status'] !== 'active') $issues[] = 'Restore or activate the parent academic program first.';
            if ($p) $context[] = ['label'=>'Academic Program', 'value'=>$p['program_code'], 'state'=>$p['status'], 'module'=>'program', 'id'=>(int)$p['id'], 'archived'=>$p['archived_at'] !== null];
        } elseif ($module === 'elective_group') {
            $v = $this->one('SELECT v.id, v.version_label, v.status version_status, c.id curriculum_id, c.curriculum_code, c.status curriculum_status, c.archived_at curriculum_archived_at, p.id program_id, p.program_code, p.status program_status, p.archived_at program_archived_at
                FROM curriculum_versions v JOIN curricula c ON c.id=v.curriculum_id JOIN academic_programs p ON p.id=c.academic_program_id WHERE v.id=?', 'i', [(int)$row['curriculum_version_id']]);
            if (!$v) $issues[] = 'The parent curriculum version is missing.';
            else {
                $context = [
                    ['label'=>'Academic Program', 'value'=>$v['program_code'], 'state'=>$v['program_status'], 'module'=>'program', 'id'=>(int)$v['program_id'], 'archived'=>$v['program_archived_at'] !== null],
                    ['label'=>'Curriculum', 'value'=>$v['curriculum_code'], 'state'=>$v['curriculum_status'], 'module'=>'curriculum', 'id'=>(int)$v['curriculum_id'], 'archived'=>$v['curriculum_archived_at'] !== null],
                    ['label'=>'Curriculum Version', 'value'=>$v['version_label'], 'state'=>$v['version_status']],
                ];
                if ($v['program_status'] !== 'active' || $v['curriculum_status'] !== 'active' || $v['version_status'] === 'archived') $issues[] = 'Restore or activate the parent program, curriculum, and version first.';
            }
            $members = $this->rows('SELECT s.subject_code, s.status, p.id placement_id, p.year_level, p.semester
                FROM curriculum_elective_group_subjects m LEFT JOIN subjects s ON s.id=m.subject_id
                LEFT JOIN curriculum_version_subjects p ON p.curriculum_version_id=m.curriculum_version_id AND p.subject_id=m.subject_id
                WHERE m.elective_group_id=?', 'i', [$id]);
            if (count($members) < 2 || (int)$row['required_selections'] < 1 || (int)$row['required_selections'] > count($members)) $issues[] = 'The elective group no longer has enough eligible subjects.';
            foreach ($members as $member) {
                if ($member['status'] !== 'active' || !$member['placement_id'] || $member['year_level'] !== $row['year_level'] || $member['semester'] !== $row['semester']) {
                    $issues[] = 'Review member subjects and their placements before restoring this group.'; break;
                }
            }
        }
        if ($module === 'subject') {
            $placements = $this->rows('SELECT DISTINCT p.program_code, c.curriculum_code, v.version_label
                FROM curriculum_version_subjects placement JOIN curriculum_versions v ON v.id=placement.curriculum_version_id
                JOIN curricula c ON c.id=v.curriculum_id JOIN academic_programs p ON p.id=c.academic_program_id
                WHERE placement.subject_id=? ORDER BY p.program_code,c.curriculum_code,v.version_label LIMIT 10', 'i', [$id]);
            foreach ($placements as $placement) $context[] = ['label'=>'Placement', 'value'=>implode(' / ', $placement), 'state'=>'Retained'];
        }
        $scope = $module === 'elective_group' ? ' AND curriculum_version_id=' . (int)$row['curriculum_version_id'] : '';
        $duplicate = $this->one("SELECT id FROM $table WHERE LOWER(TRIM($code))=LOWER(TRIM(?)) AND id<>? AND status='active'$scope LIMIT 1", 'si', [$row[$code], $id]);
        if ($duplicate) $issues[] = 'An active record already uses this identifier.';
        $actor = $row['archived_by'] ? $this->one('SELECT CONCAT_WS(\' \',first_name,last_name) name FROM users WHERE id=?', 'i', [(int)$row['archived_by']]) : null;
        return ['module'=>$module, 'module_label'=>$label, 'id'=>$id, 'identifier'=>$row[$code], 'title'=>$row[$title],
            'archived_at'=>$row['archived_at'], 'archived_by'=>$actor['name'] ?? null,
            'original_status'=>$row['archive_previous_status'], 'context'=>$context,
            'units'=>$module === 'subject' ? $row['units'] : null,
            'issues'=>array_values(array_unique($issues)), 'can_restore'=>$issues === []];
    }

    public function archive(string $module, int $id, int $actor): void
    {
        [$table, $code, , , , $entity] = $this->config($module);
        $this->db->begin_transaction();
        try {
            (new CurriculumVersionEditPolicy($this->db))->assertArchiveEditable($module, $id);
            $row = $this->one("SELECT * FROM $table WHERE id=? FOR UPDATE", 'i', [$id]);
            if (!$row) throw new OutOfBoundsException('Record not found.');
            if ($module === 'elective_group' ? $row['status'] === 'archived' : $row['archived_at'] !== null) throw new DomainException('This record is already archived.');
            if ($row['status'] !== 'active') throw new DomainException('Only active records can be archived.');
            $newStatus = $module === 'elective_group' ? 'archived' : 'inactive';
            $this->execute("UPDATE $table SET status=?, archived_at=UTC_TIMESTAMP(), archived_by=?, archive_previous_status=? WHERE id=?", 'sisi', [$newStatus, $actor, $row['status'], $id]);
            audit_log_required(strtoupper($module) . '_ARCHIVE', $entity, $id, 'Record archived.',
                ['module'=>$module, 'identifier'=>$row[$code], 'previous_status'=>$row['status'], 'new_status'=>$newStatus]);
            $this->db->commit();
        } catch (Throwable $error) { $this->db->rollback(); throw $error; }
    }

    public function restore(string $module, int $id): void
    {
        [$table, $code, , , , $entity] = $this->config($module);
        $this->db->begin_transaction();
        try {
            (new CurriculumVersionEditPolicy($this->db))->assertArchiveEditable($module, $id);
            // Validate again under a transaction, after locking the target.
            $row = $this->one("SELECT * FROM $table WHERE id=? FOR UPDATE", 'i', [$id]);
            if (!$row) throw new OutOfBoundsException('Archive record not found.');
            if ($module === 'curriculum') {
                $this->one('SELECT id FROM academic_programs WHERE id=? FOR UPDATE', 'i', [(int)$row['academic_program_id']]);
            } elseif ($module === 'elective_group') {
                $this->rows('SELECT v.id, c.id curriculum_id, p.id program_id FROM curriculum_versions v
                    JOIN curricula c ON c.id=v.curriculum_id JOIN academic_programs p ON p.id=c.academic_program_id
                    WHERE v.id=? FOR UPDATE', 'i', [(int)$row['curriculum_version_id']]);
                $this->rows('SELECT m.id, s.id subject_id, placement.id placement_id
                    FROM curriculum_elective_group_subjects m JOIN subjects s ON s.id=m.subject_id
                    LEFT JOIN curriculum_version_subjects placement ON placement.curriculum_version_id=m.curriculum_version_id AND placement.subject_id=m.subject_id
                    WHERE m.elective_group_id=? FOR UPDATE', 'i', [$id]);
            }
            $details = $this->details($module, $id);
            if (!$details['can_restore']) throw new DomainException(implode(' ', $details['issues']));
            $newStatus = $row['archive_previous_status'] ?: 'active';
            if (!in_array($newStatus, ['active', 'inactive'], true)) $newStatus = 'active';
            $this->execute("UPDATE $table SET status=?, archived_at=NULL, archived_by=NULL, archive_previous_status=NULL WHERE id=?", 'si', [$newStatus, $id]);
            audit_log_required(strtoupper($module) . '_RESTORE', $entity, $id, 'Record restored.',
                ['module'=>$module, 'identifier'=>$row[$code], 'previous_status'=>$row['status'], 'new_status'=>$newStatus]);
            $this->db->commit();
        } catch (Throwable $error) { $this->db->rollback(); throw $error; }
    }
}
