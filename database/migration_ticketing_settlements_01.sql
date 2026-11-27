-- #2197: synthetic evidence only; no matching, payment mutation or provider calls.
CREATE TABLE IF NOT EXISTS ticket_settlements (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  payout_reference VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  payout_date DATE NOT NULL,
  file_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  status ENUM('imported','rolled_back') NOT NULL DEFAULT 'imported',
  imported_by INT UNSIGNED NOT NULL,
  imported_at_utc DATETIME(6) NOT NULL,
  rolled_back_by INT UNSIGNED NULL,
  rolled_back_at_utc DATETIME(6) NULL,
  UNIQUE KEY uq_settlement_reference (payout_reference),
  UNIQUE KEY uq_settlement_hash (file_sha256),
  KEY idx_settlement_status_date (status, payout_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ticket_settlement_lines (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  settlement_id BIGINT UNSIGNED NOT NULL,
  line_reference VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  transaction_reference VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  gross_cop BIGINT NULL,
  fee_cop BIGINT NULL,
  fee_vat_cop BIGINT NULL,
  withheld_cop BIGINT NULL,
  adjustment_cop BIGINT NULL,
  credited_cop BIGINT NOT NULL,
  UNIQUE KEY uq_settlement_line (settlement_id, line_reference),
  KEY idx_settlement_transaction (transaction_reference),
  CONSTRAINT fk_settlement_line_batch FOREIGN KEY (settlement_id) REFERENCES ticket_settlements(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
