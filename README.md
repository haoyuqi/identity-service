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

## Administrator login

Administrators sign in at `/admin/login`. Only enabled administrator accounts
can open the protected `/admin` page. Sessions are stored in Redis; changing an
account password invalidates its existing sessions. Sign out with the
dashboard's POST form.

## Account administration

Active administrators can manage accounts at `/admin/users`: browse the
paginated account list, create an account with an explicit role and initial
password, view account details and audit history, and disable another account.
The service prevents disabling the current administrator and the last enabled
administrator. Account creation and disabling are recorded atomically in the
administrative audit log. Role changes, account deletion, re-enabling, and
public registration are not available in this version.

## Production operations

### Runtime configuration

Build the application image from this repository and run it behind a TLS
terminating reverse proxy on port `8080`. The container runs Apache as a
non-root user and serves only Laravel's `public/` directory. Keep PostgreSQL 18
and Redis on private networks reachable by the application. Set `APP_ENV` to
`production`, `APP_DEBUG` to `false`, and `LOG_CHANNEL` to `stderr`. Supply a
stable, randomly generated `APP_KEY` and PostgreSQL/Redis credentials through
the deployment platform's secret facility. Never pass secrets as image build
arguments or bake them into the image. Set `SESSION_DRIVER=redis`,
`SESSION_ENCRYPT=true`, and `SESSION_SECURE_COOKIE=true`; the default idle
session lifetime is 120 minutes. Serve the application only through HTTPS.
Laravel must trust only the addresses or CIDRs of the actual reverse proxies so
it can safely recognize the original HTTPS scheme. Add the proxy's explicit
addresses to the existing `withMiddleware` callback in `bootstrap/app.php`:

```php
->withMiddleware(function (Middleware $middleware): void {
    $middleware->trustProxies(at: ['10.20.0.0/16']);
})
```

Replace the example CIDR with the deployment's verified proxy range; do not
trust all addresses. Configure the proxy to forward the original scheme only
after the trusted proxy list is set.

The login form is served at `/admin/login`, the authenticated dashboard at
`/admin/`, and login submissions are rate limited. The container smoke workflow
builds the image and checks `/up` and `/admin/login` in CI.

### Database deployment and backup

Before deploying a new application version, take a PostgreSQL backup and apply
the committed migrations using the new image:

```sh
docker build --tag identity-service:deployment .
pg_dump --format=custom --file=identity-service.dump --dbname="$PGDATABASE"
docker run --rm --env-file /secure/runtime.env identity-service:deployment php artisan migrate --force
```

Supply PostgreSQL client connection settings (`PGHOST`, `PGPORT`, `PGUSER`,
`PGPASSWORD`, and `PGDATABASE`) from the deployment's secret facility when
running the database client commands. Store backups in access-controlled
encrypted storage and verify restores on a separate database. To restore,
provision an empty recovery database and run:

```sh
pg_restore --clean --if-exists --no-owner --dbname="$PGDATABASE" identity-service.dump
```

This replaces objects in the target database. Promote
the new application only after migrations and health checks succeed. Keep the
previous image available for rollback. If a migration is not compatible with
the previous image, restore the corresponding database backup before rolling
back application code.

Redis holds encrypted Laravel sessions with a configured idle lifetime of 120
minutes by default. Use a private Redis service with persistence and backups
appropriate to the deployment's recovery objectives. A database restore can
revert disabled-account state or password hashes, while restoring an old Redis
snapshot can revive sessions. After restoring the database, point every
application instance to a fresh, isolated Redis session namespace (or a new
Redis instance/database with no prior session data) before bringing the
application back into service. Also generate a new `APP_KEY`, deploy it to all
instances, and remove any previous-key decryption fallback. This ensures
restored account state cannot reuse old encrypted sessions; users must
authenticate again. Do not restore the old Redis session data as part of this
recovery.

Preserve the same `APP_KEY` across normal deployments and rollbacks. Rotating
it during ordinary releases makes existing encrypted session data unreadable,
requiring users to authenticate again; coordinate rotations as deliberate
security operations.

### First administrator and password recovery

After the database migrations are applied, create the first administrator from
a trusted interactive shell in the application container:

```sh
docker exec -it <application-container> php artisan identity:admin:bootstrap
```

The command requests the name and email address visibly, then reads the
password and its confirmation through hidden input. It does not accept a
password argument. Run it only through a secured operator session. To reset an
account password, use the same protected process:

```sh
docker exec -it <application-container> php artisan identity:admin:reset-password
```

The reset command also uses hidden confirmed input. Neither command prints
passwords. If interactive access is unavailable, use a secured operator session
or connect to the container's terminal; do not substitute credentials in
command arguments or shell history. Account changes and their audit records
are committed atomically.

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
