-- T29: opt-in marketing and synthetic-only preview receipts; no Resend queue or sending trigger.
-- This DDL is additive and never executed on merge.
CREATE TABLE IF NOT EXISTS ticket_recovery_consents (
  buyer_id BIGINT UNSIGNED NOT NULL,
  event_id INT UNSIGNED NOT NULL,
  policy_version VARCHAR(40) NOT NULL,
  consented_at_utc DATETIME(6) NOT NULL,
  revoked_at_utc DATETIME(6) NULL,
  expires_at_utc DATETIME(6) NOT NULL,
  PRIMARY KEY (buyer_id,event_id),
  CONSTRAINT fk_recovery_consent_buyer FOREIGN KEY (buyer_id) REFERENCES ticket_buyers(id) ON DELETE RESTRICT,
  CONSTRAINT fk_recovery_consent_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS ticket_recovery_previews (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  order_id BIGINT UNSIGNED NOT NULL,
  buyer_id BIGINT UNSIGNED NOT NULL,
  event_id INT UNSIGNED NOT NULL,
  campaign_key VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  request_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  stage ENUM('cart','payment_open') NOT NULL,
  outcome ENUM('planned') NOT NULL DEFAULT 'planned',
  created_at_utc DATETIME(6) NOT NULL DEFAULT (UTC_TIMESTAMP(6)),
  retention_until_utc DATETIME(6) NOT NULL,
  UNIQUE KEY uq_recovery_request (request_hash),
  KEY idx_recovery_frequency (buyer_id,event_id,created_at_utc),
  KEY idx_recovery_reporting (event_id,campaign_key,outcome),
  CONSTRAINT fk_recovery_preview_order FOREIGN KEY (order_id) REFERENCES ticket_orders(id) ON DELETE RESTRICT,
  CONSTRAINT fk_recovery_preview_buyer FOREIGN KEY (buyer_id) REFERENCES ticket_buyers(id) ON DELETE RESTRICT
) ENGINE=InnoDB;