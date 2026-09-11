-- BRVTAL Content Core 01
-- Additive/idempotent migration. Run once against the production BRVTAL database.
-- Existing content is preserved.

-- Expand event lifecycle without changing existing rows.
ALTER TABLE events
  MODIFY COLUMN status ENUM('draft','published','upcoming','tickets_available','last_tickets','sold_out','cancelled','finished','archived') NOT NULL DEFAULT 'draft';

-- Event lifecycle metadata.
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='events' AND COLUMN_NAME='published_at');
SET @sql := IF(@c=0,"ALTER TABLE events ADD COLUMN published_at DATETIME NULL AFTER status",'SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='events' AND COLUMN_NAME='cancelled_at');
SET @sql := IF(@c=0,"ALTER TABLE events ADD COLUMN cancelled_at DATETIME NULL AFTER published_at",'SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='events' AND COLUMN_NAME='finished_at');
SET @sql := IF(@c=0,"ALTER TABLE events ADD COLUMN finished_at DATETIME NULL AFTER cancelled_at",'SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='events' AND COLUMN_NAME='featured');
SET @sql := IF(@c=0,"ALTER TABLE events ADD COLUMN featured TINYINT(1) NOT NULL DEFAULT 0 AFTER finished_at",'SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='events' AND COLUMN_NAME='ticket_instructions');
SET @sql := IF(@c=0,"ALTER TABLE events ADD COLUMN ticket_instructions TEXT NULL AFTER ticket_url",'SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='events' AND COLUMN_NAME='ticket_qr');
SET @sql := IF(@c=0,"ALTER TABLE events ADD COLUMN ticket_qr VARCHAR(500) NULL AFTER ticket_instructions",'SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Explicit ticket types. No purchaser/attendee data is stored.
CREATE TABLE IF NOT EXISTS event_ticket_types (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event_id INT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  description VARCHAR(500) NULL,
  price DECIMAL(12,2) NULL,
  currency CHAR(3) NOT NULL DEFAULT 'COP',
  external_url VARCHAR(700) NULL,
  payment_instructions TEXT NULL,
  qr_image VARCHAR(500) NULL,
  status ENUM('draft','active','inactive','sold_out') NOT NULL DEFAULT 'active',
  available_from DATETIME NULL,
  available_until DATETIME NULL,
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_ticket_event_status (event_id,status,sort_order),
  CONSTRAINT fk_ticket_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Collective roster is distinct from event participation but uses artists as the canonical person/entity.
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='artists' AND COLUMN_NAME='collective_status');
SET @sql := IF(@c=0,"ALTER TABLE artists ADD COLUMN collective_status ENUM('none','active','alumni') NOT NULL DEFAULT 'none' AFTER status",'SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='artists' AND COLUMN_NAME='collective_order');
SET @sql := IF(@c=0,"ALTER TABLE artists ADD COLUMN collective_order INT NOT NULL DEFAULT 0 AFTER collective_status",'SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='artists' AND COLUMN_NAME='collective_joined_at');
SET @sql := IF(@c=0,"ALTER TABLE artists ADD COLUMN collective_joined_at DATETIME NULL AFTER collective_order",'SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='artists' AND COLUMN_NAME='collective_left_at');
SET @sql := IF(@c=0,"ALTER TABLE artists ADD COLUMN collective_left_at DATETIME NULL AFTER collective_joined_at",'SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

CREATE TABLE IF NOT EXISTS artist_collective_history (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  artist_id INT UNSIGNED NOT NULL,
  status ENUM('active','alumni') NOT NULL,
  started_at DATETIME NOT NULL,
  ended_at DATETIME NULL,
  note VARCHAR(500) NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_collective_artist (artist_id,started_at),
  FOREIGN KEY (artist_id) REFERENCES artists(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by) REFERENCES admins(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Archive/search foundations.
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='events' AND COLUMN_NAME='archive_year');
SET @sql := IF(@c=0,"ALTER TABLE events ADD COLUMN archive_year SMALLINT UNSIGNED NULL AFTER event_date",'SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
UPDATE events SET archive_year=YEAR(event_date) WHERE archive_year IS NULL AND event_date IS NOT NULL;

-- Useful indexes for the future archive and public filtering.
CREATE INDEX idx_events_city_date ON events(city,event_date);
CREATE INDEX idx_artists_collective ON artists(collective_status,collective_order,name);
