<h1 align="center">Identity Service</h1>

<p align="center">
  <a href="https://github.com/haoyuqi/identity-service/actions/workflows/ci.yml"><img alt="CI" src="https://github.com/haoyuqi/identity-service/actions/workflows/ci.yml/badge.svg?branch=main"></a>
  <a href="https://github.com/haoyuqi/identity-service/actions/workflows/container-smoke.yml"><img alt="Container smoke test" src="https://github.com/haoyuqi/identity-service/actions/workflows/container-smoke.yml/badge.svg?branch=main"></a>
  <a href="https://laravel.com/docs/13.x"><img alt="Laravel 13" src="https://img.shields.io/badge/Laravel-13-FF2D20?logo=laravel&logoColor=white"></a>
  <a href="https://github.com/haoyuqi/identity-service/releases/latest"><img alt="Latest release" src="https://img.shields.io/github/release/haoyuqi/identity-service.svg"></a>
  <a href="LICENSE"><img alt="License: MIT" src="https://img.shields.io/badge/license-MIT-blue.svg"></a>
</p>

<p align="center">
  English | <a href="README.zh-CN.md">简体中文</a>
</p>

Identity Service is a private administrator console built on Laravel 13. Accounts and audit records live in PostgreSQL 18; sessions live in Redis.

There is no public registration, self-service password reset or token API. Administrators are created from a trusted shell.

## Requirements

- PHP 8.4.27 or later, or 8.5.12 or later on PHP 8.5, with `pdo_pgsql` and `redis`
- Composer 2
- PostgreSQL 18
- Redis

## Getting started

```sh
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
```

Fill in the connections in `.env`, then serve the `public/` directory behind TLS. Sign-in starts at `/admin/login`.

## Configuration

`.env.example` holds working local values; production secrets belong in the platform secret store.

| Variable | Purpose |
| --- | --- |
| `APP_KEY` | Encrypts session data. Keep it across deployments. |
| `APP_URL` | Public origin behind the reverse proxy. |
| `DB_*` | PostgreSQL 18 connection. |
| `SESSION_DRIVER`, `SESSION_CONNECTION` | Redis session storage. |
| `SESSION_LIFETIME` | Idle lifetime in minutes. |
| `SESSION_ENCRYPT`, `SESSION_SECURE_COOKIE` | Keep both `true` outside local HTTP development. |
| `REDIS_*` | Redis endpoint and credentials. |
| `CACHE_STORE`, `REDIS_CACHE_DB` | Redis cache on its own database. |
| `LOG_CHANNEL` | `stderr` in production. |

## Administrator commands

| Command | Purpose |
| --- | --- |
| `php artisan identity:admin:bootstrap` | Create the first administrator. |
| `php artisan identity:admin:reset-password` | Set a new password for an account. |

Both hide the password input and never accept it as an argument. Each change commits with its audit row in one transaction.

## Access rules

- Only enabled administrators reach `/admin`; others are sent to `/admin/login`.
- Login allows five attempts per minute per email and address, and twenty-five per address.
- Changing a password invalidates existing sessions.
- Administrators cannot disable themselves or the last enabled administrator.

## Testing

| Command | Purpose |
| --- | --- |
| `composer validate --strict` | Validate the Composer manifest. |
| `vendor/bin/pint --test` | Check formatting. |
| `vendor/bin/phpstan analyse --no-progress` | Run Larastan at level 5. |
| `composer test:coverage` | Run the suite and require 100% `app/` line coverage. |
| `composer audit --locked --no-interaction` | Audit locked dependencies. |

Tests need a dedicated PostgreSQL 18 role and database:

```sql
CREATE ROLE identity_service_test LOGIN CREATEDB NOSUPERUSER;
```

Set `.env.testing` from `.env.example` with a fresh `APP_KEY`, `TEST_DB_HOST` and `TEST_DB_PASSWORD`. The guard requires port `5432` and the `identity_service_test` database and role. CI requires exactly 100% line coverage for `app/`.

## Deployment and operations

- Run as a non-root user on port 8080 behind TLS, and trust only explicit proxy ranges in `bootstrap/app.php`, never `*`.
- Keep sessions and cache on separate Redis databases; `cache:clear` flushes its database and would sign everyone out.
- Back up with `pg_dump`, keep `APP_KEY` outside the backup, and rotate it after a restore.
- Use `/up` to decide restarts and `/ready` to decide traffic.
- Publish by pushing a `vX.Y.Z` tag on `main`. The workflow runs CI and the container smoke test before creating the release; a manual dispatch only previews the notes.

## License

Project code is licensed under the [MIT license](LICENSE).
