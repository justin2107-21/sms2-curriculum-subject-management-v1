param([string]$MysqlBin='C:\xampp\mysql\bin',[string]$Php='C:\xampp\php\php.exe')
$ErrorActionPreference='Stop'
Set-StrictMode -Version Latest
$testDir=Join-Path ([IO.Path]::GetTempPath()) ('csms-fk-test-'+[guid]::NewGuid().ToString('N'))
$null=New-Item -ItemType Directory -Path $testDir
$listener=[Net.Sockets.TcpListener]::new([Net.IPAddress]::Loopback,0)
$listener.Start();$port=$listener.LocalEndpoint.Port;$listener.Stop()
$names=@('DB_HOST','DB_PORT','DB_USER','DB_PASS','DB_NAME','APP_ENV','SMS_TEST_LOCAL_FK','MYSQL_PWD')
$saved=@{};foreach($name in $names){$saved[$name]=[Environment]::GetEnvironmentVariable($name,'Process')}
$server=$null
try {
    $env:DB_PASS=[guid]::NewGuid().ToString('N')+[guid]::NewGuid().ToString('N')
    $init=& (Join-Path $MysqlBin 'mysql_install_db.exe') "--datadir=$testDir" "--password=$env:DB_PASS" "--port=$port" --silent
    if($LASTEXITCODE -ne 0){throw 'Isolated data directory initialization failed'}
    $server=Start-Process -FilePath (Join-Path $MysqlBin 'mysqld.exe') -ArgumentList @('--no-defaults',"--datadir=`"$testDir`"","--port=$port",'--bind-address=127.0.0.1','--console') -PassThru -WindowStyle Hidden -RedirectStandardOutput (Join-Path $testDir 'stdout.log') -RedirectStandardError (Join-Path $testDir 'stderr.log')
    $ready=$false
    for($i=0;$i -lt 60;$i++){
        $client=[Net.Sockets.TcpClient]::new()
        try{$client.Connect('127.0.0.1',$port);$ready=$true;break}catch{Start-Sleep -Milliseconds 250}finally{$client.Dispose()}
    }
    if(-not $ready){throw 'Isolated local server failed readiness'}
    $env:DB_HOST='127.0.0.1';$env:DB_PORT="$port";$env:DB_USER='root';$env:DB_NAME='csms_docker_test_local'
    $env:APP_ENV='development';$env:SMS_TEST_LOCAL_FK='1'
    & $Php (Join-Path $PSScriptRoot 'local_fk_compatibility.php')
    if($LASTEXITCODE -ne 0){throw 'Local FK compatibility checks failed'}
} finally {
    if($null -ne $server -and -not $server.HasExited){
        $env:MYSQL_PWD=$env:DB_PASS
        & (Join-Path $MysqlBin 'mysqladmin.exe') --no-defaults --host=127.0.0.1 "--port=$port" --user=root shutdown
        if(-not $server.WaitForExit(10000)){Stop-Process -Id $server.Id -Force}
    }
    foreach($name in $names){[Environment]::SetEnvironmentVariable($name,$saved[$name],'Process')}
    $resolved=[IO.Path]::GetFullPath($testDir)
    $tempRoot=[IO.Path]::GetFullPath([IO.Path]::GetTempPath()).TrimEnd('\')+'\'
    if(-not $resolved.StartsWith($tempRoot,[StringComparison]::OrdinalIgnoreCase) -or (Split-Path $resolved -Leaf) -notmatch '^csms-fk-test-[a-f0-9]{32}$'){throw 'Unsafe temporary cleanup path'}
    Remove-Item -LiteralPath $resolved -Recurse -Force
    Write-Output 'PASS: Isolated local server stopped and temporary data directory removed. XAMPP data untouched.'
}
