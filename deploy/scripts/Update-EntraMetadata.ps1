#Requires -Version 7.2
[CmdletBinding()]
param(
    [string]$TenantId,
    [string]$ApplicationId,
    [string]$EnvironmentFile = '.env'
)

$ErrorActionPreference = 'Stop'
$projectPath = Split-Path -Parent $PSScriptRoot
if (![IO.Path]::IsPathRooted($EnvironmentFile)) {
    $EnvironmentFile = Join-Path $projectPath $EnvironmentFile
}
$settings = @{}
if (Test-Path -LiteralPath $EnvironmentFile) {
    foreach ($line in [IO.File]::ReadAllLines($EnvironmentFile)) {
        if ($line -match '^\s*(ENTRA_TENANT_ID|ENTRA_APPLICATION_ID)\s*=\s*(.*?)\s*$') {
            $settings[$matches[1]] = $matches[2].Trim([char]34, [char]39)
        }
    }
}
if ([string]::IsNullOrWhiteSpace($TenantId)) { $TenantId = $settings['ENTRA_TENANT_ID'] }
if ([string]::IsNullOrWhiteSpace($ApplicationId)) { $ApplicationId = $settings['ENTRA_APPLICATION_ID'] }
$tenantGuid = [guid]::Empty
$applicationGuid = [guid]::Empty
if (![guid]::TryParse($TenantId, [ref]$tenantGuid) -or ![guid]::TryParse($ApplicationId, [ref]$applicationGuid)) {
    throw 'Set ENTRA_TENANT_ID and ENTRA_APPLICATION_ID in the environment file or supply both parameters.'
}
$tenantId = $tenantGuid.ToString()
$applicationId = $applicationGuid.ToString()
$metadataUrl = "https://login.microsoftonline.com/$tenantId/federationmetadata/2007-06/federationmetadata.xml?appid=$applicationId"
$response = Invoke-WebRequest -Uri $metadataUrl -TimeoutSec 30 -UseBasicParsing
$metadataText = if ($response.Content -is [byte[]]) {
    [System.Text.Encoding]::UTF8.GetString($response.Content)
} else {
    [string]$response.Content
}
$metadataText = $metadataText.TrimStart([char]0xFEFF)

$readerSettings = [System.Xml.XmlReaderSettings]::new()
$readerSettings.DtdProcessing = [System.Xml.DtdProcessing]::Prohibit
$readerSettings.XmlResolver = $null
$reader = [System.Xml.XmlReader]::Create([System.IO.StringReader]::new($metadataText), $readerSettings)
$metadata = [System.Xml.XmlDocument]::new()
$metadata.XmlResolver = $null
try { $metadata.Load($reader) } finally { $reader.Dispose() }

$expectedIssuer = "https://sts.windows.net/$tenantId/"
if ($metadata.DocumentElement.entityID -ne $expectedIssuer) {
    throw 'The federation metadata issuer does not match the configured Entra tenant.'
}

$ns = [System.Xml.XmlNamespaceManager]::new($metadata.NameTable)
$ns.AddNamespace('md', 'urn:oasis:names:tc:SAML:2.0:metadata')
$ns.AddNamespace('ds', 'http://www.w3.org/2000/09/xmldsig#')
$binding = 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect'
$sso = $metadata.SelectSingleNode("//md:IDPSSODescriptor/md:SingleSignOnService[@Binding='$binding']", $ns)
$ssoUrl = "https://login.microsoftonline.com/$tenantId/saml2"
if ($null -eq $sso -or $sso.Location -ne $ssoUrl) {
    throw 'The SAML login endpoint does not match the configured Entra tenant.'
}

$certificates = @($metadata.SelectNodes('//md:IDPSSODescriptor/md:KeyDescriptor[@use="signing"]//ds:X509Certificate', $ns) |
    ForEach-Object { $_.InnerText -replace '\s', '' } | Select-Object -Unique)
if ($certificates.Count -eq 0) { throw 'No SAML signing certificates were found.' }
$hasCurrentCertificate = $false
foreach ($encoded in $certificates) {
    $certificate = [System.Security.Cryptography.X509Certificates.X509Certificate2]::new([Convert]::FromBase64String($encoded))
    if ($certificate.NotBefore.ToUniversalTime() -le [DateTime]::UtcNow -and $certificate.NotAfter.ToUniversalTime() -gt [DateTime]::UtcNow) {
        $hasCurrentCertificate = $true
    }
    Write-Output ("Signing certificate {0}, expires {1:u}" -f $certificate.Thumbprint, $certificate.NotAfter.ToUniversalTime())
    $certificate.Dispose()
}
if (!$hasCurrentCertificate) { throw 'No currently valid SAML signing certificate was found.' }

$certificateYaml = ($certificates | ForEach-Object { "                        - '$_'" }) -join "`n"
$yaml = @"
# Generated from the application-specific Entra metadata. Refresh with scripts/Update-EntraMetadata.ps1.
kimai:
    saml:
        connection:
            idp:
                entityId: '$expectedIssuer'
                singleSignOnService:
                    url: '$ssoUrl'
                    binding: '$binding'
                singleLogoutService:
                    url: '$ssoUrl'
                    binding: '$binding'
                x509certMulti:
                    signing:
$certificateYaml
"@
$outputPath = Join-Path $projectPath 'config/entra'
[System.IO.Directory]::CreateDirectory($outputPath) | Out-Null
[System.IO.File]::WriteAllText((Join-Path $outputPath 'idp-metadata.xml'), $metadataText, [System.Text.UTF8Encoding]::new($false))
[System.IO.File]::WriteAllText((Join-Path $outputPath 'idp.yaml'), $yaml.Replace("`r`n", "`n") + "`n", [System.Text.UTF8Encoding]::new($false))
Write-Output 'Updated the Kimai identity provider configuration. Recreate the Kimai container to apply it.'
