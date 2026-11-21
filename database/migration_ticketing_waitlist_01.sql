-- T28: pre-live waitlist; depends on T03 buyers, T05 inventory and T10 mail outbox.
-- Additive only; migration is applied explicitly with backup/rollback D-059.
-- No order, charge, payment attempt or ticket is created by enrollment.
CREATE TABLE IF NOT EXISTS ticket_waitlist_entries (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  event_id INT UNSIGNED NOT NULL,
  ticket_type_id INT UNSIGNED NOT NULL,
  buyer_id BIGINT UNSIGNED NOT NULL,
  consent_version VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  consent_at_utc DATETIME(6) NOT NULL,
  status ENUM('waiting','offered','redeemed','unsubscribed') NOT NULL DEFAULT 'waiting',
  unsubscribe_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  offer_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  offer_expires_at_utc DATETIME(6) NULL,
  created_at_utc DATETIME(6) NOT NULL DEFAULT (UTC_TIMESTAMP(6)),
  updated_at_utc DATETIME(6) NOT NULL DEFAULT (UTC_TIMESTAMP(6)) ON UPDATE CURRENT_TIMESTAMP(6),
  UNIQUE KEY uq_waitlist_event_type_buyer (event_id,ticket_type_id,buyer_id),
  UNIQUE KEY uq_waitlist_unsubscribe (unsubscribe_hash),
  UNIQUE KEY uq_waitlist_offer (offer_hash),
  KEY idx_waitlist_priority (event_id,ticket_type_id,status,created_at_utc,id),
  KEY idx_waitlist_offer_expiry (event_id,status,offer_expires_at_utc),
  CONSTRAINT fk_waitlist_event FOREIGN KEY (event_id)
    REFERENCES events(id) ON DELETE RESTRICT,
  CONSTRAINT fk_waitlist_type FOREIGN KEY (ticket_type_id,event_id)
    REFERENCES event_ticket_types(id,event_id) ON DELETE RESTRICT,
  CONSTRAINT fk_waitlist_buyer FOREIGN KEY (buyer_id)
    REFERENCES ticket_buyers(id) ON DELETE RESTRICT,
  CONSTRAINT chk_waitlist_offer_state CHECK (
    (status='offered' AND offer_hash IS NOT NULL AND offer_expires_at_utc IS NOT NULL)
    OR (status<>'offered' AND offer_hash IS NULL AND offer_expires_at_utc IS NULL)
  )
) ENGINE=InnoDB;
