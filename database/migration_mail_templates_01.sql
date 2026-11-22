-- #1886: additive, idempotent, no recipient/token columns and no mail side effects.
CREATE TABLE IF NOT EXISTS mail_template_registry (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  template_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  scope VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  revision INT UNSIGNED NOT NULL DEFAULT 0,
  published_version_id BIGINT UNSIGNED NULL,
  published_by_admin_id INT UNSIGNED NULL,
  published_at DATETIME(6) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_mail_template_scope (scope, template_key),
  KEY idx_mail_template_published (published_version_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mail_template_versions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  template_id BIGINT UNSIGNED NOT NULL,
  version_number INT UNSIGNED NOT NULL,
  kind VARCHAR(12) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  document_json LONGTEXT NOT NULL,
  author_admin_id INT UNSIGNED NOT NULL,
  first_published_at DATETIME(6) NULL,
  discarded_at DATETIME(6) NULL,
  discarded_by_admin_id INT UNSIGNED NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_mail_template_version (template_id, version_number),
  KEY idx_mail_template_version_created (template_id, created_at),
  CONSTRAINT fk_mail_template_version_registry
    FOREIGN KEY (template_id) REFERENCES mail_template_registry(id),
  CONSTRAINT ck_mail_template_document CHECK (JSON_VALID(document_json)),
  CONSTRAINT ck_mail_template_kind CHECK (kind IN ('draft','rollback'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
