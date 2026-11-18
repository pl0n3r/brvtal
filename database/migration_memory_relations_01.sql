CREATE TABLE IF NOT EXISTS memory_relations (
  memory_id INT UNSIGNED NOT NULL,
  related_type ENUM('event','artist','set','release') NOT NULL,
  related_id INT UNSIGNED NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  PRIMARY KEY (memory_id, related_type, related_id),
  INDEX idx_memory_relations_target (related_type, related_id, memory_id),
  CONSTRAINT fk_memory_relations_memory FOREIGN KEY (memory_id) REFERENCES memories(id) ON DELETE CASCADE
) ENGINE=InnoDB;
