-- Safe additive migration: preserve existing memories and source Media links.
-- MariaDB supports IF NOT EXISTS for deploy tooling replay safety.
ALTER TABLE memories
  ADD COLUMN IF NOT EXISTS youtube_id CHAR(11) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER media_id;
