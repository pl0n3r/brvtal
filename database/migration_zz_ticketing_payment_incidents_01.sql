-- #2194: expand-only incident metadata; apply after the payment journal.
CREATE TABLE IF NOT EXISTS ticket_payment_incidents (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  attempt_id BIGINT UNSIGNED NOT NULL,
  evidence_event_id BIGINT UNSIGNED NOT NULL,
  kind ENUM('duplicate_payment') NOT NULL,
  created_at_utc DATETIME(6) NOT NULL DEFAULT (UTC_TIMESTAMP(6)),
  UNIQUE KEY uq_ticket_payment_incident (attempt_id,kind),
  CONSTRAINT fk_ticket_payment_incident_attempt
    FOREIGN KEY (attempt_id) REFERENCES ticket_payment_attempts(id) ON DELETE RESTRICT,
  CONSTRAINT fk_ticket_payment_incident_event
    FOREIGN KEY (evidence_event_id) REFERENCES ticket_payment_webhook_events(id) ON DELETE RESTRICT
) ENGINE=InnoDB;
