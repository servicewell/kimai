# Docker and Microsoft Entra SAML

This setup builds this fork from the checked-out source. It runs Kimai behind Caddy, with MySQL and persistent Docker volumes for the database, uploaded files, and plugins.

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

Use a separate checkout on the production server. The domain must resolve to that server and TCP ports 80 and 443 must reach Caddy. Caddy manages the public certificate and persists its state in production-only Docker volumes. MySQL and Kimai have no directly published ports.

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

For a machine that already runs the earlier Docker workspace beside a nested `source/` checkout, keep its running configuration until the migration is prepared. Back up the database, then transfer its private `.env`, generated metadata, and development TLS files locally into the corresponding ignored paths under `deploy/`. Preserve the existing database passwords and use the same `kimai-local` project name to retain the existing data volumes. Add the public Entra settings from `.env.example` if they are absent. Generating new database passwords for an existing MySQL volume will not change the database's stored passwords.

Useful commands:

```powershell
docker compose ps
docker compose logs --tail 100 kimai
docker compose stop
```

`docker compose down` retains named data volumes. `docker compose down -v` deletes them; use that only when intentionally discarding that project's data.

References: [Kimai Docker Compose](https://www.kimai.org/documentation/docker-compose.html), [Kimai Entra SAML](https://www.kimai.org/documentation/saml-azure.html).
