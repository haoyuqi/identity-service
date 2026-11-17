# Docker deployment

The [Dockerfile](../Dockerfile) produces a non-root application image on port
8080. A tagged release publishes `ghcr.io/haoyuqi/identity-service:vX.Y.Z`
for `linux/amd64` and `linux/arm64`. The release workflow waits for both architecture smoke checks and
image publication before it creates a GitHub Release. The image does not contain
credentials or development dependencies, and startup does not migrate, create an
administrator, or generate a key.

The [Compose file](../compose.yaml) defaults `IDENTITY_VERSION` to `latest`. Set it to a
published `vX.Y.Z` tag for predictable upgrades and rollback. Use the immutable image digest
reported by GHCR when operating the image outside Compose. Compose binds port
8080 to `127.0.0.1:8081` by default; put an HTTPS reverse proxy in front and
set `APP_URL` to the public HTTPS origin. Do not expose PostgreSQL or Redis ports.
Set `TRUSTED_PROXIES` to the reverse proxy's exact address or CIDR (comma separated)
and have the proxy sanitize forwarded headers before accepting traffic; never
trust every address. `/up` checks process health, while `/ready`
checks PostgreSQL and Redis before traffic is sent.

## Choose dependencies

Copy `compose.env.example` to the ignored `.env.compose`, replace `APP_KEY` and
`DB_PASSWORD`, and restrict that file's permissions. Generate the key with
`openssl rand -base64 32` and prefix it with `base64:`; keep the same key across
upgrades and restores. Never commit credentials or use an example password.

`--profile database` starts the bundled PostgreSQL 18.6 and stores its data in
`postgres_data`. Without that profile, supply an existing PostgreSQL 18 endpoint
through `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` and
`DB_SSLMODE`. Use an identity-only database and role; do not share the wallpaper
database or its credentials. The bundled database uses those same values for
initial creation; changing them after its volume is initialized does not rename
the existing database or rotate its password.

`--profile redis` starts bundled Redis with append-only persistence in
`redis_data`. Without that profile, supply `REDIS_HOST`, `REDIS_PORT`, and, if
required, `REDIS_USERNAME` and `REDIS_PASSWORD` for an existing Redis endpoint.
If an external provider needs TLS, set `REDIS_URL=rediss://...` in `.env.compose`.
Omit the URL's database path so `REDIS_DB` and `REDIS_CACHE_DB` remain distinct.
Bundled Redis uses plain host/port on the private Compose network. Clearing the
cache database otherwise destroys administrator sessions. A shared external
Redis should also use a service-specific key prefix and isolated databases.

For both bundled dependencies:

```sh
docker compose --env-file .env.compose --profile database --profile redis up -d postgres redis
docker compose --env-file .env.compose --profile database --profile redis run --rm app php artisan migrate --force
docker compose --env-file .env.compose --profile database --profile redis up -d app
docker compose --env-file .env.compose --profile database --profile redis run --rm app php artisan identity:admin:bootstrap
```

Select only the needed profile(s); with external PostgreSQL and Redis, omit
both. The configured external addresses must be reachable from the container.
Migrations and initial administrator creation are explicit, interactive steps.
The administrator command hides password input. Verify `/up`, `/ready` and
`/admin/login` through the configured proxy. No public account creation exists.

## Upgrades and recovery

Back up PostgreSQL with `pg_dump`, prove a restore into a separate database, and
protect the stable `APP_KEY` separately. Preserve `postgres_data` and
`redis_data`; `docker compose down` keeps volumes, while `down --volumes` deletes
them. Sessions are lost if Redis data is discarded. Run new migrations
explicitly before starting the new app version. Set `IDENTITY_VERSION` to the
new `vX.Y.Z`, then pull and recreate only the application. To roll back, set
the prior version and verify schema compatibility first; restore a compatible
database backup if needed. A container restart neither changes the key nor
creates accounts or runs migrations.

The release workflow refuses to replace an existing version tag with a different
source revision, and verifies both architectures before creating a release.
Use image digests for deployment records; GHCR administrators can still change
tags outside this workflow. The CI Compose check validates the four dependency choices and boots the fully
bundled stack against disposable resources. It does not provision a production
server or prove a particular external provider's network and TLS settings.
