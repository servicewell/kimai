#Requires -Version 7.2
# Offline synthetic regression checks; never replaces a developer's test-kimai.
[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
$deployPath = Split-Path -Parent $PSScriptRoot
$environmentFile = Join-Path $deployPath '.env.test'
$composeFile = Join-Path $deployPath 'compose.seed.yaml'
& (Join-Path $deployPath 'scripts/Initialize-Environment.ps1') -Mode Test
$command = @('--context', 'default', 'compose', '--project-name', 'kimai-business-check', '--env-file', $environmentFile, '-f', $composeFile)

function Compose {
    param([string[]]$Arguments)
    $discarded = & docker @command @Arguments 2>&1
    Write-Verbose ($discarded -join "`n")
    if ($LASTEXITCODE -ne 0) { throw 'Offline business profile regression check failed.' }
}

$context = & docker --context default context inspect default
if ($LASTEXITCODE -ne 0 -or ($context | ConvertFrom-Json)[0].Endpoints.docker.Host -notmatch '^(npipe:/{4}\./pipe/|unix:///)') { throw 'A local Docker socket is required.' }
foreach ($volume in @('kimai-business-check_mariadb', 'kimai-business-check_data')) {
    $existing = & docker --context default volume inspect $volume 2>$null
    if ($LASTEXITCODE -eq 0) { throw 'Business checks require empty dedicated volumes.' }
}
$started = $false
try {
    $started = $true
    Compose -Arguments @('up', '-d', '--wait', 'sqldb')
    $seed = 'cd /opt/kimai; php -d memory_limit=512M bin/console -n kimai:install --no-cache; php /tests/RestoreFixture.php seed'
    Compose -Arguments @('run', '--rm', '--no-deps', '-v', "${PSScriptRoot}:/tests:ro", '--entrypoint', '/bin/bash', 'kimai', '-euc', $seed)
    foreach ($attempt in 1..2) {
        Compose -Arguments @('run', '--rm', '--no-deps', '--entrypoint', 'php', 'kimai', '/restore/Sanitize-TestDatabase.php', '--sanitize', 'business')
        Compose -Arguments @('run', '--rm', '--no-deps', '-v', "${PSScriptRoot}:/tests:ro", '--entrypoint', 'php', 'kimai', '/tests/RestoreFixture.php', 'assert-business')
    }
    Write-Output 'PASS: Business records preserved across repeated credential removal'

    $sql = 'MYSQL_PWD="$MARIADB_ROOT_PASSWORD" mariadb --user=root --database=kimai --execute="$1"'
    Compose -Arguments @('exec', '-T', 'sqldb', '/bin/bash', '-euc', $sql, '--', 'ALTER TABLE kimai2_customers ADD unreviewed_secret TEXT')
    $discarded = & docker @command run --rm --no-deps --entrypoint php kimai /restore/Sanitize-TestDatabase.php --verify-seed business 2>&1
    if ($LASTEXITCODE -eq 0) { throw 'Business mode accepted an unreviewed column.' }
    Compose -Arguments @('exec', '-T', 'sqldb', '/bin/bash', '-euc', $sql, '--', 'ALTER TABLE kimai2_customers DROP unreviewed_secret')
    Write-Output 'PASS: Unreviewed business columns rejected'

    Compose -Arguments @('run', '--rm', '--no-deps', '--entrypoint', 'php', 'kimai', '/restore/Sanitize-TestDatabase.php')
    Compose -Arguments @('run', '--rm', '--no-deps', '-v', "${PSScriptRoot}:/tests:ro", '--entrypoint', 'php', 'kimai', '/tests/RestoreFixture.php', 'assert')
    Write-Output 'PASS: Default profile still anonymizes identities, metadata and financial values'
} finally {
    if ($started) { Compose -Arguments @('down', '--volumes') }
}
