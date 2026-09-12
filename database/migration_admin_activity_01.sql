-- BRVTAL Admin Activity 01
-- Append-only administrative audit trail foundation.
-- Additive + idempotent. Safe to execute more than once.

CREATE TABLE IF NOT EXISTS admin_activity_log (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  admin_id INT UNSIGNED NULL,
  admin_name VARCHAR(120) NULL,
  admin_email VARCHAR(190) NULL,
  action VARCHAR(40) NOT NULL,
  resource VARCHAR(40) NOT NULL,
  resource_id BIGINT UNSIGNED NULL,
  resource_label VARCHAR(220) NULL,
  changed_fields LONGTEXT NULL,
  before_json LONGTEXT NULL,
  after_json LONGTEXT NULL,
  meta_json LONGTEXT NULL,
  request_id CHAR(32) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_admin_activity_created (created_at),
  INDEX idx_admin_activity_resource (resource, resource_id, created_at),
  INDEX idx_admin_activity_admin (admin_id, created_at),
  CONSTRAINT fk_admin_activity_admin
    FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
