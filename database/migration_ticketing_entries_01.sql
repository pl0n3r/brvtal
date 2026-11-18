-- T04: persistence only. Requires T03 ticket_order_items from #1710.
-- Never run on production merely because the source is merged.
-- Public IDs and QR secrets MUST originate from CSPRNG random_bytes() at issuance;
-- store SHA-256(raw 32-byte secret) only, never the raw secret or QR image.
-- *_utc values are supplied as UTC by callers (no server-timezone assumption).

CREATE TABLE IF NOT EXISTS ticket_entries (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id BINARY(16) NOT NULL,
  order_item_id BIGINT UNSIGNED NOT NULL,
  event_id INT UNSIGNED NOT NULL,
  ticket_type_id INT UNSIGNED NOT NULL,
  unit_number INT UNSIGNED NOT NULL,
  status ENUM('issued','used','revoked','refunded') NOT NULL DEFAULT 'issued',
  issued_at_utc DATETIME(6) NOT NULL,
  used_at_utc DATETIME(6) NULL,
  revoked_at_utc DATETIME(6) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ticket_entries_public_id (public_id),
  UNIQUE KEY uq_ticket_entries_order_unit (order_item_id, unit_number),
  UNIQUE KEY uq_ticket_entries_id_event (id, event_id),
  KEY idx_ticket_entries_event_status (event_id, status),
  KEY idx_ticket_entries_ticket_type (ticket_type_id),
  CONSTRAINT fk_ticket_entries_order_item FOREIGN KEY (order_item_id)
    REFERENCES ticket_order_items(id) ON DELETE RESTRICT,
  CONSTRAINT fk_ticket_entries_event FOREIGN KEY (event_id)
    REFERENCES events(id) ON DELETE RESTRICT,
  CONSTRAINT fk_ticket_entries_ticket_type FOREIGN KEY (ticket_type_id)
    REFERENCES event_ticket_types(id) ON DELETE RESTRICT,
  CONSTRAINT chk_ticket_entry_unit CHECK (unit_number > 0)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS ticket_entry_credentials (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  entry_id BIGINT UNSIGNED NOT NULL,
  version INT UNSIGNED NOT NULL,
  token_hash BINARY(32) NOT NULL,
  token_algorithm VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'sha256-v1',
  status ENUM('active','revoked') NOT NULL DEFAULT 'active',
  created_at_utc DATETIME(6) NOT NULL,
  revoked_at_utc DATETIME(6) NULL,
  -- A UNIQUE nullable generated key allows any number of revoked historical tokens,
  -- but at most ONE active credential for an entry, including concurrent reissues.
  active_entry_id BIGINT UNSIGNED GENERATED ALWAYS AS (
    CASE WHEN status = 'active' THEN entry_id ELSE NULL END
  ) VIRTUAL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ticket_credential_hash (token_hash),
  UNIQUE KEY uq_ticket_credential_entry_version (entry_id, version),
  UNIQUE KEY uq_ticket_credential_one_active (active_entry_id),
  KEY idx_ticket_credential_entry_status (entry_id, status),
  CONSTRAINT fk_ticket_credential_entry FOREIGN KEY (entry_id)
    REFERENCES ticket_entries(id) ON DELETE RESTRICT,
  CONSTRAINT chk_ticket_credential_version CHECK (version > 0),
  CONSTRAINT chk_ticket_credential_revocation CHECK (
    (status = 'active' AND revoked_at_utc IS NULL) OR
    (status = 'revoked' AND revoked_at_utc IS NOT NULL)
  )
) ENGINE=InnoDB;

-- Audit keeps rejections with unknown/unmatched credentials (entry_id NULL),
-- without persisting QR plaintext or a failed token hash in the audit record.
CREATE TABLE IF NOT EXISTS ticket_checkins (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  request_id BINARY(16) NOT NULL,
  event_id INT UNSIGNED NOT NULL,
  entry_id BIGINT UNSIGNED NULL,
  credential_version INT UNSIGNED NULL,
  operator_admin_id INT UNSIGNED NOT NULL,
  device_id VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  outcome ENUM('accepted','rejected') NOT NULL,
  reason_code VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NULL,
  attempted_at_utc DATETIME(6) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ticket_checkin_request (request_id),
  KEY idx_ticket_checkins_event_time (event_id, attempted_at_utc),
  KEY idx_ticket_checkins_entry_time (entry_id, attempted_at_utc),
  KEY idx_ticket_checkins_operator_time (operator_admin_id, attempted_at_utc),
  CONSTRAINT fk_ticket_checkins_event FOREIGN KEY (event_id)
    REFERENCES events(id) ON DELETE RESTRICT,
  CONSTRAINT fk_ticket_checkins_entry_event FOREIGN KEY (entry_id, event_id)
    REFERENCES ticket_entries(id, event_id) ON DELETE RESTRICT,
  -- An accepted scan must identify a credential version of the SAME entry.
  -- Composite FK also preserves immutable credential history for audit.
  CONSTRAINT fk_ticket_checkins_entry_credential FOREIGN KEY (entry_id, credential_version)
    REFERENCES ticket_entry_credentials(entry_id, version) ON DELETE RESTRICT,
  CONSTRAINT fk_ticket_checkins_operator FOREIGN KEY (operator_admin_id)
    REFERENCES admins(id) ON DELETE RESTRICT,
  CONSTRAINT chk_ticket_checkin_result CHECK (
    (outcome = 'accepted' AND entry_id IS NOT NULL AND credential_version IS NOT NULL AND reason_code IS NULL) OR
    (outcome = 'rejected' AND reason_code IS NOT NULL)
  )
) ENGINE=InnoDB;
