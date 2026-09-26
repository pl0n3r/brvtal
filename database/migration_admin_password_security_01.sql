-- BRVTAL admin password security foundation.
-- Additive only: session credential epoch + hash-only reset-token registry.

ALTER TABLE admins
  ADD COLUMN IF NOT EXISTS credential_epoch BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER password_hash;

CREATE TABLE IF NOT EXISTS admin_password_reset_tokens (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  admin_id INT UNSIGNED NOT NULL,
  token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  expires_at DATETIME NOT NULL,
  consumed_at DATETIME NULL,
  revoked_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_admin_password_reset_hash (token_hash),
  KEY idx_admin_password_reset_admin_active (admin_id, consumed_at, revoked_at, expires_at),
  CONSTRAINT fk_admin_password_reset_admin
    FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE CASCADE
) ENGINE=InnoDB;


CREATE TABLE IF NOT EXISTS admin_password_mail_outbox (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  admin_id INT UNSIGNED NOT NULL,
  kind VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'reset',
  available_at DATETIME NOT NULL,
  claimed_at DATETIME NULL,
  delivered_at DATETIME NULL,
  attempts INT UNSIGNED NOT NULL DEFAULT 0,
  last_error_code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_admin_password_mail_kind (admin_id, kind),
  KEY idx_admin_password_mail_ready (kind, delivered_at, available_at, claimed_at),
  CONSTRAINT fk_admin_password_mail_admin
    FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE CASCADE
) ENGINE=InnoDB;
