#Requires -Version 7.2
[CmdletBinding()]
param(
    [Parameter(Mandatory)]
    [string]$BackupDirectory,
    [ValidateSet('Sanitized', 'Business')]
    [string]$DataProfile = 'Sanitized',
    [switch]$SkipBuild,
    [switch]$ValidateOnly
)

$ErrorActionPreference = 'Stop'
$deployPath = Split-Path -Parent $PSScriptRoot
$composeFile = Join-Path $deployPath 'compose.test.yaml'
$environmentFile = Join-Path $deployPath '.env.test'
$backupPath = (Resolve-Path -LiteralPath $BackupDirectory).ProviderPath
if (!(Test-Path -LiteralPath $backupPath -PathType Container)) { throw 'Provide a downloaded backup directory.' }

# Only completed, checksum-verified SQL chunks are consumed. No archive is extracted.
$manifestPath = Join-Path $backupPath 'COMPLETE'
if (!(Test-Path -LiteralPath $manifestPath -PathType Leaf)) { throw 'Backup has no COMPLETE manifest.' }
if ((Get-Item -LiteralPath $manifestPath).Length -gt 1MB) { throw 'Backup manifest is unexpectedly large.' }
$entries = @{}
foreach ($line in [IO.File]::ReadAllLines($manifestPath)) {
    if ($line -notmatch '^([0-9a-fA-F]{64}) [ *]([A-Za-z0-9_.-]+)$') { throw 'Malformed backup manifest.' }
    $fileName = $Matches[2]
    if ($fileName -in @('.', '..') -or $entries.ContainsKey($fileName)) { throw 'Unsafe or duplicate manifest entry.' }
    $entries[$fileName] = $Matches[1]
}
$chunks = @($entries.Keys | Where-Object { $_ -like 'database.sql.gz.part-*' } | Sort-Object)
if ($chunks.Count -eq 0) { throw 'Manifest contains no database chunks.' }
$actualChunks = @(Get-ChildItem -LiteralPath $backupPath -Filter 'database.sql.gz.part-*' -File | Select-Object -ExpandProperty Name | Sort-Object)
if (($chunks -join ',') -cne ($actualChunks -join ',')) { throw 'Database chunks do not match the manifest.' }
for ($index = 0; $index -lt $chunks.Count; $index++) {
    # Check GNU split's initial two-letter suffix sequence without sorting away gaps.
    $suffix = [string][char][int](97 + [math]::Floor($index / 26)) + [string][char][int](97 + ($index % 26))
    if ($index -ge 676 -or $chunks[$index] -cne ('database.sql.gz.part-' + $suffix)) { throw 'Missing or unsupported database chunk sequence.' }
    $chunkPath = Join-Path $backupPath $chunks[$index]
    if ((Get-Item -LiteralPath $chunkPath).LinkType) { throw 'Backup chunks must be regular files, not links.' }
    if ((Get-FileHash -LiteralPath $chunkPath -Algorithm SHA256).Hash -ine $entries[$chunks[$index]]) { throw 'Database checksum verification failed.' }
}
Write-Output "Verified $($chunks.Count) database chunks. Other backup content will not be restored."
if ($ValidateOnly) { return }

function Invoke-TestCompose {
    param([string[]]$ComposeArguments, [switch]$Quiet, [switch]$SanitizedOutput)
    $arguments = @('--context', 'default', 'compose', '--project-name', 'test-kimai', '--env-file', $environmentFile, '-f', $composeFile) + $ComposeArguments
    if ($Quiet) {
        # Database import errors can echo production SQL. Never print that output.
        $result = & docker @arguments 2>&1
        if ($LASTEXITCODE -ne 0) {
            if ($SanitizedOutput -and $DataProfile -eq 'Sanitized') {
                $diagnostic = $result -join "`n"
                $settings = $config.services.kimai.environment
                $password = ([uri]$settings.DATABASE_URL).UserInfo.Split(':', 2)[1]
                foreach ($secret in @($settings.APP_SECRET, $settings.TEST_ADMIN_PASSWORD, $password)) {
                    if ($secret) { $diagnostic = $diagnostic.Replace($secret, '[redacted]') }
                }
                throw "Test application preparation failed after sanitization:`n$diagnostic"
            }
            throw 'Test restore command failed. Application remains unavailable; database output was withheld to protect source data.'
        }
    } else {
        & docker @arguments
        if ($LASTEXITCODE -ne 0) { throw 'A test-kimai Docker Compose command failed.' }
    }
}

# Explicit local context defeats inherited DOCKER_HOST/DOCKER_CONTEXT settings.
$contextJson = & docker --context default context inspect default
if ($LASTEXITCODE -ne 0) { throw 'Cannot inspect the local Docker context.' }
$endpoint = ($contextJson | ConvertFrom-Json)[0].Endpoints.docker.Host
if ($endpoint -notmatch '^(npipe:/{4}\./pipe/|unix:///)') { throw 'Restore requires a local Docker socket, not a remote endpoint.' }

$lock = [IO.File]::Open((Join-Path $deployPath '.test-restore.lock'), [IO.FileMode]::OpenOrCreate, [IO.FileAccess]::ReadWrite, [IO.FileShare]::None)
$previousBackupDirectory = $env:KIMAI_BACKUP_DIRECTORY
$previousAdminUsername = $env:TEST_ADMIN_USERNAME
$previousAdminEmail = $env:TEST_ADMIN_EMAIL
$restoreStarted = $false
$success = $false
try {
    $env:KIMAI_BACKUP_DIRECTORY = $backupPath
    $adminSuffix = [Convert]::ToHexString([Security.Cryptography.RandomNumberGenerator]::GetBytes(8)).ToLowerInvariant()
    $env:TEST_ADMIN_USERNAME = "test-local-admin-$adminSuffix"
    $env:TEST_ADMIN_EMAIL = "$($env:TEST_ADMIN_USERNAME)@example.invalid"
    & (Join-Path $PSScriptRoot 'Initialize-Environment.ps1') -Mode Test
    $configJson = & docker --context default compose --project-name test-kimai --env-file $environmentFile -f $composeFile config --format json
    if ($LASTEXITCODE -ne 0) { throw 'Invalid test Compose configuration.' }
    $config = $configJson | ConvertFrom-Json
    if ($config.name -cne 'test-kimai' -or !$config.networks.default.internal) { throw 'Test project isolation check failed.' }
    $expectedVolumes = @('test-kimai_mariadb', 'test-kimai_data')
    $volumeNames = @($config.volumes.PSObject.Properties | ForEach-Object { $_.Value.name } | Sort-Object)
    if (($volumeNames -join ',') -cne (($expectedVolumes | Sort-Object) -join ',')) { throw 'Unexpected test volume names.' }
    if (@($config.volumes.PSObject.Properties | Where-Object { $_.Value.external }).Count -gt 0) { throw 'External volumes are not allowed in the test restore.' }
    foreach ($volume in $expectedVolumes) {
        $volumeJson = & docker --context default volume inspect $volume 2>$null
        if ($LASTEXITCODE -eq 0) {
            $volumeInfo = ($volumeJson | ConvertFrom-Json)[0]
            if ($volumeInfo.Labels.'com.docker.compose.project' -cne 'test-kimai') { throw 'Refusing to replace a volume not owned by test-kimai.' }
        }
    }
    if (!$SkipBuild) { Invoke-TestCompose -ComposeArguments @('build', 'kimai') }

    # Every refresh uses empty dedicated volumes, clearing files, sessions and readiness.
    $restoreStarted = $true
    Invoke-TestCompose -ComposeArguments @('down', '--volumes')
    Invoke-TestCompose -ComposeArguments @('up', '-d', '--wait', 'sqldb')
    Invoke-TestCompose -ComposeArguments @('run', '--rm', '--no-deps', 'importer') -Quiet
    $profile = $DataProfile.ToLowerInvariant()
    Invoke-TestCompose -ComposeArguments @('run', '--rm', '--no-deps', '--entrypoint', 'php', 'kimai', '/restore/Sanitize-TestDatabase.php', '--sanitize', $profile)

    # Migrations and account creation happen after the selected credential policy.
    $prepare = @'
set -euo pipefail
cd /opt/kimai
php -d memory_limit=512M bin/console -n kimai:update
php /restore/Sanitize-TestDatabase.php --sanitize "$1"
php -d memory_limit=512M bin/console kimai:user:create "$TEST_ADMIN_USERNAME" "$TEST_ADMIN_EMAIL" ROLE_SUPER_ADMIN "$TEST_ADMIN_PASSWORD"
'@
    $prepare = $prepare.Replace("`r`n", "`n")
    Invoke-TestCompose -ComposeArguments @('run', '--rm', '--no-deps', '--entrypoint', '/bin/bash', 'kimai', '-euc', $prepare, '--', $profile) -Quiet -SanitizedOutput
    Invoke-TestCompose -ComposeArguments @('run', '--rm', '--no-deps', '--entrypoint', 'php', 'kimai', '/restore/Sanitize-TestDatabase.php', '--verify', $profile)
    Invoke-TestCompose -ComposeArguments @('run', '--rm', '--no-deps', '--entrypoint', 'touch', 'kimai', '/opt/kimai/var/data/.test-restore-ready')
    Invoke-TestCompose -ComposeArguments @('up', '-d', '--no-deps', '--wait', 'kimai', 'proxy')
    $page = Invoke-WebRequest -Uri $config.services.kimai.environment.KIMAI_PUBLIC_URL -MaximumRedirection 5 -TimeoutSec 30
    if ($page.StatusCode -ne 200) { throw 'The test login page did not respond successfully.' }
    $environmentContent = [IO.File]::ReadAllText($environmentFile)
    $environmentContent = [regex]::Replace($environmentContent, '(?m)^TEST_ADMIN_USERNAME=.*$', "TEST_ADMIN_USERNAME=$($env:TEST_ADMIN_USERNAME)")
    $environmentContent = [regex]::Replace($environmentContent, '(?m)^TEST_ADMIN_EMAIL=.*$', "TEST_ADMIN_EMAIL=$($env:TEST_ADMIN_EMAIL)")
    if ($environmentContent -notmatch '(?m)^TEST_ADMIN_USERNAME=') { $environmentContent += "`nTEST_ADMIN_USERNAME=$($env:TEST_ADMIN_USERNAME)" }
    if ($environmentContent -notmatch '(?m)^TEST_ADMIN_EMAIL=') { $environmentContent += "`nTEST_ADMIN_EMAIL=$($env:TEST_ADMIN_EMAIL)" }
    [IO.File]::WriteAllText($environmentFile, $environmentContent, [Text.UTF8Encoding]::new($false))
    $success = $true
    Write-Output "$DataProfile test-kimai is ready at $($config.services.kimai.environment.KIMAI_PUBLIC_URL)."
    Write-Output 'Sign in as the generated TEST_ADMIN_USERNAME using TEST_ADMIN_PASSWORD in deploy/.env.test. Imported passwords, tokens, MFA and sessions are removed.'
} finally {
    try {
        if ($restoreStarted -and !$success) {
            Write-Warning 'Restore failed. Removing only test-kimai containers and volumes, including any unsanitized copy.'
            Invoke-TestCompose -ComposeArguments @('down', '--volumes')
        }
    } finally {
        $env:KIMAI_BACKUP_DIRECTORY = $previousBackupDirectory
        $env:TEST_ADMIN_USERNAME = $previousAdminUsername
        $env:TEST_ADMIN_EMAIL = $previousAdminEmail
        $lock.Dispose()
    }
}
