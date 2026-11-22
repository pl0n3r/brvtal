-- Buyer auth T34: additive, data-preserving profile extension to T03 buyers.
-- T03 (ticket_buyers/tokens/sessions) MUST be applied before this migration.
-- The existing one-use token digests and session epochs remain authoritative.
ALTER TABLE ticket_buyers ADD COLUMN IF NOT EXISTS full_name VARCHAR(120) NULL AFTER email;
