-- BRVTAL Artist collective membership 01
-- Add one canonical current-membership boolean without deleting historical data.
-- Legacy active => member; alumni/none => not a current member.
-- Idempotent direct re-runs never overwrite canonical edits after the first add.

SET @noop_sql := 'SELECT 1';
SET @artists_table := 'artists';

SET @membership_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=@artists_table AND COLUMN_NAME='is_collective_member'
);
SET @membership_added := IF(@membership_exists=0,1,0);
SET @sql := IF(
  @membership_exists=0,
  "ALTER TABLE artists ADD COLUMN is_collective_member TINYINT(1) NOT NULL DEFAULT 0 AFTER status",
  @noop_sql
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @legacy_status_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=@artists_table AND COLUMN_NAME='collective_status'
);
SET @sql := IF(
  @membership_added=1 AND @legacy_status_exists=1,
  "UPDATE artists SET is_collective_member=CASE WHEN collective_status='active' THEN 1 ELSE 0 END",
  @noop_sql
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @idx := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=@artists_table AND INDEX_NAME='idx_artists_collective_member'
);
SET @sql := IF(
  @idx=0,
  "CREATE INDEX idx_artists_collective_member ON artists(is_collective_member,sort_order,name)",
  @noop_sql
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
