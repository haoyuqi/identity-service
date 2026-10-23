# Architecture and feature map

Identity Service is a Laravel 13 application for private administrative account
management. This reference describes the behavior implemented in the current
source.

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
| Administrator bootstrap and password reset | [bootstrap command](../app/Console/Commands/BootstrapAdministratorCommand.php), [password reset command](../app/Console/Commands/ResetAdministratorPasswordCommand.php), [user management service](../app/Services/UserManagementService.php) |
| Accounts and roles | [User](../app/Models/User.php), [UserRole](../app/Enums/UserRole.php) |
| Audit records | [AdminAuditLog](../app/Models/AdminAuditLog.php) |
| Persistence schema | [initial migration](../database/migrations/0001_01_01_000000_create_users_table.php), [account and audit migration](../database/migrations/2026_10_23_000001_add_account_status_and_admin_audit_logs.php) |
| Administrator session login | [session controller](../app/Http/Controllers/SessionController.php), [authentication service](../app/Services/AuthenticationService.php), [login request](../app/Http/Requests/LoginRequest.php), [active administrator middleware](../app/Http/Middleware/EnsureActiveAdministrator.php) |
| Session pages | [login](../resources/views/auth/login.blade.php), [dashboard](../resources/views/admin/dashboard.blade.php) |
| Administrative account pages and actions | [account routes](../routes/web.php), [user controller](../app/Http/Controllers/Admin/UserController.php), [user policy](../app/Policies/UserPolicy.php), [account service](../app/Services/UserManagementService.php), [account views](../resources/views/admin/users) |
| Runtime configuration | [database](../config/database.php), [session](../config/session.php), [environment example](../.env.example) |
| Test setup and database guard | [test case](../tests/TestCase.php), [database safety guard](../tests/Support/TestDatabaseGuard.php), [PHPUnit configuration](../phpunit.xml) |
| Health, persistence, schema, and audit tests | [health check](../tests/Feature/HealthCheckTest.php), [user model](../tests/Feature/UserModelTest.php), [account schema](../tests/Feature/AccountAuditSchemaTest.php), [audit model](../tests/Feature/AdminAuditLogTest.php), [administrator commands](../tests/Feature/AdministratorCommandsTest.php) |
| Coverage gate | [coverage test](../tests/Unit/CoverageGateTest.php), [coverage verifier](../scripts/verify-coverage.php), [CI workflow](../.github/workflows/ci.yml), [Composer scripts](../composer.json) |

Follow the actual source and tests when locating a feature. Keep this map focused
on implemented behavior and update it when the code structure changes.

The bootstrap command creates an administrator only when none is enabled. Both
commands collect passwords through hidden interactive prompts and write their
audit record in the same database transaction as the account change. Bootstrap
uses a PostgreSQL transaction advisory lock to serialize first-admin creation;
the administrator-disable workflow must reuse the lock key in
[UserManagementService](../app/Services/UserManagementService.php).

## Runtime and data

The application targets PHP 8.4 and Laravel 13. PostgreSQL 18 is the relational
database target. Laravel sessions use Redis. Local and CI tests use a dedicated
PostgreSQL database named `identity_service_test`; test setup validates its
connection, host, and role before migrations or other destructive database
setup. Test cases use Laravel's database isolation facilities.

Accounts use Laravel's `users` table and retain `password_reset_tokens` for
future password recovery. Roles are `admin` or `member`, defaulting to `member`;
nullable `disabled_at` is the sole enabled/disabled state. Roles and disabled
status are not mass assignable. Email values are trimmed and lowercased by the
model, with canonical form and role values also constrained by PostgreSQL.
The additive migration normalizes existing emails; collisions fail rather than
discarding accounts.

User timestamps and audit `created_at` use PostgreSQL `timestamptz`. Connections
and model timestamp assignments normalize instants to UTC; legacy timestamps
are explicitly interpreted as UTC during migration.

Audit records contain a nullable actor, a required target account, an action,
and a creation timestamp. Foreign keys prevent removal of referenced accounts.
Model instances reject audit updates and deletion. This is application-level
append-only behavior, not protection against privileged raw SQL or bulk query
mutations. Business services must only insert audit records, in the same
transaction as the account operation. There is no database session table.

The current baseline does not implement public registration, seeded
demonstration credentials, production infrastructure provisioning, or
OAuth/OIDC/token endpoints. Those are outside this issue's scope.

Administrator sign-in is available at `/admin/login`. Login accepts enabled
administrator accounts only; unsuccessful attempts use the same generic
credential message and are limited to five attempts per minute. Successful
login regenerates the session ID and records Laravel's HMAC of the stored
password hash in the Redis-backed session. Each administrator request checks
that marker and delegates to Laravel's `auth.session` middleware so password
changes invalidate existing sessions. Disabled accounts and non-admin users
are logged out. Logout is a CSRF-protected POST that invalidates the session.

Active administrators can list, create, and view accounts under `/admin/users`.
Account creation accepts an explicit `admin` or `member` role and an initial
password; the form validates and normalizes names and email addresses, and
passwords are never flashed back after validation errors. Account disabling is
a CSRF-protected PATCH action. The controller authorizes requests with
`UserPolicy`, while `UserManagementService` rechecks the acting administrator
inside the transaction-scoped shared advisory lock, protects the final enabled
administrator, and commits each account change with its audit record. Account
pages display status and target-account audit history; they do not expose
password hashes or provide role changes, deletion, or re-enabling.

## Documentation maintenance

Keep each fact in one owning reference and link to it elsewhere. Update this
document when implemented responsibilities or execution paths change. The
[code style](code-style.md) owns naming and readability rules;
[Contributing](../CONTRIBUTING.md) owns development, verification, and review
requirements.
