-- BRVTAL public translation cache 01
-- Persistent provider-agnostic cache for canonical Spanish public translations.
-- No credentials or provider-specific secrets are stored in this table.

CREATE TABLE IF NOT EXISTS public_translation_cache (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  source_hash CHAR(64) NOT NULL,
  source_locale VARCHAR(16) NOT NULL,
  target_locale VARCHAR(16) NOT NULL,
  translator_version VARCHAR(120) NOT NULL,
  translated_text MEDIUMTEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_public_translation_cache_identity (
    source_hash,
    source_locale,
    target_locale,
    translator_version
  ),
  INDEX idx_public_translation_cache_target (target_locale, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
