-- BRVTAL admin password security foundation.
-- Additive only: credential epoch + reset-token state.

ALTER TABLE admins
    ADD COLUMN credential_epoch BIGINT UNSIGNED NOT NULL DEFAULT 1,
    ADD COLUMN password_changed_at DATETIME NULL;

CREATE TABLE IF NOT EXISTS admin_password_reset_tokens (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    admin_id INT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_admin_password_reset_admin (admin_id),
    UNIQUE KEY uq_admin_password_reset_hash (token_hash),
    KEY idx_admin_password_reset_expiry (expires_at),
    CONSTRAINT fk_admin_password_reset_admin
        FOREIGN KEY (admin_id) REFERENCES admins(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
