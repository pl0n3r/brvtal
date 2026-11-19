-- T08: expand-only, explicitly applied after migration_ticketing_orders_01.sql.
-- No automatic production migration, payments, mail delivery, or ticket issuance.
CREATE TABLE IF NOT EXISTS ticket_payment_webhook_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  attempt_id BIGINT UNSIGNED NOT NULL,
  provider_transaction_id VARCHAR(190) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  event_checksum CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  signed_status ENUM('PENDING','APPROVED','DECLINED','VOIDED','ERROR') NOT NULL,
  event_timestamp BIGINT UNSIGNED NOT NULL,
  received_at_utc DATETIME(6) NOT NULL DEFAULT (UTC_TIMESTAMP(6)),
  UNIQUE KEY uq_ticket_payment_webhook_event (attempt_id,event_checksum),
  KEY idx_ticket_payment_webhook_transaction (provider_transaction_id,received_at_utc),
  CONSTRAINT fk_ticket_payment_webhook_attempt
    FOREIGN KEY (attempt_id) REFERENCES ticket_payment_attempts(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- Durable T09 handoff; unique order prevents duplicated ticket issuance requests.
-- T09 owns consumption and must remain idempotent for each order/item/ordinal.
CREATE TABLE IF NOT EXISTS ticket_issue_outbox (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  order_id BIGINT UNSIGNED NOT NULL,
  payment_attempt_id BIGINT UNSIGNED NOT NULL,
  state ENUM('pending','processing','done','failed') NOT NULL DEFAULT 'pending',
  attempts INT UNSIGNED NOT NULL DEFAULT 0,
  next_attempt_at_utc DATETIME(6) NULL,
  created_at_utc DATETIME(6) NOT NULL DEFAULT (UTC_TIMESTAMP(6)),
  updated_at_utc DATETIME(6) NOT NULL DEFAULT (UTC_TIMESTAMP(6)),
  UNIQUE KEY uq_ticket_issue_outbox_order (order_id),
  KEY idx_ticket_issue_outbox_due (state,next_attempt_at_utc),
  CONSTRAINT fk_ticket_issue_outbox_order
    FOREIGN KEY (order_id) REFERENCES ticket_orders(id) ON DELETE RESTRICT,
  CONSTRAINT fk_ticket_issue_outbox_attempt
    FOREIGN KEY (payment_attempt_id) REFERENCES ticket_payment_attempts(id) ON DELETE RESTRICT
) ENGINE=InnoDB;
