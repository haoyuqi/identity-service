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

An isolated Laravel 13 service for private administrative accounts. The repository is public; the application runs privately and exposes no registration or token API.

## Requirements

- PHP 8.4.27 or later, or 8.5.12 or later, with `pdo_pgsql` and `redis`
- Composer 2
- PostgreSQL 18
- Redis, used for administrator sessions

## Getting started

```sh
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
```

Set the PostgreSQL and Redis connections in `.env`. `GET /up` proves that the process serves requests; it deliberately does not touch either dependency.

## Configuration

| Variable | Purpose |
| --- | --- |
| `APP_KEY` | At-rest key for encrypted sessions. Preserve it across normal deployments. |
| `APP_URL` | Public HTTPS origin. |
| `DB_*` | PostgreSQL 18 connection. |
| `SESSION_DRIVER`, `SESSION_CONNECTION` | `redis`, on the `default` Redis connection. |
| `SESSION_LIFETIME`, `SESSION_ENCRYPT`, `SESSION_SECURE_COOKIE` | `120`, `true`, `true` in production. |
| `REDIS_HOST`, `REDIS_PORT`, `REDIS_PASSWORD` | Redis endpoint and credentials. |
| `CACHE_STORE`, `REDIS_CACHE_DB` | `redis`; the cache store uses the `cache` connection and its own database. |
| `LOG_CHANNEL` | `stderr` in production. |

## Administrator commands

```sh
php artisan identity:admin:bootstrap       # first administrator; refuses a second enabled one
php artisan identity:admin:reset-password  # reset an account password
```

Both read passwords through hidden confirmed prompts, never accept a password argument, and commit the account change with its audit row in one transaction.

## Access rules

- Sign in at `/admin/login`; only enabled administrators reach `/admin`.
- Login allows 5 attempts per minute per email/address pair and 25 per minute per address.
- A password change invalidates existing sessions; this relies on Redis-backed sessions.
- Administrators cannot disable themselves or the last enabled administrator.

## Verification

```sh
composer validate --strict
vendor/bin/pint --test
vendor/bin/phpstan analyse --no-progress
composer test:coverage
composer audit --locked --no-interaction
```

Tests need PostgreSQL 18 with a dedicated role and database:

```sql
CREATE ROLE identity_service_test LOGIN CREATEDB NOSUPERUSER;
```

Create the ignored `.env.testing` with a fresh `APP_KEY`, `TEST_DB_HOST` (`127.0.0.1` or `postgres`) and `TEST_DB_PASSWORD`. The guard requires port `5432`, the `identity_service_test` database and role, a PostgreSQL 18 server, and that role's ownership of the database. Session tests use a Redis test namespace configured by `REDIS_TEST_*` and never flush it.

CI requires exactly 100% line coverage for `app/`; `composer test:coverage` applies the same gate locally.

## Operations

- Run the image as non-root on port 8080 behind TLS, and trust only explicit proxy CIDRs: `$middleware->trustProxies(at: ['10.20.0.0/16'])`, never `*`.
- Keep sessions and cache on different Redis databases: `cache:clear` issues `FLUSHDB` and would delete every administrator session.
- Back up with `pg_dump` and restore into a separate database before switching over. Keep `APP_KEY` outside the backup; after a restore, rotate it and start from a session namespace with no prior data.
- Use `/up` for restart decisions and `/ready` for traffic decisions.
- Push a `vX.Y.Z` tag on `main` to publish a release; a manual dispatch only previews the generated notes.

## License

Project code is MIT licensed. Third-party content keeps its own rights.
