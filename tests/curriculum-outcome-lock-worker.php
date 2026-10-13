<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../shared/db.php';
require_once __DIR__.'/../modules/curriculum/services/CurriculumOutcomeMappingService.php';
echo 'READY '.$conn->thread_id.PHP_EOL;flush();
try{(new CurriculumOutcomeMappingService($conn))->add((int)$argv[1],(int)$argv[2],(int)$argv[3],'Bounded concurrency fixture.',(int)$argv[4]);echo "COMMITTED\n";exit(0);}
catch(DomainConflictException|DomainValidationException $e){echo "REJECTED\n";exit(0);}
catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);}
