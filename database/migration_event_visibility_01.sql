-- Existing events retain their public discovery behavior; safe to replay.
ALTER TABLE events ADD COLUMN IF NOT EXISTS visibility ENUM('public','unlisted') NOT NULL DEFAULT 'public' AFTER status;
