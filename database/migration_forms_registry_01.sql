-- #2313: additive, dormant registry. Existing versions, entries and intents are untouched.
-- form_key and public_id are stable identities; slug is mutable routing metadata.
-- draft_json is the editable definition and policy bundle; never a public version.
-- The service in #2070 must validate policy contents and publish transactionally.
-- Publication policies are append-only snapshots: INSERT a new version, never
-- UPDATE or DELETE an existing policy. No policy is inferred for legacy versions.
CREATE TABLE IF NOT EXISTS form_publication_policies (
  form_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  version_number INT UNSIGNED NOT NULL,
  definition_sha256 BINARY(32) NOT NULL,
  notice_version VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  privacy_policy_json LONGTEXT NOT NULL,
  delivery_policy_json LONGTEXT NOT NULL,
  created_at_utc DATETIME(6) NOT NULL,
  PRIMARY KEY (form_key, version_number),
  KEY idx_form_policy_definition (form_key, version_number, definition_sha256),
  CONSTRAINT fk_form_policy_definition FOREIGN KEY (form_key, version_number, definition_sha256)
    REFERENCES form_definition_versions(form_key, version_number, definition_sha256)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT ck_form_policy_notice CHECK (notice_version REGEXP '^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$'),
  CONSTRAINT ck_form_policy_privacy CHECK (JSON_VALID(privacy_policy_json) AND JSON_TYPE(privacy_policy_json) = 'OBJECT'),
  CONSTRAINT ck_form_policy_delivery CHECK (JSON_VALID(delivery_policy_json) AND JSON_TYPE(delivery_policy_json) = 'OBJECT')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The nullable published pointer only references a version with a policy for
-- this form. Disable without deleting or changing history. Revision is a CAS
-- counter managed by the service; activation requires separate authorization.
CREATE TABLE IF NOT EXISTS form_definition_registry (
  form_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  public_id BINARY(16) NOT NULL,
  slug VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  revision INT UNSIGNED NOT NULL DEFAULT 1,
  draft_json LONGTEXT NOT NULL,
  state ENUM('draft','active','disabled') NOT NULL DEFAULT 'draft',
  published_version_number INT UNSIGNED NULL,
  created_at_utc DATETIME(6) NOT NULL,
  updated_at_utc DATETIME(6) NOT NULL,
  PRIMARY KEY (form_key),
  UNIQUE KEY uq_form_registry_public_id (public_id),
  UNIQUE KEY uq_form_registry_slug (slug),
  KEY idx_form_registry_publication (form_key, published_version_number),
  CONSTRAINT fk_form_registry_publication FOREIGN KEY (form_key, published_version_number)
    REFERENCES form_publication_policies(form_key, version_number)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT ck_form_registry_key CHECK (form_key REGEXP '^[a-z][a-z0-9_]{0,63}$'),
  CONSTRAINT ck_form_registry_slug CHECK (slug REGEXP '^[a-z0-9]+(-[a-z0-9]+)*$'),
  CONSTRAINT ck_form_registry_revision CHECK (revision > 0),
  CONSTRAINT ck_form_registry_draft CHECK (JSON_VALID(draft_json) AND JSON_TYPE(draft_json) = 'OBJECT'),
  CONSTRAINT ck_form_registry_state CHECK (
    (state = 'draft' AND published_version_number IS NULL)
    OR (state = 'active' AND published_version_number IS NOT NULL)
    OR state = 'disabled'),
  CONSTRAINT ck_form_registry_times CHECK (updated_at_utc >= created_at_utc)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
