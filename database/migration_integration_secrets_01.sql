-- At-rest encrypted integration secrets: additive, idempotent, no master key here.
CREATE TABLE IF NOT EXISTS integration_secrets (
  secret_key VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
  value_encrypted TEXT NOT NULL,
  updated_by_admin_id INT UNSIGNED NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_integration_secrets_updated (updated_at),
  CONSTRAINT fk_integration_secrets_admin FOREIGN KEY (updated_by_admin_id)
    REFERENCES admins(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
