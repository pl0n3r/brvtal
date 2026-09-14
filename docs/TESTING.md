# BRVTAL Automated Testing Strategy

This document defines how BRVTAL catches regressions before GitHub -> Hostinger deployment and how validation evidence must be interpreted.

## Current baseline

`BRVTAL CI` runs on:

- every pull request into `main`;
- every push to `main`;
- manual `workflow_dispatch`.

The workflow validates combinations of:

- PHP syntax for `config`, `discadmin`, `api`, and `tests`;
- JavaScript syntax for `discadmin`, public `js`, and Playwright tests;
- API/security contracts;
- Media Library contracts;
- Releases and Blog contracts;
- Content Health and SEO contracts;
- Global Search and Bulk Actions contracts;
- Public Archive / Entity / Related Content contracts;
- Admin Activity and Backups contracts;
- project operations/documentation contract;
- migration idempotency checks;
- MariaDB disposable integration tests;
- Playwright browser behavior;
- targeted Chromium and WebKit/Safari regressions;
- Content Core real-stack test harness.

The CI workflow must **not** commit build metadata automatically. Product version metadata is deliberate release data, not per-change noise.

## GitHub Actions build/deploy summary

Every CI run publishes a `GITHUB_STEP_SUMMARY`.

At the beginning of the job it records build context such as:

- event type;
- branch/ref;
- PR number/title when applicable;
- source and checkout SHAs;
- commit subject/time;
- changed file count/list;
- detected product areas affected;
- deploy eligibility.

At the end of the job it appends:

- final job status;
- PHP/Node/MariaDB client versions available in the runner;
- deploy note.

A `push` to `main` is marked as an **auto-deploy candidate** because Hostinger is connected to `main`. This summary still does not prove that Hostinger completed the deployment or that the deployed behavior was visually/operationally verified.

The workflow path remains `.github/workflows/update-release-metadata.yml` for historical compatibility, while the visible workflow name is `BRVTAL CI`.

## Commands

Run contract tests:

```bash
npm run test:contracts
```

Run the project operations contract directly:

```bash
php tests/project-operations-contract.php
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

Install Playwright locally only when browser tests are needed:

```bash
npm install
npx playwright install chromium webkit
```

GitHub Actions installs test dependencies and browser engines automatically. MariaDB integration runs against a disposable service container.

## Testing pyramid

### 1. Static checks

Purpose: catch syntax errors and unsafe patterns quickly.

Examples:

- `php -l` for PHP files;
- `node --check` for JS/MJS;
- contract scans for risky public exposure or destructive admin behavior.

### 2. Contract tests

Purpose: verify critical file, route, security, architecture, and operational invariants without a production session.

Examples include:

- Event lineup route behavior;
- public API settings allowlist;
- Media Library upload/reference protection;
- Release/Blog contracts;
- deployment traceability;
- Hero Slider public/admin contract;
- project operations contract.

`tests/project-operations-contract.php` specifically protects the operational documentation contract:

- README must keep architecture, CI/deploy, and feature-checklist sections;
- CI must keep GitHub Job Summary reporting;
- CI must expose deploy eligibility and changed-file context;
- CI must not reintroduce automatic writes/commits to `config/version.php`.

### 3. Database integration tests

Purpose: verify actual persistence and relational behavior against disposable MariaDB.

Safety rules:

- only runs when `BRVTAL_INTEGRATION_TESTS=1` where required;
- DB name must use the `brvtal_test...` namespace for guarded suites;
- CI uses a disposable MariaDB service;
- production tables are not reset or seeded.

Coverage includes content persistence plus specialized integration for Search, Bulk Actions, Public Archive, Related Content, Admin Activity, and Backups.

### 4. Browser UI tests

Purpose: replace repetitive manual clicking and catch regressions in responsive/admin/public behavior.

Coverage includes, among other flows:

- DISCADMIN shell and responsive navigation;
- Media Picker/form behavior;
- Content Core create/update regression coverage;
- admin forms/dialogs/feedback;
- 2FA browser regressions;
- Hero Slider and mobile overrides/layers;
- public Archive/Media discovery;
- shareable discovery URL state;
- public accessibility/responsive behavior.

Browser harness tests do not imply production validation.

### 5. Production smoke tests

Purpose: verify the actual Hostinger deployment without destructive production mutations.

Safe checks can include:

- public homepage returns 200;
- public API returns valid JSON;
- public API does not expose private settings;
- DISCADMIN login page returns 200;
- required static assets return 200;
- System Status/deployment SHA matches the intended source;
- key public navigation and responsive rendering behave correctly.

Authenticated production checks must avoid creating/editing/deleting real content unless a dedicated safe test namespace exists.

## Release metadata policy

Do not auto-update `config/version.php` on every push.

Preferred model:

- `BRVTAL_APP_VERSION`: manually controlled product version;
- `BRVTAL_APP_BUILD`: intentional release/fallback metadata;
- actual deployment identity: resolved at runtime by `config/deployment.php`;
- CI: reports the Git SHA in Job Summary without committing metadata.

If an intentional release stamp is needed later, it should be a separate manual-only operation.

## Definition of done

Use these labels precisely:

- **IMPLEMENTED** — code changed;
- **VALIDATED IN CODE** — applicable automated validation passed;
- **DEPLOYED** — Hostinger has picked up the source;
- **VALIDATED IN PRODUCTION** — deployed behavior was actually checked.

Never claim production validation from CI alone.
