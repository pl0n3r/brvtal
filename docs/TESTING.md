# BRVTAL Automated Testing Strategy

This document defines how BRVTAL reduces manual production testing and catches regressions before GitHub -> Hostinger deployment.

## Current baseline

The CI workflow runs on every push to `main`, every pull request into `main`, and manual dispatch.

It currently validates:

- PHP syntax for `config`, `discadmin`, `api`, and `tests`.
- JavaScript syntax for `discadmin` and `tests/e2e`.
- API contract tests with `tests/api-contract.php`.
- Media Library contract tests with `tests/media-library-contract.php`.
- MariaDB integration tests with `tests/integration/content-persistence.php`.
- Browser UI smoke tests with Playwright under `tests/e2e`.

The CI workflow must not commit build metadata automatically. Product version and build metadata are deliberate release data, not per-change noise.

## Commands

Run contract tests:

```bash
npm run test:contracts
```

Run MariaDB integration tests:

```bash
BRVTAL_INTEGRATION_TESTS=1 \
BRVTAL_TEST_DB_HOST=127.0.0.1 \
BRVTAL_TEST_DB_NAME=brvtal_test_local \
BRVTAL_TEST_DB_USER=root \
BRVTAL_TEST_DB_PASS=your_local_test_password \
npm run test:integration
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

GitHub Actions installs Node dependencies and Chromium automatically, so local installation is optional. The MariaDB integration step runs against a disposable service container in CI.

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

### 3. Integration tests with a database fixture

Purpose: verify actual persistence against disposable MariaDB data.

Current integration coverage:

- Creates temporary Media, Event, Artist, Lineup, Set, Ticket, Page, and Setting records inside MariaDB.
- Persists `events.cover_image`.
- Persists `events.ticket_qr`.
- Persists `artists.photo`.
- Persists `event_artists.lineup_order` and `event_artists.role`.
- Persists `event_ticket_types.qr_image` and active status.
- Confirms `brvtal_media_usage()` detects references in Event, Event QR, Artist, Set, Ticket QR, Page, and Setting.
- Confirms unused media reports no references.

Safety rules:

- The test only runs when `BRVTAL_INTEGRATION_TESTS=1`.
- The database name must start with `brvtal_test`.
- The test uses `CREATE TEMPORARY TABLE`, so it does not drop, alter, or overwrite production tables.
- The CI workflow provides a disposable MariaDB service container.

Future integration tests should add:

- Full API-level create/update/delete Event.
- Full API-level create/update/delete Artist.
- Public API visibility for tickets and events.
- Settings public/private separation with real API responses.
- Releases and Blog persistence once implemented.

### 4. Browser UI tests

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
