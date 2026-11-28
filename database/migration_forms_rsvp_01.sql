-- #2354: inert RSVP configuration and historical associations only.
-- Absence of a configuration row disables RSVP. Publication, capacity and
-- atomic entry linkage must be enforced by the future submission service.
CREATE TABLE IF NOT EXISTS event_rsvp_forms (
  event_id INT UNSIGNED NOT NULL,
  form_public_id BINARY(16) NOT NULL,
  capacity INT UNSIGNED NULL DEFAULT NULL,
  PRIMARY KEY (event_id),
  KEY idx_event_rsvp_form (form_public_id),
  CONSTRAINT fk_event_rsvp_form_event FOREIGN KEY (event_id) REFERENCES events(id)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_event_rsvp_form_registry FOREIGN KEY (form_public_id)
    REFERENCES form_definition_registry(public_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT ck_event_rsvp_capacity CHECK (capacity IS NULL OR capacity > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- History deliberately does not depend on the removable configuration row.
-- The entry already owns the form version, retention policy and payload.
CREATE TABLE IF NOT EXISTS event_rsvp_entries (
  entry_id BINARY(16) NOT NULL,
  event_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (entry_id),
  KEY idx_event_rsvp_entries_event (event_id, entry_id),
  CONSTRAINT fk_event_rsvp_entry FOREIGN KEY (entry_id) REFERENCES form_entries(entry_id)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_event_rsvp_entry_event FOREIGN KEY (event_id) REFERENCES events(id)
    ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
