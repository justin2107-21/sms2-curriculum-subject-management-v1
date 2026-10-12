<?php

/** Pure read-model calculation. Database IDs for placements/groups/schemes are not equivalence keys. */
final class CurriculumHistoryComparison
{
    public const ACADEMIC_FIELDS = ['subject_code','subject_title','units','lecture_units','laboratory_units','description'];
    private const PLACEMENT_FIELDS = ['year_level','semester','sequence_order','is_required'];

    public static function calculate(array $reference, array $revision, array $sourceIds, int $referenceId): array
    {
        $a = self::index($reference['placements'], 'subject_id');
        $b = self::index($revision['placements'], 'subject_id');
        $ids = array_unique(array_merge(array_keys($a), array_keys($b))); sort($ids, SORT_NUMERIC);
        $summary = array_fill_keys(['added','removed','moved','changed','unchanged','unresolved','academic_differences','unique_changed_subjects'], 0);
        $records = []; $warnings = [];
        foreach ($ids as $id) {
            $left = $a[$id] ?? []; $right = $b[$id] ?? [];
            if (count($left)>1 || count($right)>1) {
                $records[] = ['subject_id'=>(int)$id,'before'=>null,'after'=>null,'categories'=>['unresolved'],
                    'differences'=>[], 'academic_differences'=>[], 'warning'=>'Ambiguous duplicate subject placement; no equivalency inferred.'];
                $summary['unresolved']++; continue;
            }
            $p = $left[0] ?? null; $q = $right[0] ?? null;
            $complete = $p === null || ((int)($p['snapshot_complete']??0)===1
                && in_array((int)($p['source_reference_id']??0), $sourceIds, true)
                && trim((string)$p['subject_code_snapshot'])!=='' && trim((string)$p['subject_title_snapshot'])!==''
                && $p['units_snapshot']!==null);
            $before = $p ? self::values($p,true,$complete) : null;
            $after = $q ? self::values($q,false,true) : null;
            $categories = []; $differences = []; $academic = [];
            if (!$p) $categories[]='added';
            elseif (!$q) $categories[]='removed';
            else {
                foreach (self::PLACEMENT_FIELDS as $field) if (!self::same($p[$field],$q[$field])) $differences[$field]=['before'=>$p[$field],'after'=>$q[$field]];
                if (isset($differences['year_level']) || isset($differences['semester']) || isset($differences['sequence_order'])) $categories[]='moved';
                if (isset($differences['is_required'])) $categories[]='changed';
                if ($complete) foreach (self::ACADEMIC_FIELDS as $field) {
                    if (!self::same($before[$field],$after[$field])) $academic[$field]=['before'=>$before[$field],'after'=>$after[$field]];
                }
                if ($academic) {
                    $summary['academic_differences']++;
                    // Subject properties remain shared, not revision-owned. Show the difference without attributing intent.
                    $categories[]='unresolved';
                }
            }
            if (!$complete) $categories[]='unresolved';
            if (!$categories) $categories[]='unchanged';
            $categories=array_values(array_unique($categories));
            foreach ($categories as $category) $summary[$category]++;
            if (array_intersect($categories,['added','removed','moved','changed'])) $summary['unique_changed_subjects']++;
            $warning = !$complete ? 'Historical subject details unavailable' : ($academic ? 'Shared Subject Master differs from the captured reference; revision-specific academic intent cannot be determined.' : null);
            $records[]=['subject_id'=>(int)$id,'before'=>$before,'after'=>$after,'categories'=>$categories,
                'differences'=>$differences,'academic_differences'=>$academic,'warning'=>$warning,
                'revision_clone_snapshot'=>$q && $q['capture_method']==='clone' ? [
                    'captured_at'=>$q['captured_at'],'source_reference_id'=>$q['source_reference_id'],
                    'notice'=>'Clone-time evidence only; revision values shown here use current Subject Master and current version-owned placement.'] : null];
        }
        if ($summary['unresolved']) $warnings[]='Comparison coverage is partial. Unresolved subjects are not counted as unchanged.';
        $requirements=self::requirements($reference['requirements'],$revision['requirements'],$a,$b);
        $groups=self::groups($reference,$revision,$referenceId);
        $weights=self::weights($reference,$revision);
        $labels=[];
        foreach ($records as $record) {
            $v=($record['before']['details_available']??false) ? $record['before'] : $record['after'];
            $labels[$record['subject_id']]=$v ? $v['subject_code'].' — '.$v['subject_title'] : 'Unresolved subject #'.$record['subject_id'];
        }
        return ['comparison'=>$records,'subject_labels'=>$labels,'summary'=>$summary+['total_subjects'=>count($ids),
            'version_a_subjects'=>count($a),'version_b_subjects'=>count($b),
            'counter_semantics'=>'Subject categories cover placements and academic evidence; relationships are reported separately. Category counts overlap; unique_changed_subjects counts each subject with a confirmed placement/add/remove change once. Academic differences are shared-data observations, not confirmed revision changes.'],
            'requirements'=>$requirements,'electives'=>$groups,'grade_weighting'=>$weights,
            'partial'=>(bool)($summary['unresolved'] || !$requirements['complete'] || !$groups['complete'] || !$weights['complete']),
            'warnings'=>$warnings];
    }

    private static function index(array $rows, string $key): array
    {
        $out=[]; foreach ($rows as $row) $out[(string)$row[$key]][]=$row; return $out;
    }

    private static function same($a,$b): bool { return $a===null || $b===null ? $a===$b : (string)$a===(string)$b; }

    private static function values(array $row,bool $historical,bool $complete): array
    {
        $out=array_intersect_key($row,array_flip(self::PLACEMENT_FIELDS));
        foreach (self::ACADEMIC_FIELDS as $field) $out[$field]=$historical ? ($complete ? $row[$field.'_snapshot'] : null) : $row[$field];
        return $out+['placement_id'=>(int)$row['id'],'details_available'=>$complete,
            'provenance'=>$historical ? 'Immutable reference snapshot' : 'Current version-owned placement; present-day shared Subject Master attributes',
            'captured_at'=>$historical ? $row['captured_at'] : null];
    }

    private static function requirements(array $left,array $right,array $a,array $b): array
    {
        $convert=function(array $rows,array $owners): array {
            $out=[];
            foreach ($rows as $row) {
                $key=$row['subject_id'].':'.$row['required_subject_id'];
                if (!isset($owners[$row['subject_id']],$owners[$row['required_subject_id']])
                    || count($owners[$row['subject_id']])!==1 || count($owners[$row['required_subject_id']])!==1
                    || !in_array($row['requirement_type'],['prerequisite','corequisite'],true)) $row['unresolved']=true;
                $out[$key][]=$row;
            }
            return $out;
        };
        $x=$convert($left,$a);$y=$convert($right,$b);$changes=[];$complete=true;
        foreach (array_unique(array_merge(array_keys($x),array_keys($y))) as $key) {
            $p=$x[$key]??[];$q=$y[$key]??[];
            if (array_filter(array_merge($p,$q),static fn($r)=>!empty($r['unresolved']))) {
                $changes[]=['key'=>$key,'change'=>'unresolved','before'=>$p,'after'=>$q];$complete=false;continue;
            }
            if ($p===$q) continue;
            if (count($p)===1 && count($q)===1) $changes[]=['key'=>$key,'change'=>'type_changed','before'=>$p[0],'after'=>$q[0]];
            else {
                $pt=array_column($p,null,'requirement_type');$qt=array_column($q,null,'requirement_type');
                foreach (array_diff_key($pt,$qt) as $row) $changes[]=['key'=>$key,'change'=>'removed','before'=>$row,'after'=>null];
                foreach (array_diff_key($qt,$pt) as $row) $changes[]=['key'=>$key,'change'=>'added','before'=>null,'after'=>$row];
            }
        }
        return ['complete'=>$complete,'matching'=>'Shared target/required subject IDs and requirement type; row IDs ignored.','changes'=>$changes];
    }

    private static function groups(array $a,array $b,int $referenceId): array
    {
        $prepare=function(array $state): array {
            $members=[];foreach ($state['memberships'] as $m) $members[$m['elective_group_id']][]=(int)$m['subject_id'];
            $out=[];foreach ($state['groups'] as $g) {
                $list=$members[$g['id']]??[];sort($list,SORT_NUMERIC);
                $g['members']=$list;$out[$g['id']]=$g;
            }return $out;
        };
        $left=$prepare($a);$right=$prepare($b);$maps=[];
        foreach ($b['clone_events'] as $event) {
            $data=json_decode($event['metadata_json']??'',true);
            if ((int)($data['source_version_id']??0)===$referenceId) $maps[]=$data['group_id_map']??[];
        }
        $map=count($maps)===1 ? $maps[0] : []; $pairs=[];$used=[];$complete=true;
        foreach ($left as $id=>$g) {
            $new=$map[$id]??null;
            if ($new!==null && isset($right[$new]) && !isset($used[$new])) {
                $pairs[]=[$g,$right[$new],'Recorded clone lineage'];$used[$new]=true;
            } else {
                $matches=array_filter($right,static fn($q)=>$q['group_name']===$g['group_name']);
                $matches=array_diff_key($matches,$used);
                if (count($matches)===1) {$key=array_key_first($matches);$pairs[]=[$g,$matches[$key],'Exact group name'];$used[$key]=true;}
                else {$pairs[]=[$g,null,'No matching key'];if (count($matches)>1)$complete=false;}
            }
        }
        foreach (array_diff_key($right,$used) as $g) $pairs[]=[null,$g,'No matching key'];
        $changes=[];
        foreach ($pairs as [$p,$q,$matching]) {
            $before=$p;$after=$q;unset($before['id'],$after['id']);
            if ($before===$after) continue;
            $changes[]=['change'=>$p===null?'added':($q===null?'removed':'changed'),'matching'=>$matching,'before'=>$before,'after'=>$after];
        }
        // Without lineage a rename cannot be distinguished from a removal plus addition.
        if (!$map && array_filter($changes,static fn($c)=>$c['change']==='added') && array_filter($changes,static fn($c)=>$c['change']==='removed')) $complete=false;
        return ['complete'=>$complete,'matching'=>'Recorded clone group lineage, otherwise exact group name. Unmatched names are additions/removals; rename equivalence is unresolved without lineage.','changes'=>$changes];
    }

    private static function weights(array $a,array $b): array
    {
        $prepare=function(array $state): array {
            $out=[];
            foreach ($state['schemes'] as $s) $out[(string)$s['subject_id']]=['subject_id'=>(int)$s['subject_id'],'description'=>$s['description'],'components'=>[]];
            foreach ($state['components'] as $c) {
                $subject=(string)$c['subject_id'];$name=$c['component_name'];unset($c['subject_id'],$c['component_name']);
                $out[$subject]['components'][$name]=$c;
            }return $out;
        };
        $left=$prepare($a);$right=$prepare($b);$changes=[];
        foreach (array_unique(array_merge(array_keys($left),array_keys($right))) as $key) {
            $p=$left[$key]??null;$q=$right[$key]??null;
            if ($p!==$q) $changes[]=['subject_id'=>(int)$key,'change'=>$p===null?'added':($q===null?'removed':'changed'),'before'=>$p,'after'=>$q];
        }
        return ['complete'=>true,'matching'=>'Scheme by shared subject ID; components by exact name. Renamed components appear as removed/added names, not inferred equivalency; changed subject association appears as scheme removal/addition.','changes'=>$changes];
    }
}
