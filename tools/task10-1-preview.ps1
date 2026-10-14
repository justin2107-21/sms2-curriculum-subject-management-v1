param(
    [ValidateSet('Start','Stop','Status','Reset')][string]$Action='Start',
    [string]$ConfirmReset='',
    [string]$MysqlBin='C:\xampp\mysql\bin',
    [string]$Php='C:\xampp\php\php.exe'
)
$ErrorActionPreference='Stop'
$root=[IO.Path]::GetFullPath((Split-Path $PSScriptRoot -Parent))
if((Split-Path $root -Leaf) -ne 'sms-csms-task10-1'){throw 'This preview belongs only to sms-csms-task10-1.'}
$stateDir=[IO.Path]::GetFullPath((Join-Path $env:LOCALAPPDATA 'CSMS-SMS2\task10-1-preview'))
$stateFile=Join-Path $stateDir 'state.json'
$utf8=[Text.UTF8Encoding]::new($false)
function Save-State($value){[IO.File]::WriteAllText($stateFile,($value|ConvertTo-Json -Depth 5),$utf8)}
function Free-Port {
    $listener=[Net.Sockets.TcpListener]::new([Net.IPAddress]::Loopback,0)
    $listener.Start();$result=$listener.LocalEndpoint.Port;$listener.Stop()
    if($result -in @(3306,80,443)){throw 'Unsafe disposable port.'};return $result
}
function Ready-Port([int]$port) {
    $client=[Net.Sockets.TcpClient]::new()
    try{$client.Connect('127.0.0.1',$port);return $true}catch{return $false}finally{$client.Dispose()}
}
function Check-Server([string]$mode='check') {
    & $Php (Join-Path $PSScriptRoot 'task10-1-preview.php') $stateFile $mode
    if($LASTEXITCODE -ne 0){throw 'Preview identity/schema verification failed. No normal XAMPP connection is allowed.'}
}
function Stop-Owned-Process([int]$processId,[string]$started,[string]$executable) {
    if($processId -lt 1){return}
    $process=Get-Process -Id $processId -ErrorAction SilentlyContinue
    if($null -eq $process){return}
    if($process.StartTime.ToUniversalTime().Ticks.ToString() -ne $started -or $process.Path -ne $executable){throw 'Refusing to stop a process that does not match the preview manifest.'}
    Stop-Process -Id $processId
}
if(Test-Path -LiteralPath $stateFile) {
    $state=Get-Content -LiteralPath $stateFile -Raw|ConvertFrom-Json
    if($state.kind -ne 'CSMS_TASK10_1_DISPOSABLE_PREVIEW' -or $state.root -ne $root -or $state.marker -notmatch '^[a-f0-9]{32}$' -or ([IO.Path]::GetFullPath($state.data)) -ne (Join-Path $stateDir 'data') -or $state.database -notmatch '^csms_docker_test_task7_run_101_[a-f0-9]{32}$' -or $state.dbPort -eq 3306 -or $state.httpPort -in @(80,443,3306)){throw 'Invalid preview manifest; refusing all operations.'}
} elseif($Action -ne 'Start') {Write-Output 'No Task 10.1 preview has been created.';exit 0}
if($Action -eq 'Reset') {
    if($ConfirmReset -ne $state.marker){throw ('Reset is explicit. Re-run with -ConfirmReset '+$state.marker)}
    & $PSCommandPath -Action Stop -MysqlBin $MysqlBin -Php $Php
    if($LASTEXITCODE -ne 0){throw 'Preview stop failed; retaining all files.'}
    $expected=[IO.Path]::GetFullPath((Join-Path $env:LOCALAPPDATA 'CSMS-SMS2\task10-1-preview'))
    if($stateDir -ne $expected -or (Split-Path $stateDir -Leaf) -ne 'task10-1-preview'){throw 'Unsafe reset directory.'}
    Remove-Item -LiteralPath $stateDir -Recurse -Force
    Write-Output 'Only the verified disposable Task 10.1 preview was reset.';exit 0
}
if($Action -eq 'Stop') {
    if(Ready-Port $state.dbPort) {
        Check-Server
        $previous=[Environment]::GetEnvironmentVariable('MYSQL_PWD','Process')
        try{$env:MYSQL_PWD=$state.dbPassword;& (Join-Path $MysqlBin 'mysqladmin.exe') --no-defaults --host=127.0.0.1 "--port=$($state.dbPort)" --user=root shutdown;if($LASTEXITCODE -ne 0){throw 'Disposable shutdown failed.'};$ownedDb=Get-Process -Id ([int]$state.dbPid) -ErrorAction SilentlyContinue;if($ownedDb -and -not $ownedDb.WaitForExit(10000)){throw 'Disposable database has not exited; retaining files.'}}
        finally{[Environment]::SetEnvironmentVariable('MYSQL_PWD',$previous,'Process')}
    } else {
        $dbProcess=Get-Process -Id ([int]$state.dbPid) -ErrorAction SilentlyContinue
        if($dbProcess){throw 'Recorded database process is alive but not reachable; retaining files for inspection.'}
    }
    Stop-Owned-Process ([int]$state.webPid) ([string]$state.webStarted) $Php
    Write-Output 'Task 10.1 preview stopped. Synthetic data retained. Start resumes it; Reset is separate.';exit 0
}
if($Action -eq 'Status') {
    if(Ready-Port $state.dbPort){Check-Server 'status'}else{Write-Output 'Task 10.1 preview database is stopped.'}
    Write-Output "Preview URL: http://127.0.0.1:$($state.httpPort)/auth/signin.php"
    Write-Output "Local login instructions: $(Join-Path $stateDir 'login.txt')"
    Write-Output "Reset confirmation: $($state.marker)";exit 0
}
if(!(Test-Path -LiteralPath $stateFile)) {
    if(Test-Path -LiteralPath $stateDir){throw 'Preview directory already exists without its manifest. Refusing to overwrite it.'}
    $null=New-Item -ItemType Directory -Path $stateDir
    $null=New-Item -ItemType Directory -Path (Join-Path $stateDir 'sessions')
    $state=[pscustomobject]@{kind='CSMS_TASK10_1_DISPOSABLE_PREVIEW';marker=[guid]::NewGuid().ToString('N');root=$root;data=(Join-Path $stateDir 'data');database=('csms_docker_test_task7_run_101_'+[guid]::NewGuid().ToString('N'));dbPort=(Free-Port);httpPort=(Free-Port);dbPassword=([guid]::NewGuid().ToString('N')+[guid]::NewGuid().ToString('N'));loginPassword=[guid]::NewGuid().ToString('N');viewerPassword=[guid]::NewGuid().ToString('N');dbPid=0;dbStarted='';webPid=0;webStarted=''}
    if($state.dbPort -eq $state.httpPort){throw 'Preview ports collided; no server started.'}
    Save-State $state
    $null=& (Join-Path $MysqlBin 'mysql_install_db.exe') "--datadir=$($state.data)" "--password=$($state.dbPassword)" "--port=$($state.dbPort)" --silent
    if($LASTEXITCODE -ne 0){throw 'Disposable database initialization failed.'}
    [IO.File]::WriteAllText((Join-Path $stateDir 'login.txt'),("DISPOSABLE SYNTHETIC TASK 10.1 PREVIEW - NOT BCP ACADEMIC DATA"+[Environment]::NewLine+"Editor username: task10_1_preview"+[Environment]::NewLine+"Editor password: "+$state.loginPassword+[Environment]::NewLine+"Viewer username: task10_1_viewer"+[Environment]::NewLine+"Viewer password: "+$state.viewerPassword),$utf8)
}
if(!(Ready-Port $state.dbPort)) {
    $dbProcess=Start-Process -FilePath (Join-Path $MysqlBin 'mysqld.exe') -ArgumentList @('--no-defaults',"--datadir=`"$($state.data)`"","--port=$($state.dbPort)",'--bind-address=127.0.0.1','--console') -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $stateDir 'database-stdout.log') -RedirectStandardError (Join-Path $stateDir 'database-stderr.log')
    $state.dbPid=$dbProcess.Id;$state.dbStarted=$dbProcess.StartTime.ToUniversalTime().Ticks.ToString();Save-State $state
    $ready=$false;for($n=0;$n -lt 100;$n++){if(Ready-Port $state.dbPort){$ready=$true;break};Start-Sleep -Milliseconds 100}
    if(!$ready){throw 'Disposable preview database did not become ready.'}
}
Check-Server
if(!(Test-Path -LiteralPath (Join-Path $stateDir 'fixture.json'))){Check-Server 'init'}
Check-Server 'status'
if(!(Ready-Port $state.httpPort)) {
    $keys=@('DB_HOST','DB_PORT','DB_USER','DB_PASS','DB_NAME','APP_ENV','SMS_SESSION_COOKIE_SECURE','CIMF_AI_ENABLED','CSMS_DISPOSABLE_PREVIEW')
    $saved=@{};foreach($key in $keys){$saved[$key]=[Environment]::GetEnvironmentVariable($key,'Process')}
    try {
        $env:DB_HOST='127.0.0.1';$env:DB_PORT=[string]$state.dbPort;$env:DB_USER='root';$env:DB_PASS=$state.dbPassword;$env:DB_NAME=$state.database;$env:APP_ENV='development';$env:SMS_SESSION_COOKIE_SECURE='0';$env:CIMF_AI_ENABLED='false';$env:CSMS_DISPOSABLE_PREVIEW='1'
        $web=Start-Process -FilePath $Php -ArgumentList @('-d',"session.save_path=`"$(Join-Path $stateDir 'sessions')`"",'-d',"error_log=`"$(Join-Path $stateDir 'php-errors.log')`"",'-S',"127.0.0.1:$($state.httpPort)",'-t',"`"$root`"") -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $stateDir 'web-stdout.log') -RedirectStandardError (Join-Path $stateDir 'web-stderr.log')
        $state.webPid=$web.Id;$state.webStarted=$web.StartTime.ToUniversalTime().Ticks.ToString();Save-State $state
    } finally {foreach($key in $keys){[Environment]::SetEnvironmentVariable($key,$saved[$key],'Process')}}
    $ready=$false;for($n=0;$n -lt 60;$n++){if(Ready-Port $state.httpPort){$ready=$true;break};Start-Sleep -Milliseconds 100}
    if(!$ready){throw 'Preview web server did not become ready.'}
} else {
    $web=Get-Process -Id ([int]$state.webPid) -ErrorAction SilentlyContinue
    if(!$web -or $web.StartTime.ToUniversalTime().Ticks.ToString() -ne $state.webStarted -or $web.Path -ne $Php){throw 'Preview HTTP port belongs to an unverified process.'}
}
Write-Output "Preview URL: http://127.0.0.1:$($state.httpPort)/auth/signin.php"
Write-Output "Read local credentials: Get-Content -LiteralPath '$(Join-Path $stateDir 'login.txt')'"
Write-Output 'Preview stays running for interactive testing. Use -Action Stop to stop it without deleting data.'
