-- BRVTAL Event timetable 01
-- Additive run-of-show storage. Event/Artist identities remain relational.
-- No attendee data, provider credentials, ticketing changes or destructive writes.

CREATE TABLE IF NOT EXISTS event_timetable_items (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event_id INT UNSIGNED NOT NULL,
  artist_id INT UNSIGNED NULL,
  label VARCHAR(180) NULL,
  starts_at_utc DATETIME NOT NULL,
  ends_at_utc DATETIME NOT NULL,
  timezone VARCHAR(64) NOT NULL,
  status ENUM('draft','approved') NOT NULL DEFAULT 'draft',
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_event_timetable_event_time (event_id, starts_at_utc, sort_order),
  INDEX idx_event_timetable_event_status (event_id, status, starts_at_utc),
  INDEX idx_event_timetable_artist (artist_id, starts_at_utc),
  CONSTRAINT fk_event_timetable_event
    FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  CONSTRAINT fk_event_timetable_artist
    FOREIGN KEY (artist_id) REFERENCES artists(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
