param([string]$MysqlBin='C:\xampp\mysql\bin',[string]$Php='C:\xampp\php\php.exe')
$ErrorActionPreference='Stop'
Set-StrictMode -Version Latest
$testDir=Join-Path ([IO.Path]::GetTempPath()) ('csms-demo-import-'+[guid]::NewGuid().ToString('N'))
$null=New-Item -ItemType Directory -Path $testDir
$listener=[Net.Sockets.TcpListener]::new([Net.IPAddress]::Loopback,0)
$listener.Start();$port=$listener.LocalEndpoint.Port;$listener.Stop()
$names=@('DB_HOST','DB_PORT','DB_USER','DB_PASS','DB_NAME','APP_ENV','MYSQL_PWD',
    'CSMS_RUN_DEMO_IMPORT','CSMS_DEMO_IMPORT_CONFIRM_DATABASE','CSMS_DEMO_IMPORT_CONFIRM_DATASET',
    'CSMS_DEMO_IMPORT_CONFIRM_MODE','CSMS_DEMO_IMPORT_ACTOR_ID','CSMS_BOOTSTRAP_ADMIN',
    'CSMS_RUN_DEMO_REPAIR','CSMS_DEMO_REPAIR_CONFIRM_DATABASE','CSMS_DEMO_REPAIR_CONFIRM_DATASET',
    'CSMS_DEMO_REPAIR_CONFIRM_MODE','CSMS_DEMO_REPAIR_ACTOR_ID')
$saved=@{};foreach($name in $names){$saved[$name]=[Environment]::GetEnvironmentVariable($name,'Process')}
$server=$null
try {
    $rootPass=[guid]::NewGuid().ToString('N')+[guid]::NewGuid().ToString('N')
    $testPass=[guid]::NewGuid().ToString('N')+[guid]::NewGuid().ToString('N')
    & (Join-Path $MysqlBin 'mysql_install_db.exe') "--datadir=$testDir" "--password=$rootPass" "--port=$port" --silent | Out-Null
    if($LASTEXITCODE -ne 0){throw 'Isolated MariaDB initialization failed'}
    $server=Start-Process -FilePath (Join-Path $MysqlBin 'mysqld.exe') -ArgumentList @('--no-defaults',"--datadir=`"$testDir`"","--port=$port",'--bind-address=0.0.0.0','--console') -PassThru -WindowStyle Hidden -RedirectStandardOutput (Join-Path $testDir 'stdout.log') -RedirectStandardError (Join-Path $testDir 'stderr.log')
    $ready=$false
    for($i=0;$i -lt 80;$i++){
        $client=[Net.Sockets.TcpClient]::new()
        try{$client.Connect('127.0.0.2',$port);$ready=$true;break}catch{Start-Sleep -Milliseconds 250}finally{$client.Dispose()}
    }
    if(-not $ready){throw 'Isolated MariaDB failed readiness'}
    $env:MYSQL_PWD=$rootPass
    $sql="CREATE DATABASE hf_db_9du6kxvk CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE USER 'csms_demo_test'@'%' IDENTIFIED BY '$testPass'; GRANT ALL PRIVILEGES ON hf_db_9du6kxvk.* TO 'csms_demo_test'@'%';"
    & (Join-Path $MysqlBin 'mysql.exe') --no-defaults --host=127.0.0.1 "--port=$port" --user=root --execute=$sql
    if($LASTEXITCODE -ne 0){throw 'Isolated schema/user creation failed'}
    $env:DB_HOST='127.0.0.2';$env:DB_PORT="$port";$env:DB_USER='csms_demo_test';$env:DB_PASS=$testPass
    $env:DB_NAME='hf_db_9du6kxvk';$env:APP_ENV='production'
    $env:CSMS_RUN_DEMO_IMPORT='1';$env:CSMS_DEMO_IMPORT_CONFIRM_DATABASE='hf_db_9du6kxvk'
    $env:CSMS_DEMO_IMPORT_CONFIRM_DATASET='DEV-BSIT-PREORAL-2026'
    $env:CSMS_DEMO_IMPORT_CONFIRM_MODE='PRODUCTION-DEMO'
    $env:CSMS_BOOTSTRAP_ADMIN='0'
    & $Php (Join-Path $PSScriptRoot '..\database\deploy.php') --apply --confirm-database=hf_db_9du6kxvk | Out-Null
    if($LASTEXITCODE -ne 0){throw 'Isolated migrations failed'}
    & $Php (Join-Path $PSScriptRoot 'demo_import_integration.php') setup
    if($LASTEXITCODE -ne 0){throw 'Isolated admin fixture failed'}
    $env:CSMS_DEMO_IMPORT_ACTOR_ID='1'
    $dry=& $Php (Join-Path $PSScriptRoot '..\database\import_demo.php') --dry-run
    if($LASTEXITCODE -ne 0 -or ($dry -join "`n") -notmatch 'Ready: yes'){throw 'Dry run failed'}
    Write-Output 'PASS: Production-like dry run: empty academic schema, 50 subjects, 152 units.'
    & $Php (Join-Path $PSScriptRoot 'demo_import_integration.php') unrelated
    if($LASTEXITCODE -ne 0){throw 'Collision fixture failed'}
    $ErrorActionPreference='Continue'
    $collision=& $Php (Join-Path $PSScriptRoot '..\database\import_demo.php') --apply --confirm-dataset=DEV-BSIT-PREORAL-2026 2>&1
    $ErrorActionPreference='Stop'
    if($LASTEXITCODE -eq 0 -or ($collision -join "`n") -notmatch 'Production academic data is not empty'){throw 'Unrelated academic data was not refused'}
    Write-Output 'PASS: Unrelated academic data refused.'
    & $Php (Join-Path $PSScriptRoot 'demo_import_integration.php') clear-unrelated
    if($LASTEXITCODE -ne 0){throw 'Collision fixture cleanup failed'}
    & $Php (Join-Path $PSScriptRoot 'demo_import_integration.php') failure-on
    if($LASTEXITCODE -ne 0){throw 'Failure fixture failed'}
    $ErrorActionPreference='Continue'
    $failed=& $Php (Join-Path $PSScriptRoot '..\database\import_demo.php') --apply --confirm-dataset=DEV-BSIT-PREORAL-2026 2>&1
    $ErrorActionPreference='Stop'
    if($LASTEXITCODE -eq 0){throw 'Injected failure was not refused'}
    & $Php (Join-Path $PSScriptRoot 'demo_import_integration.php') failure-off
    if($LASTEXITCODE -ne 0){throw 'Failure fixture cleanup failed'}
    & $Php (Join-Path $PSScriptRoot 'demo_import_integration.php') empty
    if($LASTEXITCODE -ne 0){throw 'Rollback verification failed'}
    & $Php (Join-Path $PSScriptRoot 'demo_import_integration.php') lock
    if($LASTEXITCODE -ne 0){throw 'Advisory lock test failed'}
    & $Php (Join-Path $PSScriptRoot '..\database\import_demo.php') --apply --confirm-dataset=DEV-BSIT-PREORAL-2026
    if($LASTEXITCODE -ne 0){throw 'First import failed'}
    & $Php (Join-Path $PSScriptRoot 'demo_import_integration.php') verify
    if($LASTEXITCODE -ne 0){throw 'Imported data verification failed'}
    $again=& $Php (Join-Path $PSScriptRoot '..\database\import_demo.php') --apply --confirm-dataset=DEV-BSIT-PREORAL-2026
    if($LASTEXITCODE -ne 0 -or ($again -join "`n") -notmatch 'already present'){throw 'Second import did not refuse duplication'}
    Write-Output 'PASS: Second import unchanged.'
    & $Php (Join-Path $PSScriptRoot 'demo_import_integration.php') verify
    if($LASTEXITCODE -ne 0){throw 'Post-rerun verification failed'}
    $repair=Join-Path $PSScriptRoot '..\database\repair_demo_program.php'
    $repairArgs=@('--apply','--confirm-dataset=DEV-BSIT-PREORAL-2026')
    $env:CSMS_RUN_DEMO_REPAIR='1';$env:CSMS_DEMO_REPAIR_CONFIRM_DATABASE='hf_db_9du6kxvk'
    $env:CSMS_DEMO_REPAIR_CONFIRM_DATASET='DEV-BSIT-PREORAL-2026'
    $env:CSMS_DEMO_REPAIR_CONFIRM_MODE='PROGRAM-CLASSIFICATION';$env:CSMS_DEMO_REPAIR_ACTOR_ID='1'
    & $Php (Join-Path $PSScriptRoot 'demo_import_integration.php') legacy-fixture
    if($LASTEXITCODE -ne 0){throw 'Legacy fixture failed'}
    $env:CSMS_DEMO_REPAIR_CONFIRM_DATABASE='wrong'
    $ErrorActionPreference='Continue';$bad=& $Php $repair @repairArgs 2>&1;$ErrorActionPreference='Stop'
    if($LASTEXITCODE -eq 0 -or ($bad -join "`n") -notmatch 'Database confirmation mismatch'){throw 'Wrong repair database accepted'}
    $env:CSMS_DEMO_REPAIR_CONFIRM_DATABASE='hf_db_9du6kxvk'
    $env:CSMS_DEMO_REPAIR_CONFIRM_DATASET='wrong'
    $ErrorActionPreference='Continue';$bad=& $Php $repair @repairArgs 2>&1;$ErrorActionPreference='Stop'
    if($LASTEXITCODE -eq 0 -or ($bad -join "`n") -notmatch 'confirmations are required'){throw 'Wrong repair dataset accepted'}
    $env:CSMS_DEMO_REPAIR_CONFIRM_DATASET='DEV-BSIT-PREORAL-2026'
    $env:CSMS_RUN_DEMO_REPAIR='0'
    $ErrorActionPreference='Continue';$bad=& $Php $repair @repairArgs 2>&1;$ErrorActionPreference='Stop'
    if($LASTEXITCODE -eq 0 -or ($bad -join "`n") -notmatch 'confirmations are required'){throw 'Normal startup repair gate failed'}
    $env:CSMS_RUN_DEMO_REPAIR='1'
    & $Php (Join-Path $PSScriptRoot 'demo_import_integration.php') repair-identity wrong
    $ErrorActionPreference='Continue';$bad=& $Php $repair @repairArgs 2>&1;$ErrorActionPreference='Stop'
    if($LASTEXITCODE -eq 0 -or ($bad -join "`n") -notmatch 'identity mismatch'){throw 'Unrelated BSIT identity accepted'}
    & $Php (Join-Path $PSScriptRoot 'demo_import_integration.php') repair-identity restore
    & $Php (Join-Path $PSScriptRoot 'demo_import_integration.php') repair-ownership wrong
    $ErrorActionPreference='Continue';$bad=& $Php $repair @repairArgs 2>&1;$ErrorActionPreference='Stop'
    if($LASTEXITCODE -eq 0 -or ($bad -join "`n") -notmatch 'ownership IDs mismatch'){throw 'Non-owned record ID accepted'}
    & $Php (Join-Path $PSScriptRoot 'demo_import_integration.php') repair-ownership restore
    & $Php (Join-Path $PSScriptRoot 'demo_import_integration.php') repair-value OTHER
    $ErrorActionPreference='Continue';$bad=& $Php $repair @repairArgs 2>&1;$ErrorActionPreference='Stop'
    if($LASTEXITCODE -eq 0 -or ($bad -join "`n") -notmatch 'manual review required'){throw 'Unexpected program type accepted'}
    & $Php (Join-Path $PSScriptRoot 'demo_import_integration.php') repair-value NULL
    $snapshot=Join-Path $testDir 'repair-snapshot.json'
    & $Php (Join-Path $PSScriptRoot 'demo_import_integration.php') repair-snapshot $snapshot save
    if($LASTEXITCODE -ne 0){throw 'Repair baseline failed'}
    & $Php $repair @repairArgs
    if($LASTEXITCODE -ne 0){throw 'Owned legacy BSIT repair failed'}
    & $Php (Join-Path $PSScriptRoot 'demo_import_integration.php') repair-verify
    if($LASTEXITCODE -ne 0){throw 'Repair verification failed'}
    & $Php (Join-Path $PSScriptRoot 'demo_import_integration.php') repair-snapshot $snapshot compare
    if($LASTEXITCODE -ne 0){throw 'Protected row comparison failed'}
    $again=& $Php $repair @repairArgs
    if($LASTEXITCODE -ne 0 -or ($again -join "`n") -notmatch 'already correct; no changes made'){throw 'Repair was not idempotent'}
    Write-Output 'PASS: Repair gates, unexpected value refusal, owned update, and idempotence.'
} finally {
    if($null -ne $server -and -not $server.HasExited){
        $env:MYSQL_PWD=$rootPass
        & (Join-Path $MysqlBin 'mysqladmin.exe') --no-defaults --host=127.0.0.1 "--port=$port" --user=root shutdown | Out-Null
        if(-not $server.WaitForExit(10000)){Stop-Process -Id $server.Id -Force}
    }
    foreach($name in $names){[Environment]::SetEnvironmentVariable($name,$saved[$name],'Process')}
    $resolved=[IO.Path]::GetFullPath($testDir)
    $tempRoot=[IO.Path]::GetFullPath([IO.Path]::GetTempPath()).TrimEnd('\')+'\'
    if(-not $resolved.StartsWith($tempRoot,[StringComparison]::OrdinalIgnoreCase) -or (Split-Path $resolved -Leaf) -notmatch '^csms-demo-import-[a-f0-9]{32}$'){throw 'Unsafe test cleanup path'}
    Remove-Item -LiteralPath $resolved -Recurse -Force
    Write-Output 'PASS: Isolated server stopped and temporary data removed.'
}
