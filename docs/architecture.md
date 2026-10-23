# Architecture and feature map

Identity Service is a Laravel 13 application for private administrative account
management. This reference describes the implemented baseline; it does not imply
that account-management features beyond the current source are already present.

## Boundaries

For application behavior, keep HTTP controllers and console commands at the
boundary: validate and authorize inputs there, then delegate business work to
services. Services coordinate operations and use Eloquent for persistence.
There is no Repository layer. Render administrative pages with Blade. Session
authentication is stateful and uses Redis-backed Laravel sessions; Passport
and public token authentication are outside this service's session-only scope.

## Find implemented behavior

| Concern | Source of truth |
| --- | --- |
| HTTP routes and bootstrap | [web routes](../routes/web.php), [application bootstrap](../bootstrap/app.php) |
| Controller boundary | [base controller](../app/Http/Controllers/Controller.php) |
| Eloquent model | [User](../app/Models/User.php) |
| Persistence schema | [initial migrations](../database/migrations/0001_01_01_000000_create_users_table.php) |
| Runtime configuration | [database](../config/database.php), [session](../config/session.php), [environment example](../.env.example) |
| Test setup and database guard | [test case](../tests/TestCase.php), [database safety guard](../tests/Support/TestDatabaseGuard.php), [PHPUnit configuration](../phpunit.xml) |
| Health and persistence tests | [health check](../tests/Feature/HealthCheckTest.php), [user model](../tests/Feature/UserModelTest.php) |
| Coverage gate | [coverage test](../tests/Unit/CoverageGateTest.php), [coverage verifier](../scripts/verify-coverage.php), [CI workflow](../.github/workflows/ci.yml), [Composer scripts](../composer.json) |

Follow the actual source and tests when locating a feature. Keep this map focused
on implemented behavior and update it when the code structure changes.

## Runtime and data

The application targets PHP 8.4 and Laravel 13. PostgreSQL 18 is the relational
database target. Laravel sessions use Redis. Local and CI tests use a dedicated
PostgreSQL database named `identity_service_test`; test setup validates its
connection, host, and role before migrations or other destructive database
setup. Test cases use Laravel's database isolation facilities.

The current baseline does not implement public registration, seeded
demonstration credentials, production infrastructure provisioning, or
OAuth/OIDC/token endpoints. Those are outside this issue's scope.

## Documentation maintenance

Keep each fact in one owning reference and link to it elsewhere. Update this
document when implemented responsibilities or execution paths change. The
[code style](code-style.md) owns naming and readability rules;
[Contributing](../CONTRIBUTING.md) owns development, verification, and review
requirements.
