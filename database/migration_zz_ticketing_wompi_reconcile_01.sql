-- #2276: dormant reconciliation coordination; no existing financial rows changed.
CREATE TABLE IF NOT EXISTS ticket_wompi_reconcile_claims (
  attempt_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  environment ENUM('sandbox','production') NOT NULL,
  last_started_at_utc DATETIME(6) NOT NULL,
  claim_id BINARY(16) NULL,
  expires_at_utc DATETIME(6) NULL,
  KEY idx_wompi_claim_expiry (environment,expires_at_utc),
  CONSTRAINT fk_wompi_claim_attempt FOREIGN KEY (attempt_id)
    REFERENCES ticket_payment_attempts(id) ON DELETE RESTRICT,
  CONSTRAINT chk_wompi_claim_environment CHECK (environment IN ('sandbox','production')),
  CONSTRAINT chk_wompi_claim_lease CHECK (
    (claim_id IS NULL AND expires_at_utc IS NULL) OR
    (claim_id IS NOT NULL AND expires_at_utc IS NOT NULL AND expires_at_utc > last_started_at_utc)
  )
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS ticket_wompi_reconcile_budget (
  environment ENUM('sandbox','production') NOT NULL PRIMARY KEY,
  window_started_at_utc DATETIME(6) NOT NULL,
  request_count BIGINT NOT NULL DEFAULT 0,
  CONSTRAINT chk_wompi_budget_environment CHECK (environment IN ('sandbox','production')),
  CONSTRAINT chk_wompi_budget_count CHECK (request_count >= 0)
) ENGINE=InnoDB;
