-- T18b: additive synthetic audit ledger. Do not execute against production without
-- a separate approved migration/recovery plan and privacy gate.
-- The current API denies ALL writes outside a disposable brvtal_test* database.
CREATE TABLE IF NOT EXISTS ticket_admin_actions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  order_id BIGINT UNSIGNED NOT NULL,
  actor_admin_id BIGINT UNSIGNED NOT NULL,
  action ENUM('note','contact_preview','recovery_preview','resend_preview') NOT NULL,
  request_key_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  request_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  note_text VARCHAR(280) NULL,
  status ENUM('recorded','pending','blocked') NOT NULL,
  created_at_utc DATETIME(6) NOT NULL DEFAULT (UTC_TIMESTAMP(6)),
  retention_until_utc DATETIME(6) NOT NULL,
  UNIQUE KEY uq_ticket_admin_request (request_key_hash),
  KEY idx_ticket_admin_order (order_id,created_at_utc),
  KEY idx_ticket_admin_retention (retention_until_utc),
  CONSTRAINT fk_ticket_admin_order FOREIGN KEY (order_id)
    REFERENCES ticket_orders(id) ON DELETE RESTRICT
) ENGINE=InnoDB;
