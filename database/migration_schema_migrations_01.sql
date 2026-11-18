-- BRVTAL Schema Migrations 01
-- Canonical registry for explicit database migration state.
-- Additive/idempotent. This table does not execute any other migration automatically.

CREATE TABLE IF NOT EXISTS schema_migrations (
  migration_name VARCHAR(190) NOT NULL,
  checksum_sha256 CHAR(64) NOT NULL,
  applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  applied_by VARCHAR(120) NULL,
  deploy_sha CHAR(40) NULL,
  PRIMARY KEY (migration_name),
  INDEX idx_schema_migrations_applied_at (applied_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
