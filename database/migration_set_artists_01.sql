-- #1634: add the ordered many-to-many relationship, preserving sets_media.artist_id.
-- Backfill is performed idempotently by the gated migration runner after DDL.
CREATE TABLE IF NOT EXISTS set_artists (
  set_id INT UNSIGNED NOT NULL,
  artist_id INT UNSIGNED NOT NULL,
  sort_order INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (set_id, artist_id),
  KEY idx_set_artists_artist (artist_id),
  CONSTRAINT fk_set_artists_set FOREIGN KEY (set_id) REFERENCES sets_media(id) ON DELETE CASCADE,
  CONSTRAINT fk_set_artists_artist FOREIGN KEY (artist_id) REFERENCES artists(id) ON DELETE RESTRICT
) ENGINE=InnoDB;
