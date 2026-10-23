# Contributing

Focused bug reports, documentation updates, and pull requests are welcome.
Search existing issues before proposing a new feature. Discuss changes to
security boundaries or public interfaces before implementing them.

## Documentation map

| Topic | Reference |
| --- | --- |
| Implemented components and execution paths | [Architecture](docs/architecture.md) |
| Naming, types, and tests | [Code style](docs/code-style.md) |
| Agent reading order | [AGENTS.md](AGENTS.md) |
| Setup and local commands | [README](README.md) |

## Development and verification

Follow [Getting started](README.md#getting-started) for local setup and use a
dedicated PostgreSQL 18 database for tests. The configured test database name
is `identity_service_test`.

Run the repository checks from its root:

```sh
composer validate --strict
vendor/bin/pint --test
vendor/bin/phpstan analyse --no-progress
composer test:coverage
composer audit --locked --no-interaction
```

CI also enforces 100% line coverage across every file in [app/](app/). The
`composer test:coverage` script runs the coverage check. Do not satisfy the gate
with trivial assertions, blanket exclusions, or untested application files.
Fix formatting with `vendor/bin/pint` and fix static-analysis findings at their
source. Documentation-only changes need link, example, and formatting checks
rather than an application test run. The coverage command runs the suite and
requires the PCOV or Xdebug coverage driver.

For each review or handoff, record the verified commit SHA, runtime versions,
checks run, and their results. Include test pass/skip counts when available,
and identify checks that failed or were not run with the reason. Recheck the
current commit after adding commits; cancelled or incomplete checks are not
passes.

## Test database safety

### Local configuration

Create the ignored `.env.testing` file in the repository root with a fresh
application key and the local PostgreSQL connection values. `.env.testing` is
covered by the repository's `.env.*` ignore rule. Use a different generated key
for this environment and keep it private:

```dotenv
APP_ENV=testing
APP_KEY=
TEST_DB_HOST=127.0.0.1
TEST_DB_PASSWORD=
```

Generate the key after creating the file:

```sh
APP_ENV=testing php artisan key:generate
```

Set `TEST_DB_HOST` to `127.0.0.1` when PostgreSQL is reachable on the local
machine, or `postgres` when it is reachable by that network name. Set
`TEST_DB_PASSWORD` to the local password for the test role. Do not put real
passwords or generated keys in this guide or another tracked file.

Create a dedicated, non-superuser role once on the local PostgreSQL 18 server:

```sql
CREATE ROLE identity_service_test LOGIN CREATEDB NOSUPERUSER;
```

Set its password interactively in `psql` with `\password identity_service_test`,
then place that password in the ignored `.env.testing`. The test role needs
`CREATEDB` so the safety guard can create the test database if it is absent.
The guard creates only `identity_service_test`, assigns ownership to
`identity_service_test`, and rejects the database if another role owns it.
Laravel tests using `RefreshDatabase` then apply migrations and isolate database
changes.

Administrator session feature tests require a Redis test namespace or
dedicated Redis service. Set `REDIS_TEST_HOST`, `REDIS_TEST_PORT`, and
`REDIS_TEST_DATABASE` to its connection; optional credentials use
`REDIS_TEST_USERNAME` and `REDIS_TEST_PASSWORD`. A local development Redis may
be shared only when tests use a unique database or key namespace and it is not
used by production. Each test gets a random session key prefix; tests verify
their own session keys and TTLs, allow keys to expire, and never flush Redis.

### Safety checks

The guard checks the PostgreSQL server version (18.x), connection, host, port,
database, and role before test setup. The only permitted test hosts are
`postgres` and `127.0.0.1`; the port is `5432`. The role and database name must
both be `identity_service_test`, and the database must be owned by that role.
Keep the role as a non-superuser. `phpunit.xml` fixes the test connection to
PostgreSQL and forces the dedicated database name, role, and port. It leaves
the host and password available to local test configuration. Never use shared
or production credentials. CI provides its own isolated PostgreSQL 18 service.

## Branches and commits

Name branches `<type>/<issue-number>-<kebab-case-description>`, for example
`feat/1-initialize-laravel`. Create feature branches from the latest `main`.

Use Conventional Commit subjects in this form:

```text
<type>(<scope>): <imperative English summary>
```

The scope is optional. Supported types are `feat`, `fix`, `docs`, `refactor`,
`test`, `style`, `chore`, and `ci`. Begin the summary with a lowercase letter,
omit a trailing period, and keep the complete subject within 72 characters.
Use `!` only for breaking changes and describe the compatibility impact.

Submit changes through a pull request into `main`; do not commit or push directly
to `main`. Do not introduce `develop` or version branches for v0.1.

## Pull requests and review

Keep each change focused and explain its effect on interfaces, data, security,
compatibility, and failure behavior as applicable. Link relevant issues and
update the owning documentation when implemented behavior changes. Preserve
unrelated work and keep credentials, private data, and local environment files
out of commits.

For code review, trace the complete path from each affected entry point through
callers, services, persistence, outputs, and side effects. Check related entry
points and boundary cases against the implementation and tests. State which
paths and behaviors were verified, and call out remaining uncertainty instead
of treating documentation as implementation evidence.
