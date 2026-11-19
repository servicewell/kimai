#Requires -Version 7.2
[CmdletBinding()]
param([switch]$SkipBuild)

$ErrorActionPreference = 'Stop'
$deployPath = Split-Path -Parent $PSScriptRoot
$seedPath = Join-Path $deployPath 'seeds/team-v1'
$metadataPath = Join-Path $seedPath 'seed.json'
if (!(Test-Path -LiteralPath $metadataPath)) { throw 'Download the private team-test package first; see deploy/TEAM-TEST.md.' }
$metadata = [IO.File]::ReadAllText($metadataPath) | ConvertFrom-Json
if ($metadata.kind -cne 'kimai-sanitized-seed' -or $metadata.formatVersion -ne 1) { throw 'Unsupported team seed package.' }
& (Join-Path $PSScriptRoot 'Restore-TestBackup.ps1') -BackupDirectory $seedPath -SkipBuild:$SkipBuild
