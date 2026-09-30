# Dot-sourced only by docker-deployment.ps1, reusing its Docker helpers/cleanup.
$bootstrapVariables=@('CSMS_RUN_MIGRATIONS','CSMS_MIGRATION_CONFIRM_DATABASE','CSMS_BOOTSTRAP_ADMIN','CSMS_ADMIN_USERNAME','CSMS_ADMIN_PASSWORD','CSMS_ADMIN_EMAIL','CSMS_ADMIN_FIRST_NAME','CSMS_ADMIN_LAST_NAME')
$bootstrapDb=$database+'_bootstrap'
$bootstrapDbContainer='csms-bootstrap-db-'+$suffix
$bootstrapEnvNames=$bootstrapVariables+@('DB_HOST','DB_PORT','DB_NAME','DB_USER','DB_PASS','MARIADB_DATABASE','SMS_TEST_CONFIRM_DATABASE','SMS_TEST_ADMIN_USER')
$bootstrapSaved=@{};foreach($variable in $bootstrapEnvNames){$bootstrapSaved[$variable]=[Environment]::GetEnvironmentVariable($variable,'Process')}
function Assert-Bootstrap([string]$Container,[string]$Mode) {
    Invoke-Docker @('exec','--env','SMS_TEST_ADMIN_PASSWORD','--env','SMS_TEST_ADMIN_USER',$Container,'php','tests/bootstrap_assertions.php',$Mode)
}
function Failed-Bootstrap([string]$Label) {
    $container='csms-bootstrap-'+$Label+'-'+$suffix
    $arguments=@('run','--detach','--name',$container,'--network',$network,'--env','PORT=8080')
    foreach($variable in ($styleA+$bootstrapVariables)){$arguments+=@('--env',$variable)}
    Invoke-Docker ($arguments+@($Image)) | Out-Null
    $createdContainers.Add($container)
    $stopped=$false
    for($attempt=0;$attempt -lt 60;$attempt++){
        $running=Invoke-Docker @('inspect','--format','{{.State.Running}}',$container)
        if($running -eq 'false'){$stopped=$true;break}
        Start-Sleep -Milliseconds 500
    }
    if(-not $stopped){throw 'Fail-closed bootstrap container did not exit'}
    $exitCode=Invoke-Docker @('inspect','--format','{{.State.ExitCode}}',$container)
    if([int]$exitCode -eq 0){throw 'Invalid bootstrap unexpectedly succeeded'}
    $log=(Invoke-Docker @('logs',$container)|Out-String)
    if($log -match 'Apache/|resuming normal operations' -or ($env:CSMS_ADMIN_PASSWORD -and $log.Contains($env:CSMS_ADMIN_PASSWORD))){throw 'Unsafe failed-bootstrap startup/log'}
    Write-Output ('PASS: Bootstrap refused '+$Label+'; nonzero exit, Apache not started, no secret in logs.')
}
try {
    foreach($variable in $bootstrapVariables){[Environment]::SetEnvironmentVariable($variable,$null,'Process')}
    $env:MARIADB_DATABASE=$bootstrapDb
    Invoke-Docker @('run','--detach','--name',$bootstrapDbContainer,'--network',$network,'--env','MARIADB_DATABASE','--env','MARIADB_USER','--env','MARIADB_PASSWORD','--env','MARIADB_ROOT_PASSWORD','mariadb:10.11') | Out-Null
    $createdContainers.Add($bootstrapDbContainer)
    $ready=$false
    for($attempt=0;$attempt -lt 60;$attempt++){$probe=Get-DockerResult @('exec',$bootstrapDbContainer,'healthcheck.sh','--connect','--innodb_initialized');if($probe.ExitCode -eq 0){$ready=$true;break};Start-Sleep -Milliseconds 500}
    if(-not $ready){throw 'Bootstrap database readiness timeout'}
    $env:DB_HOST=$bootstrapDbContainer;$env:DB_NAME=$bootstrapDb;$env:DB_PASS=$env:MARIADB_PASSWORD
    $env:SMS_TEST_CONFIRM_DATABASE=$bootstrapDb
    $normal='csms-bootstrap-normal-'+$suffix
    New-TestApp $normal 8080 $styleA
    Assert-Bootstrap $normal 'empty'
    Assert-Bootstrap $normal 'empty-login'
    $env:CSMS_RUN_MIGRATIONS='false';$env:CSMS_BOOTSTRAP_ADMIN='true'
    $disabled='csms-bootstrap-disabled-'+$suffix
    New-TestApp $disabled 8080 ($styleA+$bootstrapVariables)
    Assert-Bootstrap $disabled 'empty'
    $env:CSMS_BOOTSTRAP_ADMIN='1';$env:CSMS_ADMIN_USERNAME='csms_bootstrap_test'
    Failed-Bootstrap 'missing-schema'
    Assert-Bootstrap $normal 'empty'
    $env:CSMS_BOOTSTRAP_ADMIN='0';$env:CSMS_RUN_MIGRATIONS='1';$env:CSMS_MIGRATION_CONFIRM_DATABASE='wrong-target'
    Failed-Bootstrap 'wrong-database'
    Assert-Bootstrap $normal 'empty'
    $env:CSMS_MIGRATION_CONFIRM_DATABASE=$bootstrapDb
    $migrated='csms-bootstrap-schema-'+$suffix
    New-TestApp $migrated 8080 ($styleA+$bootstrapVariables)
    Assert-Bootstrap $migrated 'schema'
    $env:CSMS_RUN_MIGRATIONS='0';$env:CSMS_BOOTSTRAP_ADMIN='1'
    $env:CSMS_ADMIN_PASSWORD=$env:SMS_TEST_ADMIN_PASSWORD
    $env:CSMS_ADMIN_EMAIL='bootstrap@example.invalid';$env:CSMS_ADMIN_FIRST_NAME='TEST';$env:CSMS_ADMIN_LAST_NAME='Administrator'
    $env:SMS_TEST_ADMIN_USER=$env:CSMS_ADMIN_USERNAME
    Assert-Bootstrap $migrated 'missing-role'
    Failed-Bootstrap 'missing-role'
    Assert-Bootstrap $migrated 'restore-role'
    Assert-Bootstrap $migrated 'schema'
    Assert-Bootstrap $migrated 'block-role'
    Failed-Bootstrap 'role-rollback'
    Assert-Bootstrap $migrated 'unblock-role'
    Assert-Bootstrap $migrated 'schema'
    Assert-Bootstrap $migrated 'block-audit'
    Failed-Bootstrap 'audit-rollback'
    Assert-Bootstrap $migrated 'unblock-audit'
    Assert-Bootstrap $migrated 'schema'
    $env:CSMS_ADMIN_PASSWORD=([guid]::NewGuid().ToString('N')).Substring(0,5)
    Failed-Bootstrap 'weak-secret'
    Assert-Bootstrap $migrated 'schema'
    $env:CSMS_ADMIN_PASSWORD=$env:SMS_TEST_ADMIN_PASSWORD;$env:CSMS_RUN_MIGRATIONS='1'
    $provisioned='csms-bootstrap-provisioned-'+$suffix
    New-TestApp $provisioned 8080 ($styleA+$bootstrapVariables)
    Assert-Bootstrap $provisioned 'smoke'
    $env:CSMS_ADMIN_PASSWORD=[guid]::NewGuid().ToString('N')+[guid]::NewGuid().ToString('N')
    $repeat='csms-bootstrap-repeat-'+$suffix
    New-TestApp $repeat 8080 ($styleA+$bootstrapVariables)
    Assert-Bootstrap $repeat 'admin'
    $log=(Invoke-Docker @('logs',$repeat)|Out-String)
    if($log -notmatch 'no pending migrations' -or $log -notmatch 'already complete'){throw 'Idempotent bootstrap report missing'}
    Assert-Bootstrap $repeat 'nonadmin-collision'
    Failed-Bootstrap 'nonadmin-collision'
    Assert-Bootstrap $repeat 'restore-admin'
    Assert-Bootstrap $repeat 'admin'
    $env:CSMS_ADMIN_USERNAME='another_test_admin'
    Failed-Bootstrap 'second-admin'
    Assert-Bootstrap $repeat 'admin'
    foreach($variable in $bootstrapVariables){[Environment]::SetEnvironmentVariable($variable,$null,'Process')}
    $removed='csms-bootstrap-removed-'+$suffix
    New-TestApp $removed 9090 $styleA
    Assert-Bootstrap $removed 'smoke'
    $log=(Invoke-Docker @('logs',$removed)|Out-String)
    if($log -match 'Startup migrations:|administrator provisioned|provisioning already complete'){throw 'Bootstrap ran without flags'}
    foreach($container in @($normal,$disabled,$migrated,$provisioned,$repeat,$removed)){
        $log=(Invoke-Docker @('logs',$container)|Out-String)
        if($log.Contains($env:SMS_TEST_ADMIN_PASSWORD) -or $log -match 'PHP (Warning|Fatal|Notice)|AH00526|Syntax error'){throw 'Unsafe bootstrap container logs'}
    }
    Write-Output 'PASS: Explicit startup bootstrap, default-off, wrong DB, atomic admin, retry, removed flags, production login and module smoke.'
} finally {
    foreach($variable in $bootstrapEnvNames){[Environment]::SetEnvironmentVariable($variable,$bootstrapSaved[$variable],'Process')}
}
