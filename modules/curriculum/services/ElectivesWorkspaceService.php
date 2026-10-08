<?php

require_once __DIR__ . '/CurriculumVersionEditPolicy.php';
require_once __DIR__ . '/../validators/DomainValidator.php';
require_once __DIR__ . '/../repositories/ElectivesWorkspaceRepository.php';
require_once __DIR__ . '/../../../shared/audit.php';

class ElectivesWorkspaceService
{
    private ElectivesWorkspaceRepository $repo;
    public function __construct(private mysqli $db) { $this->repo = new ElectivesWorkspaceRepository($db); }

    public function contextOptions(array $input): array
    {
        $search = DomainValidator::optionalString($input,'search','Search',100) ?? '';
        $level = $input['level'] ?? '';
        if (!in_array($level,['program','curriculum','version'],true)) throw new DomainValidationException('Invalid context level.');
        $program = $level !== 'program' ? DomainValidator::positiveId($input['program_id'] ?? null,'Program / Strand') : null;
        $curriculum = $level === 'version' ? DomainValidator::positiveId($input['curriculum_id'] ?? null,'Curriculum') : null;
        return ['options'=>$this->repo->contextOptions($level,$search,$program,$curriculum)];
    }

    private function context(array $input, bool $lock = false): array
    {
        $program = DomainValidator::positiveId($input['program_id'] ?? null, 'Program / Strand');
        $curriculum = DomainValidator::positiveId($input['curriculum_id'] ?? null, 'Curriculum');
        $version = DomainValidator::positiveId($input['curriculum_version_id'] ?? null, 'Curriculum version');
        $context = $this->repo->context($program,$curriculum,$version,$lock);
        if (!$context) throw new DomainValidationException('Select a curriculum version belonging to the selected program and curriculum.');
        return $context;
    }

    public function workspace(array $input): array
    {
        $context = $this->context($input);
        $version = (int)$context['id'];
        $groups = $this->repo->groups($version);
        $placements = $this->repo->placements($version);
        $years = [];
        $terms = [];
        $termsByYear = [];
        foreach ($placements as $placement) {
            if ($placement['year_level']) $years[] = $placement['year_level'];
            if ($placement['semester']) $terms[] = $placement['semester'];
            if ($placement['year_level'] && $placement['semester']) $termsByYear[$placement['year_level']][] = $placement['semester'];
        }
        $years = array_values(array_unique($years));
        $terms = array_values(array_unique($terms));
        usort($years, [self::class, 'academicOrder']);
        usort($terms, [self::class, 'academicOrder']);
        foreach ($termsByYear as &$yearTerms) {
            $yearTerms = array_values(array_unique($yearTerms));
            usort($yearTerms, [self::class, 'academicOrder']);
        }
        unset($yearTerms);
        return ['groups'=>$groups, 'placements'=>$placements, 'prerequisites'=>$this->repo->prerequisites($version),
            'years'=>$years, 'terms'=>$terms, 'terms_by_year'=>$termsByYear, 'review'=>self::review($groups)];
    }

    private static function academicOrder(string $a, string $b): int
    {
        $number = static function (string $value): int {
            return preg_match('/\d+/', $value, $match) ? (int)$match[0] : PHP_INT_MAX;
        };
        return $number($a) <=> $number($b) ?: strnatcasecmp($a, $b);
    }

    public static function review(array $groups): array
    {
        $counts = array_fill_keys(['empty','selection','duplicate','mismatch','unmapped','inactive','context'], 0);
        $issues = [];
        foreach ($groups as $group) {
            $add = function (string $type, string $message, ?int $subject = null) use (&$counts,&$issues,$group): void {
                $counts[$type]++;
                $issues[] = ['type'=>$type,'group_id'=>(int)$group['id'],'group_name'=>$group['group_name'],'subject_id'=>$subject,'message'=>$message];
            };
            $members = $group['members'];
            if (empty($group['year_level']) || empty($group['semester']) || (array_key_exists('context_valid', $group) && !$group['context_valid'])) $add('context','This group has missing curriculum context or year / semester placement. Review its configuration.');
            if (!$members) $add('empty','This group contains no eligible subjects.');
            if ((int)$group['required_selections'] < 1 || (int)$group['required_selections'] > count(array_unique(array_column($members,'subject_id')))) $add('selection','Required selections must be between 1 and the number of distinct eligible subjects.');
            $seen = [];
            foreach ($members as $member) {
                $id = (int)$member['subject_id'];
                $code = $member['subject_code'] ?? ('Subject #'.$id);
                if (isset($seen[$id])) $add('duplicate',"$code appears more than once in this group.",$id);
                $seen[$id] = true;
                if (!$member['placement_id'] || !$member['year_level'] || !$member['semester']) {
                    $add('unmapped',"$code has no valid placement in this curriculum version.",$id);
                } elseif ($member['year_level'] !== $group['year_level'] || $member['semester'] !== $group['semester']) {
                    $add('mismatch',"$code is placed in {$member['year_level']} / {$member['semester']} while this group is configured for " . ($group['year_level'] ?: 'Unset year') . ' / ' . ($group['semester'] ?: 'Unset semester') . '.', $id);
                }
                if ($group['status'] === 'active' && ($member['subject_status'] ?? '') !== 'active') $add('inactive',"$code is inactive or archived.",$id);
                if (isset($member['curriculum_version_id'], $group['curriculum_version_id']) && (int)$member['curriculum_version_id'] !== (int)$group['curriculum_version_id']) $add('context',"$code has a membership belonging to another curriculum version.",$id);
            }
        }
        return ['counts'=>$counts,'issues'=>$issues];
    }

    public function save(array $input, int $actor, ?int $id = null): array
    {
        $name = DomainValidator::requiredString($input,'group_name','Group Name',150);
        $year = DomainValidator::requiredString($input,'year_level','Year / Grade',50);
        $term = DomainValidator::requiredString($input,'semester','Semester / Term',50);
        $required = DomainValidator::positiveId($input['required_selections'] ?? null,'Number of Subjects to Choose');
        $status = DomainValidator::status($input,['active','inactive','archived'],'active');
        if ($status === 'archived') throw new DomainValidationException('Use Archive to archive an elective group.');
        $raw = $input['subject_ids'] ?? [];
        if (!is_array($raw)) throw new DomainValidationException('Eligible Subjects must be a list.');
        $subjects = array_map(fn($value) => DomainValidator::positiveId($value,'Subject'), $raw);
        if (count(array_unique($subjects)) !== count($subjects)) throw new DomainValidationException('Duplicate subject membership is not allowed.');
        if (count($subjects) < 2) throw new DomainValidationException('Choose at least two available subjects to form an elective group.');
        if ($required > count($subjects)) throw new DomainValidationException('Number of subjects to choose cannot exceed the available subjects.');
        $this->db->begin_transaction();
        try {
            $policy = new CurriculumVersionEditPolicy($this->db);
            if ($id !== null) $policy->assertChildEditable('group', $id);
            else $policy->assertEditable(DomainValidator::positiveId($input['curriculum_version_id'] ?? null, 'Curriculum version'));
            $context = $this->context($input,true);
            $version = (int)$context['id'];
            $old = null;
            if ($id !== null) {
                $old = $this->repo->lockGroup($id,$version);
                if (!$old) throw new DomainNotFoundException('Elective group not found in the selected version.');
                if ($old['status'] === 'archived') throw new DomainConflictException('Restore this elective group from Settings > Archives before editing.');
            }
            $placements = $this->repo->placements($version,true);
            if (!$this->repo->hasPlacement($version, $year, $term)) throw new DomainValidationException('The selected year / grade and semester / term do not have a current placement in this curriculum version.');
            if ($this->repo->duplicateGroup($version, $name, $year, $term, $id)) throw new DomainConflictException('An identical elective group already exists in this version and placement.');
            $bySubject = array_column($placements,null,'subject_id');
            foreach ($subjects as $subject) {
                $p = $bySubject[$subject] ?? null;
                if (!$p || !$p['year_level'] || !$p['semester']) throw new DomainValidationException('An eligible subject has no valid placement in this version. Open Subject Mapping.');
                if ($p['year_level'] !== $year || $p['semester'] !== $term) throw new DomainValidationException("Placement mismatch: {$p['subject_code']} is currently placed in {$p['year_level']} / {$p['semester']} but this elective group is configured for $year / $term. Open Subject Mapping.");
                if ($p['subject_status'] !== 'active') throw new DomainValidationException("{$p['subject_code']} is inactive. Remove it from the group or review Subject Master.");
            }
            // Requiring a matching stored placement also validates flexible College/SHS values.
            $before = $id ? $this->repo->memberIds($id) : [];
            $id = $this->repo->saveGroup($id,['curriculum_version_id'=>$version,'group_name'=>$name,'year_level'=>$year,'semester'=>$term,'required_selections'=>$required,'status'=>$status],$actor);
            foreach (array_diff($before,$subjects) as $subject) {
                $this->repo->removeMember($id,$subject);
                audit_log_required('ELECTIVE_GROUP_SUBJECT_REMOVE','curriculum_elective_group',$id,'Subject removed from elective group.',['subject_id'=>$subject]);
            }
            foreach (array_diff($subjects,$before) as $subject) {
                $this->repo->addMember($id,$version,$subject,$actor);
                audit_log_required('ELECTIVE_GROUP_SUBJECT_ADD','curriculum_elective_group',$id,'Subject added to elective group.',['subject_id'=>$subject]);
            }
            audit_log_required($old ? 'ELECTIVE_GROUP_UPDATE' : 'ELECTIVE_GROUP_CREATE','curriculum_elective_group',$id,'Elective group saved.',['curriculum_version_id'=>$version,'group_name'=>$name,'year_level'=>$year,'semester'=>$term,'required_selections'=>$required,'status'=>$status]);
            if ($old && (int)$old['required_selections'] !== $required) audit_log_required('ELECTIVE_GROUP_SELECTION_CHANGE','curriculum_elective_group',$id,'Number of subjects to choose changed.',['before'=>(int)$old['required_selections'],'after'=>$required]);
            if ($old && $old['status'] !== $status) audit_log_required('ELECTIVE_GROUP_STATUS_CHANGE','curriculum_elective_group',$id,'Elective group status changed.',['before'=>$old['status'],'after'=>$status]);
            $this->db->commit();
            return ['id'=>$id];
        } catch (Throwable $e) {
            $this->db->rollback();
            if ($e instanceof mysqli_sql_exception && (int)$e->getCode() === 1062) throw new DomainConflictException('An elective group with this name already exists in this version.');
            throw $e;
        }
    }

    public function archive(array $input): void
    {
        $id = DomainValidator::positiveId($input['id'] ?? null,'Elective group');
        $this->db->begin_transaction();
        try {
            (new CurriculumVersionEditPolicy($this->db))->assertChildEditable('group', $id);
            $context = $this->context($input,true);
            $group = $this->repo->lockGroup($id,(int)$context['id']);
            if (!$group) throw new DomainNotFoundException('Elective group not found in the selected version.');
            if ($group['status'] !== 'archived') {
                $this->repo->archive($id, function_exists('current_user_id') ? current_user_id() : null, $group['status']);
                audit_log_required('ELECTIVE_GROUP_ARCHIVE','curriculum_elective_group',$id,'Elective group archived; membership preserved.',['curriculum_version_id'=>(int)$context['id'],'group_name'=>$group['group_name'],'previous_status'=>$group['status'],'new_status'=>'archived']);
            }
            $this->db->commit();
        } catch (Throwable $e) { $this->db->rollback(); throw $e; }
    }
}
