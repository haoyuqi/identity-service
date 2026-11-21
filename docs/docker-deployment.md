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

## Environment file

Copy [compose.env.example](../compose.env.example) to the ignored `.env` and fill
it in:

```sh
cp compose.env.example .env
chmod 600 .env
openssl rand -base64 32   # prefix the output with base64: for APP_KEY
```

Compose uses `.env` twice: for `${...}` interpolation in the Compose file and,
through the app's `env_file`, as container environment. `COMPOSE_ENV_FILE`
selects a different path. The entry is declared `required: false`, so CI without
an env file still parses, and variables exported by the CI script reach the
container through the explicit `environment` mapping. Keep the same `APP_KEY`
across upgrades and restores. Never commit credentials or use an example
password.

`COMPOSE_PROFILES` selects bundled dependencies and is equivalent to passing
`--profile`: empty uses external PostgreSQL and Redis, `database` or `redis`
starts one of them, and `database,redis` starts both.

Existing deployments that keep `.env.compose` must set
`COMPOSE_ENV_FILE=.env.compose` and continue passing `--env-file .env.compose`
so interpolation and `env_file` read the same file. Do not enable a bundled
profile while its network is external: the local service would join the shared
network. Leave the corresponding `COMPOSE_PROFILES` entry out in that case.

## Choose dependencies

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
If an external provider needs TLS, set `REDIS_URL=rediss://...` in `.env`.
Omit the URL's database path so `REDIS_DB` and `REDIS_CACHE_DB` remain distinct.
Bundled Redis uses plain host/port on the private Compose network. Clearing the
cache database otherwise destroys administrator sessions.

A shared external Redis must allocate both databases explicitly. Sessions use
`REDIS_DB` and the cache uses `REDIS_CACHE_DB`; the defaults `0` and `1` usually
collide with other applications on the same instance. Keep the two values
different, because `cache:clear` flushes its whole database. Give the service
its own keys as well, for example `REDIS_PREFIX=identity:`, `CACHE_PREFIX=cache:`,
sessions on `REDIS_DB=4` and cache on `REDIS_CACHE_DB=5`. The prefixes keep keys
identifiable and let a Redis ACL user restrict this application to them.

## External networks and ingress

The Compose file declares `postgres`, `redis` and `ingress` networks. By default
each is created per project as `<project>_postgres`, `<project>_redis` and
`<project>_ingress`. To join networks owned by a host stack, set
`POSTGRES_NETWORK_EXTERNAL`, `REDIS_NETWORK_EXTERNAL` and
`INGRESS_NETWORK_EXTERNAL` to `true` and name the existing network, for example
`POSTGRES_NETWORK=infra-postgres-private`,
`REDIS_NETWORK=infra-redis-private` and `INGRESS_NETWORK=ingress-nginx_default`.
Compose then refuses to start if a named external network is missing instead of
creating an isolated replacement.

The app joins `default`, `postgres`, `redis` and `ingress`; the bundled
PostgreSQL and Redis join only their own network. On `ingress` the app answers
to `identity-upstream`, so the reverse proxy can forward to
`http://identity-upstream:8080` while `.env` keeps the published port bound to
`127.0.0.1`. Set `TRUSTED_PROXIES` to that network's CIDR, not to a hostname or
`*`.

## Runtime limits and mounted configuration

`IDENTITY_MEMORY_LIMIT` and `IDENTITY_CPU_LIMIT` cap the app container, and
`DOCKER_LOG_MAX_SIZE` and `DOCKER_LOG_MAX_FILES` rotate its `json-file` logs;
bundled PostgreSQL and Redis get smaller fixed defaults. Apache and PHP settings
are mounted read-only from the repository, so changing them needs a container
recreate, not an image rebuild:

- [docker/apache-limits.conf](../docker/apache-limits.conf) loads after
  `mods-enabled` as `zz-limits.conf` and lowers prefork from the default 150
  workers to a small fixed pool.
- [docker/php-production.ini](../docker/php-production.ini) disables error
  display, sends `error_log` to the container's stderr, caps request size, and
  enables OPcache without timestamp validation. Because OPcache does not check
  file timestamps, code changes ship as a new image and restart, never as
  in-place edits.

The image stays at the published `v0.2.0` tag; these files are configuration
mounted over it.

## Quick start

Copy the env file, then start the dependencies, migrate, start the app and
create the first administrator:

```sh
docker compose --profile database --profile redis up -d postgres redis
docker compose --profile database --profile redis run --rm app php artisan migrate --force
docker compose --profile database --profile redis up -d app
docker compose --profile database --profile redis run --rm app php artisan identity:admin:bootstrap
```

Setting `COMPOSE_PROFILES=database,redis` in `.env` makes the flags optional.
Select only the needed profile(s); with external PostgreSQL and Redis, leave
both unset. The configured external addresses must be reachable from the
container. Migrations and initial administrator creation are explicit,
interactive steps. The administrator command hides password input. Verify
`/up`, `/ready` and `/admin/login` through the configured proxy. No public
account creation exists.

## Upgrades and recovery

Back up PostgreSQL with `pg_dump`, prove a restore into a separate database, and
protect the stable `APP_KEY` separately. Preserve `postgres_data` and
`redis_data`; `docker compose down` keeps volumes, while `down --volumes` deletes
them. Sessions are lost if Redis data is discarded. Run new migrations
explicitly before starting the new app version. Set `IDENTITY_VERSION` to the
new `vX.Y.Z`, then pull and recreate only the application. To roll back, set
the prior version and verify schema compatibility first; restore a compatible
database backup if needed. A container restart neither changes the key nor
creates accounts or runs migrations. Because OPcache does not validate
timestamps, always recreate the app container after changing its image.

The release workflow refuses to replace an existing version tag with a different
source revision, and verifies both architectures before creating a release.
Use image digests for deployment records; GHCR administrators can still change
tags outside this workflow. The CI Compose check validates the four dependency
choices and boots the fully bundled stack against disposable resources. It does
not provision a production server or prove a particular external provider's
network and TLS settings.
