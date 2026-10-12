-- BRVTAL #989: optional authentic duration of Sets in seconds.
-- Additive and idempotent for MariaDB and MySQL. Requires controlled
-- migration/backup gate before production; Git deployment does not apply SQL.
SET @brvtal_set_duration_present := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'sets_media'
      AND COLUMN_NAME = 'duration_seconds'
);
SET @brvtal_set_duration_ddl := IF(
    @brvtal_set_duration_present = 0,
    'ALTER TABLE sets_media ADD COLUMN duration_seconds INT UNSIGNED NULL AFTER description',
    'SELECT 1'
);
PREPARE brvtal_set_duration_statement FROM @brvtal_set_duration_ddl;
EXECUTE brvtal_set_duration_statement;
DEALLOCATE PREPARE brvtal_set_duration_statement;
