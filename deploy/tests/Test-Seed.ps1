#Requires -Version 7.2
[CmdletBinding()]
param(
    [Parameter(Mandatory)][string]$SeedDirectory,
    [ValidateSet('Sanitized', 'Business')][string]$DataProfile = 'Sanitized'
)

$ErrorActionPreference = 'Stop'
$deployPath = Split-Path -Parent $PSScriptRoot
$composeFile = Join-Path $deployPath 'compose.seed.yaml'
$environmentFile = Join-Path $deployPath '.env.test'
$seedPath = (Resolve-Path -LiteralPath $SeedDirectory).ProviderPath
$profile = $DataProfile.ToLowerInvariant()
& (Join-Path $deployPath 'scripts/Initialize-Environment.ps1') -Mode Test
& (Join-Path $deployPath 'scripts/Restore-TestBackup.ps1') -BackupDirectory $seedPath -ValidateOnly

function Compose {
    param([string[]]$Arguments, [switch]$Quiet)
    $command = @('--context', 'default', 'compose', '--project-name', 'kimai-seed-check', '--env-file', $environmentFile, '-f', $composeFile) + $Arguments
    if ($Quiet) { $discarded = & docker @command 2>&1 } else { & docker @command }
    if ($LASTEXITCODE -ne 0) { throw 'Seed round-trip check failed.' }
}

$context = & docker --context default context inspect default
if ($LASTEXITCODE -ne 0 -or ($context | ConvertFrom-Json)[0].Endpoints.docker.Host -notmatch '^(npipe:/{4}\./pipe/|unix:///)') { throw 'A local Docker socket is required.' }
foreach ($volume in @('kimai-seed-check_mariadb', 'kimai-seed-check_data')) {
    $existing = & docker --context default volume inspect $volume 2>$null
    if ($LASTEXITCODE -eq 0) { throw 'Seed checks require empty dedicated check volumes.' }
}
$previousBackup = $env:KIMAI_BACKUP_DIRECTORY
$previousPassword = $env:TEST_ADMIN_PASSWORD
$started = $false
try {
    $env:KIMAI_BACKUP_DIRECTORY = $seedPath
    # Model a different developer: a fresh password unrelated to the exporting machine.
    $env:TEST_ADMIN_PASSWORD = [Convert]::ToHexString([Security.Cryptography.RandomNumberGenerator]::GetBytes(24)).ToLowerInvariant()
    $started = $true
    Compose -Arguments @('up', '-d', '--wait', 'sqldb')
    Compose -Arguments @('run', '--rm', '--no-deps', 'importer') -Quiet
    Compose -Arguments @('run', '--rm', '--no-deps', '--entrypoint', 'php', 'kimai', '/restore/Sanitize-TestDatabase.php', '--verify-seed', $profile)
    Write-Output 'PASS: Exported seed round-trips with no account credentials'

    $sql = 'MYSQL_PWD="$MARIADB_ROOT_PASSWORD" mariadb --user=root --database=kimai --execute="$1"'
    Compose -Arguments @('exec', '-T', 'sqldb', '/bin/bash', '-euc', $sql, '--', "UPDATE kimai2_users SET password='must-not-be-shared' ORDER BY id LIMIT 1") -Quiet
    $discarded = & docker --context default compose --project-name kimai-seed-check --env-file $environmentFile -f $composeFile run --rm --no-deps --entrypoint php kimai /restore/Sanitize-TestDatabase.php --verify-seed $profile 2>&1
    if ($LASTEXITCODE -eq 0) { throw 'Seed verification accepted a password leak.' }
    Write-Output 'PASS: Seed verification rejects a retained password'
    Compose -Arguments @('run', '--rm', '--no-deps', '--entrypoint', 'php', 'kimai', '/restore/Sanitize-TestDatabase.php', '--sanitize', $profile)
    $create = 'php -d memory_limit=512M /opt/kimai/bin/console kimai:user:create test-admin test-admin@example.invalid ROLE_SUPER_ADMIN "$TEST_ADMIN_PASSWORD"'
    Compose -Arguments @('run', '--rm', '--no-deps', '--entrypoint', '/bin/bash', 'kimai', '-euc', $create) -Quiet
    Compose -Arguments @('run', '--rm', '--no-deps', '--entrypoint', 'php', 'kimai', '/restore/Sanitize-TestDatabase.php', '--verify', $profile)
    Write-Output 'PASS: Restoring developer creates a fresh independently verified administrator'
} finally {
    try { if ($started) { Compose -Arguments @('down', '--volumes') } } finally {
        $env:KIMAI_BACKUP_DIRECTORY = $previousBackup
        $env:TEST_ADMIN_PASSWORD = $previousPassword
    }
}
