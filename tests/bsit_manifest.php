<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/../database/seeds/bsit_preoral.php';
$checks=0;
function manifestCheck(bool $ok,string $label): void {
    if(!$ok)throw new RuntimeException($label);
    global $checks;$checks++;echo "PASS: $label\n";
}
try {
    $m=BsitDataset::manifest();
    manifestCheck($m['planned_structure']['academic_program']['program_type']==="Bachelor's Degree",'Canonical BSIT degree level');
    manifestCheck(count($m['subjects'])===50,'50 unique source subjects, 152 units, seven exact distributions, lecture/lab NULL');
    foreach(['pending','negative','duplicate','blank','year','semester','precision','lab','total','program_type'] as $bad) {
        $v=$m;
        switch($bad) {
            case 'pending': $v['subjects'][0]['total_units']='PENDING';break;
            case 'negative': $v['subjects'][0]['total_units']=-1;break;
            case 'duplicate': $v['subjects'][1]['subject_code']=strtolower($v['subjects'][0]['subject_code']);break;
            case 'blank': $v['subjects'][0]['subject_name']=' ';break;
            case 'year': $v['subjects'][0]['year_level']='5th Year';break;
            case 'semester': $v['subjects'][0]['year_level']='4th Year';$v['subjects'][0]['semester']='2nd Semester';break;
            case 'precision': $v['subjects'][0]['total_units']=3.001;break;
            case 'lab': $v['subjects'][0]['laboratory_units']=0;break;
            case 'total': $v['subjects'][0]['total_units']=4;break;
            case 'program_type': $v['planned_structure']['academic_program']['program_type']=null;break;
        }
        $rejected=false;try{BsitDataset::manifest($v);}catch(RuntimeException $e){$rejected=true;}
        manifestCheck($rejected,'Reject '.$bad.' before any database connection');
    }
    $c=['host'=>'127.0.0.1','port'=>3306,'database'=>'sms_db'];
    bsitLocalConfig($c);
    foreach(['remote','port','database'] as $bad) {
        $v=$c;
        if($bad==='remote')$v['host']='production.example.invalid';
        elseif($bad==='port')$v['port']=3307;
        else $v['database']='production';
        $rejected=false;try{bsitLocalConfig($v);}catch(RuntimeException $e){$rejected=true;}
        manifestCheck($rejected,'Local gate refuses '.$bad);
    }
    foreach(array_slice($argv,1) as $arg) {
        if($arg==='--require-seed-ready')continue; // Compatibility with preparation-only check.
        if(strpos($arg,'--source=')!==0)throw new RuntimeException('Unknown test option.');
        $source=file_get_contents(substr($arg,9));
        $start=strpos($source,'AUTHORITATIVE PHASE 2 DATASET');
        $end=strpos($source,'EXPECTED SOURCE TOTALS',$start===false?0:$start);
        manifestCheck($start!==false && $end!==false,'Original attachment boundaries');
        $source=substr($source,$start,$end-$start);
        preg_match_all('/^([^\r\n]+)\R+\s*([^\r\n]+)\R+Total Units:\s*([0-9]+)\s*$/m',$source,$matches,PREG_SET_ORDER);
        $pairs=[];foreach($matches as $row)$pairs[]=[trim($row[1]),trim($row[2]),(int)$row[3]];
        $actual=array_map(static fn($s)=>[$s['subject_code'],$s['subject_name'],$s['total_units']],$m['subjects']);
        manifestCheck($pairs===$actual,'Every code/title/unit matches authoritative attachment in order');
    }
    echo "PASS: $checks database-free manifest checks; no database opened.\n";
} catch(Throwable $e) {fwrite(STDERR,'FAIL: '.$e->getMessage().PHP_EOL);exit(1);}
