#Requires -Version 7.2
[CmdletBinding()]
param(
    [Parameter(Mandatory)]
    [string]$BackupDirectory,
    [Parameter(Mandatory)]
    [string]$OutputDirectory,
    [switch]$SkipBuild
)

$ErrorActionPreference = 'Stop'
$deployPath = Split-Path -Parent $PSScriptRoot
$composeFile = Join-Path $deployPath 'compose.seed.yaml'
$environmentFile = Join-Path $deployPath '.env.test'
$backupPath = (Resolve-Path -LiteralPath $BackupDirectory).ProviderPath
$outputPath = [IO.Path]::GetFullPath($OutputDirectory)
$privateRoot = [IO.Path]::GetFullPath((Join-Path $deployPath 'backups')) + [IO.Path]::DirectorySeparatorChar
if (!$outputPath.StartsWith($privateRoot, [StringComparison]::OrdinalIgnoreCase)) { throw 'Export seeds only inside the ignored deploy/backups directory; distribute them privately.' }
if ((Test-Path -LiteralPath $outputPath) -and @(Get-ChildItem -LiteralPath $outputPath -Force).Count -gt 0) { throw 'Seed output directory must be empty.' }
& (Join-Path $PSScriptRoot 'Restore-TestBackup.ps1') -BackupDirectory $backupPath -ValidateOnly
$context = & docker --context default context inspect default
if ($LASTEXITCODE -ne 0 -or ($context | ConvertFrom-Json)[0].Endpoints.docker.Host -notmatch '^(npipe:/{4}\./pipe/|unix:///)') { throw 'A local Docker socket is required.' }

function Invoke-SeedCompose {
    param([string[]]$Arguments, [switch]$Quiet)
    $command = @('--context', 'default', 'compose', '--project-name', 'kimai-seed-builder', '--env-file', $environmentFile, '-f', $composeFile) + $Arguments
    if ($Quiet) { $discarded = & docker @command 2>&1 } else { & docker @command }
    if ($LASTEXITCODE -ne 0) { throw 'Seed staging failed; raw database output is withheld. No seed is ready to publish.' }
}

$lock = [IO.File]::Open((Join-Path $deployPath '.seed-export.lock'), [IO.FileMode]::OpenOrCreate, [IO.FileAccess]::ReadWrite, [IO.FileShare]::None)
$previousBackup = $env:KIMAI_BACKUP_DIRECTORY
$started = $false
try {
    $env:KIMAI_BACKUP_DIRECTORY = $backupPath
    & (Join-Path $PSScriptRoot 'Initialize-Environment.ps1') -Mode Test
    foreach ($volume in @('kimai-seed-builder_mariadb', 'kimai-seed-builder_data')) {
        $existing = & docker --context default volume inspect $volume 2>$null
        if ($LASTEXITCODE -eq 0 -and ($existing | ConvertFrom-Json)[0].Labels.'com.docker.compose.project' -cne 'kimai-seed-builder') { throw 'Staging volume belongs to another project.' }
    }
    if (!$SkipBuild) { Invoke-SeedCompose -Arguments @('build', 'kimai') }
    $started = $true
    Invoke-SeedCompose -Arguments @('down', '--volumes')
    Invoke-SeedCompose -Arguments @('up', '-d', '--wait', 'sqldb')
    Invoke-SeedCompose -Arguments @('run', '--rm', '--no-deps', 'importer') -Quiet
    Invoke-SeedCompose -Arguments @('run', '--rm', '--no-deps', '--entrypoint', 'php', 'kimai', '/restore/Sanitize-TestDatabase.php')
    Invoke-SeedCompose -Arguments @('run', '--rm', '--no-deps', '--entrypoint', 'php', 'kimai', '-d', 'memory_limit=512M', '/opt/kimai/bin/console', '-n', 'kimai:update', '--no-cache') -Quiet
    # No administrator is created in a seed. Even test password hashes must be absent.
    Invoke-SeedCompose -Arguments @('run', '--rm', '--no-deps', '--entrypoint', 'php', 'kimai', '/restore/Sanitize-TestDatabase.php', '--verify-seed')
    [IO.Directory]::CreateDirectory($outputPath) | Out-Null
    $dump = @'
set -euo pipefail
MYSQL_PWD="$MARIADB_PASSWORD" mariadb-dump --host=sqldb --user=kimai --single-transaction --quick --skip-comments --skip-dump-date --skip-add-locks --complete-insert --hex-blob --databases kimai |
  gzip -n | split -b 256M - /seed-output/database.sql.gz.part-
cd /seed-output
sha256sum database.sql.gz.part-* > COMPLETE
'@
    Invoke-SeedCompose -Arguments @('run', '--rm', '--no-deps', '-v', "${outputPath}:/seed-output", '--entrypoint', '/bin/bash', 'importer', '-euc', $dump) -Quiet
    & (Join-Path $PSScriptRoot 'Restore-TestBackup.ps1') -BackupDirectory $outputPath -ValidateOnly
    $metadataCode = @'
require '/opt/kimai/vendor/autoload.php';
$url = parse_url(getenv('DATABASE_URL'));
$db = new PDO('mysql:host=sqldb;dbname=kimai;charset=utf8mb4', 'kimai', rawurldecode($url['pass']), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$counts = [];
foreach (['users', 'customers', 'projects', 'activities', 'timesheet'] as $entity) { $counts[$entity] = (int) $db->query('SELECT COUNT(*) FROM kimai2_' . $entity)->fetchColumn(); }
echo json_encode(['formatVersion' => 1, 'kind' => 'kimai-sanitized-seed', 'kimaiVersion' => App\Constants::VERSION, 'databaseEngine' => 'MariaDB', 'databaseVersion' => '11.4', 'accounts' => 'All disabled; all password fields empty; no shared administrator', 'privacy' => 'Private team use only: retains scheduling dates, durations and relationships', 'counts' => $counts, 'durationSeconds' => (int) $db->query('SELECT COALESCE(SUM(duration), 0) FROM kimai2_timesheet')->fetchColumn()], JSON_THROW_ON_ERROR);
'@
    $metadata = & docker --context default compose --project-name kimai-seed-builder --env-file $environmentFile -f $composeFile run --rm --no-deps --entrypoint php kimai -r $metadataCode
    if ($LASTEXITCODE -ne 0) { throw 'Seed metadata verification failed.' }
    [IO.File]::WriteAllText((Join-Path $outputPath 'seed.json'), (($metadata | ConvertFrom-Json) | ConvertTo-Json -Depth 5), [Text.UTF8Encoding]::new($false))
    Write-Output "Verified credential-free sanitized seed: $outputPath"
} catch {
    # An incomplete export is never advertised as a completed seed.
    $marker = Join-Path $outputPath 'COMPLETE'
    if (Test-Path -LiteralPath $marker) { Remove-Item -LiteralPath $marker -Force }
    throw
} finally {
    try { if ($started) { Invoke-SeedCompose -Arguments @('down', '--volumes') } } finally {
        $env:KIMAI_BACKUP_DIRECTORY = $previousBackup
        $lock.Dispose()
    }
}
