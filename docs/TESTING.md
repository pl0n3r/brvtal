# BRVTAL Automated Testing Strategy

This document defines how BRVTAL catches regressions before GitHub -> Hostinger deployment and how validation evidence must be interpreted.

## Current baseline

`BRVTAL CI` is the single automatic source-validation workflow. It runs on:

- every pull request into `main`;
- every push to `main`;
- manual `workflow_dispatch`.

Pull requests and exact `main` pushes use the same changed-file-aware gate selection. The always-on `fast` job computes scope, validates PHP 8.5, runs every top-level PHP contract, checks JavaScript syntax, and verifies the per-deploy README snapshot on pull requests. Expensive jobs are selected only when the changed surfaces require them. Manual dispatch intentionally runs the complete matrix.

CodeRabbit is an additional advisory review layer on pull requests. It reads `AGENTS.md` as a repository guideline and applies path-specific review instructions from `.coderabbit.yaml`. It does **not** replace BRVTAL CI, execute the full runtime test matrix, prove deployment, or authorize merge by itself.

The source-validation stack therefore has two complementary parts:

1. **CodeRabbit review** finds semantic, security, architectural and maintainability risks that may not already have a regression test.
2. **BRVTAL CI** executes deterministic checks proving that known invariants and changed runtime surfaces still work.

When CodeRabbit or another audit finds a valid deterministic defect, the preferred closure is **fix + regression test** whenever the behavior can be exercised safely. A review comment alone is not durable protection against recurrence.

BRVTAL CI validates combinations of:

- PHP 8.5 syntax and warning/deprecation compatibility for project PHP sources;
- every top-level `tests/*-contract.php` safety/architecture contract;
- JavaScript syntax for `discadmin`, public `js`, and Playwright tests;
- migration idempotency and disposable MariaDB integration tests when relevant;
- Playwright Chromium behavior when relevant;
- targeted WebKit/Safari TOTP regression when auth-sensitive paths change;
- Content Core real-stack PHP + MariaDB + Chromium validation when relevant;
- isolated backup recovery rehearsal when backup/recovery paths change;
- one final stable `validate` aggregate check.

The CI workflow must **not** commit build metadata automatically. Product version metadata is deliberate release data, not per-change noise.

## Why the CI is consolidated

Normal source validation lives in `.github/workflows/update-release-metadata.yml` under the visible workflow name **BRVTAL CI**. Standalone automatic wrappers for PHP 8.5 compatibility, README deploy-snapshot validation, backup recovery rehearsal, and production-smoke source contracts were removed because they duplicated checkout/runtime setup and could compete for runners.

Their guarantees were not removed:

- PHP 8.5 compatibility is enforced by `scripts/php85-compatibility.sh` inside `fast`;
- README exact-diff validation runs inside `fast` on pull requests;
- `tests/production-smoke-contract.php` runs with the other top-level PHP contracts;
- Backup recovery rehearsal is the path-aware `recovery` job in BRVTAL CI.

The actual authenticated production checks remain separate **manual-only** workflows because they interact with the deployed site rather than validating source in isolation.

## Diff-aware gate policy

The path classifier must err toward running too much rather than silently missing a runtime surface.

Important mappings include:

- `api/contact.php` and `api/public*.php` -> database + Chromium + real-stack;
- `config/public_*.php` -> database + Chromium + real-stack;
- `config/*` -> database + real-stack, with auth-sensitive files also selecting WebKit;
- `discadmin/*.php` -> Chromium + real-stack;
- `discadmin/*.js` / `discadmin/*.css` -> Chromium;
- `package.json`, lockfiles and `playwright.config.mjs` -> database + Chromium + real-stack + WebKit;
- backup/recovery implementation -> database + isolated recovery rehearsal;
- manual `workflow_dispatch` -> full matrix.

`tests/ci-scope-contract.php` protects these mappings against accidental regression.

## GitHub Actions build/deploy summary

Every BRVTAL CI run publishes a `GITHUB_STEP_SUMMARY` with:

- event type and branch/ref;
- PR number/title when applicable;
- source and checkout SHAs;
- commit subject/time;
- changed file count/list;
- detected product areas affected;
- deploy eligibility;
- selected optional validation gates;
- final aggregate gate results.

A `push` to `main` is marked as an **auto-deploy candidate** because Hostinger is connected to `main`. This summary still does not prove that Hostinger completed the deployment or that deployed behavior was visually/operationally verified.

## Canonical commands

Run the same PHP 8.5 compatibility + auto-discovered top-level contract sweep used by the always-on fast gate:

```bash
npm run test:contracts
```

Equivalent direct entry point:

```bash
bash scripts/php85-compatibility.sh
```

Run the canonical MariaDB integration suite:

```bash
BRVTAL_INTEGRATION_TESTS=1 \
BRVTAL_TEST_DB_HOST=127.0.0.1 \
BRVTAL_TEST_DB_NAME=brvtal_test_local \
BRVTAL_TEST_DB_USER=root \
BRVTAL_TEST_DB_PASS=your_local_test_password \
npm run test:integration
```

The integration command includes Content Persistence, Releases schema, Migrations, Media reference atomicity, Global Search, Bulk Actions, Public Archive, Related Content, Admin Activity and Backups. CI calls this same command instead of maintaining a second list.

Run browser UI + targeted WebKit + Content Core real-stack tests:

```bash
npm run test:e2e
```

Run the regular complete local suite:

```bash
npm test
```

`npm test` requires the MariaDB integration environment above and Playwright browsers. The destructive-format recovery rehearsal remains a separately guarded test and is intentionally not hidden inside `npm test`.

Install browser dependencies locally only when needed:

```bash
npm install
npx playwright install chromium webkit
```

GitHub Actions installs test dependencies and browser engines automatically. MariaDB integration runs against disposable service containers.

## Testing pyramid

### 1. Static checks

Purpose: catch syntax errors and unsafe patterns quickly.

Examples:

- `php -l` for PHP files;
- `node --check` for JS/MJS;
- PHP 8.5 warnings/notices/deprecations treated as compatibility failures;
- contract scans for risky public exposure or destructive admin behavior.

### 2. Contract tests

Purpose: verify critical file, route, security, architecture, and operational invariants without a production session.

Examples include:

- Event lineup route behavior;
- public API settings allowlist;
- Contact proxy and private rate-limit-storage boundaries;
- authentication rate-limit atomicity and success-reset invariants;
- Media Library upload/reference protection;
- Release/Blog contracts;
- deployment traceability;
- Hero Slider public/admin contract;
- path-aware CI selection;
- project operations contract;
- authenticated production smoke safety contract;
- isolated backup recovery safety contract.

`fast` auto-discovers top-level files matching `tests/*-contract.php`, so adding a new top-level contract automatically places it on the always-on gate without editing the workflow.

`tests/project-operations-contract.php` protects the operational delivery contract, including:

- README remains a compact latest-deploy snapshot;
- AGENTS remains the canonical AI/bootstrap source;
- BRVTAL CI keeps GitHub Job Summary reporting and changed-file context;
- PHP compatibility/README/recovery stay consolidated instead of regressing to duplicate workflows;
- exact `main` uses diff-aware gates and retains the stable `validate` aggregate;
- CI must not reintroduce automatic writes/commits to `config/version.php`.

`tests/ci-scope-contract.php` additionally protects the testing-layer audit decisions:

- public APIs and `config/public_*` select browser validation;
- test-tooling changes select MariaDB as well as browser/real-stack/WebKit gates;
- CI uses the same integration command developers run locally;
- CodeRabbit remains advisory while its signal/noise is calibrated;
- unreachable production performance probes are classified as inconclusive instead of measured regressions.

`tests/production-smoke-contract.php` protects both production-smoke boundaries:

- the read-only authenticated workflow remains manual-only and restricted to `main`;
- production credentials come only from GitHub secrets;
- the canonical `www.brvtal.com.co` origin and exact dispatched SHA are required;
- after authentication, the read-only browser allows only GET/HEAD/OPTIONS requests;
- the shell's automatic media-permission repair is fulfilled locally rather than sent to production;
- any other browser POST/PUT/PATCH/DELETE is blocked and recorded as a failure;
- the separate #122 Page-write workflow also remains manual-only, requires an explicit confirmation token, uses a unique temporary namespace and must delete only the Page it created;
- the #122 probe must verify cleanup by requiring the temporary Page ID to return 404 afterward.

`tests/backup-recovery-rehearsal-contract.php` protects the recovery boundary:

- the BRVTAL CI `recovery` job uses only a disposable MariaDB service and fixed `brvtal_test_*` source namespace;
- the job is selected through `run_recovery`, not run universally;
- both integration and recovery-specific opt-in guards are required before destructive fixture SQL can run;
- recovery is always into a unique `brvtal_test_recovery_*` database and that database is dropped in cleanup;
- no production URL, GitHub secret or production environment is consumed;
- database credentials are not embedded in command arguments or evidence;
- DISCADMIN continues to reject restore and backup manifests continue to advertise `restore_supported=false`.

### 3. Database integration tests

Purpose: verify actual persistence and relational behavior against disposable MariaDB.

Safety rules:

- only runs when `BRVTAL_INTEGRATION_TESTS=1` where required;
- DB name must use the `brvtal_test...` namespace for guarded suites;
- CI uses a disposable MariaDB service;
- production tables are not reset or seeded.

Coverage includes content persistence plus specialized integration for Search, Bulk Actions, Public Archive, Related Content, Admin Activity, Backups, Migrations, Releases and Media reference atomicity.

#### Backup recovery rehearsal

Backup recovery is the `recovery` job inside `.github/workflows/update-release-metadata.yml`. It is selected only when backup/recovery paths change or when the full workflow is manually dispatched. It does **not** authenticate to BRVTAL production, does not consume production secrets and does not expose a restore action in DISCADMIN.

Run it locally only against a disposable test database:

```bash
BRVTAL_INTEGRATION_TESTS=1 \
BRVTAL_BACKUP_RECOVERY_REHEARSAL=1 \
BRVTAL_TEST_DB_HOST=127.0.0.1 \
BRVTAL_TEST_DB_NAME=brvtal_test_backup_source \
BRVTAL_TEST_DB_USER=root \
BRVTAL_TEST_DB_PASS=your_local_test_password \
php tests/integration/backup-recovery-rehearsal.php
```

The rehearsal uses a disposable source database named `brvtal_test_backup_source`, creates representative relational fixtures plus a view and temporary media files, then generates a normal BRVTAL database dump and media ZIP through the existing backup engine. After the snapshot it deliberately mutates the source data and media so the recovery check can prove it is reconstructing the backup point-in-time rather than the later source state.

The SQL dump is imported with the MariaDB client into a unique database matching `brvtal_test_recovery_<random>`. The rehearsal verifies:

- the database artifact SHA-256 matches the manifest;
- Unicode, apostrophes and `NULL` values survive restore;
- rows created or modified after the backup are absent;
- restored foreign-key constraints still enforce referential integrity;
- the restored SQL view returns the expected snapshot result;
- the media ZIP extracts to expected paths and file hashes match the pre-backup snapshot.

Both the source fixtures and the unique recovery database are removed in `finally` cleanup. A strict namespace guard prevents the rehearsal from creating or dropping an arbitrary database. The evidence artifact is `backup-recovery-rehearsal.json` and contains no database password.

This rehearsal proves recoverability of the **backup format in an isolated test environment**. It does not authorize or implement a production restore. `restore_supported=false` remains the product contract and `/discadmin/backups.php` must continue returning `RESTORE_NOT_SUPPORTED` for restore attempts. Automatic or one-click production restore remains deliberately deferred.

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

Purpose: verify the actual Hostinger deployment while keeping production-side effects explicit and narrowly controlled.

Safe checks can include:

- public homepage returns 200;
- public API returns valid JSON;
- public API does not expose private settings;
- DISCADMIN login page returns 200;
- required static assets return 200;
- System Status/deployment SHA matches the intended source;
- key public navigation and responsive rendering behave correctly.

Authenticated production checks should avoid creating/editing/deleting real content. When a production mutation is unavoidable to validate a specific defect, it must use a dedicated manual workflow, an explicit confirmation gate, a unique temporary namespace and verified cleanup.

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

#### Controlled production Page write smoke

Issue #122 cannot be proven in production with a read-only probe because the original defect occurs while creating a CMS Page. `.github/workflows/production-page-write-smoke.yml` therefore isolates that single mutation in a separate **manual-only** workflow.

Run it only from `main`, after the intended SHA is deployed, and type the exact dispatch confirmation:

```text
WRITE_AND_DELETE_TEMP_PAGE
```

The controlled sequence is intentionally narrow:

1. authenticate through the normal admin API, including TOTP when enabled;
2. confirm production exposes the dispatched `main` SHA;
3. create exactly one temporary Page with a unique `production-smoke-122-...` slug, locale `es`, status `published`, and `content_json={"text":"Manifiesto"}`;
4. read that exact Page back and verify title, slug, locale, published status and JSON content;
5. in cleanup, delete only the Page ID created by this run;
6. verify the deleted Page ID returns HTTP 404.

If the create response is ambiguous, cleanup searches only for the unique slug generated by that run and removes that exact record if present. The workflow does not PUT/PATCH existing content and does not delete by arbitrary query. The evidence artifact is `production-page-write-smoke.json`.

Because the temporary Page is intentionally `published` to reproduce #122 faithfully, there is a brief interval between creation and cleanup in which that unique slug can technically exist publicly. The slug is generated per run and the probe performs cleanup immediately even when verification fails. A run with failed or unverified cleanup must be treated as a production incident to inspect before retrying.

A green CI/contract run does **not** validate #122 in production. Only a green manual `Controlled Production Page Write Smoke` run against the intended deployed SHA, with `cleanup.verifiedAbsent=true` in the evidence artifact, is sufficient to mark #122 **VALIDATED IN PRODUCTION**.

### 6. Production performance evidence

`.github/workflows/production-performance.yml` runs only after a successful `main` BRVTAL CI push or by manual dispatch. It first checks whether the canonical production origin is reachable from that GitHub runner.

The result semantics are deliberate:

- **measured failure**: production was reachable, the expected deploy was observable when required, measurement ran, and a performance assertion failed;
- **inconclusive**: the runner could not reach production, so no performance measurement was executed;
- **success**: the requested measurements completed successfully.

An unreachable runner must not be presented as evidence that BRVTAL performance regressed. The summary records `INCONCLUSIVE — UNREACHABLE FROM THIS RUNNER` and skips browser setup/measurement.

## CodeRabbit policy

`.coderabbit.yaml` keeps automatic PR review enabled for `main`, but `request_changes_workflow` remains disabled while review signal/noise is calibrated. CodeRabbit therefore contributes findings without replacing the mandatory `BRVTAL CI / validate` decision boundary.

Path guidance specifically asks CodeRabbit to scrutinize:

- authentication/session/rate-limit/proxy/filesystem boundaries under `config/**`;
- authorization, validation, publication contracts and information exposure under `api/**`;
- the ONE SHELL / ONE SIDEBAR / ONE SESSION / ONE CENTRAL WORKSPACE invariant in `discadmin/**`;
- deterministic regression coverage in `tests/**`;
- false-negative path selection and production-vs-source semantics in `.github/workflows/**`;
- destructive/idempotency risks in `database/**`.

Once CodeRabbit has been observed across several real PRs, its blocking role can be reconsidered separately. Until then, it is deliberately advisory.

## Branch protection

Repository settings should require `BRVTAL CI / validate` before merging into `main`. This is a GitHub repository administration setting, not a source-file setting. If the active automation connection lacks GitHub Administration permission, it cannot be enforced from repository code; configure the required status check through GitHub rulesets/branch protection using an account with admin rights.

CodeRabbit should not be made a required blocking check during the initial calibration period.

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
