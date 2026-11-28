-- Dormant marketing ledger; never execute on production by inference.
CREATE TABLE IF NOT EXISTS marketing_consents (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(254) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    consent_version VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    origin ENUM('checkout','registration','account') NOT NULL,
    granted_at_utc DATETIME(6) NOT NULL DEFAULT (UTC_TIMESTAMP(6)),
    revoked_at_utc DATETIME(6) NULL DEFAULT NULL,
    unsubscribe_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    active_slot TINYINT UNSIGNED NULL DEFAULT 1,
    UNIQUE KEY uq_marketing_active (email,active_slot),
    UNIQUE KEY uq_marketing_unsubscribe (unsubscribe_hash),
    CONSTRAINT ck_marketing_active CHECK (
        (revoked_at_utc IS NULL AND active_slot IS NOT NULL AND active_slot=1)
        OR (revoked_at_utc IS NOT NULL AND active_slot IS NULL)
    )
) ENGINE=InnoDB;
