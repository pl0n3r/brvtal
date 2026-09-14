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
- project operations contract;
- authenticated production smoke safety contract.

`tests/project-operations-contract.php` specifically protects the operational documentation contract:

- README must keep architecture, CI/deploy, and feature-checklist sections;
- CI must keep GitHub Job Summary reporting;
- CI must expose deploy eligibility and changed-file context;
- CI must not reintroduce automatic writes/commits to `config/version.php`.

`tests/production-smoke-contract.php` protects the production-smoke boundary:

- the workflow remains manual-only and restricted to `main`;
- production credentials come only from GitHub secrets;
- the canonical `www.brvtal.com.co` origin and exact dispatched SHA are required;
- after authentication, the browser allows only GET/HEAD/OPTIONS requests;
- the shell's automatic media-permission repair is fulfilled locally rather than sent to production;
- any other browser POST/PUT/PATCH/DELETE is blocked and recorded as a failure.

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

#### Authenticated production smoke

`.github/workflows/production-authenticated-smoke.yml` is a **manual-only** authenticated production check. It is intentionally not triggered by push, pull request, schedule, or another workflow. Dispatch it only from `main`, after the exact Hostinger deployment is visible.

Required GitHub `production-smoke` environment secrets:

- `BRVTAL_PROD_ADMIN_EMAIL`;
- `BRVTAL_PROD_ADMIN_PASSWORD`;
- `BRVTAL_PROD_TOTP_SECRET` when that admin has TOTP enabled. The value is the Base32 authenticator secret, not a one-time recovery code.

The runner first confirms that production exposes the exact short SHA marker for the dispatched `main` commit. It then authenticates through the normal DISCADMIN API and performs only content-read checks:

- #123: select an existing Event that already has a date, reopen it, and confirm the `datetime-local` field contains the persisted date;
- #124: select existing published Artist/Event records, open **New Set** without saving, and confirm both relation selectors contain those IDs;
- #125: open the real Hero Slider manager three times and require every load to settle without a loading/error state.

After login/TOTP, a browser network guard allows only GET/HEAD/OPTIONS. DISCADMIN normally sends a media-permission repair POST during session bootstrap; the smoke fulfills that request locally so it never reaches Hostinger. Any other browser mutation is blocked, recorded in `production-authenticated-smoke.json`, and fails the run. The smoke never clicks Save/Delete and never creates test content.

Authentication itself can update normal security/session metadata such as last-login/audit state. The content validation is otherwise read-only.

A green PR/CI proves only that this smoke **can be run safely**. Do not mark an issue **VALIDATED IN PRODUCTION** until the manual workflow has actually run against the intended deployed SHA and its evidence artifact is green.

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
