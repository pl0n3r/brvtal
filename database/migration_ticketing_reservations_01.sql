-- T05 · Stock configuration and temporary holds. Expand-only; no money or issued tickets.
-- Run ONLY through explicit migration tooling after T03; do not auto-migrate on deploy.
CREATE TABLE IF NOT EXISTS ticket_inventory_events (
  event_id INT UNSIGNED NOT NULL PRIMARY KEY,
  capacity INT UNSIGNED NOT NULL,
  CONSTRAINT fk_inventory_event FOREIGN KEY (event_id)
    REFERENCES events(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS ticket_inventory_types (
  ticket_type_id INT UNSIGNED NOT NULL PRIMARY KEY,
  event_id INT UNSIGNED NOT NULL,
  capacity INT UNSIGNED NOT NULL,
  courtesy_capacity INT UNSIGNED NOT NULL DEFAULT 0,
  KEY idx_inventory_type_event (event_id),
  CONSTRAINT chk_inventory_courtesy CHECK (courtesy_capacity <= capacity),
  CONSTRAINT fk_inventory_type FOREIGN KEY (ticket_type_id,event_id)
    REFERENCES event_ticket_types(id,event_id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS ticket_inventory_holds (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  order_id BIGINT UNSIGNED NOT NULL,
  event_id INT UNSIGNED NOT NULL,
  ticket_type_id INT UNSIGNED NOT NULL,
  quantity INT UNSIGNED NOT NULL,
  kind ENUM('paid','courtesy') NOT NULL,
  status ENUM('active','expired','consumed','released') NOT NULL DEFAULT 'active',
  expires_at_utc DATETIME(6) NOT NULL,
  created_at_utc DATETIME(6) NOT NULL DEFAULT (UTC_TIMESTAMP(6)),
  UNIQUE KEY uq_inventory_hold_order_type (order_id,ticket_type_id),
  KEY idx_inventory_event_expiry (event_id,status,expires_at_utc),
  KEY idx_inventory_type_expiry (ticket_type_id,status,expires_at_utc),
  CONSTRAINT chk_inventory_quantity CHECK (quantity > 0),
  CONSTRAINT fk_inventory_hold_order FOREIGN KEY (order_id,event_id)
    REFERENCES ticket_orders(id,event_id) ON DELETE RESTRICT,
  CONSTRAINT fk_inventory_hold_type FOREIGN KEY (ticket_type_id,event_id)
    REFERENCES event_ticket_types(id,event_id) ON DELETE RESTRICT
) ENGINE=InnoDB;
