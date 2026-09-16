-- BRVTAL Media Relations 01
-- Additive/idempotent bridge between reusable Media and cultural entities.
-- Related targets are polymorphic, so target existence/publication is enforced
-- in application code. Media ownership remains canonical in `media`.

CREATE TABLE IF NOT EXISTS media_relations (
  media_id INT UNSIGNED NOT NULL,
  related_type ENUM('event','artist','set','release') NOT NULL,
  related_id INT UNSIGNED NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  PRIMARY KEY (media_id, related_type, related_id),
  INDEX idx_media_relations_target (related_type, related_id, sort_order, media_id),
  CONSTRAINT fk_media_relations_media
    FOREIGN KEY (media_id) REFERENCES media(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
