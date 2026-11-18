#Requires -Version 7.2
[CmdletBinding()]
param(
    [ValidateSet('Local', 'Production', 'Test')]
    [string]$Mode = 'Local',
    [string]$EnvironmentFile
)

$ErrorActionPreference = 'Stop'
$projectPath = Split-Path -Parent $PSScriptRoot
$defaultName = switch ($Mode) {
    'Production' { '.env.production' }
    'Test' { '.env.test' }
    default { '.env' }
}
if ([string]::IsNullOrWhiteSpace($EnvironmentFile)) { $EnvironmentFile = $defaultName }
if (![IO.Path]::IsPathRooted($EnvironmentFile)) { $EnvironmentFile = Join-Path $projectPath $EnvironmentFile }

$content = if (Test-Path -LiteralPath $EnvironmentFile) {
    [IO.File]::ReadAllText($EnvironmentFile)
} else {
    [IO.File]::ReadAllText((Join-Path $projectPath ($defaultName + '.example')))
}
$content = $content.Replace("`r`n", "`n")
$secretNames = @('ADMIN_PASSWORD', 'DATABASE_PASSWORD', 'DATABASE_ROOT_PASSWORD', 'APP_SECRET')
if ($Mode -eq 'Test') { $secretNames = $secretNames | ForEach-Object { 'TEST_' + $_ } }
foreach ($name in $secretNames) {
    $pattern = '(?m)^' + $name + '=(.*)$'
    $entry = [regex]::Match($content, $pattern)
    if (!$entry.Success) { throw "The environment file is missing $name." }
    if ([string]::IsNullOrWhiteSpace($entry.Groups[1].Value.Trim([char]34, [char]39))) {
        # Kimai limits a user's plain password to 60 characters.
        $byteCount = if ($name.EndsWith('ADMIN_PASSWORD')) { 24 } else { 32 }
        $bytes = [Security.Cryptography.RandomNumberGenerator]::GetBytes($byteCount)
        $secret = [Convert]::ToHexString($bytes).ToLowerInvariant()
        $content = [regex]::Replace($content, $pattern, ($name + '=' + $secret))
    }
}
[IO.Directory]::CreateDirectory([IO.Path]::GetDirectoryName([IO.Path]::GetFullPath($EnvironmentFile))) | Out-Null
[IO.File]::WriteAllText($EnvironmentFile, $content, [Text.UTF8Encoding]::new($false))
Write-Output "Environment ready: $EnvironmentFile"
Write-Output 'Existing nonempty secrets were preserved. Keep this file private.'
