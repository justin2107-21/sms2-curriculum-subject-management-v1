<?php
// Development-only library; excluded from Docker and denied by Apache.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../../shared/auth.php';
require_once __DIR__ . '/../../shared/audit.php';

final class BsitDataset
{
    public const ID = 'DEV-BSIT-PREORAL-2026';
    public const COUNTS = ['academic_programs'=>1,'subjects'=>50,'curricula'=>1,'curriculum_versions'=>1,'curriculum_version_subjects'=>50];
    public const ORDER = ['compliance_validation_results','compliance_validation_runs','compliance_rules','compliance_references',
        'grade_weighting_components','grade_weighting_schemes','subject_equivalencies','curriculum_elective_group_subjects',
        'curriculum_elective_groups','curriculum_subject_requirements','curriculum_version_subjects','curriculum_versions','curricula','subjects','academic_programs'];
    public const PROTECTED = ['users','roles','permissions','user_roles','role_permissions','students','audit_logs'];
    public const GROUPS = ['1st Year / 1st Semester'=>[9,26],'1st Year / 2nd Semester'=>[9,26],
        '2nd Year / 1st Semester'=>[8,23],'2nd Year / 2nd Semester'=>[8,23],
        '3rd Year / 1st Semester'=>[6,18],'3rd Year / 2nd Semester'=>[6,18],'4th Year / 1st Semester'=>[4,18]];
    private mysqli $db;
    private array $manifest;
    private int $actor;

    public static function require(bool $ok, string $message): void {
        if (!$ok) { throw new RuntimeException($message); }
    }
    public static function manifest(?array $data=null): array {
        $m=$data ?? json_decode(file_get_contents(__DIR__.'/bsit_preoral_manifest.json'),true,512,JSON_THROW_ON_ERROR);
        self::require(($m['dataset_id']??null)===self::ID && ($m['preparation_status']??null)==='CONFIRMED_TOTAL_UNITS','Unconfirmed dataset.');
        self::require(count($m['subjects']??[])===50,'Expected 50 subjects.');
        $codes=[];$groups=[];$total=0;
        foreach($m['subjects'] as $s) {
            self::require(is_string($s['subject_code']??null) && trim($s['subject_code'])===$s['subject_code']
                && strlen($s['subject_code'])>0 && strlen($s['subject_code'])<=60,'Invalid source code.');
            $key=strtolower(trim($s['subject_code']));
            self::require(!isset($codes[$key]),'Duplicate normalized source code.');$codes[$key]=true;
            self::require(is_string($s['subject_name']??null) && trim($s['subject_name'])!=='' && strlen($s['subject_name'])<=255,'Invalid source title.');
            $u=$s['total_units']??null;
            self::require((is_int($u)||is_float($u)) && is_finite((float)$u) && $u>=0 && $u<=9999.99
                && abs($u*100-round($u*100))<0.000001,'Confirmed numeric units with at most two decimals required.');
            self::require(array_key_exists('lecture_units',$s)&&$s['lecture_units']===null
                && array_key_exists('laboratory_units',$s)&&$s['laboratory_units']===null,'Unprovided lecture/lab must be NULL.');
            $group=($s['year_level']??'').' / '.($s['semester']??'');
            self::require(isset(self::GROUPS[$group]),'Unsupported source year/semester.');
            $groups[$group][0]=($groups[$group][0]??0)+1;
            $groups[$group][1]=($groups[$group][1]??0)+(int)round($u*100);$total+=(int)round($u*100);
        }
        foreach(self::GROUPS as $group=>[$count,$units]) {
            self::require(($groups[$group]??null)===[$count,$units*100],'Year/semester counts or units differ.');
        }
        self::require($total===15200,'Source must total 152 units.');
        foreach(['CCS1102/CC102','ITSP1-A','ITSP2A','PRAC3301','ITE4'] as $code) self::require(in_array($code,array_column($m['subjects'],'subject_code'),true),'Missing exact source code.');
        $p=$m['planned_structure'];
        self::require($p['academic_program']['program_code']==='BSIT'
            && $p['academic_program']['program_name']==='Bachelor of Science in Information Technology'
            && $p['academic_program']['program_type']==="Bachelor's Degree"
            && $p['curriculum']['curriculum_code']==='BSIT-DEMO-2026'
            && $p['curriculum_version']['version_label']==='PREORAL-2026'
            && $p['placement_plan']['is_required']===1,'Unexpected dataset structure.');
        return $m;
    }
    public static function fingerprint(array $row): string { ksort($row); return hash('sha256',json_encode($row,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)); }
    public static function digest(array $m): string { return hash('sha256',json_encode($m,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)); }
    public function __construct(mysqli $db, int $actor, array $manifest) {
        $this->db=$db;$this->actor=$actor;$this->manifest=self::manifest($manifest);
        $s=$db->prepare("SELECT COUNT(*) FROM user_roles ur JOIN roles r ON r.id=ur.role_id WHERE ur.user_id=? AND r.name IN ('super_admin','admin')");
        $s->bind_param('i',$actor);$s->execute();self::require((int)$s->get_result()->fetch_row()[0]===1,'Existing local admin actor required.');$s->close();
    }
    public function rows(string $table): array {
        self::require(in_array($table,array_merge(self::ORDER,self::PROTECTED),true),'Unsupported table.');
        return $this->db->query("SELECT * FROM $table ORDER BY ".(in_array($table,['user_roles','role_permissions'],true)?'1,2':'id')." FOR UPDATE")->fetch_all(MYSQLI_ASSOC);
    }
    public function snapshot(array $tables): array {
        $result=[];foreach($tables as $table) {
            $rows=$this->rows($table);$result[$table]=['count'=>count($rows),'sha256'=>hash('sha256',json_encode($rows,JSON_THROW_ON_ERROR))];
        }return $result;
    }
    public function legacyPlan(): array {
        // Approval identities, not reusable prefix/broad-table deletion authority.
        $expected=[
            'academic_programs'=>[28=>['ITE4','IT Elective IV'],47=>['PE','Physical Education'],50=>['cap101','capstone1'],
                51=>['IAS','INTEGRATION'],52=>['ASDFASDF','ADSFASF'],53=>['ASDFA','ASDFA'],54=>['ASDFAS','ASDFASDFA'],
                55=>['ASDFASDFASDF','ASDFASDFASDF'],56=>['ASDFASF','ASDFASDF']],
            'subjects'=>[17=>['ITE4','IT Elective 4'],54=>['GE 9','RIZAL']],
            'curricula'=>[34=>['PE','PHYSICAL EDUCATION']],
        ];
        $records=[];
        foreach(self::ORDER as $table) {
            $rows=$this->rows($table);$records[$table]=[];
            self::require(count($rows)===count($expected[$table]??[]),'Cleanup inventory changed: '.$table);
            foreach($rows as $row) {
                $identity=$expected[$table][(int)$row['id']]??null;
                $fields=['academic_programs'=>['program_code','program_name'],'subjects'=>['subject_code','subject_title'],'curricula'=>['curriculum_code','curriculum_name']][$table];
                self::require($identity===[$row[$fields[0]],$row[$fields[1]]],'Cleanup identity changed.');
                if($table==='curricula')self::require((int)$row['academic_program_id']===47,'Cleanup dependency changed.');
                $records[$table][(int)$row['id']]=self::fingerprint($row);
            }
        }
        return ['dataset'=>self::ID,'records'=>$records,'protected'=>$this->snapshot(self::PROTECTED),'manifest_digest'=>self::digest($this->manifest)];
    }
    private function insert(string $table, array $values): int {
        self::require(isset(self::COUNTS[$table]),'Unsupported insert table.');
        $sql="INSERT INTO $table (".implode(',',array_keys($values)).") VALUES (".implode(',',array_fill(0,count($values),'?')).")";
        $stmt=$this->db->prepare($sql);$v=array_values($values);$stmt->bind_param(str_repeat('s',count($v)),...$v);$stmt->execute();$id=(int)$this->db->insert_id;$stmt->close();return $id;
    }
    private function create(): array {
        $p=$this->manifest['planned_structure'];$ids=[];
        $ids['academic_programs'][]=$program=$this->insert('academic_programs',[
            'program_code'=>$p['academic_program']['program_code'],'program_name'=>$p['academic_program']['program_name'],
            'program_type'=>$p['academic_program']['program_type'],'status'=>'active','created_by'=>$this->actor]);
        foreach($this->manifest['subjects'] as $s) {
            $ids['subjects'][]=$this->insert('subjects',['subject_code'=>$s['subject_code'],'subject_title'=>$s['subject_name'],
                'description'=>null,'units'=>$s['total_units'],'lecture_units'=>null,'laboratory_units'=>null,'status'=>'active','created_by'=>$this->actor]);
        }
        $ids['curricula'][]=$curriculum=$this->insert('curricula',['curriculum_code'=>$p['curriculum']['curriculum_code'],
            'curriculum_name'=>$p['curriculum']['curriculum_name'],'academic_program_id'=>$program,'status'=>'active','created_by'=>$this->actor]);
        $ids['curriculum_versions'][]=$version=$this->insert('curriculum_versions',['curriculum_id'=>$curriculum,
            'version_label'=>$p['curriculum_version']['version_label'],'effective_academic_year'=>null,
            'description'=>$p['curriculum_version']['description'],'status'=>'draft','created_by'=>$this->actor]);
        foreach($this->manifest['subjects'] as $i=>$s) {
            $ids['curriculum_version_subjects'][]=$this->insert('curriculum_version_subjects',['curriculum_version_id'=>$version,
                'subject_id'=>$ids['subjects'][$i],'year_level'=>$s['year_level'],'semester'=>$s['semester'],'sequence_order'=>null,
                'is_required'=>1,'created_by'=>$this->actor]);
        }
        $records=[];
        foreach($ids as $table=>$list)foreach($list as $id) {
            $row=$this->db->query("SELECT * FROM $table WHERE id=$id")->fetch_assoc();
            $records[$table][$id]=self::fingerprint($row);
        }
        return $records;
    }
    private function owned(array $metadata): array {
        self::require(($metadata['dataset']??null)===self::ID && ($metadata['manifest_digest']??null)===self::digest($this->manifest),'Ownership dataset/digest mismatch.');
        $records=$metadata['records']??[];
        self::require(is_array($records)&&array_keys($records)===array_keys(self::COUNTS),'Malformed ownership tables.');
        foreach(self::COUNTS as $table=>$count) {
            self::require(is_array($records[$table])&&count($records[$table])===$count,'Incomplete ownership.');
            foreach($records[$table] as $id=>$fingerprint) {
                self::require(ctype_digit((string)$id)&&(int)$id>0&&is_string($fingerprint)&&preg_match('/^[a-f0-9]{64}$/D',$fingerprint)===1,'Invalid ownership entry.');
                $row=$this->db->query("SELECT * FROM $table WHERE id=".(int)$id.' FOR UPDATE')->fetch_assoc();
                self::require($row!==null&&hash_equals($fingerprint,self::fingerprint($row)),'Owned row changed/missing; nothing removed.');
            }
        }
        $this->verifyData($records);
        return $records;
    }
    public function verifyData(array $records): void {
        $version=(int)array_key_first($records['curriculum_versions']);
        $curriculum=(int)array_key_first($records['curricula']);
        $program=(int)array_key_first($records['academic_programs']);
        $context=$this->db->query("SELECT c.id,c.academic_program_id FROM curriculum_versions v JOIN curricula c ON c.id=v.curriculum_id WHERE v.id=$version")->fetch_assoc();
        self::require($context && (int)$context['id']===$curriculum && (int)$context['academic_program_id']===$program,'Dataset context mismatch.');
        $rows=$this->db->query("SELECT s.*,p.year_level,p.semester,p.is_required,p.sequence_order FROM curriculum_version_subjects p JOIN subjects s ON s.id=p.subject_id WHERE p.curriculum_version_id=$version ORDER BY s.id")->fetch_all(MYSQLI_ASSOC);
        self::require(count($rows)===50,'Expected 50 owned placements.');$sum=0;
        $expected=array_column($this->manifest['subjects'],null,'subject_code');
        foreach($rows as $row) {
            $s=$expected[$row['subject_code']]??null;
            self::require($s!==null && isset($records['subjects'][(int)$row['id']]) && $row['subject_title']===$s['subject_name']
                && (int)round((float)$row['units']*100)===(int)round($s['total_units']*100)
                && $row['lecture_units']===null && $row['laboratory_units']===null && $row['description']===null
                && $row['year_level']===$s['year_level'] && $row['semester']===$s['semester']
                && (int)$row['is_required']===1 && $row['sequence_order']===null,'Stored dataset differs from source.');
            $sum+=(int)round((float)$row['units']*100);unset($expected[$row['subject_code']]);
        }
        self::require($sum===15200 && !$expected,'Stored units must total 152.');
    }
    private function remove(array $records): void {
        foreach(self::ORDER as $table)foreach($records[$table]??[] as $id=>$fingerprint) {
            self::require(ctype_digit((string)$id)&&(int)$id>0,'Invalid removal ID.');
            $stmt=$this->db->prepare("DELETE FROM $table WHERE id=?");$stmt->bind_param('i',$id);$stmt->execute();
            self::require($stmt->affected_rows===1,'Removal count mismatch.');$stmt->close();
        }
    }
    public function run(string $action, ?array $plan=null): array {
        self::require(in_array($action,['seed','replace','verify','cleanup'],true),'Unsupported action.');
        $db=$this->db;$lock='csms_bsit_'.substr(hash('sha256',$db->query('SELECT DATABASE()')->fetch_row()[0]),0,40);
        $s=$db->prepare('SELECT GET_LOCK(?,0)');$s->bind_param('s',$lock);$s->execute();$locked=(int)$s->get_result()->fetch_row()[0]===1;$s->close();
        self::require($locked,'Another BSIT operation is running.');
        try {
            $db->begin_transaction();
            $protected=$this->snapshot(self::PROTECTED);
            $stmt=$db->prepare("SELECT id,action,metadata_json FROM audit_logs WHERE entity_type='development_dataset' AND JSON_UNQUOTE(JSON_EXTRACT(metadata_json,'$.dataset'))=? ORDER BY id DESC LIMIT 1 FOR UPDATE");
            $dataset=self::ID;$stmt->bind_param('s',$dataset);$stmt->execute();$last=$stmt->get_result()->fetch_assoc();$stmt->close();
            if($last)self::require(in_array($last['action'],['DEVELOPMENT_SEED_CREATE','DEVELOPMENT_SEED_REMOVE'],true),'Unexpected ownership event.');
            $active=$last && $last['action']==='DEVELOPMENT_SEED_CREATE';
            $removed=[];
            if($active) {
                $records=$this->owned(json_decode($last['metadata_json'],true,512,JSON_THROW_ON_ERROR));
                self::require($action!=='replace','Replacement already completed; use verify.');
                if($action==='cleanup'){$this->remove($records);}
                else {$db->rollback();return ['action'=>$action,'unchanged'=>true,'counts'=>self::COUNTS,'units'=>152];}
            } else {
                self::require(in_array($action,['seed','replace'],true),'No active BSIT ownership; nothing removed.');
                if($action==='replace') {
                    self::require($plan!==null,'Approved cleanup plan required.');
                    $current=$this->legacyPlan();
                    foreach(['dataset','records','protected','manifest_digest'] as $key)self::require(($plan[$key]??null)===$current[$key],'Cleanup snapshot changed; regenerate/review before continuing.');
                    $removed=$current['records'];$this->remove($removed);
                }
                // Never adopt pre-existing collisions; UNIQUE constraints roll back the transaction.
                $records=$this->create();$this->verifyData($records);
            }
            self::require($this->snapshot(self::PROTECTED)===$protected,'Protected records changed; rolling back.');
            // Same existing aggregate audit mechanism; no fabricated per-row human events.
            $GLOBALS['conn']=$db;$_SESSION=['user_id'=>$this->actor];
            if($action==='cleanup') {
                audit_log_required('DEVELOPMENT_SEED_REMOVE','development_dataset',null,'Controlled BSIT demonstration dataset removed.',
                    ['dataset'=>self::ID,'seed_audit_id'=>(int)$last['id']]);
            } else {
                audit_log_required('DEVELOPMENT_SEED_CREATE','development_dataset',null,'Controlled BSIT demonstration dataset created.',
                    ['dataset'=>self::ID,'manifest_digest'=>self::digest($this->manifest),'records'=>$records,
                        'replaced_records'=>$removed,'backup_sha256'=>$plan['backup_sha256']??null]);
            }
            $db->commit();
            return ['action'=>$action,'counts'=>self::COUNTS,'units'=>152,'owned_ids'=>array_map('array_keys',$records)];
        } catch(Throwable $e) {$db->rollback();throw $e;}
        finally {$s=$db->prepare('SELECT RELEASE_LOCK(?)');$s->bind_param('s',$lock);$s->execute();$s->close();}
    }
}
