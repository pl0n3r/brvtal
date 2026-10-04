-- BRVTAL Event timetable 01
-- Additive/idempotent bootstrap for Event Ops V1.
-- The canonical fresh-install shape remains in database/schema.sql.

CREATE TABLE IF NOT EXISTS event_timetable_items (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE event_timetable_items
  ADD COLUMN IF NOT EXISTS event_id INT UNSIGNED NOT NULL AFTER id;
ALTER TABLE event_timetable_items
  ADD COLUMN IF NOT EXISTS artist_id INT UNSIGNED NULL AFTER event_id;
ALTER TABLE event_timetable_items
  ADD COLUMN IF NOT EXISTS label VARCHAR(180) NULL AFTER artist_id;
ALTER TABLE event_timetable_items
  ADD COLUMN IF NOT EXISTS starts_at_utc DATETIME NOT NULL AFTER label;
ALTER TABLE event_timetable_items
  ADD COLUMN IF NOT EXISTS ends_at_utc DATETIME NOT NULL AFTER starts_at_utc;
ALTER TABLE event_timetable_items
  ADD COLUMN IF NOT EXISTS timezone VARCHAR(64) NOT NULL AFTER ends_at_utc;
ALTER TABLE event_timetable_items
  ADD COLUMN IF NOT EXISTS status ENUM('draft','approved') NOT NULL DEFAULT 'draft' AFTER timezone;
ALTER TABLE event_timetable_items
  ADD COLUMN IF NOT EXISTS sort_order INT NOT NULL DEFAULT 0 AFTER status;
ALTER TABLE event_timetable_items
  ADD COLUMN IF NOT EXISTS created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER sort_order;
ALTER TABLE event_timetable_items
  ADD COLUMN IF NOT EXISTS updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at;

SET @noop_sql := 'SELECT 1';

SET @idx_event_time := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=DATABASE()
    AND TABLE_NAME='event_timetable_items'
    AND INDEX_NAME='idx_event_timetable_event_time'
);
SET @sql := IF(
  @idx_event_time=0,
  'CREATE INDEX idx_event_timetable_event_time ON event_timetable_items(event_id,starts_at_utc,sort_order)',
  @noop_sql
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_event_status := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=DATABASE()
    AND TABLE_NAME='event_timetable_items'
    AND INDEX_NAME='idx_event_timetable_event_status'
);
SET @sql := IF(
  @idx_event_status=0,
  'CREATE INDEX idx_event_timetable_event_status ON event_timetable_items(event_id,status,starts_at_utc)',
  @noop_sql
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_artist := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=DATABASE()
    AND TABLE_NAME='event_timetable_items'
    AND INDEX_NAME='idx_event_timetable_artist'
);
SET @sql := IF(
  @idx_artist=0,
  'CREATE INDEX idx_event_timetable_artist ON event_timetable_items(artist_id,starts_at_utc)',
  @noop_sql
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk_event := (
  SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA=DATABASE()
    AND TABLE_NAME='event_timetable_items'
    AND CONSTRAINT_NAME='fk_event_timetable_event'
);
SET @sql := IF(
  @fk_event=0,
  'ALTER TABLE event_timetable_items ADD CONSTRAINT fk_event_timetable_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE',
  @noop_sql
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk_artist := (
  SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA=DATABASE()
    AND TABLE_NAME='event_timetable_items'
    AND CONSTRAINT_NAME='fk_event_timetable_artist'
);
SET @sql := IF(
  @fk_artist=0,
  'ALTER TABLE event_timetable_items ADD CONSTRAINT fk_event_timetable_artist FOREIGN KEY (artist_id) REFERENCES artists(id) ON DELETE RESTRICT',
  @noop_sql
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
