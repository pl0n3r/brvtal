-- BRVTAL Media Relations 01
-- Additive/idempotent migration. Run once against the production BRVTAL database.
-- Existing media/content is preserved. Source deploy does not execute this migration.

CREATE TABLE IF NOT EXISTS media_relations (
  media_id INT UNSIGNED NOT NULL,
  related_type ENUM('event','artist','set','release') NOT NULL,
  related_id INT UNSIGNED NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  PRIMARY KEY (media_id,related_type,related_id),
  INDEX idx_media_relations_target (related_type,related_id,sort_order),
  CONSTRAINT fk_media_relations_media FOREIGN KEY (media_id) REFERENCES media(id) ON DELETE CASCADE
) ENGINE=InnoDB;
