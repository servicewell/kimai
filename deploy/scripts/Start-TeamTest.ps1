#Requires -Version 7.2
[CmdletBinding()]
param([switch]$SkipBuild)

$ErrorActionPreference = 'Stop'
$deployPath = Split-Path -Parent $PSScriptRoot
$seedPath = Join-Path $deployPath 'seeds/team-v1'
$metadataPath = Join-Path $seedPath 'seed.json'
if (!(Test-Path -LiteralPath $metadataPath)) { throw 'Download the private team-test package first; see deploy/TEAM-TEST.md.' }
$metadata = [IO.File]::ReadAllText($metadataPath) | ConvertFrom-Json
if ($metadata.kind -cnotin @('kimai-sanitized-seed', 'kimai-business-seed') -or $metadata.formatVersion -ne 1) { throw 'Unsupported team seed package.' }
$profile = if ($metadata.kind -ceq 'kimai-business-seed') { 'Business' } else { 'Sanitized' }
& (Join-Path $PSScriptRoot 'Restore-TestBackup.ps1') -BackupDirectory $seedPath -DataProfile $profile -SkipBuild:$SkipBuild
