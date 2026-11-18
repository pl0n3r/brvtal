-- T03: additive order/payment foundation. Apply explicitly with the migration runner.
-- No live charges, raw payment credentials or buyer data are inserted by this DDL.
-- Amounts are integer COP; application services must validate snapshots and transitions.
CREATE TABLE IF NOT EXISTS ticket_buyers (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  public_id BINARY(16) NOT NULL,
  email VARCHAR(190) NULL,
  password_hash VARCHAR(255) NULL,
  email_verified_at DATETIME(6) NULL,
  consent_version VARCHAR(40) NULL,
  consent_recorded_at DATETIME(6) NULL,
  security_epoch BIGINT UNSIGNED NOT NULL DEFAULT 1,
  status ENUM('guest','active','blocked') NOT NULL DEFAULT 'guest',
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  UNIQUE KEY uq_ticket_buyers_public (public_id),
  UNIQUE KEY uq_ticket_buyers_email (email)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS ticket_buyer_tokens (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  buyer_id BIGINT UNSIGNED NOT NULL,
  purpose ENUM('verify_email','reset_password') NOT NULL,
  token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  expires_at_utc DATETIME(6) NOT NULL,
  consumed_at_utc DATETIME(6) NULL,
  revoked_at_utc DATETIME(6) NULL,
  created_at_utc DATETIME(6) NOT NULL DEFAULT (UTC_TIMESTAMP(6)),
  UNIQUE KEY uq_ticket_buyer_token_hash (token_hash),
  KEY idx_ticket_buyer_token_state (buyer_id,purpose,consumed_at_utc,revoked_at_utc,expires_at_utc),
  CONSTRAINT fk_ticket_buyer_token_owner FOREIGN KEY (buyer_id) REFERENCES ticket_buyers(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS ticket_buyer_sessions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  buyer_id BIGINT UNSIGNED NOT NULL,
  session_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  issued_epoch BIGINT UNSIGNED NOT NULL,
  expires_at_utc DATETIME(6) NOT NULL,
  revoked_at_utc DATETIME(6) NULL,
  created_at_utc DATETIME(6) NOT NULL DEFAULT (UTC_TIMESTAMP(6)),
  UNIQUE KEY uq_ticket_buyer_session_hash (session_hash),
  KEY idx_ticket_buyer_session_owner (buyer_id,revoked_at_utc,expires_at_utc),
  CONSTRAINT fk_ticket_buyer_session_owner FOREIGN KEY (buyer_id) REFERENCES ticket_buyers(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- Add a composite parent key without deleting or rewriting legacy ticket types.
SET @t03_type_event_index := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='event_ticket_types'
    AND INDEX_NAME='uq_t03_ticket_type_event'
);
SET @t03_type_event_ddl := IF(
  @t03_type_event_index=0,
  'ALTER TABLE event_ticket_types ADD UNIQUE KEY uq_t03_ticket_type_event (id, event_id)',
  'DO 0'
);
PREPARE t03_type_event_stmt FROM @t03_type_event_ddl;
EXECUTE t03_type_event_stmt;
DEALLOCATE PREPARE t03_type_event_stmt;

CREATE TABLE IF NOT EXISTS ticket_orders (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  public_id BINARY(16) NOT NULL,
  buyer_id BIGINT UNSIGNED NOT NULL,
  event_id INT UNSIGNED NOT NULL,
  checkout_key_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  status ENUM('draft','pending','paid','failed','cancelled','expired','refunded') NOT NULL DEFAULT 'draft',
  currency CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'COP',
  total_cop BIGINT UNSIGNED NOT NULL,
  expires_at_utc DATETIME(6) NULL,
  created_at_utc DATETIME(6) NOT NULL DEFAULT (UTC_TIMESTAMP(6)),
  updated_at_utc DATETIME(6) NOT NULL DEFAULT (UTC_TIMESTAMP(6)),
  UNIQUE KEY uq_ticket_orders_public (public_id),
  UNIQUE KEY uq_ticket_orders_checkout (checkout_key_hash),
  UNIQUE KEY uq_ticket_orders_id_event (id,event_id),
  KEY idx_ticket_orders_buyer (buyer_id,created_at_utc),
  KEY idx_ticket_orders_event_state (event_id,status),
  CONSTRAINT fk_ticket_orders_buyer FOREIGN KEY (buyer_id) REFERENCES ticket_buyers(id) ON DELETE RESTRICT,
  CONSTRAINT fk_ticket_orders_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE RESTRICT,
  CONSTRAINT chk_ticket_order_currency CHECK (currency='COP')
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS ticket_order_items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  order_id BIGINT UNSIGNED NOT NULL,
  event_id INT UNSIGNED NOT NULL,
  ticket_type_id INT UNSIGNED NOT NULL,
  ticket_name_snapshot VARCHAR(120) NOT NULL,
  quantity INT UNSIGNED NOT NULL,
  unit_price_cop BIGINT UNSIGNED NOT NULL,
  subtotal_cop BIGINT UNSIGNED NOT NULL,
  currency CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'COP',
  UNIQUE KEY uq_ticket_order_item_type (order_id,ticket_type_id),
  KEY idx_ticket_order_items_type (ticket_type_id,event_id),
  CONSTRAINT fk_ticket_items_order FOREIGN KEY (order_id,event_id) REFERENCES ticket_orders(id,event_id) ON DELETE RESTRICT,
  CONSTRAINT fk_ticket_items_type FOREIGN KEY (ticket_type_id,event_id) REFERENCES event_ticket_types(id,event_id) ON DELETE RESTRICT,
  CONSTRAINT chk_ticket_item_quantity CHECK (quantity>0),
  CONSTRAINT chk_ticket_item_currency CHECK (currency='COP')
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS ticket_payment_attempts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  order_id BIGINT UNSIGNED NOT NULL,
  provider VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  provider_reference VARCHAR(190) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  provider_transaction_id VARCHAR(190) CHARACTER SET ascii COLLATE ascii_bin NULL,
  idempotency_key_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  status ENUM('created','pending','approved','declined','error','refunded') NOT NULL DEFAULT 'created',
  gross_amount_cop BIGINT UNSIGNED NOT NULL,
  fee_amount_cop BIGINT UNSIGNED NULL,
  fee_vat_cop BIGINT UNSIGNED NULL,
  withheld_amount_cop BIGINT UNSIGNED NULL,
  net_amount_cop BIGINT UNSIGNED NULL,
  created_at_utc DATETIME(6) NOT NULL DEFAULT (UTC_TIMESTAMP(6)),
  updated_at_utc DATETIME(6) NOT NULL DEFAULT (UTC_TIMESTAMP(6)),
  UNIQUE KEY uq_ticket_payment_reference (provider,provider_reference),
  UNIQUE KEY uq_ticket_payment_transaction (provider,provider_transaction_id),
  UNIQUE KEY uq_ticket_payment_idempotency (idempotency_key_hash),
  KEY idx_ticket_payment_order_status (order_id,status),
  CONSTRAINT fk_ticket_payment_order FOREIGN KEY (order_id) REFERENCES ticket_orders(id) ON DELETE RESTRICT
) ENGINE=InnoDB;
