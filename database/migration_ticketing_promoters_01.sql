-- T26 dormant, synthetic promoter attribution. Requires T03 orders.
CREATE TABLE IF NOT EXISTS promoters (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  promoter_key VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  active TINYINT UNSIGNED NOT NULL DEFAULT 1,
  UNIQUE KEY uq_promoter_key (promoter_key),
  CONSTRAINT ck_promoter_active CHECK (active IN (0,1))
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS promoter_codes (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  promoter_id BIGINT UNSIGNED NOT NULL,
  code VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  active TINYINT UNSIGNED NOT NULL DEFAULT 1,
  UNIQUE KEY uq_promoter_code (code),
  KEY idx_promoter_codes_owner (promoter_id),
  CONSTRAINT fk_promoter_code_owner FOREIGN KEY (promoter_id) REFERENCES promoters(id) ON DELETE RESTRICT,
  CONSTRAINT ck_promoter_code_active CHECK (active IN (0,1))
) ENGINE=InnoDB;

ALTER TABLE ticket_orders
  ADD COLUMN IF NOT EXISTS promoter_code_id BIGINT UNSIGNED NULL DEFAULT NULL,
  ADD INDEX IF NOT EXISTS idx_order_promoter_code (promoter_code_id),
  ADD CONSTRAINT fk_order_promoter_code FOREIGN KEY IF NOT EXISTS (promoter_code_id)
    REFERENCES promoter_codes(id) ON DELETE RESTRICT;
