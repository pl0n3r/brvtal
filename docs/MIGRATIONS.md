# BRVTAL Database Migrations

BRVTAL treats source deployment and database migration as separate operations. A merge to `main` never implies that SQL ran in Hostinger.

## State registry

`database/migration_schema_migrations_01.sql` creates `schema_migrations`, which records:

- migration filename;
- SHA-256 checksum of the SQL file;
- application timestamp;
- operator/actor when provided;
- deploy SHA when available.

Applied migrations are immutable. If a tracked SQL file changes after it was recorded, status reports `checksum_mismatch` and the CLI refuses to reapply it.

## CLI

The migration tool is CLI-only:

```bash
php scripts/migrations.php status
php scripts/migrations.php status --json
```

Read-only `status` never creates or modifies schema.

Write commands require both an environment opt-in and `--confirm`:

```bash
BRVTAL_MIGRATIONS_ALLOW_WRITE=1 php scripts/migrations.php init --confirm
BRVTAL_MIGRATIONS_ALLOW_WRITE=1 php scripts/migrations.php apply migration_example_01.sql --confirm
BRVTAL_MIGRATIONS_ALLOW_WRITE=1 php scripts/migrations.php baseline migration_example_01.sql --confirm
```

There is deliberately no `apply-all` command.

### `init`

Creates the registry table by applying only `migration_schema_migrations_01.sql` and records that migration.

### `apply <name>`

Executes exactly one named migration. The registry must already exist. On success the exact checksum is recorded. If the same migration is already recorded with the same checksum, it is a no-op. If the checksum differs, it fails closed.

### `baseline <name>`

Records an already-verified historical migration **without executing its SQL**. This exists for established environments such as current production, where older migrations may already have been applied manually before the registry existed.

Baseline is an operator assertion, not schema detection. Never baseline a production migration merely because the file exists; verify the real schema first.

## Production safety

For production:

1. inspect the actual schema/logs first;
2. initialize the registry only with explicit approval;
3. baseline only migrations whose effects have been verified;
4. apply missing migrations one at a time;
5. verify application behavior after each relevant schema change.

Do not automate production migration execution as part of Hostinger Git deploy.

DDL in MariaDB can auto-commit. Migrations should therefore remain additive/idempotent where practical so an interrupted operation can be inspected and safely reconciled before retrying.
