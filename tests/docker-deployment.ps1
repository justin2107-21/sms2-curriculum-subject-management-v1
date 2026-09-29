param([string]$Image = 'csms-hostforge-test')
$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest
if (-not (Get-Command docker -ErrorAction SilentlyContinue)) { throw 'Docker is not installed. No build/runtime validation was performed.' }
$project = Split-Path -Parent $PSScriptRoot
$suffix = [guid]::NewGuid().ToString('N').Substring(0,12)
$network = 'csms-deploy-test-' + $suffix
$dbContainer = 'csms-deploy-db-' + $suffix
$database = 'csms_docker_test_' + $suffix
$createdContainers = [Collections.Generic.List[string]]::new()
$networkCreated = $false
$names = @('MARIADB_DATABASE','MARIADB_USER','MARIADB_PASSWORD','MARIADB_ROOT_PASSWORD','DB_HOST','DB_PORT','DB_NAME','DB_USER','DB_PASS','DB_DATABASE','DB_USERNAME','DB_PASSWORD','SMS_TEST_CONFIRM_DATABASE','SMS_TEST_ADMIN_PASSWORD')
$saved = @{}
foreach ($name in $names) { $saved[$name] = [Environment]::GetEnvironmentVariable($name,'Process') }
function Invoke-Docker([string[]]$Arguments) {
    $result = & docker @Arguments
    if ($LASTEXITCODE -ne 0) { throw ('Docker command failed: ' + $Arguments[0]) }
    return $result
}
function Wait-App([string]$Container) {
    for ($i=0; $i -lt 40; $i++) {
        & docker exec $Container php /usr/local/bin/csms-healthcheck.php 2>$null
        if ($LASTEXITCODE -eq 0) { return }
        Start-Sleep -Milliseconds 500
    }
    throw 'Application health check timed out.'
}
function New-TestApp([string]$Name, [int]$Port, [string[]]$DatabaseNames, [string]$Secure = '0') {
    $argsList = @('run','--detach','--name',$Name,'--network',$network,
        '--publish',"127.0.0.1::${Port}",'--env',"PORT=$Port",'--env',"SMS_SESSION_COOKIE_SECURE=$Secure",
        '--env','SMS_TEST_CONFIRM_DATABASE','--mount',"type=bind,source=$PSScriptRoot,target=/var/www/html/tests,readonly")
    foreach ($name in $DatabaseNames) { $argsList += @('--env',$name) }
    Invoke-Docker ($argsList + @($Image)) | Out-Null
    $createdContainers.Add($Name)
    Wait-App $Name
    # Check the published port from the host, not just EXPOSE or container CLI.
    $binding = (Invoke-Docker @('port',$Name,"$Port/tcp") | Select-Object -First 1).Trim()
    $response = Invoke-WebRequest -UseBasicParsing -Uri ('http://' + $binding + '/health.php')
    if ($response.StatusCode -ne 200 -or $response.Content -ne '{"status":"ok"}') { throw 'Published-port liveness failed.' }
}
Push-Location $project
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
    $imageCheck = 'foreach ([".git", ".github", ".env", "docs", "tests", "database/seeds"] as $path) { if (file_exists($path)) { fwrite(STDERR, "Private/development artifact in image.\n"); exit(1); } }'
    Invoke-Docker @('run','--rm','--entrypoint','php',$Image,'-r',$imageCheck)
    Invoke-Docker @('pull','mariadb:10.11') | Out-Null
    Invoke-Docker @('network','create','--internal',$network) | Out-Null
    $networkCreated = $true
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
        & docker exec $dbContainer healthcheck.sh --connect --innodb_initialized 2>$null
        if ($LASTEXITCODE -eq 0) { $ready=$true; break }
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
        $log = (& docker logs $name 2>&1 | Out-String)
        if ($log -match 'PHP (Warning|Fatal|Notice)|\[SMS2\]|\[CSMS\]|AH00526|Syntax error') { throw 'Unexpected application/Apache warning; inspect container logs privately.' }
    }
    $env:DB_PASS = [guid]::NewGuid().ToString('N')
    $badApp = 'csms-deploy-app-bad-' + $suffix
    New-TestApp $badApp 8080 $styleA
    Invoke-Docker @('exec','--env','SMS_DOCKER_EXPECT_DB_FAILURE=1',$badApp,'php','tests/docker_http.php')
    $log = (& docker logs $badApp 2>&1 | Out-String)
    if ($log.Contains($env:DB_PASS) -or $log -match 'PHP (Warning|Fatal|Notice)|AH00526|Syntax error') { throw 'Unsafe database-failure log output.' }
    Invoke-Docker @('exec',$appA,'php','database/inspect.php')
    Write-Output 'PASS: Docker build, both DB styles, ports 8080/9090, security URLs, sessions, module regression and controlled DB failure.'
} finally {
    # Only containers created by this run; -v removes their anonymous DB volume.
    $cleanupFailed = $false
    for ($i=$createdContainers.Count-1; $i -ge 0; $i--) {
        & docker rm --force --volumes $createdContainers[$i] | Out-Null
        if ($LASTEXITCODE -ne 0) { $cleanupFailed = $true }
    }
    if ($networkCreated) {
        & docker network rm $network | Out-Null
        if ($LASTEXITCODE -ne 0) { $cleanupFailed = $true }
    }
    foreach ($name in $names) { [Environment]::SetEnvironmentVariable($name,$saved[$name],'Process') }
    Pop-Location
    if ($cleanupFailed) { throw 'One or more named test resources could not be removed; inspect Docker before retrying.' }
}
