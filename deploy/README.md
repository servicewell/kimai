# Docker and Microsoft Entra SAML

For a ready-to-run private team bundle with sanitized test data, see [Shared Kimai test environment](TEAM-TEST.md).

This setup builds this fork from the checked-out source. It runs Kimai behind Caddy, with MariaDB 11.4 and persistent Docker volumes for the database, uploaded files, and plugins.

Local development uses `https://localhost:8443`; `http://localhost:8001` redirects there. The production override uses `https://time.servicewell.se` in the separate `kimai-production` Docker project. The production configuration is prepared for deployment; it does not update the existing server automatically.

## Local setup

Requirements: Docker with Compose 2.24.4 or newer and PowerShell 7.2 or newer for the setup helpers. Run commands from this directory (`deploy/`).

```powershell
./scripts/Initialize-Environment.ps1
./scripts/Update-EntraMetadata.ps1
./scripts/Initialize-LocalHttps.ps1
```

The environment initializer creates `.env` from `.env.example` and fills empty passwords and `APP_SECRET` with independent random values. Re-running it preserves existing nonempty secrets. Review the administrator email and public Entra settings in `.env` before starting. The generated database passwords use URL-safe hexadecimal characters.

On Windows, trust the localhost certificate for the current user and approve the Windows certificate dialog:

```powershell
Import-Certificate -FilePath ./config/tls/localhost.crt -CertStoreLocation Cert:\CurrentUser\Root
docker compose up -d --build --wait
```

On another operating system, trust the generated `config/tls/localhost.crt` in the browser or operating-system certificate store before opening the site. The certificate is valid only for `localhost`, is not a certificate authority, and expires after one year. The helper renews it when fewer than 14 days remain; approve the replacement certificate after renewal.

Open `https://localhost:8443` and select **Sign in with Microsoft**. The internal backup account is `admin`; its password is `ADMIN_PASSWORD` in `.env`. To prepare a specifically selected Entra account as a Kimai administrator:

```powershell
docker compose cp scripts/Provision-EntraAdmin.php kimai:/tmp/provision-entra-admin.php
docker compose exec -T kimai php /tmp/provision-entra-admin.php your.name@example.com
```

This creates or updates that account, enables SAML authentication, removes its local password, and grants the Kimai super-administrator role. Use it only for an account that should have that role. The backup `admin` account is separate.

## Entra configuration

The examples contain this fork's public tenant/application identifiers. They contain no client secret. Set `ENTRA_TENANT_ID` and `ENTRA_APPLICATION_ID` for the Enterprise Application you intend to use.

Both environments use the same SAML Entity ID, configured by `SAML_ENTITY_ID`. Register both reply URLs in that Enterprise Application:

| Setting | Value for this fork |
| --- | --- |
| Entity ID | `https://time.servicewell.se/auth/saml/metadata` |
| Local Reply URL | `https://localhost:8443/auth/saml/acs` |
| Production Reply URL | `https://time.servicewell.se/auth/saml/acs` |
| Production sign-on URL | `https://time.servicewell.se/` |

Require user assignment to the Enterprise Application and assign the intended users or groups. The setup helpers read public metadata; they do not change Entra users, assignments, claims, or redirect URLs. For a different production domain, update the production Caddyfile, trusted-host expression, health-check host, SAML Entity ID, and Entra URLs together.

Kimai uses the signed email-address claim as the username so guest `#EXT#` identifiers do not become usernames. Entra must emit the standard email-address claim. Given name and surname are optional. New SAML users receive the normal user role; explicitly assigned Kimai administrator roles survive login. Signed assertions are required, debug mode is disabled, and the HTTPS session cookie uses `Secure` and `SameSite=None` for the cross-site SAML response.

`config/entra/saml.yaml` is mounted as Kimai's `config/packages/local.yaml`, after the framework defaults. The metadata helper validates the tenant issuer, SAML endpoint, and certificate validity, and imports all published signing certificates to support rollover. Refresh the metadata when certificates change:

```powershell
./scripts/Update-EntraMetadata.ps1
docker compose up -d --force-recreate kimai proxy --wait
```

Generated metadata, credentials, development TLS files, and backups are ignored by Git. The entire deployment directory is excluded from the application image's build context. Do not commit or distribute private environment files or TLS keys.

## Production setup

Use a separate checkout on the production server. The domain must resolve to that server and TCP ports 80 and 443 must reach Caddy. Caddy manages the public certificate and persists its state in production-only Docker volumes. MariaDB and Kimai have no directly published ports.

```powershell
./scripts/Initialize-Environment.ps1 -Mode Production
# Review .env.production, especially the administrator email and Entra settings.
./scripts/Update-EntraMetadata.ps1 -EnvironmentFile .env.production
docker compose --env-file .env.production -f compose.yaml -f compose.production.yaml up -d --build --wait
```

Use a protected environment file or secret delivery mechanism on the production host. An environment file used by Compose is not encrypted. Production does not mount the development TLS directory, and it has separate application/database volumes through the `kimai-production` project name. Prepare the selected SAML administrator with the same helper, using the production Compose options on both `cp` and `exec`.

Before replacing an existing installation, take a database/files backup and restore or migrate that data into the intended production volumes. This PR does not perform that migration or switch production traffic.

## Updates and existing installations

From a checkout using this layout, update the source with `git pull --ff-only`, then run `docker compose up -d --build --wait` from `deploy/`.

MariaDB now uses the `mariadb` volume key, separate from the earlier `mysql` volume. Existing `kimai-local_mysql` and `kimai-production_mysql` volumes remain intact. Do not attach an existing MySQL data directory to MariaDB. An existing installation needs a reviewed logical dump/import migration before switching engines; simply starting this configuration creates an empty MariaDB database. Generating new environment passwords does not update credentials stored in an existing database volume.

For a machine that runs an earlier Docker workspace, keep its running configuration until that migration is prepared. Preserve private environment, metadata and TLS files locally, and retain the original volumes until the migration is verified.

Useful commands:

```powershell
docker compose ps
docker compose logs --tail 100 kimai
docker compose stop
```

`docker compose down` retains named data volumes. `docker compose down -v` deletes them; use that only when intentionally discarding that project's data.

References: [Kimai Docker Compose](https://www.kimai.org/documentation/docker-compose.html), [Kimai Entra SAML](https://www.kimai.org/documentation/saml-azure.html).

## Restore production data into test-kimai

Use the standalone `compose.test.yaml` for a disposable local copy of production data. It builds this fork, uses MariaDB 11.4, and publishes only `http://localhost:8002` on the Docker host. It has its own `test-kimai_mariadb` and `test-kimai_data` volumes. Email and SAML are disabled and plugins are not mounted. Kimai and MariaDB use an internal Docker network with no external route; a Caddy reverse proxy joins a second network to publish the loopback host port. No production service, backup job, DNS record or Entra application is changed.

The restore accepts an **existing downloaded backup**, never connects to production, and never creates a new production backup. Use the deployed backup script's format: `COMPLETE` plus every `database.sql.gz.part-*` chunk from the same completed run. Download the SQL chunks and manifest only; deployment archives, production `.env` files, TLS keys and uploaded files are not needed. Keep this raw backup in a private directory. `deploy/backups/` is ignored by Git and excluded from application builds.

To download only these files from an existing Azure Blob backup, use an identity that already has Blob Data Reader access. Set the account and completed run prefix, then run:

```powershell
$account = '<backup-storage-account>'
$prefix = 'nightly/<completed-run>'
$destination = Join-Path $PWD 'backups/production'
New-Item -ItemType Directory -Force -Path $destination | Out-Null
az storage blob download --account-name $account --container-name backups --auth-mode login --name "$prefix/COMPLETE" --file "$destination/COMPLETE" --overwrite
if ($LASTEXITCODE -ne 0) { throw 'Completed backup manifest could not be downloaded.' }
$chunks = Get-Content "$destination/COMPLETE" | ForEach-Object {
    if ($_ -match '^[0-9a-fA-F]{64} [ *](database\.sql\.gz\.part-[a-z]{2})$') { $Matches[1] }
}
foreach ($chunk in $chunks) {
    az storage blob download --account-name $account --container-name backups --auth-mode login --name "$prefix/$chunk" --file "$destination/$chunk" --overwrite
    if ($LASTEXITCODE -ne 0) { throw 'A database chunk could not be downloaded.' }
}
```

Confirm the source Kimai version before restoring. The current fork is 2.72.0; older compatible databases are upgraded using the fork's migrations after sanitization. A database newer than this checkout should be restored using matching/newer source. Unknown tables, columns, views, non-InnoDB engines, stored routines, events or triggers cause rejection; add and test an explicit policy before using backups with plugins or schema extensions.

Run from `deploy/` with PowerShell 7.2 or newer and local Docker/Compose:

```powershell
./scripts/Restore-TestBackup.ps1 -BackupDirectory ./backups/production
```

For integration development requiring original business records, opt in with
`./scripts/Restore-TestBackup.ps1 -BackupDirectory ./backups/production -DataProfile Business`.
This keeps names, customer/project references, contacts, rates, budgets, descriptions,
invoices, templates, preferences, custom fields, roles and enabled flags. It removes
passwords, API/reset tokens, MFA secrets and sessions, selects local authentication,
and creates the independent `test-admin`. Imported accounts keep their original
enabled flags for API/filter testing but have no usable imported login credentials.
Before and after credential removal, SHA-256 fingerprints of every retained field
must match. The default profile remains `Sanitized`.

The business profile is a confidential database copy for authorized private use.
The source backup stays byte-for-byte unchanged. The test copy differs in known
authentication fields, the new local administrator and any required schema migrations.
Files/uploads and plugin code are not part of this database restore. Business free
text, metadata and configuration are preserved; these arbitrary values are not
certified secret-free. Review them before distribution. The isolated network, disabled
mail/SAML and localhost binding apply to both profiles. Adding an integration's
outbound connection is a separate setup step.

The script creates `.env.test` with independent random secrets, verifies the manifest's SQL chunk checksums and sequence, builds the test image, and replaces only the two dedicated test volumes. It uses the explicit `default` Docker context and refuses remote Docker endpoints or volumes belonging to another project. The SQL is imported using the database-scoped `kimai` account. The import container rechecks SQL checksums, and raw SQL/error output is withheld.

The application stays stopped while a standalone PDO script checks and sanitizes the database, without booting plugins against raw data. It then runs Kimai migrations, creates a fresh test administrator, verifies the result and marks the volume ready. The web container refuses startup without this marker. Import/sanitization/preparation failures remove the test containers and volumes, including the raw copy. If Docker itself becomes unavailable during cleanup, restore Docker and run the test-only cleanup command below before starting anything. The source backup remains untouched.

| Data | Test treatment |
| --- | --- |
| Users, customers, projects, activities, teams, tags | Names replaced with ID-based placeholders; contact details and free text cleared |
| Imported user credentials | Passwords, API tokens, reset tokens, MFA secrets and sessions removed; imported accounts disabled |
| Roles and permissions | Imported accounts receive `ROLE_USER`; custom role/permission records removed |
| Configuration, preferences, comments, custom fields, bookmarks | Removed, including integration settings and arbitrary values |
| Invoices, invoice/export templates and uploaded files | Excluded/removed; source attachments and generated documents are never restored |
| Rates and budgets | Synthetic rates of 100, internal rates of 50, zero budgets; timesheet amounts recalculated from duration or a synthetic fixed rate |
| IDs, relationships, dates, durations, team membership and working-time structure | Retained and checked for broken foreign keys; timesheet count and total duration verified unchanged |

This retains real scheduling patterns and stable IDs, so keep access limited to the test team. It is a sanitized test copy, not a guarantee that individuals cannot be inferred from working patterns. Currency/language/customer timezone defaults become SEK/en/Europe-Stockholm; timesheet timezones are retained and validated.

Sign in at `http://localhost:8002` as **test-admin**, using `TEST_ADMIN_PASSWORD` in `deploy/.env.test`. Imported users cannot sign in. Change `TEST_PORT` before the restore if port 8002 is occupied. SSO testing can be prepared separately using dedicated test identities.

Repeat the same restore command whenever test needs refreshing. After source changes it rebuilds automatically; use `-SkipBuild` when deliberately reusing an already-built test image. Every refresh discards test edits/files, empties the test volumes, reimports and sanitizes again. It does not refresh continuously from production. The script supports the backup script's two-letter chunk suffixes (up to 676 chunks).

Start, stop or discard only this test project with explicit options:

```powershell
docker --context default compose --project-name test-kimai --env-file .env.test -f compose.test.yaml up -d --wait sqldb kimai proxy
docker --context default compose --project-name test-kimai --env-file .env.test -f compose.test.yaml stop
docker --context default compose --project-name test-kimai --env-file .env.test -f compose.test.yaml down --volumes
```

After building the image, the integration suite creates its own synthetic production-format backups, checks successful/repeated restores, sensitive-value removal, preserved relationships/durations, checksum/missing-chunk guards, unsupported schema and orphan rejection, and failure cleanup. It requires an empty `test-kimai` project and never reads a production backup:

```powershell
./scripts/Initialize-Environment.ps1 -Mode Test
docker --context default compose --env-file .env.test -f compose.test.yaml build kimai
./tests/Test-Restore.ps1
```

`./tests/Test-BusinessProfile.ps1` checks retained business values, repeatability,
unreviewed-column rejection and the anonymous default in a separate offline project.
It does not replace the running `test-kimai` environment.
