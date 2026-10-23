# Identity Service

An isolated Laravel 13 service for private administrative account management.
The repository is public; the application is intended for a private
administrative runtime. It does not provide public registration or token APIs.

## Requirements

- PHP 8.4 with the extensions required by Laravel and the locked Composer packages
- Composer 2
- PostgreSQL 18
- Redis for Laravel sessions

## Getting started

```sh
composer install
cp .env.example .env
php artisan key:generate
```

Configure the local `.env` with the application URL, PostgreSQL connection, and
Redis session connection. Keep credentials in this ignored local file or another
secret store; never commit them. For local development over plain HTTP only,
set `SESSION_SECURE_COOKIE=false` in `.env`; keep it enabled for HTTPS. The
health endpoint is `/up`.

## Administrator commands

Create the first administrator from a controlled environment with:

```sh
php artisan identity:admin:bootstrap
```

To reset an account password, run:

```sh
php artisan identity:admin:reset-password
```

Both commands prompt for account details and read passwords through hidden
prompts with confirmation. Passwords are not command-line arguments. Bootstrap
normalizes the email address, refuses to create a second enabled administrator,
and serializes concurrent attempts. Each successful account change and its
audit record commit together. The commands display completion status only;
they never display account credentials or password values. See [the architecture map](docs/architecture.md)
for the implementation paths.

## Verification

Run the checks used by the repository before proposing a change:

```sh
composer validate --strict
vendor/bin/pint --test
vendor/bin/phpstan analyse --no-progress
composer test:coverage
composer audit --locked --no-interaction
```

The coverage command runs the test suite and requires PCOV or Xdebug.
The test suite targets a dedicated PostgreSQL 18 database named
`identity_service_test`. Configure its connection using the test environment
documented in [Contributing](CONTRIBUTING.md#test-database-safety). The test
database guard validates the connection, host, and role before database setup.
Do not point tests at a shared or production database. CI enforces 100% line
coverage for all files under [app/](app/); see
[Contributing](CONTRIBUTING.md#development-and-verification) and the
[coverage verifier](scripts/verify-coverage.php).

## Engineering references

- [Architecture and feature map](docs/architecture.md)
- [Code style](docs/code-style.md)
- [Contributing](CONTRIBUTING.md)
- [Agent guide](AGENTS.md)
