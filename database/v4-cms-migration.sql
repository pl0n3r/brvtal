-- BRVTAL CMS V4 migration
-- Safe additive migration. Run once against u151692719_brvtal.
-- Existing tables are preserved.

CREATE TABLE IF NOT EXISTS media_meta (
  media_id INT UNSIGNED PRIMARY KEY,
  original_name VARCHAR(255) NULL,
  width INT UNSIGNED NULL,
  height INT UNSIGNED NULL,
  duration_seconds DECIMAL(12,3) NULL,
  optimized_path VARCHAR(500) NULL,
  thumbnail_path VARCHAR(500) NULL,
  webp_path VARCHAR(500) NULL,
  avif_path VARCHAR(500) NULL,
  exif_stripped TINYINT(1) NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (media_id) REFERENCES media(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS content_revisions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  entity_type VARCHAR(40) NOT NULL,
  entity_id INT UNSIGNED NOT NULL,
  version_no INT UNSIGNED NOT NULL,
  payload_json LONGTEXT NOT NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_revision (entity_type, entity_id, version_no),
  INDEX idx_revision_entity (entity_type, entity_id, created_at),
  FOREIGN KEY (created_by) REFERENCES admins(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS activity_log (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  admin_id INT UNSIGNED NULL,
  action VARCHAR(80) NOT NULL,
  entity_type VARCHAR(40) NULL,
  entity_id INT UNSIGNED NULL,
  metadata_json LONGTEXT NULL,
  ip_address VARCHAR(45) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_activity_date (created_at),
  INDEX idx_activity_entity (entity_type, entity_id),
  FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS redirects (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  from_path VARCHAR(500) NOT NULL UNIQUE,
  to_path VARCHAR(500) NOT NULL,
  status_code SMALLINT UNSIGNED NOT NULL DEFAULT 301,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS seo_meta (
  entity_type VARCHAR(40) NOT NULL,
  entity_id INT UNSIGNED NOT NULL,
  seo_title VARCHAR(190) NULL,
  seo_description VARCHAR(320) NULL,
  canonical_url VARCHAR(700) NULL,
  og_title VARCHAR(190) NULL,
  og_description VARCHAR(320) NULL,
  og_image VARCHAR(500) NULL,
  robots VARCHAR(120) NULL,
  schema_type VARCHAR(80) NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (entity_type, entity_id)
);
