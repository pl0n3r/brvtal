-- T22: additive configuration for stages of an existing Event ticket type.
-- A stage quota is a cumulative threshold over that type's sold + held units.
-- No order item, buyer, payment, live product or existing ticket type is rewritten.
CREATE TABLE IF NOT EXISTS ticket_type_stages (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  ticket_type_id INT UNSIGNED NOT NULL,
  event_id INT UNSIGNED NOT NULL,
  code ENUM('early_bird','preventa','general','puerta') NOT NULL,
  price_cop INT UNSIGNED NOT NULL,
  cumulative_capacity INT UNSIGNED NOT NULL,
  opens_at_local DATETIME NOT NULL,
  closes_at_local DATETIME NOT NULL,
  UNIQUE KEY uq_ticket_stage_type_code (ticket_type_id,code),
  KEY idx_ticket_stage_event (event_id,ticket_type_id),
  CONSTRAINT chk_ticket_stage_price CHECK (price_cop > 0),
  CONSTRAINT chk_ticket_stage_capacity CHECK (cumulative_capacity > 0),
  CONSTRAINT chk_ticket_stage_window CHECK (opens_at_local < closes_at_local),
  CONSTRAINT fk_ticket_stage_type FOREIGN KEY (ticket_type_id,event_id)
    REFERENCES event_ticket_types(id,event_id) ON DELETE RESTRICT
) ENGINE=InnoDB;
