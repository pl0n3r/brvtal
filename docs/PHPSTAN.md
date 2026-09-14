# BRVTAL PHPStan

BRVTAL uses PHPStan as an incremental development/CI guard. It is development-only tooling and does not change the Hostinger runtime or database migration behavior.

## Version and runtime contract

- PHPStan is pinned in `composer.json`.
- Composer's platform PHP is fixed to `8.3.0` to match the production PHP 8.3 contract.
- PHPStan is installed only as a `require-dev` dependency.

## Initial scope

The first adoption deliberately analyzes a small set of infrastructure files:

- `config/deployment.php`
- `config/migrations.php`
- `config/public_visibility.php`
- `scripts/migrations.php`

The initial level is `3`.

This scope is intentionally narrow. Expand it in focused PRs as findings are understood and corrected. Do not add a blanket baseline or broad `ignoreErrors` list merely to make a larger scope green.

## CI

`.github/workflows/phpstan.yml` runs on relevant pull requests, relevant pushes to `main`, and manual dispatch. It installs the pinned development dependency and runs:

```bash
composer analyse
```

The existing `Project Operations` contract also protects the foundation from silently losing the pinned version, PHP 8.3 platform contract, migration-tool coverage, or the no-baseline/no-ignoreErrors decision.

## What PHPStan does not replace

PHPStan can find PHP type/flow/API mistakes in analyzed code. It does not know whether a production MariaDB table is missing a column referenced inside a SQL string. Database migrations, MariaDB integrations, schema-state tracking and production schema verification remain separate controls.
