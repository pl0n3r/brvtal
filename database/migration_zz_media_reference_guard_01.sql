-- BRVTAL Media reference/delete atomicity guard
-- Additive + idempotent. Requires the current Events, Artists, Sets, Tickets,
-- Releases, Blog, Pages, Settings and Media schema to already exist.
--
-- Design:
-- - every write that can create a media-path reference takes one shared InnoDB
--   mutex row for the duration of its statement/transaction;
-- - media DELETE takes the same mutex, rechecks all tracked references while
--   holding it, then tombstones the path before the row can disappear;
-- - writers refuse any path already tombstoned by a completed media DELETE.
-- This closes both TOCTOU interleavings without changing the reusable path model.

CREATE TABLE IF NOT EXISTS media_reference_mutex (
  id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
  state TINYINT UNSIGNED NOT NULL DEFAULT 0,
  CONSTRAINT chk_media_reference_mutex_state CHECK (state = 0)
) ENGINE=InnoDB;

INSERT IGNORE INTO media_reference_mutex(id, state) VALUES (1, 0);

CREATE TABLE IF NOT EXISTS media_deleted_paths (
  file_path VARCHAR(500) NOT NULL PRIMARY KEY,
  deleted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

DROP TRIGGER IF EXISTS brvtal_events_media_guard_bi;
CREATE TRIGGER brvtal_events_media_guard_bi
BEFORE INSERT ON events
FOR EACH ROW
UPDATE media_reference_mutex
SET state = IF(
  EXISTS(
    SELECT 1 FROM media_deleted_paths d
    WHERE d.file_path = COALESCE(NEW.cover_image, '')
       OR d.file_path = COALESCE(NEW.ticket_qr, '')
  ), 1, 0
)
WHERE id = 1;

DROP TRIGGER IF EXISTS brvtal_events_media_guard_bu;
CREATE TRIGGER brvtal_events_media_guard_bu
BEFORE UPDATE ON events
FOR EACH ROW
UPDATE media_reference_mutex
SET state = IF(
  EXISTS(
    SELECT 1 FROM media_deleted_paths d
    WHERE d.file_path = COALESCE(NEW.cover_image, '')
       OR d.file_path = COALESCE(NEW.ticket_qr, '')
  ), 1, 0
)
WHERE id = 1;

DROP TRIGGER IF EXISTS brvtal_artists_media_guard_bi;
CREATE TRIGGER brvtal_artists_media_guard_bi
BEFORE INSERT ON artists
FOR EACH ROW
UPDATE media_reference_mutex
SET state = IF(EXISTS(
  SELECT 1 FROM media_deleted_paths d WHERE d.file_path = COALESCE(NEW.photo, '')
), 1, 0)
WHERE id = 1;

DROP TRIGGER IF EXISTS brvtal_artists_media_guard_bu;
CREATE TRIGGER brvtal_artists_media_guard_bu
BEFORE UPDATE ON artists
FOR EACH ROW
UPDATE media_reference_mutex
SET state = IF(EXISTS(
  SELECT 1 FROM media_deleted_paths d WHERE d.file_path = COALESCE(NEW.photo, '')
), 1, 0)
WHERE id = 1;

DROP TRIGGER IF EXISTS brvtal_sets_media_guard_bi;
CREATE TRIGGER brvtal_sets_media_guard_bi
BEFORE INSERT ON sets_media
FOR EACH ROW
UPDATE media_reference_mutex
SET state = IF(EXISTS(
  SELECT 1 FROM media_deleted_paths d WHERE d.file_path = COALESCE(NEW.cover_image, '')
), 1, 0)
WHERE id = 1;

DROP TRIGGER IF EXISTS brvtal_sets_media_guard_bu;
CREATE TRIGGER brvtal_sets_media_guard_bu
BEFORE UPDATE ON sets_media
FOR EACH ROW
UPDATE media_reference_mutex
SET state = IF(EXISTS(
  SELECT 1 FROM media_deleted_paths d WHERE d.file_path = COALESCE(NEW.cover_image, '')
), 1, 0)
WHERE id = 1;

DROP TRIGGER IF EXISTS brvtal_ticket_types_media_guard_bi;
CREATE TRIGGER brvtal_ticket_types_media_guard_bi
BEFORE INSERT ON event_ticket_types
FOR EACH ROW
UPDATE media_reference_mutex
SET state = IF(EXISTS(
  SELECT 1 FROM media_deleted_paths d WHERE d.file_path = COALESCE(NEW.qr_image, '')
), 1, 0)
WHERE id = 1;

DROP TRIGGER IF EXISTS brvtal_ticket_types_media_guard_bu;
CREATE TRIGGER brvtal_ticket_types_media_guard_bu
BEFORE UPDATE ON event_ticket_types
FOR EACH ROW
UPDATE media_reference_mutex
SET state = IF(EXISTS(
  SELECT 1 FROM media_deleted_paths d WHERE d.file_path = COALESCE(NEW.qr_image, '')
), 1, 0)
WHERE id = 1;

DROP TRIGGER IF EXISTS brvtal_releases_media_guard_bi;
CREATE TRIGGER brvtal_releases_media_guard_bi
BEFORE INSERT ON releases
FOR EACH ROW
UPDATE media_reference_mutex
SET state = IF(EXISTS(
  SELECT 1 FROM media_deleted_paths d WHERE d.file_path = COALESCE(NEW.artwork, '')
), 1, 0)
WHERE id = 1;

DROP TRIGGER IF EXISTS brvtal_releases_media_guard_bu;
CREATE TRIGGER brvtal_releases_media_guard_bu
BEFORE UPDATE ON releases
FOR EACH ROW
UPDATE media_reference_mutex
SET state = IF(EXISTS(
  SELECT 1 FROM media_deleted_paths d WHERE d.file_path = COALESCE(NEW.artwork, '')
), 1, 0)
WHERE id = 1;

DROP TRIGGER IF EXISTS brvtal_blog_media_guard_bi;
CREATE TRIGGER brvtal_blog_media_guard_bi
BEFORE INSERT ON blog_posts
FOR EACH ROW
UPDATE media_reference_mutex
SET state = IF(EXISTS(
  SELECT 1 FROM media_deleted_paths d WHERE d.file_path = COALESCE(NEW.cover_image, '')
), 1, 0)
WHERE id = 1;

DROP TRIGGER IF EXISTS brvtal_blog_media_guard_bu;
CREATE TRIGGER brvtal_blog_media_guard_bu
BEFORE UPDATE ON blog_posts
FOR EACH ROW
UPDATE media_reference_mutex
SET state = IF(EXISTS(
  SELECT 1 FROM media_deleted_paths d WHERE d.file_path = COALESCE(NEW.cover_image, '')
), 1, 0)
WHERE id = 1;

DROP TRIGGER IF EXISTS brvtal_pages_media_guard_bi;
CREATE TRIGGER brvtal_pages_media_guard_bi
BEFORE INSERT ON pages
FOR EACH ROW
UPDATE media_reference_mutex
SET state = IF(EXISTS(
  SELECT 1 FROM media_deleted_paths d
  WHERE LOCATE(d.file_path, COALESCE(NEW.content_json, '')) > 0
), 1, 0)
WHERE id = 1;

DROP TRIGGER IF EXISTS brvtal_pages_media_guard_bu;
CREATE TRIGGER brvtal_pages_media_guard_bu
BEFORE UPDATE ON pages
FOR EACH ROW
UPDATE media_reference_mutex
SET state = IF(EXISTS(
  SELECT 1 FROM media_deleted_paths d
  WHERE LOCATE(d.file_path, COALESCE(NEW.content_json, '')) > 0
), 1, 0)
WHERE id = 1;

DROP TRIGGER IF EXISTS brvtal_settings_media_guard_bi;
CREATE TRIGGER brvtal_settings_media_guard_bi
BEFORE INSERT ON settings
FOR EACH ROW
UPDATE media_reference_mutex
SET state = IF(EXISTS(
  SELECT 1 FROM media_deleted_paths d
  WHERE LOCATE(d.file_path, COALESCE(NEW.setting_value, '')) > 0
), 1, 0)
WHERE id = 1;

DROP TRIGGER IF EXISTS brvtal_settings_media_guard_bu;
CREATE TRIGGER brvtal_settings_media_guard_bu
BEFORE UPDATE ON settings
FOR EACH ROW
UPDATE media_reference_mutex
SET state = IF(EXISTS(
  SELECT 1 FROM media_deleted_paths d
  WHERE LOCATE(d.file_path, COALESCE(NEW.setting_value, '')) > 0
), 1, 0)
WHERE id = 1;

DROP TRIGGER IF EXISTS brvtal_media_delete_guard_lock;
CREATE TRIGGER brvtal_media_delete_guard_lock
BEFORE DELETE ON media
FOR EACH ROW
UPDATE media_reference_mutex SET state = 0 WHERE id = 1;

DROP TRIGGER IF EXISTS brvtal_media_delete_guard_recheck;
CREATE TRIGGER brvtal_media_delete_guard_recheck
BEFORE DELETE ON media
FOR EACH ROW FOLLOWS brvtal_media_delete_guard_lock
UPDATE media_reference_mutex
SET state = IF(
  EXISTS(SELECT 1 FROM events WHERE cover_image = OLD.file_path OR ticket_qr = OLD.file_path LIMIT 1)
  OR EXISTS(SELECT 1 FROM artists WHERE photo = OLD.file_path LIMIT 1)
  OR EXISTS(SELECT 1 FROM sets_media WHERE cover_image = OLD.file_path LIMIT 1)
  OR EXISTS(SELECT 1 FROM event_ticket_types WHERE qr_image = OLD.file_path LIMIT 1)
  OR EXISTS(SELECT 1 FROM releases WHERE artwork = OLD.file_path LIMIT 1)
  OR EXISTS(SELECT 1 FROM blog_posts WHERE cover_image = OLD.file_path LIMIT 1)
  OR EXISTS(SELECT 1 FROM pages WHERE LOCATE(OLD.file_path, COALESCE(content_json, '')) > 0 LIMIT 1)
  OR EXISTS(SELECT 1 FROM settings WHERE LOCATE(OLD.file_path, COALESCE(setting_value, '')) > 0 LIMIT 1),
  1, 0
)
WHERE id = 1;

DROP TRIGGER IF EXISTS brvtal_media_delete_guard_tombstone;
CREATE TRIGGER brvtal_media_delete_guard_tombstone
BEFORE DELETE ON media
FOR EACH ROW FOLLOWS brvtal_media_delete_guard_recheck
INSERT INTO media_deleted_paths(file_path, deleted_at)
VALUES(OLD.file_path, CURRENT_TIMESTAMP)
ON DUPLICATE KEY UPDATE deleted_at = VALUES(deleted_at);
