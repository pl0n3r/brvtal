-- Optional web purchase limits. NULL preserves legacy behavior.
ALTER TABLE event_ticket_types
    ADD COLUMN IF NOT EXISTS min_per_order INT UNSIGNED NULL DEFAULT NULL;
ALTER TABLE event_ticket_types
    ADD COLUMN IF NOT EXISTS max_per_order INT UNSIGNED NULL DEFAULT NULL;
ALTER TABLE events
    ADD COLUMN IF NOT EXISTS max_per_buyer INT UNSIGNED NULL DEFAULT NULL;
