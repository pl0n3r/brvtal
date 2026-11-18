-- BRVTAL Media exact-content deduplication
-- Additive + idempotent. Apply explicitly; source deployment never runs this automatically.

SET @c := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='media' AND COLUMN_NAME='content_hash'
);
SET @sql := IF(
  @c=0,
  "ALTER TABLE media ADD COLUMN content_hash CHAR(64) NULL AFTER file_size",
  'SELECT 1'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @c := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='media' AND INDEX_NAME='uq_media_content_hash'
);
SET @sql := IF(
  @c=0,
  "ALTER TABLE media ADD UNIQUE KEY uq_media_content_hash (content_hash)",
  'SELECT 1'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
