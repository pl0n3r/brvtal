# BRVTAL Automated Testing Strategy

This document defines how BRVTAL should reduce manual production testing and catch regressions before GitHub -> Hostinger deployment.

## Current baseline

The CI workflow runs on every push to `main`, every pull request into `main`, and manual dispatch.

It currently validates:

- PHP syntax for `config`, `discadmin`, `api`, and `tests`.
- JavaScript syntax for `discadmin`.
- API contract tests with `tests/api-contract.php`.
- Media Library contract tests with `tests/media-library-contract.php`.

The CI workflow must not commit build metadata automatically. Product version and build metadata are deliberate release data, not per-change noise.

## Testing pyramid

### 1. Static checks

Purpose: catch syntax and unsafe patterns quickly.

Examples:

- `php -l` for PHP files.
- `node --check` for JavaScript files.
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

Purpose: verify actual CRUD and persistence against a disposable database.

Target coverage:

- Create/update/delete Event.
- Create/update/delete Artist.
- Select Media in Event and Artist and confirm persistence.
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

### 4. Browser UI tests

Purpose: replace repetitive manual clicking in DISCADMIN.

Recommended tool: Playwright.

Target flows:

- Login to test environment.
- Open DISCADMIN shell and verify one sidebar.
- Open Events, Artists, Media, Content Core, Security/2FA.
- Upload or register test media.
- Select media from picker for Event and Artist.
- Save and reload to verify persistence.
- Confirm toast feedback appears for success and error states.
- Confirm protected delete state for media in use.

These should run against a disposable test environment first. Running write tests directly against production is not recommended.

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
- VALIDATED IN CODE: CI/static/contract/integration tests passed.
- DEPLOYED: Hostinger auto-deploy has picked up the commit.
- VALIDATED IN PRODUCTION: the deployed behavior was actually checked on `brvtal.com.co`.

Never claim production validation from CI alone.
