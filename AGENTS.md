# Agent guide

This guide supplements global instructions. Follow the user's requirements for the environment, safety and authorization.

## Reading order

- [README.md](README.md): setup, configuration, administrator commands, testing, deployment and operations.
- [docs/architecture.md](docs/architecture.md): feature map, responsibilities and runtime paths.
- [docs/code-style.md](docs/code-style.md): naming, types and readability.

Read the owning reference and verify it against the source. When responsibilities, interfaces, execution paths, data behavior or failure handling change, update that document in the same change.

## Boundaries

- Sessions are stateful Redis authentication; keep the session and cache databases separate, because `cache:clear` flushes the whole cache database.
- The test base runs an isolated-database guard first. PostgreSQL 18 only, host `127.0.0.1` or `postgres`, port `5432`, and the `identity_service_test` database and role owned by that role.
- CI requires exactly 100% line coverage for `app/`; do not add exclusions or trivial assertions to reach it.
- Audit rows are append-only and are written in the same transaction as the account change they describe.
- Account state changes share one PostgreSQL advisory lock. Keep new administrator mutations inside it.
- Never commit credentials, generated keys, database dumps or personal environment files, and never log them.

## Verification

Run the commands in README's *Testing* section. The suite needs the dedicated test role and `.env.testing`; Redis session tests use `REDIS_TEST_*` and never flush. Report what ran, its results and any unverified scope; a cancelled or incomplete run is not a pass.

## Delivery

- Branch from `main` as `<type>/<issue-number>-<kebab-case-description>`, or `<type>/<kebab-case-description>` when there is no issue. Supported types: `feat`, `fix`, `docs`, `refactor`, `test`, `style`, `chore`, `ci`.
- Commit messages and pull request titles use `<type>(<scope>): <imperative summary>` in English, lowercase, without a trailing period, within 72 characters. Use `!` only for breaking changes and describe the compatibility impact.
- Submit through a pull request into `main`; never push directly to `main` and do not create long-lived `develop` or version branches.
- Keep each change focused, describe its effect on interfaces, data, security and failure behavior, and link the checks that exercise it.
