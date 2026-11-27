-- Administrative workflow is independent of capture/redaction state.
-- Serialize changes using the parent entry lock; revisions are append-only.
-- Notes may be cleared by the separately gated retention/suppression service.
CREATE TABLE IF NOT EXISTS form_entry_management (
  entry_id BINARY(16) NOT NULL,
  revision INT UNSIGNED NOT NULL,
  workflow_state VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  action_kind VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  actor_admin_id INT UNSIGNED NOT NULL,
  created_at_utc DATETIME(6) NOT NULL,
  note_text VARCHAR(1000) NULL,
  PRIMARY KEY (entry_id, revision),
  CONSTRAINT fk_form_management_entry FOREIGN KEY (entry_id) REFERENCES form_entries(entry_id)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT ck_form_management_identity CHECK (revision > 0 AND actor_admin_id > 0),
  CONSTRAINT ck_form_management_state CHECK (workflow_state IN ('new','in_progress','resolved','archived','spam')),
  CONSTRAINT ck_form_management_action CHECK (
    (action_kind = 'transition' AND note_text IS NULL)
    OR (action_kind = 'note' AND (note_text IS NULL OR CHAR_LENGTH(TRIM(note_text)) > 0)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
