CREATE DATABASE IF NOT EXISTS brvtal CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE brvtal;

CREATE TABLE admins (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  name VARCHAR(120) NOT NULL DEFAULT 'BRVTAL Admin',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  last_login_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE events (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(180) NOT NULL,
  slug VARCHAR(190) NOT NULL UNIQUE,
  event_date DATETIME NULL,
  venue VARCHAR(180) NULL,
  city VARCHAR(120) NULL,
  description TEXT NULL,
  skin VARCHAR(60) NOT NULL DEFAULT 'CORE',
  accent VARCHAR(30) NULL,
  cover_image VARCHAR(255) NULL,
  ticket_url VARCHAR(500) NULL,
  status ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_events_status_date (status, event_date)
) ENGINE=InnoDB;

CREATE TABLE artists (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(180) NOT NULL,
  slug VARCHAR(190) NOT NULL UNIQUE,
  bio TEXT NULL,
  photo VARCHAR(255) NULL,
  instagram_url VARCHAR(500) NULL,
  soundcloud_url VARCHAR(500) NULL,
  website_url VARCHAR(500) NULL,
  status ENUM('draft','published') NOT NULL DEFAULT 'draft',
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE event_artists (
  event_id INT UNSIGNED NOT NULL,
  artist_id INT UNSIGNED NOT NULL,
  lineup_order INT NOT NULL DEFAULT 0,
  role VARCHAR(80) NULL,
  PRIMARY KEY (event_id, artist_id),
  CONSTRAINT fk_ea_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  CONSTRAINT fk_ea_artist FOREIGN KEY (artist_id) REFERENCES artists(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE sets_media (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(180) NOT NULL,
  slug VARCHAR(190) NOT NULL UNIQUE,
  artist_id INT UNSIGNED NULL,
  event_id INT UNSIGNED NULL,
  platform ENUM('soundcloud','youtube','spotify','other') NOT NULL DEFAULT 'soundcloud',
  external_url VARCHAR(700) NOT NULL,
  embed_url VARCHAR(700) NULL,
  cover_image VARCHAR(255) NULL,
  description TEXT NULL,
  status ENUM('draft','published') NOT NULL DEFAULT 'draft',
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (artist_id) REFERENCES artists(id) ON DELETE SET NULL,
  FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE media (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  type ENUM('image','video','audio','document') NOT NULL,
  title VARCHAR(180) NOT NULL,
  file_path VARCHAR(500) NOT NULL,
  mime_type VARCHAR(120) NULL,
  file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
  alt_text VARCHAR(255) NULL,
  status ENUM('draft','published') NOT NULL DEFAULT 'published',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE pages (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(180) NOT NULL,
  slug VARCHAR(190) NOT NULL UNIQUE,
  locale CHAR(2) NOT NULL DEFAULT 'es',
  content_json LONGTEXT NULL,
  seo_title VARCHAR(190) NULL,
  seo_description VARCHAR(320) NULL,
  status ENUM('draft','published') NOT NULL DEFAULT 'draft',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE settings (
  setting_key VARCHAR(120) PRIMARY KEY,
  setting_value LONGTEXT NULL,
  is_json TINYINT(1) NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE analytics_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event_name VARCHAR(120) NOT NULL,
  page_url VARCHAR(700) NULL,
  referrer VARCHAR(700) NULL,
  locale CHAR(2) NULL,
  user_agent VARCHAR(700) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_analytics_date (created_at),
  INDEX idx_analytics_name (event_name)
) ENGINE=InnoDB;

INSERT INTO settings(setting_key, setting_value, is_json) VALUES
('site', '{"name":"BRVTAL","tagline":"RAVE TILL GRAVE","default_locale":"es","available_locales":["es","en"]}', 1),
('social', '{"instagram":"","soundcloud":"","youtube":"","spotify":""}', 1),
('appearance', '{"coreSkin":"CORE","defaultAccent":"#ff1717"}', 1)
ON DUPLICATE KEY UPDATE setting_key=VALUES(setting_key);
