-- T23: additive, dormant promotion rules and immutable per-order discounts.
-- Applying DDL never enables checkout/charges; approval and backup remain separate.
CREATE TABLE IF NOT EXISTS ticket_promo_rules (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code_digest CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  event_id INT UNSIGNED NOT NULL,
  ticket_type_id INT UNSIGNED NULL,
  kind ENUM('fixed','percent','group3','group5') NOT NULL,
  value_cop INT UNSIGNED NOT NULL,
  starts_at_utc DATETIME(6) NOT NULL,
  ends_at_utc DATETIME(6) NOT NULL,
  max_uses INT UNSIGNED NOT NULL,
  buyer_limit INT UNSIGNED NOT NULL,
  uses_count INT UNSIGNED NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 0,
  campaign_key VARCHAR(48) NOT NULL DEFAULT '',
  created_at_utc DATETIME(6) NOT NULL DEFAULT (UTC_TIMESTAMP(6)),
  UNIQUE KEY uq_promo_digest (code_digest),
  KEY idx_promo_event_type (event_id,ticket_type_id),
  CONSTRAINT fk_promo_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE RESTRICT,
  CONSTRAINT fk_promo_type FOREIGN KEY (ticket_type_id,event_id)
    REFERENCES event_ticket_types(id,event_id) ON DELETE RESTRICT,
  CONSTRAINT chk_promo_window CHECK (starts_at_utc < ends_at_utc),
  CONSTRAINT chk_promo_limits CHECK (max_uses > 0 AND buyer_limit > 0 AND value_cop > 0),
  CONSTRAINT chk_promo_count CHECK (uses_count <= max_uses)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS ticket_promo_redemptions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  promo_id BIGINT UNSIGNED NOT NULL,
  order_id BIGINT UNSIGNED NOT NULL,
  buyer_id BIGINT UNSIGNED NOT NULL,
  code_digest CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  event_id INT UNSIGNED NOT NULL,
  ticket_type_id INT UNSIGNED NOT NULL,
  quantity INT UNSIGNED NOT NULL,
  subtotal_cop BIGINT UNSIGNED NOT NULL,
  discount_cop BIGINT UNSIGNED NOT NULL,
  total_cop BIGINT UNSIGNED NOT NULL,
  campaign_key VARCHAR(48) NOT NULL,
  created_at_utc DATETIME(6) NOT NULL DEFAULT (UTC_TIMESTAMP(6)),
  UNIQUE KEY uq_promo_order (order_id),
  KEY idx_promo_buyer (promo_id,buyer_id),
  CONSTRAINT fk_promo_redemption_rule FOREIGN KEY (promo_id)
    REFERENCES ticket_promo_rules(id) ON DELETE RESTRICT,
  CONSTRAINT fk_promo_redemption_order FOREIGN KEY (order_id)
    REFERENCES ticket_orders(id) ON DELETE RESTRICT,
  CONSTRAINT fk_promo_redemption_buyer FOREIGN KEY (buyer_id)
    REFERENCES ticket_buyers(id) ON DELETE RESTRICT,
  CONSTRAINT chk_promo_total CHECK (discount_cop <= subtotal_cop AND
    total_cop = subtotal_cop - discount_cop)
) ENGINE=InnoDB;
