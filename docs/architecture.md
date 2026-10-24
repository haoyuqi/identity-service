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
| Health and readiness probes | [readiness controller](../app/Http/Controllers/ReadinessController.php) |
| Request correlation | [request identifier middleware](../app/Http/Middleware/AssignRequestId.php) |
| HTTP error handling and response headers | [safe exception handler](../app/Support/SafeExceptionHandler.php), [security headers](../app/Http/Middleware/SecurityHeaders.php) |
| Controller boundary | [base controller](../app/Http/Controllers/Controller.php) |
| Administrator bootstrap and password reset | [bootstrap command](../app/Console/Commands/BootstrapAdministratorCommand.php), [password reset command](../app/Console/Commands/ResetAdministratorPasswordCommand.php), [user management service](../app/Services/UserManagementService.php) |
| Accounts and roles | [User](../app/Models/User.php), [UserRole](../app/Enums/UserRole.php) |
| Audited operations | [AuditAction](../app/Enums/AuditAction.php) |
| Audit records | [AdminAuditLog](../app/Models/AdminAuditLog.php) |
| Persistence schema | [initial migration](../database/migrations/0001_01_01_000000_create_users_table.php), [account and audit migration](../database/migrations/2026_10_23_000001_add_account_status_and_admin_audit_logs.php), [audit action constraint migration](../database/migrations/2026_10_24_000001_constrain_admin_audit_actions.php) |
| Administrator session login | [session controller](../app/Http/Controllers/SessionController.php), [authentication service](../app/Services/AuthenticationService.php), [login request](../app/Http/Requests/LoginRequest.php), [active administrator middleware](../app/Http/Middleware/EnsureActiveAdministrator.php) |
| Session pages | [login](../resources/views/auth/login.blade.php), [dashboard](../resources/views/admin/dashboard.blade.php) |
| Administrative account pages and actions | [account routes](../routes/web.php), [user controller](../app/Http/Controllers/Admin/UserController.php), [user policy](../app/Policies/UserPolicy.php), [account service](../app/Services/UserManagementService.php), [account views](../resources/views/admin/users) |
| Runtime configuration | [database](../config/database.php), [session](../config/session.php), [environment example](../.env.example) |
| Test setup and database guard | [test case](../tests/TestCase.php), [database safety guard](../tests/Support/TestDatabaseGuard.php), [PHPUnit configuration](../phpunit.xml) |
| Health, persistence, schema, and audit tests | [health check](../tests/Feature/HealthCheckTest.php), [user model](../tests/Feature/UserModelTest.php), [account schema](../tests/Feature/AccountAuditSchemaTest.php), [audit model](../tests/Feature/AdminAuditLogTest.php), [administrator commands](../tests/Feature/AdministratorCommandsTest.php) |
| Security response behavior | [security response tests](../tests/Feature/SecurityResponsesTest.php) |
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

Audit records contain a nullable actor, a required target account, an action
constrained to the [AuditAction](../app/Enums/AuditAction.php) values by both the
model and a database check constraint, and a creation timestamp. Foreign keys prevent removal of referenced accounts.
Model instances reject audit updates and deletion. This is application-level
append-only behavior, not protection against privileged raw SQL or bulk query
mutations. Business services must only insert audit records, in the same
transaction as the account operation. There is no database session table.

The current baseline does not implement public registration, seeded
demonstration credentials, production infrastructure provisioning, or
OAuth/OIDC/token endpoints. Those are outside this issue's scope.

Administrator sign-in is available at `/admin/login`. Login accepts enabled
administrator accounts only; unsuccessful attempts use the same generic
credential message and are limited to five attempts per minute per normalized
email address and client address, plus twenty-five attempts per minute per
client address, so one account's failures cannot lock out the others. Successful
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

HTTP responses include content-type sniffing, framing, referrer, cross-domain
policy, and same-origin content security policies, plus
`Strict-Transport-Security` on requests the application receives over HTTPS.
Administrative and login route responses are marked `private, no-store`,
including error responses finalized outside the middleware stack.

Every response carries `X-Request-Id`. The outermost middleware assigns it before
any other middleware runs, so error rendering and reporting can reference it; a
caller-supplied value is honored only when it matches a restricted pattern and is
otherwise replaced. Requests to `/admin/*` are authenticated and have their
session validated before route model binding, so an unauthenticated caller
cannot distinguish an existing account identifier from a missing one. In production, unexpected application and
database failures return a generic 500 response even when debug mode is
enabled; Redis client failures return a generic 503 in every environment.
HTTP exceptions retain their status and safe protocol headers while returning
generic bodies. Validation, authentication, and redirect responses retain
Laravel's response handling. Error reports record the exception class and code,
the chain of wrapped exception classes, any PostgreSQL SQLSTATE, the request
identifier, and a matched route template when available. The identifier is
resolved on demand, so a failure reported before the middleware stack ran is
logged with the same value its response carries. They do not include exception
messages, traces, SQL, bindings, request values, query strings, cookies, or
authorization headers.

The readiness probe is registered outside the `web` middleware group. It reports
dependency state without a session, so with Redis-backed sessions a probe neither
writes a session or cookie nor loses its own 503 when Redis is the unavailable
dependency.

## Documentation maintenance

The [concurrency tests](../tests/Feature/AdministratorConcurrencyTest.php) use
independent guarded workers to verify first-administrator creation and mutual
administrator disabling under actual PostgreSQL lock contention. The
[session tests](../tests/Feature/AdministratorAuthenticationTest.php) exercise
Redis cookies after password reset and account disablement through the service.

Keep each fact in one owning reference and link to it elsewhere. Update this
document when implemented responsibilities or execution paths change. The
[code style](code-style.md) owns naming and readability rules;
[Contributing](../CONTRIBUTING.md) owns development, verification, and review
requirements.
