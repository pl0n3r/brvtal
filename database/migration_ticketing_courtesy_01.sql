-- T25: additive courtesy receipts. No payment approval, email or QR secret in storage.
-- T03/T04/T05 must be applied before this table; reconciliation orders it last.
CREATE TABLE IF NOT EXISTS ticket_courtesy_invites (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  event_id INT UNSIGNED NOT NULL,
  ticket_type_id INT UNSIGNED NOT NULL,
  order_id BIGINT UNSIGNED NOT NULL,
  entry_id BIGINT UNSIGNED NOT NULL,
  origin ENUM('courtesy') NOT NULL DEFAULT 'courtesy',
  category ENUM('artist','staff','press','partner') NOT NULL,
  recipient_digest CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  request_digest CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  reason VARCHAR(180) NOT NULL,
  authorized_by INT UNSIGNED NOT NULL,
  revoked_by INT UNSIGNED NULL,
  status ENUM('issued','used','revoked') NOT NULL DEFAULT 'issued',
  resend_requests SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  created_at_utc DATETIME(6) NOT NULL DEFAULT (UTC_TIMESTAMP(6)),
  revoked_at_utc DATETIME(6) NULL,
  UNIQUE KEY uq_courtesy_event_recipient (event_id,recipient_digest),
  UNIQUE KEY uq_courtesy_request (request_digest),
  UNIQUE KEY uq_courtesy_entry (entry_id),
  KEY idx_courtesy_event_status (event_id,status),
  CONSTRAINT fk_courtesy_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE RESTRICT,
  CONSTRAINT fk_courtesy_type FOREIGN KEY (ticket_type_id,event_id)
    REFERENCES event_ticket_types(id,event_id) ON DELETE RESTRICT,
  CONSTRAINT fk_courtesy_order FOREIGN KEY (order_id) REFERENCES ticket_orders(id) ON DELETE RESTRICT,
  CONSTRAINT fk_courtesy_entry FOREIGN KEY (entry_id) REFERENCES ticket_entries(id) ON DELETE RESTRICT,
  CONSTRAINT fk_courtesy_actor FOREIGN KEY (authorized_by) REFERENCES admins(id) ON DELETE RESTRICT,
  CONSTRAINT fk_courtesy_revoker FOREIGN KEY (revoked_by) REFERENCES admins(id) ON DELETE RESTRICT,
  CONSTRAINT chk_courtesy_revocation CHECK (
    (status='revoked' AND revoked_at_utc IS NOT NULL AND revoked_by IS NOT NULL)
    OR (status<>'revoked' AND revoked_at_utc IS NULL AND revoked_by IS NULL))
) ENGINE=InnoDB;
