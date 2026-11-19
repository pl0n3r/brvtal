-- Add an optional address for an event's opt-in Google Maps embed.
-- Nullable and idempotent: never changes existing event rows.
ALTER TABLE events ADD COLUMN IF NOT EXISTS venue_address VARCHAR(300) NULL AFTER venue;
