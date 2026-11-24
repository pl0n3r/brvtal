-- #1979: additive order operations and internal notes, never automatic production DDL.
-- Apply only through the existing explicit migration runner after T03/T05/T10.
-- No payment, contact data, QR credential, or existing order is mutated by DDL.
CREATE TABLE IF NOT EXISTS ticket_order_notes (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  order_id BIGINT UNSIGNED NOT NULL,
  actor_admin_id BIGINT UNSIGNED NOT NULL,
  note_text VARCHAR(280) NOT NULL,
  created_at_utc DATETIME(6) NOT NULL DEFAULT (UTC_TIMESTAMP(6)),
  KEY idx_ticket_order_note_history (order_id,created_at_utc,id),
  CONSTRAINT fk_ticket_order_note_order FOREIGN KEY (order_id)
    REFERENCES ticket_orders(id) ON DELETE RESTRICT
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS ticket_order_action_requests (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  order_id BIGINT UNSIGNED NOT NULL,
  actor_admin_id BIGINT UNSIGNED NOT NULL,
  action ENUM('note','resend','cancel_pending') NOT NULL,
  request_key_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  request_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  status ENUM('recorded','queued','cancelled') NOT NULL,
  mail_outbox_id BIGINT UNSIGNED NULL,
  note_id BIGINT UNSIGNED NULL,
  created_at_utc DATETIME(6) NOT NULL DEFAULT (UTC_TIMESTAMP(6)),
  UNIQUE KEY uq_ticket_order_action_request (request_key_hash),
  KEY idx_ticket_order_action_history (order_id,created_at_utc),
  CONSTRAINT fk_ticket_order_action_order FOREIGN KEY (order_id)
    REFERENCES ticket_orders(id) ON DELETE RESTRICT
) ENGINE=InnoDB;
