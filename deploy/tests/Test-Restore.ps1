#Requires -Version 7.2
# Integration test: replaces only the disposable test-kimai project with synthetic data.
[CmdletBinding()]
param([switch]$KeepRunning)

$ErrorActionPreference = 'Stop'
$deployPath = Split-Path -Parent $PSScriptRoot
$environmentFile = Join-Path $deployPath '.env.test'
$composeFile = Join-Path $deployPath 'compose.test.yaml'
$restoreScript = Join-Path $deployPath 'scripts/Restore-TestBackup.ps1'
$workPath = Join-Path $deployPath ('backups/validation-' + [Guid]::NewGuid().ToString('N'))
[IO.Directory]::CreateDirectory($workPath) | Out-Null
& (Join-Path $deployPath 'scripts/Initialize-Environment.ps1') -Mode Test

function Compose {
    param([string[]]$Arguments)
    & docker --context default compose --project-name test-kimai --env-file $environmentFile -f $composeFile @Arguments
    if ($LASTEXITCODE -ne 0) { throw 'Fixture Compose command failed.' }
}

function Snapshot {
    param([string]$Name)
    $destination = Join-Path $workPath $Name
    [IO.Directory]::CreateDirectory($destination) | Out-Null
    $dump = @'
set -euo pipefail
MYSQL_PWD="$MARIADB_PASSWORD" mariadb-dump --host=sqldb --user=kimai --single-transaction --quick --routines --events --triggers --hex-blob --databases kimai |
  gzip | split -b 4096 - /fixture-output/database.sql.gz.part-
cd /fixture-output
sha256sum database.sql.gz.part-* > COMPLETE
'@
    Compose -Arguments @('run', '--rm', '--no-deps', '-v', "${destination}:/fixture-output", '--entrypoint', '/bin/bash', 'importer', '-euc', $dump)
    return $destination
}

function Expect-Failure {
    param([string]$Directory, [string]$Message, [switch]$AfterImport)
    $failed = $false
    try { & $restoreScript -BackupDirectory $Directory -SkipBuild } catch { $failed = $true }
    if (!$failed) { throw "Expected rejection: $Message" }
    if ($AfterImport) {
        $remaining = & docker --context default ps -aq --filter label=com.docker.compose.project=test-kimai
        if ($LASTEXITCODE -ne 0 -or $remaining) { throw 'A failed restore left test containers behind.' }
        foreach ($volume in @('test-kimai_mariadb', 'test-kimai_data')) {
            $null = & docker --context default volume inspect $volume 2>$null
            if ($LASTEXITCODE -eq 0) { throw 'A failed restore left a potentially raw test volume behind.' }
        }
    }
    Write-Output "PASS: $Message"
}

# Never silently discard an existing local test instance when running the test suite.
$existing = & docker --context default ps -aq --filter label=com.docker.compose.project=test-kimai
if ($LASTEXITCODE -ne 0 -or $existing) { throw 'Stop/remove the disposable test-kimai project before running these integration tests.' }
foreach ($volume in @('test-kimai_mariadb', 'test-kimai_data')) {
    $null = & docker --context default volume inspect $volume 2>$null
    if ($LASTEXITCODE -eq 0) { throw 'Integration tests require empty test-kimai volumes.' }
}

$previousBackup = $env:KIMAI_BACKUP_DIRECTORY
$env:KIMAI_BACKUP_DIRECTORY = $workPath
try {
    Compose -Arguments @('up', '-d', '--wait', 'sqldb')
    $seed = 'cd /opt/kimai; bin/console -n kimai:install --no-cache; php /tests/RestoreFixture.php seed'
    Compose -Arguments @('run', '--rm', '--no-deps', '-v', "${PSScriptRoot}:/tests:ro", '--entrypoint', '/bin/bash', 'kimai', '-euc', $seed)
    $base = @(Snapshot -Name 'complete')[-1]
    $sql = 'MYSQL_PWD="$MARIADB_ROOT_PASSWORD" mariadb --user=root --database=kimai --execute="$1"'
    Compose -Arguments @('exec', '-T', 'sqldb', '/bin/bash', '-euc', $sql, '--', 'CREATE TABLE private_plugin_data (id INT PRIMARY KEY, secret TEXT) ENGINE=InnoDB; INSERT INTO private_plugin_data VALUES (1, "PRODUCTION_SENTINEL");')
    $plugin = @(Snapshot -Name 'plugin')[-1]
    Compose -Arguments @('exec', '-T', 'sqldb', '/bin/bash', '-euc', $sql, '--', 'DROP TABLE private_plugin_data; ALTER TABLE kimai2_customers ADD private_extra TEXT;')
    $column = @(Snapshot -Name 'column')[-1]
    Compose -Arguments @('exec', '-T', 'sqldb', '/bin/bash', '-euc', $sql, '--', 'ALTER TABLE kimai2_customers DROP private_extra; SET FOREIGN_KEY_CHECKS=0; UPDATE kimai2_timesheet SET user=999999;')
    $orphan = @(Snapshot -Name 'orphan')[-1]
    Compose -Arguments @('down', '--volumes')

    $incomplete = Join-Path $workPath 'incomplete'
    [IO.Directory]::CreateDirectory($incomplete) | Out-Null
    Expect-Failure -Directory $incomplete -Message 'Incomplete backup rejected before Docker changes'
    $firstChunk = (Get-ChildItem -LiteralPath $base -Filter 'database.sql.gz.part-*' | Sort-Object Name)[0]
    $original = [IO.File]::ReadAllBytes($firstChunk.FullName)
    [IO.File]::WriteAllText($firstChunk.FullName, 'corrupt')
    Expect-Failure -Directory $base -Message 'Corrupt chunk rejected before Docker changes'
    [IO.File]::WriteAllBytes($firstChunk.FullName, $original)
    Move-Item -LiteralPath $firstChunk.FullName -Destination ($firstChunk.FullName + '.missing')
    try { Expect-Failure -Directory $base -Message 'Missing chunk rejected before Docker changes' } finally {
        Move-Item -LiteralPath ($firstChunk.FullName + '.missing') -Destination $firstChunk.FullName
    }

    # An SQL file with a valid checksum must still fail safely at import time.
    $invalid = Join-Path $workPath 'invalid-sql'
    [IO.Directory]::CreateDirectory($invalid) | Out-Null
    $invalidChunk = Join-Path $invalid 'database.sql.gz.part-aa'
    $file = [IO.File]::Create($invalidChunk)
    $gzip = [IO.Compression.GZipStream]::new($file, [IO.Compression.CompressionLevel]::Optimal)
    try { $gzip.Write([Text.Encoding]::UTF8.GetBytes('CREATE DATABASE forbidden_database;')) } finally { $gzip.Dispose(); $file.Dispose() }
    [IO.File]::WriteAllText((Join-Path $invalid 'COMPLETE'), ((Get-FileHash -LiteralPath $invalidChunk).Hash.ToLowerInvariant() + "  database.sql.gz.part-aa`n"))

    & $restoreScript -BackupDirectory $base -SkipBuild
    # Restore verifies session removal before HTTP starts creating fresh test sessions.
    Compose -Arguments @('run', '--rm', '--no-deps', '-v', "${PSScriptRoot}:/tests:ro", '--entrypoint', 'php', 'kimai', '/tests/RestoreFixture.php', 'verify')
    Compose -Arguments @('exec', '-T', 'kimai', 'touch', '/opt/kimai/var/data/reset-proof')
    & $restoreScript -BackupDirectory $base -SkipBuild
    Compose -Arguments @('exec', '-T', 'kimai', 'test', '!', '-e', '/opt/kimai/var/data/reset-proof')
    Compose -Arguments @('run', '--rm', '--no-deps', '-v', "${PSScriptRoot}:/tests:ro", '--entrypoint', 'php', 'kimai', '/tests/RestoreFixture.php', 'verify')
    Write-Output 'PASS: Repeated restore preserves relationships and replaces only disposable data/files'
    Expect-Failure -Directory $plugin -Message 'Unknown plugin table rejected and raw volumes removed' -AfterImport
    Expect-Failure -Directory $column -Message 'Unknown retained-table column rejected and raw volumes removed' -AfterImport
    Expect-Failure -Directory $orphan -Message 'Broken foreign keys rejected and raw volumes removed' -AfterImport
    Expect-Failure -Directory $invalid -Message 'Out-of-scope SQL rejected by database-scoped importer and raw volumes removed' -AfterImport
    & $restoreScript -BackupDirectory $base -SkipBuild
    $portLine = [IO.File]::ReadAllLines($environmentFile) | Where-Object { $_ -match '^TEST_PORT=' }
    $port = $portLine.Split('=', 2)[1]
    $page = Invoke-WebRequest -Uri "http://localhost:$port" -MaximumRedirection 5
    if ($page.StatusCode -ne 200 -or $page.Content -match 'Sign in with Microsoft') { throw 'Test login page failed or production SSO is active.' }
    Write-Output 'PASS: Test login responds over localhost with SSO disabled'
    Write-Output "All restore integration checks passed. Synthetic backups: $workPath"
} finally {
    try {
        if (!$KeepRunning) { Compose -Arguments @('down', '--volumes') }
    } finally {
        $env:KIMAI_BACKUP_DIRECTORY = $previousBackup
    }
}
