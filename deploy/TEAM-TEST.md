# Shared Kimai test environment

The GitHub fork is public. The production-derived test seed is distributed through the private **ServiceWell** Azure DevOps project as a versioned Universal Package. It is not stored in Git.

The bundle contains the fork's application source, deployment/restore scripts and a sanitized seed. Docker builds that source locally. The bundled seed has no enabled accounts, password hashes, API tokens, sessions or shared administrator password. Each developer gets independent database credentials, application secret and administrator password when initializing their local environment.

An additional private package, **`kimai-business-test` version `1.0.0`**, preserves
the original business records for integration development. Choose that package
instead of `kimai-team-test` in the download command below and use a new directory.
Its `Start-TeamTest.ps1` automatically selects the business profile from `seed.json`.
The business seed retains names, contact details, customer/project references,
financial values, invoices, custom fields, preferences and original enabled flags.
Known authentication secrets and sessions are removed; each developer still gets
an independent test administrator. It is confidential company data and must stay
in the private feed and authorized developers' local environments.

## Download and start

Requirements: Docker Desktop/Engine with Linux containers and Compose 2.24.4+, PowerShell 7.2+, and Azure CLI with the `azure-devops` extension. You need access to the private ServiceWell project and read access to the `kimai-test-data` feed. Members of the existing ServiceWell Team are granted feed reader access.

Sign in with the company identity used for Azure DevOps. If your organization uses a PAT for CLI access, run `az devops login --organization https://dev.azure.com/servicewell` and enter the token at the prompt; never put a token into a source file or seed.

```powershell
az login
az extension add --name azure-devops
$destination = Join-Path $PWD 'kimai-team-test-1.0.0'
az artifacts universal download --organization https://dev.azure.com/servicewell --project ServiceWell --scope project --feed kimai-test-data --name kimai-team-test --version 1.0.0 --path $destination
if ($LASTEXITCODE -ne 0) { throw 'Private test bundle download failed.' }
& (Join-Path $destination 'deploy/scripts/Start-TeamTest.ps1')
```

Use a new empty download directory. Open **http://localhost:8002** and sign in as **test-admin**. Its generated password is `TEST_ADMIN_PASSWORD` in the downloaded bundle's `deploy/.env.test`. That file is private to your machine and is never part of the package. To use another port, initialize `deploy/.env.test` with `Initialize-Environment.ps1 -Mode Test`, change `TEST_PORT`, then run `Start-TeamTest.ps1`.

`Start-TeamTest.ps1` resets only the local `test-kimai` project. Existing edits in that disposable test database/files are discarded. Run it again with `-SkipBuild` to reset to the same seed without rebuilding the application. MariaDB 11.4, mail disabled, SAML disabled, internal application/database networking and a loopback-only proxy are used. No production access or production credentials are required.

The business package uses the existing database snapshot dated **2026-11-18**.
It is not a continuously refreshed copy. The raw backup is unchanged; the test seed
has known authentication fields removed and schema migrations applied. It excludes
uploaded files and plugin code. A future Fortnox connector needs its own credentials
and an explicitly configured outbound connection; no Fortnox calls run during restore.

## Seed privacy and scope

Names are placeholders, contact information and free text are cleared, credentials and custom settings are removed, and financial rates/budgets are synthetic. Uploaded files, invoices, invoice/export templates, production configuration and plugins are excluded. IDs, relationships, scheduling dates and durations remain so the application has useful realistic data. These retained patterns mean the seed is for private team use and must not be posted to a public repository or release.

The `seed.json` file describes the application/database version, row counts and credential policy. `COMPLETE` contains the SQL chunk checksums. Restore verifies them, sanitizes again, applies migrations and creates a new local administrator before starting HTTP. The exported seed itself has no administrator password.

## Maintaining a release

From a maintainer checkout with an already downloaded backup, use `Export-SanitizedSeed.ps1 -BackupDirectory <private-backup-directory> -OutputDirectory <empty-directory-under-deploy/backups>`. It imports into an isolated `kimai-seed-builder` project, sanitizes without booting plugins against raw data, applies migrations, verifies every retained field without an administrator exception, exports only the cleaned database and removes staging volumes. The running `test-kimai` project is untouched.

Use `-DataProfile Business` on both `Export-SanitizedSeed.ps1` and `tests/Test-Seed.ps1`
for the business package. Review preserved configuration, preferences, free text and
custom fields for embedded secrets before publishing: the policy removes known
authentication columns/tables, not arbitrary secrets pasted into business records.
Never package the raw backup. Publish the two profiles under separate package names
and immutable versions; do not replace an anonymous seed with business data silently.

Run `tests/Test-Seed.ps1 -SeedDirectory <exported-seed-directory>` to verify the exported SQL in a second isolated database, confirm password-leak rejection and verify fresh credentials for another developer. Raw backups, generated `.env` files, TLS material, restore logs and verification reports containing private source details must never be included in a published bundle. Review feed and view access before publishing a new immutable package version.
