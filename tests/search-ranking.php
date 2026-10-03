<?php
// Connection-local fixtures only; no permanent data is changed.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/../shared/database_config.php';
require_once __DIR__.'/../modules/curriculum/repositories/SubjectRepository.php';
require_once __DIR__.'/../modules/curriculum/repositories/ElectivesWorkspaceRepository.php';
$config=csms_database_config();
if (!in_array($config['host'],['localhost','127.0.0.1'],true) || getenv('APP_ENV')==='production') throw new RuntimeException('Local only.');
$db=csms_database_connect($config);$tables=['subjects','academic_programs','curricula','curriculum_versions','curriculum_version_subjects'];$checks=0;
function searchCheck(bool $value,string $message):void {global $checks;if(!$value)throw new RuntimeException($message);$checks++;}
try {
 foreach($tables as $table){$db->query("CREATE TEMPORARY TABLE __search_template LIKE `$table`");$db->query("CREATE TEMPORARY TABLE `$table` LIKE __search_template");$db->query('DROP TEMPORARY TABLE __search_template');}
 $fixtures=[['BPM101','Business Process Management in IT'],['GE3','The Contemporary World'],['GE1','Understanding the Self'],['GE2','Readings in Philippine History'],['CAP102','Capstone Project 2'],['CAP101','Capstone Project 1'],['CC101','Introduction to Computing'],['UNRELATED','Plain title']];
 foreach($fixtures as [$code,$title])RepositoryQuery::execute($db,"INSERT INTO subjects(subject_code,subject_title,description) VALUES(?,?,'GE hidden description')",'ss',[$code,$title])->close();
 $repo=new SubjectRepository($db);
 $search=fn($q,$extra=[])=>$repo->paginate(array_replace(['search'=>$q,'status'=>null,'page'=>1,'per_page'=>50],$extra));
 $codes=fn($q)=>array_column($search($q)['records'],'subject_code');
 foreach(['GE','ge',' Ge '] as $q)searchCheck($codes($q)===['GE1','GE2','GE3','BPM101'],'GE relevance and explicit fields');
 foreach(['BPM'=>['BPM101'],'CAP'=>['CAP101','CAP102'],'CC101'=>['CC101'],'ZZZZ'=>[],' Business  Process   Management in IT '=>['BPM101'],'%'=>[],'_'=>[],"' OR 1=1 --"=>[]] as $q=>$expected)searchCheck($codes($q)===$expected,'Query '.$q);
 $one=$search('GE',['per_page'=>1,'page'=>2]);searchCheck($one['records'][0]['subject_code']==='GE2'&&$one['pagination']['total_records']===4,'Ranking before pagination with matching count');
 $db->query("INSERT INTO academic_programs(id,program_code,program_name) VALUES(1,'IT','Computing'),(2,'OTHER','Other')");
 $db->query("INSERT INTO curricula(id,academic_program_id,curriculum_code,curriculum_name) VALUES(1,1,'IT-C','Computing'),(2,2,'OTHER-C','Other')");
 $db->query("INSERT INTO curriculum_versions(id,curriculum_id,version_label,effective_academic_year) VALUES(1,1,'V1','2026-2027'),(2,2,'V2','2027-2028')");
 $db->query("INSERT INTO curriculum_version_subjects(curriculum_version_id,subject_id,year_level,semester) VALUES(1,3,'3rd Year','2nd Semester'),(2,4,'4th Year','1st Semester')");
 searchCheck(array_column($search('GE',['curriculum_version_id'=>1])['records'],'subject_code')===['GE1'],'Version isolation');
 searchCheck($search('GE',['curriculum_version_id'=>1,'academic_program_id'=>2])['records']===[],'Conflicting context returns no subjects');
 $electives=new ElectivesWorkspaceRepository($db);
 searchCheck(count($electives->contextOptions('version','2026',1,1))===1,'Version academic year searchable');
 searchCheck($electives->contextOptions('version','',2,1)===[],'Dependent context isolation');
 // Database and browser helpers must agree on token boundaries and whitespace.
 $rankParams=[];$order=RepositoryQuery::searchOrder(['subject_code','subject_title'],'Computing',$rankParams);
 $result=RepositoryQuery::execute($db,'SELECT subject_code FROM subjects ORDER BY '.$order.'id',str_repeat('s',count($rankParams)),$rankParams)->get_result()->fetch_all(MYSQLI_ASSOC);
 searchCheck($result[0]['subject_code']==='CC101','Token prefix rank');
 $db->query("INSERT INTO subjects(subject_code,subject_title) VALUES('AAA','General elective')");
 searchCheck($codes('GE')===['GE1','GE2','GE3','AAA','BPM101'],'Code prefixes outrank title prefixes');
 searchCheck($codes('General elective')===['AAA'],'Exact subject title');
 echo "PASS: $checks database search checks; temporary fixtures only.\n";
} finally {foreach(array_reverse($tables) as $table)$db->query("DROP TEMPORARY TABLE IF EXISTS `$table`");$db->close();}
