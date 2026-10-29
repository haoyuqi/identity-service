# Security policy

## Supported versions

Security fixes target the latest commit on `main` and the latest published release. Older versions are not maintained; upgrade before reporting an issue that may already be fixed.

## Reporting a vulnerability

Report suspected vulnerabilities privately through GitHub's [security advisory](https://github.com/haoyuqi/identity-service/security/advisories/new). Do not open a public issue, pull request or discussion for a suspected vulnerability.

Include the affected commit or version, reproduction steps with synthetic data, expected and actual behavior, and the potential impact. Remove credentials, keys, session cookies, database dumps and personal data from attachments.

This is a community-maintained project without a response deadline or bug bounty. The maintainer coordinates the fix and disclosure through the advisory.

## Scope

Authentication, session handling, authorization, audit integrity and rate limiting are security-relevant. CORS and IP rate limits are neither authentication nor a spending cap. Report unsafe error disclosure, unintended external requests and integrity problems as security concerns.

## Secrets

Credentials belong in the deployment platform's secret store or the ignored local `.env` files; never in tracked files, images, logs or command arguments. If a key or credential is exposed, rotate it: deleting a file does not remove it from Git history or copies.

## Automation

Workflows use a read-only `GITHUB_TOKEN`, except the release job, which publishes with `contents: write` after the tagged commit passes CI and the container smoke test. Release notes use `RELEASE_NOTES_TOKEN`, `RELEASE_NOTES_BASE_URL` and `RELEASE_NOTES_MODEL`; the generator never prints them and never sends source files or diffs.
