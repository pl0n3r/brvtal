-- Build-ahead only. Definitions are append-only and referenced by their digest.
-- Callers must validate allowlisted payloads and supply an approved retention policy.
-- Redact payload and suppress pending intents atomically; preserve opaque audit identity.
CREATE TABLE IF NOT EXISTS form_definition_versions (
  form_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  version_number INT UNSIGNED NOT NULL,
  definition_sha256 BINARY(32) NOT NULL,
  definition_json LONGTEXT NOT NULL,
  created_at_utc DATETIME(6) NOT NULL,
  PRIMARY KEY (form_key, version_number),
  UNIQUE KEY uq_form_version_digest (form_key, version_number, definition_sha256),
  CONSTRAINT ck_form_version_number CHECK (version_number > 0),
  CONSTRAINT ck_form_version_key CHECK (form_key REGEXP '^[a-z][a-z0-9_]{0,63}$'),
  CONSTRAINT ck_form_version_json CHECK (JSON_VALID(definition_json) AND JSON_TYPE(definition_json) = 'OBJECT'),
  CONSTRAINT ck_form_version_digest CHECK (definition_sha256 = UNHEX(SHA2(definition_json, 256)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS form_entries (
  entry_id BINARY(16) NOT NULL,
  form_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  version_number INT UNSIGNED NOT NULL,
  definition_sha256 BINARY(32) NOT NULL,
  idempotency_digest BINARY(32) NOT NULL,
  payload_json LONGTEXT NULL,
  created_at_utc DATETIME(6) NOT NULL,
  purpose_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  notice_version VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  retention_policy_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  retention_until_utc DATETIME(6) NOT NULL,
  state ENUM('received','reviewed','redacted') NOT NULL,
  redacted_at_utc DATETIME(6) NULL,
  PRIMARY KEY (entry_id),
  UNIQUE KEY uq_form_entry_idempotency (form_key, idempotency_digest),
  KEY idx_form_entry_version (form_key, version_number, definition_sha256),
  KEY idx_form_entry_state (form_key, state, created_at_utc, entry_id),
  KEY idx_form_entry_retention (state, retention_until_utc, entry_id),
  CONSTRAINT fk_form_entry_version FOREIGN KEY (form_key, version_number, definition_sha256)
    REFERENCES form_definition_versions(form_key, version_number, definition_sha256)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT ck_form_entry_policy CHECK (CHAR_LENGTH(TRIM(purpose_key)) > 0 AND CHAR_LENGTH(TRIM(notice_version)) > 0
    AND CHAR_LENGTH(TRIM(retention_policy_key)) > 0 AND retention_until_utc > created_at_utc),
  CONSTRAINT ck_form_entry_redaction CHECK (
    (state = 'redacted' AND payload_json IS NULL AND redacted_at_utc IS NOT NULL AND redacted_at_utc >= created_at_utc)
    OR (state IN ('received','reviewed') AND payload_json IS NOT NULL AND JSON_VALID(payload_json)
      AND JSON_TYPE(payload_json) = 'OBJECT' AND redacted_at_utc IS NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS form_notification_intents (
  entry_id BINARY(16) NOT NULL,
  action_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  state ENUM('pending','enqueued','suppressed') NOT NULL,
  created_at_utc DATETIME(6) NOT NULL,
  PRIMARY KEY (entry_id, action_key),
  KEY idx_form_intent_pending (state, created_at_utc, entry_id),
  CONSTRAINT fk_form_intent_entry FOREIGN KEY (entry_id) REFERENCES form_entries(entry_id)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT ck_form_intent_action CHECK (action_key REGEXP '^[a-z][a-z0-9_]{0,63}$'),
  CONSTRAINT ck_form_intent_state CHECK (state IN ('pending','enqueued','suppressed'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
