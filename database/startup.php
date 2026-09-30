<?php
// Explicit container-start maintenance only. No HTTP setup route.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (getenv('CSMS_RUN_MIGRATIONS') !== '1') { exit(0); }
require_once __DIR__.'/../shared/database_config.php';
$stage='database confirmation';
try {
    $config=csms_database_config();
    $confirmed=getenv('CSMS_MIGRATION_CONFIRM_DATABASE');
    if (!is_string($confirmed) || $confirmed==='' || $confirmed!==$config['database']) {
        throw new RuntimeException('Database confirmation mismatch.');
    }
    $db=csms_database_connect($config);
    if ($db->query('SELECT DATABASE()')->fetch_row()[0]!==$confirmed) {
        throw new RuntimeException('Connected database confirmation mismatch.');
    }
    $db->close();
    foreach ([['deploy.php','--status'],['deploy.php','--apply','--confirm-database='.$confirmed],['inspect.php']] as $command) {
        $stage=$command[0]==='inspect.php'?'schema verification':($command[1]==='--status'?'migration status':'migration apply');
        $file=array_shift($command);
        // Array argv, no shell interpolation. Capture output: no DB names, row
        // counts, environment values or stack traces go into startup logs.
        $process=proc_open(array_merge([PHP_BINARY,__DIR__.'/'.$file],$command),
            [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        if (!is_resource($process)) { throw new RuntimeException('Maintenance process unavailable.'); }
        fclose($pipes[0]);$output=stream_get_contents($pipes[1]);fclose($pipes[1]);
        $diagnostic=stream_get_contents($pipes[2]);fclose($pipes[2]);
        if (preg_match('/Deployment migration stopped; type [A-Za-z_]+, code [0-9]+/',$diagnostic,$safeDiagnostic)) {
            fwrite(STDERR,$safeDiagnostic[0].PHP_EOL);
        }
        if (proc_close($process)!==0) { throw new RuntimeException('Maintenance process failed.'); }
        if ($stage==='migration status') {
            $status=json_decode($output,true,512,JSON_THROW_ON_ERROR);
            echo empty($status['pending']) ? "Startup migrations: no pending migrations.\n" : "Startup migrations: pending migrations found.\n";
        }
        if ($stage==='schema verification') {
            $report=json_decode($output,true,512,JSON_THROW_ON_ERROR);
            if (!isset($report['issues']) || $report['issues']!==[]) { throw new RuntimeException('Schema verification failed.'); }
        }
    }
    echo "Startup migrations: completed and verified.\n";
} catch (Throwable $error) {
    fwrite(STDERR,'Startup migrations failed at '.$stage.'; Apache will not start. Inspect the migration journal before retrying.'.PHP_EOL);
    exit(1);
}
