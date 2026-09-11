# BRVTAL Automated Testing Strategy

This document defines how BRVTAL reduces manual production testing and catches regressions before GitHub -> Hostinger deployment.

## Current baseline

The CI workflow runs on every push to `main`, every pull request into `main`, and manual dispatch.

It currently validates:

- PHP syntax for `config`, `discadmin`, `api`, and `tests`.
- JavaScript syntax for `discadmin` and `tests/e2e`.
- API contract tests with `tests/api-contract.php`.
- Media Library contract tests with `tests/media-library-contract.php`.
- Browser UI smoke tests with Playwright under `tests/e2e`.

The CI workflow must not commit build metadata automatically. Product version and build metadata are deliberate release data, not per-change noise.

## Commands

Run contract tests:

```bash
npm run test:contracts
```

Run browser UI tests:

```bash
npm run test:e2e
```

Run the full local suite:

```bash
npm test
```

Install Playwright locally only when you want to run browser tests on your own machine:

```bash
npm install
npx playwright install chromium
```

GitHub Actions installs Node dependencies and Chromium automatically, so local installation is optional.

## Testing pyramid

### 1. Static checks

Purpose: catch syntax and unsafe patterns quickly.

Examples:

- `php -l` for PHP files.
- `node --check` for JavaScript and Playwright test files.
- Contract scans for risky code paths, such as public settings exposure or media deletion without usage checks.

### 2. Contract tests

Purpose: verify that critical routes, file contracts, and security rules keep behaving as expected without requiring a live production session.

Current examples:

- `/events/{id}/lineup` routing contract.
- Public API settings allowlist contract.
- Media Library upload/security/delete/picker contracts.

Future contracts should cover:

- 2FA/TOTP required files and safe redirects.
- DISCADMIN module loading contract.
- Event lifecycle fields.
- Ticket type visibility.
- Releases and Blog contracts once implemented.

### 3. Browser UI tests

Purpose: replace repetitive manual clicking in DISCADMIN.

Current Playwright coverage:

- Media Picker attaches to Event and Artist image fields.
- Selecting media normalizes `uploads/...` to `/uploads/...`.
- Selecting media updates the input value.
- Selecting media updates the thumbnail preview.
- Selecting media shows the instruction to press SAVE.
- Saving Event sends `cover_image` in the payload.
- Saving Artist sends `photo` in the payload.
- Successful mutations display global feedback.
- Failed mutations display persistent error feedback.
- Broken thumbnails degrade to a safe placeholder.

These tests run against a mocked browser harness. They do not log into production, do not call `brvtal.com.co`, and do not mutate real content.

### 4. Integration tests with a database fixture

Purpose: verify actual CRUD and persistence against a disposable database.

Target coverage:

- Create/update/delete Event.
- Create/update/delete Artist.
- Select Media in Event and Artist and confirm persistence in the database.
- Add/remove/reorder Event lineup.
- Create ticket types and public visibility states.
- Media deletion blocked when referenced.
- Settings public/private separation.

Recommended GitHub Actions setup:

- MariaDB service container.
- Test database and non-production credentials from CI environment variables.
- Idempotent schema install/migrations.
- Seed fixtures.
- PHP integration runner under `tests/integration/`.

### 5. Production smoke tests

Purpose: verify deployment after Hostinger auto-deploy without mutating production data.

Safe checks:

- Public homepage returns 200.
- Public API returns valid JSON.
- Public API does not expose private settings.
- DISCADMIN login page returns 200.
- Static assets needed by DISCADMIN return 200.
- Version endpoint/status, if available, matches expected release.

Avoid production smoke tests that create/edit/delete real production content unless there is a dedicated test namespace or cleanup mechanism.

## Release metadata policy

Do not auto-update `config/version.php` on every push.

Preferred model:

- `BRVTAL_APP_VERSION`: manually controlled product version.
- `BRVTAL_APP_BUILD`: updated only for intentional releases, or left as the last stamped release/build.
- CI validates code but does not push metadata commits.

If an intentional release stamp is needed later, create a separate manual-only workflow named `Stamp Release Metadata` and trigger it explicitly.

## Definition of done

For each change, report the real state using these labels:

- IMPLEMENTED: code changed.
- VALIDATED IN CODE: CI/static/contract/integration/browser tests passed.
- DEPLOYED: Hostinger auto-deploy has picked up the commit.
- VALIDATED IN PRODUCTION: the deployed behavior was actually checked on `brvtal.com.co`.

Never claim production validation from CI alone.
