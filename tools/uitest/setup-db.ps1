# Rebuilds sms_db_uitest from a fresh read-only dump of sms_db, then seeds the UI-test users.
# sms_db is only ever read (mysqldump --single-transaction); it is never dropped or written.
$ErrorActionPreference = 'Stop'
$Database = 'sms_db_uitest'
$Php = 'C:\xampp\php\php.exe'
$MysqlBin = 'C:\xampp\mysql\bin'
$Root = Resolve-Path (Join-Path $PSScriptRoot '..\..')
$BackupDir = 'C:\xampp\csms-backups'

& $Php (Join-Path $PSScriptRoot 'guard.php') $Database | Out-Null
if ($LASTEXITCODE -ne 0) { throw 'Guard rejected the database name.' }
if ($Database -cne 'sms_db_uitest') { throw 'setup-db.ps1 only drops or creates sms_db_uitest.' }

New-Item -ItemType Directory -Force $BackupDir | Out-Null
$Backup = Join-Path $BackupDir ("sms_db_{0}.sql" -f (Get-Date -Format 'yyyyMMdd_HHmm'))
Write-Host "Backing up sms_db (read-only) to $Backup"
& "$MysqlBin\mysqldump.exe" -uroot --single-transaction --routines --no-create-db --skip-triggers --result-file="$Backup" sms_db
if ($LASTEXITCODE -ne 0) { throw 'mysqldump failed.' }
if (Select-String -Path $Backup -Pattern '^USE |`sms_db`\.' -Quiet) { throw 'Backup references sms_db directly; refusing to import.' }

Write-Host "Database to be dropped and recreated: $Database"
& "$MysqlBin\mysql.exe" -uroot -e "DROP DATABASE IF EXISTS ``$Database``; CREATE DATABASE ``$Database`` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
if ($LASTEXITCODE -ne 0) { throw 'Could not recreate the test database.' }
cmd /c "`"$MysqlBin\mysql.exe`" -uroot $Database < `"$Backup`""
if ($LASTEXITCODE -ne 0) { throw 'Import failed.' }

$env:DB_NAME = $Database
try {
    & $Php (Join-Path $Root 'database\deploy.php') --apply "--confirm-database=$Database"
    if ($LASTEXITCODE -ne 0) { throw 'Applying pending migrations to the test database failed.' }
    & $Php (Join-Path $PSScriptRoot 'seed-users.php')
} finally { Remove-Item Env:DB_NAME }
if ($LASTEXITCODE -ne 0) { throw 'Seeding failed.' }
Write-Host "Ready: $Database (from $Backup)"
