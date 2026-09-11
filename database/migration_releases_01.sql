-- BRVTAL Releases 01
-- Additive/idempotent migration for the label/releases module.
-- Run against the BRVTAL database before enabling RELEASES in production.

CREATE TABLE IF NOT EXISTS releases (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(180) NOT NULL,
  slug VARCHAR(190) NOT NULL,
  release_type ENUM('single','ep','album','compilation','other') NOT NULL DEFAULT 'single',
  catalog_number VARCHAR(80) NULL,
  release_date DATE NULL,
  description TEXT NULL,
  artwork VARCHAR(500) NULL,
  spotify_url VARCHAR(700) NULL,
  soundcloud_url VARCHAR(700) NULL,
  bandcamp_url VARCHAR(700) NULL,
  youtube_url VARCHAR(700) NULL,
  beatport_url VARCHAR(700) NULL,
  status ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
  featured TINYINT(1) NOT NULL DEFAULT 0,
  sort_order INT NOT NULL DEFAULT 0,
  published_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_releases_slug (slug),
  INDEX idx_releases_public (status,release_date,sort_order),
  INDEX idx_releases_catalog (catalog_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS release_artists (
  release_id INT UNSIGNED NOT NULL,
  artist_id INT UNSIGNED NOT NULL,
  role VARCHAR(80) NULL,
  sort_order INT NOT NULL DEFAULT 0,
  PRIMARY KEY (release_id,artist_id),
  INDEX idx_release_artists_artist (artist_id,sort_order),
  CONSTRAINT fk_release_artists_release FOREIGN KEY (release_id) REFERENCES releases(id) ON DELETE CASCADE,
  CONSTRAINT fk_release_artists_artist FOREIGN KEY (artist_id) REFERENCES artists(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
