-- BRVTAL Releases 02
-- Reconcile pre-existing releases tables with the columns required by the current API/public surfaces.
-- Additive/idempotent. Run only after migration_releases_01.sql has ensured the base tables exist.

ALTER TABLE releases
  ADD COLUMN IF NOT EXISTS release_type ENUM('single','ep','album','compilation','other') NOT NULL DEFAULT 'single' AFTER slug,
  ADD COLUMN IF NOT EXISTS catalog_number VARCHAR(80) NULL AFTER release_type,
  ADD COLUMN IF NOT EXISTS release_date DATE NULL AFTER catalog_number,
  ADD COLUMN IF NOT EXISTS description TEXT NULL AFTER release_date,
  ADD COLUMN IF NOT EXISTS seo_title VARCHAR(190) NULL AFTER description,
  ADD COLUMN IF NOT EXISTS seo_description VARCHAR(320) NULL AFTER seo_title,
  ADD COLUMN IF NOT EXISTS artwork VARCHAR(500) NULL AFTER seo_description,
  ADD COLUMN IF NOT EXISTS spotify_url VARCHAR(700) NULL AFTER artwork,
  ADD COLUMN IF NOT EXISTS soundcloud_url VARCHAR(700) NULL AFTER spotify_url,
  ADD COLUMN IF NOT EXISTS bandcamp_url VARCHAR(700) NULL AFTER soundcloud_url,
  ADD COLUMN IF NOT EXISTS youtube_url VARCHAR(700) NULL AFTER bandcamp_url,
  ADD COLUMN IF NOT EXISTS beatport_url VARCHAR(700) NULL AFTER youtube_url,
  ADD COLUMN IF NOT EXISTS status ENUM('draft','published','archived') NOT NULL DEFAULT 'draft' AFTER beatport_url,
  ADD COLUMN IF NOT EXISTS featured TINYINT(1) NOT NULL DEFAULT 0 AFTER status,
  ADD COLUMN IF NOT EXISTS sort_order INT NOT NULL DEFAULT 0 AFTER featured,
  ADD COLUMN IF NOT EXISTS published_at DATETIME NULL AFTER sort_order,
  ADD COLUMN IF NOT EXISTS created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER published_at,
  ADD COLUMN IF NOT EXISTS updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at;

ALTER TABLE release_artists
  ADD COLUMN IF NOT EXISTS role VARCHAR(80) NULL AFTER artist_id,
  ADD COLUMN IF NOT EXISTS sort_order INT NOT NULL DEFAULT 0 AFTER role;

SET @c := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='releases' AND INDEX_NAME='uq_releases_slug');
SET @sql := IF(@c=0,"ALTER TABLE releases ADD UNIQUE KEY uq_releases_slug (slug)",'SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @c := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='releases' AND INDEX_NAME='idx_releases_public');
SET @sql := IF(@c=0,"CREATE INDEX idx_releases_public ON releases(status,release_date,sort_order)",'SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @c := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='releases' AND INDEX_NAME='idx_releases_catalog');
SET @sql := IF(@c=0,"CREATE INDEX idx_releases_catalog ON releases(catalog_number)",'SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @c := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='release_artists' AND INDEX_NAME='idx_release_artists_artist');
SET @sql := IF(@c=0,"CREATE INDEX idx_release_artists_artist ON release_artists(artist_id,sort_order)",'SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
