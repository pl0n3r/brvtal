-- T10: additive, isolated ticketing transactional mail outbox (no live activation).
-- Apply explicitly via scripts/migrations.php; never from a PR merge.
CREATE TABLE IF NOT EXISTS ticket_mail_outbox (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  message_key VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  payload_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  related_ref VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  recipient_email VARCHAR(254) NOT NULL,
  subject VARCHAR(255) NOT NULL,
  body_text TEXT NOT NULL,
  state VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'queued',
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  first_attempt_at DATETIME NULL,
  next_attempt_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  locked_until DATETIME NULL,
  provider_message_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NULL,
  last_error_code VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
  accepted_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ticket_mail_message_key (message_key),
  KEY idx_ticket_mail_ready (state, next_attempt_at, locked_until),
  KEY idx_ticket_mail_relation (related_ref)
) ENGINE=InnoDB;
