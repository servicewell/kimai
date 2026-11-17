#Requires -Version 7.2
[CmdletBinding()]
param([string]$OutputDirectory)

$ErrorActionPreference = 'Stop'
$projectPath = Split-Path -Parent $PSScriptRoot
if ([string]::IsNullOrWhiteSpace($OutputDirectory)) { $OutputDirectory = 'config/tls' }
$tlsPath = if ([IO.Path]::IsPathRooted($OutputDirectory)) { $OutputDirectory } else { Join-Path $projectPath $OutputDirectory }
[System.IO.Directory]::CreateDirectory($tlsPath) | Out-Null
$certificatePath = Join-Path $tlsPath 'localhost.crt'
$privateKeyPath = Join-Path $tlsPath 'localhost.key'

if ((Test-Path -LiteralPath $certificatePath) -and (Test-Path -LiteralPath $privateKeyPath)) {
    $existing = [System.Security.Cryptography.X509Certificates.X509Certificate2]::CreateFromPemFile($certificatePath, $privateKeyPath)
    if ($existing.NotAfter.ToUniversalTime() -gt [DateTime]::UtcNow.AddDays(14)) {
        Write-Output ("Local HTTPS certificate already exists: {0}" -f $existing.Thumbprint)
        $existing.Dispose()
        return
    }
    $existing.Dispose()
}

$key = [System.Security.Cryptography.RSA]::Create(3072)
try {
    $request = [System.Security.Cryptography.X509Certificates.CertificateRequest]::new(
        'CN=Kimai localhost development', $key, [System.Security.Cryptography.HashAlgorithmName]::SHA256,
        [System.Security.Cryptography.RSASignaturePadding]::Pkcs1)
    $request.CertificateExtensions.Add([System.Security.Cryptography.X509Certificates.X509BasicConstraintsExtension]::new($false, $false, 0, $true))
    $request.CertificateExtensions.Add([System.Security.Cryptography.X509Certificates.X509KeyUsageExtension]::new(
        [System.Security.Cryptography.X509Certificates.X509KeyUsageFlags]::DigitalSignature -bor
        [System.Security.Cryptography.X509Certificates.X509KeyUsageFlags]::KeyEncipherment, $true))
    $usages = [System.Security.Cryptography.OidCollection]::new()
    $usages.Add([System.Security.Cryptography.Oid]::new('1.3.6.1.5.5.7.3.1')) | Out-Null
    $request.CertificateExtensions.Add([System.Security.Cryptography.X509Certificates.X509EnhancedKeyUsageExtension]::new($usages, $true))
    $names = [System.Security.Cryptography.X509Certificates.SubjectAlternativeNameBuilder]::new()
    $names.AddDnsName('localhost')
    $request.CertificateExtensions.Add($names.Build())
    $certificate = $request.CreateSelfSigned([DateTimeOffset]::UtcNow.AddMinutes(-5), [DateTimeOffset]::UtcNow.AddYears(1))
    [System.IO.File]::WriteAllText($certificatePath, $certificate.ExportCertificatePem(), [System.Text.UTF8Encoding]::new($false))
    [System.IO.File]::WriteAllText($privateKeyPath, $key.ExportPkcs8PrivateKeyPem(), [System.Text.UTF8Encoding]::new($false))
    Write-Output ("Created localhost-only HTTPS certificate: {0}" -f $certificate.Thumbprint)
    $certificate.Dispose()
} finally { $key.Dispose() }

Write-Output "Certificate: $certificatePath"
Write-Output 'Trust this certificate for the current Windows user before opening the local HTTPS site.'
