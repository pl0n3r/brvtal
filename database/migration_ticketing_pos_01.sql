-- T24 · POS preparatorio: sin dinero, proveedor ni compradores reales.
-- DDL aditivo; solo mediante runner de migraciones autorizado.
CREATE TABLE IF NOT EXISTS ticket_pos_shifts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  event_id INT UNSIGNED NOT NULL,
  operator_admin_id INT UNSIGNED NOT NULL,
  status ENUM('open','closed') NOT NULL DEFAULT 'open',
  opening_cash_cop BIGINT UNSIGNED NOT NULL,
  expected_cash_cop BIGINT UNSIGNED NULL,
  closing_cash_cop BIGINT UNSIGNED NULL,
  cash_difference_cop BIGINT NULL,
  opened_at_utc DATETIME(6) NOT NULL DEFAULT (UTC_TIMESTAMP(6)),
  closed_at_utc DATETIME(6) NULL,
  KEY idx_pos_shift_operator_event (operator_admin_id,event_id,status),
  CONSTRAINT fk_pos_shift_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE RESTRICT,
  CONSTRAINT fk_pos_shift_admin FOREIGN KEY (operator_admin_id) REFERENCES admins(id) ON DELETE RESTRICT,
  CONSTRAINT chk_pos_shift_close CHECK (
    (status='open' AND closed_at_utc IS NULL AND closing_cash_cop IS NULL
      AND expected_cash_cop IS NULL AND cash_difference_cop IS NULL)
    OR (status='closed' AND closed_at_utc IS NOT NULL AND closing_cash_cop IS NOT NULL
      AND expected_cash_cop IS NOT NULL AND cash_difference_cop IS NOT NULL))
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS ticket_pos_sales (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  shift_id BIGINT UNSIGNED NOT NULL,
  event_id INT UNSIGNED NOT NULL,
  operator_admin_id INT UNSIGNED NOT NULL,
  ticket_type_id INT UNSIGNED NOT NULL,
  order_id BIGINT UNSIGNED NULL,
  quantity INT UNSIGNED NOT NULL,
  unit_price_cop BIGINT UNSIGNED NOT NULL,
  amount_cop BIGINT UNSIGNED NOT NULL,
  method ENUM('cash','transfer','wompi_qr') NOT NULL,
  status ENUM('prepared','paid','cancelled') NOT NULL DEFAULT 'prepared',
  request_key_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  request_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  confirmation_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  expires_at_utc DATETIME(6) NOT NULL,
  created_at_utc DATETIME(6) NOT NULL DEFAULT (UTC_TIMESTAMP(6)),
  completed_at_utc DATETIME(6) NULL,
  UNIQUE KEY uq_pos_sale_request (request_key_hash),
  UNIQUE KEY uq_pos_sale_order (order_id),
  KEY idx_pos_sale_shift_state (shift_id,status),
  CONSTRAINT fk_pos_sale_shift FOREIGN KEY (shift_id) REFERENCES ticket_pos_shifts(id) ON DELETE RESTRICT,
  CONSTRAINT fk_pos_sale_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE RESTRICT,
  CONSTRAINT fk_pos_sale_operator FOREIGN KEY (operator_admin_id) REFERENCES admins(id) ON DELETE RESTRICT,
  CONSTRAINT fk_pos_sale_type FOREIGN KEY (ticket_type_id,event_id)
    REFERENCES event_ticket_types(id,event_id) ON DELETE RESTRICT,
  CONSTRAINT fk_pos_sale_order FOREIGN KEY (order_id,event_id)
    REFERENCES ticket_orders(id,event_id) ON DELETE RESTRICT,
  CONSTRAINT chk_pos_sale_qty CHECK (quantity BETWEEN 1 AND 10),
  CONSTRAINT chk_pos_sale_amount CHECK (amount_cop=quantity*unit_price_cop),
  CONSTRAINT chk_pos_sale_status CHECK (
    (status='prepared' AND order_id IS NULL AND completed_at_utc IS NULL)
    OR (status='paid' AND order_id IS NOT NULL AND completed_at_utc IS NOT NULL)
    OR (status='cancelled' AND order_id IS NULL AND completed_at_utc IS NOT NULL))
) ENGINE=InnoDB;
