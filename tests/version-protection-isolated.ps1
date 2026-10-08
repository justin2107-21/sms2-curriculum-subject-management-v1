param([string]$MysqlBin='C:\xampp\mysql\bin',[string]$Php='C:\xampp\php\php.exe')
$ErrorActionPreference='Stop'
$root=Split-Path $PSScriptRoot -Parent
$taskDir=Join-Path ([IO.Path]::GetTempPath()) ('csms-task2-'+[guid]::NewGuid().ToString('N'))
$null=New-Item -ItemType Directory -Path $taskDir
$listener=[Net.Sockets.TcpListener]::new([Net.IPAddress]::Loopback,0)
$listener.Start();$taskPort=$listener.LocalEndpoint.Port;$listener.Stop()
$taskPassword=[guid]::NewGuid().ToString('N')+[guid]::NewGuid().ToString('N')
$server=$null
try {
    $init=& (Join-Path $MysqlBin 'mysql_install_db.exe') "--datadir=$taskDir\data" "--password=$taskPassword" "--port=$taskPort" --silent
    if($LASTEXITCODE -ne 0){throw 'BLOCKED: disposable initialization failed'}
    $server=Start-Process -FilePath (Join-Path $MysqlBin 'mysqld.exe') -ArgumentList @('--no-defaults',"--datadir=`"$taskDir\data`"","--port=$taskPort",'--bind-address=127.0.0.1','--console') -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $taskDir 'stdout.log') -RedirectStandardError (Join-Path $taskDir 'stderr.log')
    $ready=$false
    for($n=0;$n -lt 60;$n++){
        $client=[Net.Sockets.TcpClient]::new()
        try{$client.Connect('127.0.0.1',$taskPort);$ready=$true;break}catch{Start-Sleep -Milliseconds 100}finally{$client.Dispose()}
    }
    if(-not $ready){throw 'BLOCKED: disposable server not ready'}
    $utf8=[Text.UTF8Encoding]::new($false)
    [IO.File]::WriteAllText((Join-Path $taskDir 'state.json'),(@{port=$taskPort;password=$taskPassword;root=$root}|ConvertTo-Json),$utf8)
    $script=@'
<?php
$s=json_decode(file_get_contents(__DIR__.'/state.json'),true);
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$db=new mysqli('127.0.0.1','root',$s['password'],null,$s['port']);
$r=$db->query('SELECT VERSION() version,@@port port,@@datadir datadir,@@foreign_key_checks fk')->fetch_assoc();
if(strtolower(realpath($r['datadir']))!==strtolower(realpath(__DIR__.'/data'))||(int)$r['port']!==$s['port']||(int)$r['fk']!==1)throw new RuntimeException('BLOCKED: unsafe database identity');
echo 'SAFE SERVER '.json_encode($r).PHP_EOL;
foreach(['DB_HOST'=>'127.0.0.1','DB_PORT'=>(string)$s['port'],'DB_USER'=>'root','DB_PASS'=>$s['password'],'APP_ENV'=>'development','SMS_SESSION_COOKIE_SECURE'=>'0','CIMF_AI_ENABLED'=>'false'] as $k=>$v)putenv("$k=$v");
function cli(array $args): int {
    global $s;
    $p=proc_open(array_merge([PHP_BINARY],$args),[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$s['root'],null,['bypass_shell'=>true,'create_new_console'=>false]);
    fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($p);echo $out.$err;return $code;
}
$tests=['deployment_migrations.php','curriculum_lifecycle_schema.php','version-protection.php','version-protection-http.php','prerequisite-service.php','electives-service.php','subject-mapping-http.php','electives-http.php','archives-http.php','academic-sequence.php','search-ranking.php'];
$failed=[];
foreach($tests as $i=>$file){
    $name='csms_docker_test_task2_run_'.$i.'_'.bin2hex(random_bytes(4));
    $db->query("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    putenv("DB_NAME=$name");putenv("SMS_TEST_CONFIRM_DATABASE=$name");
    try {
        if($file!=='deployment_migrations.php' && cli([$s['root'].'/database/deploy.php','--apply','--confirm-database='.$name])!==0)throw new RuntimeException('Deployment failed');
        if(cli([$s['root'].'/tests/'.$file])!==0)$failed[]=$file;
    }finally{$db->query("DROP DATABASE `$name`");}
}
echo json_encode(['tests'=>count($tests),'failed'=>$failed,'passed'=>count($tests)-count($failed)]).PHP_EOL;
exit($failed?1:0);
'@
    [IO.File]::WriteAllText((Join-Path $taskDir 'runner.php'),$script,$utf8)
    & $Php (Join-Path $taskDir 'runner.php')
    $testExit=$LASTEXITCODE
} finally {
    if($null -ne $server -and -not $server.HasExited){
        $saved=[Environment]::GetEnvironmentVariable('MYSQL_PWD','Process')
        try{
            $env:MYSQL_PWD=$taskPassword
            & (Join-Path $MysqlBin 'mysqladmin.exe') --no-defaults --host=127.0.0.1 "--port=$taskPort" --user=root shutdown
            if($LASTEXITCODE -ne 0 -or -not $server.WaitForExit(10000)){throw 'Retaining files: disposable shutdown incomplete'}
        } finally {[Environment]::SetEnvironmentVariable('MYSQL_PWD',$saved,'Process')}
    }
    $resolved=[IO.Path]::GetFullPath($taskDir)
    $tempRoot=[IO.Path]::GetFullPath([IO.Path]::GetTempPath()).TrimEnd('\')+'\'
    if(-not $resolved.StartsWith($tempRoot,[StringComparison]::OrdinalIgnoreCase) -or (Split-Path $resolved -Leaf) -notmatch '^csms-task2-[a-f0-9]{32}$'){throw 'Unsafe cleanup path'}
    Remove-Item -LiteralPath $resolved -Recurse -Force
    Write-Output 'Disposable server/data removed; existing XAMPP data untouched.'
}
exit $testExit
