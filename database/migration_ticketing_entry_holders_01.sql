-- #2099: additive persistence only. Requires T03 and T04.
-- An absent holder row means legacy only after proving the schema and order relation.
-- The future verified-recipient confirmation must compare the expected revision,
-- increment it and rotate the credential atomically; no writer or activation here.
-- A request alone never changes the holder, payment or credential.
-- No invitation secret, address, QR or buyer profile is duplicated.
CREATE TABLE IF NOT EXISTS ticket_entry_holders (
  entry_id BIGINT UNSIGNED NOT NULL,
  buyer_id BIGINT UNSIGNED NOT NULL,
  revision BIGINT UNSIGNED NOT NULL,
  assigned_at_utc DATETIME(6) NOT NULL,
  PRIMARY KEY (entry_id),
  KEY idx_entry_holder_buyer (buyer_id),
  CONSTRAINT fk_entry_holder_entry FOREIGN KEY (entry_id)
    REFERENCES ticket_entries(id) ON DELETE RESTRICT,
  CONSTRAINT fk_entry_holder_buyer FOREIGN KEY (buyer_id)
    REFERENCES ticket_buyers(id) ON DELETE RESTRICT,
  CONSTRAINT ck_entry_holder_revision CHECK (revision > 0)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS ticket_entry_transfer_requests (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  entry_id BIGINT UNSIGNED NOT NULL,
  from_buyer_id BIGINT UNSIGNED NOT NULL,
  to_buyer_id BIGINT UNSIGNED NOT NULL,
  expected_revision BIGINT UNSIGNED NOT NULL COMMENT 'Zero denotes verified legacy absence only',
  status ENUM('pending','accepted','rejected','expired') NOT NULL DEFAULT 'pending',
  requested_at_utc DATETIME(6) NOT NULL,
  expires_at_utc DATETIME(6) NOT NULL,
  resolved_at_utc DATETIME(6) NULL,
  pending_entry_id BIGINT UNSIGNED GENERATED ALWAYS AS (
    CASE WHEN status = 'pending' THEN entry_id ELSE NULL END
  ) VIRTUAL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_entry_transfer_pending (pending_entry_id),
  KEY idx_entry_transfer_history (entry_id, id),
  KEY idx_entry_transfer_from (from_buyer_id),
  KEY idx_entry_transfer_to (to_buyer_id),
  CONSTRAINT fk_entry_transfer_entry FOREIGN KEY (entry_id)
    REFERENCES ticket_entries(id) ON DELETE RESTRICT,
  CONSTRAINT fk_entry_transfer_from FOREIGN KEY (from_buyer_id)
    REFERENCES ticket_buyers(id) ON DELETE RESTRICT,
  CONSTRAINT fk_entry_transfer_to FOREIGN KEY (to_buyer_id)
    REFERENCES ticket_buyers(id) ON DELETE RESTRICT,
  CONSTRAINT ck_entry_transfer_distinct CHECK (from_buyer_id <> to_buyer_id),
  CONSTRAINT ck_entry_transfer_expiry CHECK (expires_at_utc > requested_at_utc),
  CONSTRAINT ck_entry_transfer_resolution CHECK (
    (status = 'pending' AND resolved_at_utc IS NULL) OR
    (status IN ('accepted','rejected','expired') AND resolved_at_utc IS NOT NULL
      AND resolved_at_utc >= requested_at_utc)
  )
) ENGINE=InnoDB;
