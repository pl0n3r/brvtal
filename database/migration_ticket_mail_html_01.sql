-- #1889 additive, no live delivery or credentials. Applied only by the protected migration path.
-- Existing queued messages remain text-only and do not need rewriting.
ALTER TABLE ticket_mail_outbox
  ADD COLUMN IF NOT EXISTS body_html MEDIUMTEXT NULL AFTER body_text,
  ADD COLUMN IF NOT EXISTS template_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER body_html,
  ADD COLUMN IF NOT EXISTS template_version BIGINT UNSIGNED NULL AFTER template_key;
