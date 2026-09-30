param([string]$Image = 'csms-hostforge-test')
$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest
if (-not (Get-Command docker -ErrorAction SilentlyContinue)) { throw 'Docker is not installed. No build/runtime validation was performed.' }
$project = Split-Path -Parent $PSScriptRoot
$suffix = [guid]::NewGuid().ToString('N').Substring(0,12)
$network = 'csms-deploy-test-' + $suffix
$frontendNetwork = 'csms-deploy-http-' + $suffix
$dbContainer = 'csms-deploy-db-' + $suffix
$database = 'csms_docker_test_' + $suffix
$createdContainers = [Collections.Generic.List[string]]::new()
$networkCreated = $false
$frontendNetworkCreated = $false
$names = @('MARIADB_DATABASE','MARIADB_USER','MARIADB_PASSWORD','MARIADB_ROOT_PASSWORD','DB_HOST','DB_PORT','DB_NAME','DB_USER','DB_PASS','DB_DATABASE','DB_USERNAME','DB_PASSWORD','SMS_TEST_CONFIRM_DATABASE','SMS_TEST_ADMIN_PASSWORD')
$saved = @{}
foreach ($name in $names) { $saved[$name] = [Environment]::GetEnvironmentVariable($name,'Process') }
function Get-DockerResult([string[]]$Arguments) {
    # Windows PowerShell 5 treats native stderr as ErrorRecords, even for
    # successful docker logs or an expected not-ready health probe. Capture
    # both streams; the native exit status remains authoritative.
    $ErrorActionPreference = 'Continue'
    $output = @(& docker @Arguments 2>&1)
    $exitCode = $LASTEXITCODE
    return [pscustomobject]@{ ExitCode=$exitCode; Output=@($output | ForEach-Object {
        if ($_ -is [System.Management.Automation.ErrorRecord]) { $_.Exception.Message } else { $_.ToString() }
    }) }
}
function Invoke-Docker([string[]]$Arguments) {
    $result = Get-DockerResult $Arguments
    if ($result.ExitCode -ne 0) {
        $result.Output | ForEach-Object { Write-Host $_ }
        throw ('Docker command failed: ' + $Arguments[0] + ' (exit ' + $result.ExitCode + ')')
    }
    return $result.Output
}
function Wait-App([string]$Container) {
    for ($i=0; $i -lt 40; $i++) {
        $probe = Get-DockerResult @('exec',$Container,'php','/usr/local/bin/csms-healthcheck.php')
        if ($probe.ExitCode -eq 0) { return }
        Start-Sleep -Milliseconds 500
    }
    throw 'Application health check timed out.'
}
function New-TestApp([string]$Name, [int]$Port, [string[]]$DatabaseNames, [string]$Secure = '0') {
    $argsList = @('run','--detach','--name',$Name,'--network',$network,'--network',$frontendNetwork,
        '--publish',"127.0.0.1::${Port}",'--env',"PORT=$Port",'--env',"SMS_SESSION_COOKIE_SECURE=$Secure",
        '--env','SMS_TEST_CONFIRM_DATABASE','--mount',"type=bind,source=$PSScriptRoot,target=/var/www/html/tests,readonly")
    foreach ($dbVariable in $DatabaseNames) { $argsList += @('--env',$dbVariable) }
    Invoke-Docker ($argsList + @($Image)) | Out-Null
    $createdContainers.Add($Name)
    Wait-App $Name
    # Check the published port from the host, not just EXPOSE or container CLI.
    $bindings = @(Invoke-Docker @('port',$Name,"$Port/tcp"))
    if ($bindings.Count -ne 1 -or $bindings[0] -notmatch '^127\.0\.0\.1:[0-9]+$') { throw 'Expected one localhost-only published port.' }
    $binding = $bindings[0].Trim()
    $response = Invoke-WebRequest -UseBasicParsing -Uri ('http://' + $binding + '/health.php')
    if ($response.StatusCode -ne 200 -or $response.Content -ne '{"status":"ok"}') { throw 'Published-port liveness failed.' }
}
Push-Location $project
$testFailure = $null
try {
    Invoke-Docker @('version') | Out-Null
    # Targeted source scan before COPY/build. Do not print matching contents.
    $sourceFiles = & git ls-files --cached --others --exclude-standard
    if ($LASTEXITCODE -ne 0) { throw 'Cannot inspect build source inventory.' }
    foreach ($file in $sourceFiles) {
        if ($file -match '\.(php|sql|js|css|sh|ini|conf|ya?ml|md)$' -or $file -eq 'Dockerfile') {
            $content = Get-Content -LiteralPath $file -Raw
            if ($content -match '\$2[aby]\$[0-9]{2}\$[./A-Za-z0-9]{53}|-----BEGIN (RSA |EC |OPENSSH )?PRIVATE KEY-----|\bgh[pousr]_[A-Za-z0-9]{20,}|\bgithub_pat_[A-Za-z0-9_]{30,}|\bsk-(proj-|svcacct-)?[A-Za-z0-9_-]{20,}') {
                throw ('Possible credential in build source; review privately: ' + $file)
            }
        }
    }
    # No credentials are build arguments; test secrets are generated after build.
    Invoke-Docker @('build','--tag',$Image,'.')
    # Windows PowerShell native argv handling strips quotes in PHP -r code.
    # Execute a real file outside DocumentRoot, never shell-constructed PHP.
    Invoke-Docker @('run','--rm','--mount',"type=bind,source=$PSScriptRoot,target=/opt/csms-tests,readonly",
        '--entrypoint','php',$Image,'/opt/csms-tests/docker-runtime-check.php')
    Invoke-Docker @('pull','mariadb:10.11') | Out-Null
    Invoke-Docker @('network','create','--internal',$network) | Out-Null
    $networkCreated = $true
    # Internal-only networks cannot publish ports on current Docker Engine.
    # Only apps join this bridge; MariaDB stays internal and unpublished.
    Invoke-Docker @('network','create',$frontendNetwork) | Out-Null
    $frontendNetworkCreated = $true
    $env:MARIADB_DATABASE = $database
    $env:MARIADB_USER = 'csms_test_user'
    $env:MARIADB_PASSWORD = [guid]::NewGuid().ToString('N') + [guid]::NewGuid().ToString('N')
    $env:MARIADB_ROOT_PASSWORD = [guid]::NewGuid().ToString('N') + [guid]::NewGuid().ToString('N')
    $env:SMS_TEST_ADMIN_PASSWORD = [guid]::NewGuid().ToString('N') + [guid]::NewGuid().ToString('N')
    $env:SMS_TEST_CONFIRM_DATABASE = $database
    Invoke-Docker @('run','--detach','--name',$dbContainer,'--network',$network,
        '--env','MARIADB_DATABASE','--env','MARIADB_USER','--env','MARIADB_PASSWORD','--env','MARIADB_ROOT_PASSWORD','mariadb:10.11') | Out-Null
    $createdContainers.Add($dbContainer)
    $ready = $false
    for ($i=0; $i -lt 60; $i++) {
        $probe = Get-DockerResult @('exec',$dbContainer,'healthcheck.sh','--connect','--innodb_initialized')
        if ($probe.ExitCode -eq 0) { $ready=$true; break }
        Start-Sleep -Milliseconds 500
    }
    if (-not $ready) { throw 'Temporary MariaDB readiness timed out.' }
    $env:DB_HOST = $dbContainer; $env:DB_PORT = '3306'
    $env:DB_NAME = $database; $env:DB_USER = $env:MARIADB_USER; $env:DB_PASS = $env:MARIADB_PASSWORD
    $styleA = @('DB_HOST','DB_PORT','DB_NAME','DB_USER','DB_PASS')
    $appA = 'csms-deploy-app-a-' + $suffix
    New-TestApp $appA 8080 $styleA
    Invoke-Docker @('exec',$appA,'php','-m')
    Invoke-Docker @('exec',$appA,'php','tests/deployment_migrations.php')
    Invoke-Docker @('exec',$appA,'php','tests/academic_fk_compatibility.php')
    Invoke-Docker @('exec','--env','SMS_TEST_ADMIN_PASSWORD',$appA,'php','tests/deployment_admin.php')
    Invoke-Docker @('exec',$appA,'php','tests/docker_http.php')
    Invoke-Docker @('exec','--env','SMS_TEST_ADMIN_PASSWORD','--env','SMS_TEST_BASE_URL=http://127.0.0.1:8080',$appA,'php','tests/foundation.php')
    $env:DB_DATABASE = $database; $env:DB_USERNAME = $env:MARIADB_USER; $env:DB_PASSWORD = $env:MARIADB_PASSWORD
    $styleB = @('DB_HOST','DB_PORT','DB_DATABASE','DB_USERNAME','DB_PASSWORD')
    $appB = 'csms-deploy-app-b-' + $suffix
    New-TestApp $appB 9090 $styleB
    Invoke-Docker @('exec',$appB,'php','tests/docker_http.php')
    Invoke-Docker @('exec','--env','SMS_TEST_ADMIN_PASSWORD','--env','SMS_TEST_BASE_URL=http://127.0.0.1:9090',$appB,'php','tests/foundation.php')
    $secureApp = 'csms-deploy-app-secure-' + $suffix
    New-TestApp $secureApp 8080 $styleA '1'
    Invoke-Docker @('exec',$secureApp,'php','tests/docker_http.php')
    foreach ($name in @($appA,$appB,$secureApp)) {
        $log = (Invoke-Docker @('logs',$name) | Out-String)
        if ($log -match 'PHP (Warning|Fatal|Notice)|\[SMS2\]|\[CSMS\]|AH00526|Syntax error') { throw 'Unexpected application/Apache warning; inspect container logs privately.' }
    }
    $env:DB_PASS = [guid]::NewGuid().ToString('N')
    $badApp = 'csms-deploy-app-bad-' + $suffix
    New-TestApp $badApp 8080 $styleA
    Invoke-Docker @('exec','--env','SMS_DOCKER_EXPECT_DB_FAILURE=1',$badApp,'php','tests/docker_http.php')
    $log = (Invoke-Docker @('logs',$badApp) | Out-String)
    if ($log.Contains($env:DB_PASS) -or $log -match 'PHP (Warning|Fatal|Notice)|AH00526|Syntax error') { throw 'Unsafe database-failure log output.' }
    Invoke-Docker @('exec',$appA,'php','database/inspect.php')
    Write-Output 'PASS: Docker build, both DB styles, ports 8080/9090, security URLs, sessions, module regression and controlled DB failure.'
} catch {
    $testFailure = $_
    throw
} finally {
    # Only containers created by this run; -v removes their anonymous DB volume.
    $cleanupFailed = $false
    for ($i=$createdContainers.Count-1; $i -ge 0; $i--) {
        $removed = Get-DockerResult @('rm','--force','--volumes',$createdContainers[$i])
        if ($removed.ExitCode -ne 0) { $cleanupFailed = $true }
    }
    if ($networkCreated) {
        $removed = Get-DockerResult @('network','rm',$network)
        if ($removed.ExitCode -ne 0) { $cleanupFailed = $true }
    }
    if ($frontendNetworkCreated) {
        $removed = Get-DockerResult @('network','rm',$frontendNetwork)
        if ($removed.ExitCode -ne 0) { $cleanupFailed = $true }
    }
    foreach ($name in $names) { [Environment]::SetEnvironmentVariable($name,$saved[$name],'Process') }
    Pop-Location
    if ($cleanupFailed) {
        $message = 'One or more named test resources could not be removed; inspect Docker before retrying.'
        if ($null -ne $testFailure) { Write-Warning $message } else { throw $message }
    } else {
        Write-Output 'PASS: Named test containers, anonymous database volume and test networks removed.'
    }
}
