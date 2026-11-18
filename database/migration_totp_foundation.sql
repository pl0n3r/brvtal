-- BRVTAL FASE 0B-4
-- TOTP / Google Authenticator foundation.
-- This migration prepares the schema only; login enforcement is NOT enabled by it.

ALTER TABLE admins
    ADD COLUMN totp_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active,
    ADD COLUMN totp_secret_enc TEXT NULL AFTER totp_enabled,
    ADD COLUMN totp_confirmed_at DATETIME NULL AFTER totp_secret_enc;

CREATE TABLE IF NOT EXISTS admin_recovery_codes (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    admin_id INT UNSIGNED NOT NULL,
    code_hash VARCHAR(255) NOT NULL,
    used_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_admin_recovery_code (admin_id, code_hash),
    KEY idx_admin_recovery_admin (admin_id),
    CONSTRAINT fk_admin_recovery_admin
        FOREIGN KEY (admin_id) REFERENCES admins(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
