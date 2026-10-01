<?php
// Read-only service/export regression for an existing confirmed BSIT dataset.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/../shared/database_config.php';
require_once __DIR__.'/../database/seeds/bsit_dataset.php';
if(!isset($conn)) {
    $c=csms_database_config();
    BsitDataset::require(in_array($c['host'],['localhost','127.0.0.1'],true)&&$c['database']==='sms_db'
        && $c['port']===3306 && getenv('APP_ENV')!=='production','Local read-only verification required.');
    $conn=csms_database_connect($c);
}
foreach(glob(__DIR__.'/../modules/curriculum/services/*Service.php') as $file)require_once $file;
$readChecks=0;
$assertRead=static function(bool $ok,string $label)use(&$readChecks):void{
    BsitDataset::require($ok,$label);$readChecks++;
};
$auditBefore=(int)$conn->query('SELECT COUNT(*) FROM audit_logs')->fetch_row()[0];
$program=(int)$conn->query("SELECT id FROM academic_programs WHERE program_code='BSIT'")->fetch_row()[0];
$curriculum=(int)$conn->query("SELECT id FROM curricula WHERE curriculum_code='BSIT-DEMO-2026'")->fetch_row()[0];
$version=(int)$conn->query("SELECT id FROM curriculum_versions WHERE curriculum_id=$curriculum AND version_label='PREORAL-2026'")->fetch_row()[0];
$subjects=new SubjectService($conn);$mapping=new CurriculumVersionSubjectService($conn);
$m=BsitDataset::manifest();
foreach($m['subjects'] as $source) {
    $result=$subjects->list(['search'=>$source['subject_code'],'per_page'=>50,'status'=>'active']);
    $exact=array_values(array_filter($result['subjects'],static fn($s)=>$s['subject_code']===$source['subject_code']));
    $assertRead(count($exact)===1 && $exact[0]['subject_title']===$source['subject_name']
        && (float)$exact[0]['units']===(float)$source['total_units'],'Subject exact code/title/units search');
}
$context=['academic_program_id'=>$program,'curriculum_id'=>$curriculum,'curriculum_version_id'=>$version];
foreach(BsitDataset::GROUPS as $group=>[$count,$units]) {
    [$year,$term]=explode(' / ',$group);
    $result=$mapping->listMappings(array_merge($context,['year_level'=>$year,'semester'=>$term,'per_page'=>50]));
    $assertRead($result['pagination']['total_records']===$count && (float)array_sum(array_column($result['mappings'],'units'))===(float)$units,'Mapping group count/units');
}
$ids=[];
for($page=1;$page<=3;$page++){
    $result=$mapping->listMappings(array_merge($context,['page'=>$page,'per_page'=>20]));
    $assertRead($result['pagination']['total_records']===50 && $result['pagination']['total_pages']===3,'Pagination metadata');
    foreach($result['mappings'] as $row)$ids[]=$row['id'];
}
$assertRead(count($ids)===50 && count(array_unique($ids))===50,'Pagination has no missing/duplicate placements');
foreach(['program','subject'] as $view){
    $r=(new AcademicProgramAssignmentService($conn))->listAssignments(array_merge($context,['view'=>$view,'per_page'=>50]));
    $assertRead($r['pagination']['total_records']===50,'Assignment derived view');
}
$h=(new SubjectOfferingHistoryService($conn))->listHistory(array_merge($context,['per_page'=>50]));
$assertRead($h['pagination']['total_records']===50,'History is stored curriculum usage');
$assertRead((new CurriculumSubjectRequirementService($conn))->list(['curriculum_version_id'=>$version])['pagination']['total_records']===0,'Empty requirements');
$assertRead((new CurriculumElectiveGroupService($conn))->list($version,[])['pagination']['total_records']===0,'Empty elective groups');
$assertRead((new SubjectEquivalencyService($conn))->list([])['pagination']['total_records']===0,'Empty equivalency records');
$export=new CurriculumExportService($conn);
$report=$export->dataset(array_merge($context,['include_requirements'=>1,'include_electives'=>1,'include_grade_weighting'=>1,'include_compliance'=>1]));
$assertRead($report['context']['program_code']==='BSIT' && $report['context']['curriculum_code']==='BSIT-DEMO-2026'
    && $report['context']['version_label']==='PREORAL-2026','Export context');
$assertRead(count($report['subjects'])===50 && (float)$report['summary']['total_units']===152.0,'Export 50 subjects / 152 units');
$assertRead($report['summary']['total_lecture_units']===null && $report['summary']['total_laboratory_units']===null,
    'All-NULL lecture/lab summaries remain unavailable, never zero');
foreach(BsitDataset::GROUPS as $group=>[$expectedCount,$expectedUnits]) {
    [$year,$semester]=explode(' / ',$group);
    $filtered=$subjects->list(['curriculum_version_id'=>$version,'year_level'=>$year,'semester'=>$semester,'per_page'=>50]);
    $assertRead($filtered['pagination']['total_records']===$expectedCount
        && (float)array_sum(array_column($filtered['subjects'],'units'))===(float)$expectedUnits,'Subject Master placement-context filters');
}
$contexts=$subjects->contexts(['curriculum_version_id'=>$version,'per_page'=>20]);
$assertRead(in_array('1st Year',$contexts['filter_values']['year_levels'],true)
    && in_array('2nd Semester',$contexts['filter_values']['semesters'],true),'Context choices derive from stored placements');
$assertRead(!$report['requirements'] && !$report['elective_groups'] && !$report['grade_weighting'] && $report['latest_compliance_run']===null,'Empty optional export sections');
$csv=fopen('php://temp','w+b');$export->writeCsv($csv,$report);rewind($csv);
$csvSubjects=[];
while(($row=fgetcsv($csv))!==false){
    if(count($row)===18 && $row[0]==='BSIT'){
        $csvSubjects[$row[8]]=$row;
        $assertRead($row[12]==='' && $row[13]==='','CSV unknown lecture/lab blank, not zero');
    }
}
$assertRead(count($csvSubjects)===50,'CSV has 50 source rows');
foreach($m['subjects'] as $s) {
    $r=$csvSubjects[$s['subject_code']]??[];
    $assertRead(($r[9]??null)===$s['subject_name'] && (float)($r[11]??-1)===(float)$s['total_units']
        && ($r[14]??null)===$s['year_level'] && ($r[15]??null)===$s['semester'],'CSV source values preserved');
}
if(realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__){
    $options=getopt('',['csv:']);
    if(isset($options['csv'])){
        $path=$options['csv'];$root=realpath('C:/Users/esquitajustin/Documents/csms-local-backups');
        BsitDataset::require(realpath(dirname($path))===$root && basename($path)==='bsit_preoral_demonstration.csv' && !file_exists($path),'New external demonstration CSV path required.');
        rewind($csv);$file=fopen($path,'xb');BsitDataset::require($file!==false,'Cannot create external CSV.');
        stream_copy_to_stream($csv,$file);fclose($file);echo "PASS: Proposed/demonstration CSV saved outside repository/web root.\n";
    }
}
fclose($csv);
$assertRead((int)$conn->query('SELECT COUNT(*) FROM audit_logs')->fetch_row()[0]===$auditBefore,'Read-only checks create no audit noise');
echo "PASS: $readChecks BSIT service/search/filter/pagination/CSV checks.\n";
