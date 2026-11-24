-- #1978: additive, nullable authenticated encryption of QR credentials.
-- Older tickets continue to work through their original email fragment.
-- No plaintext token or QR image is ever persisted.
ALTER TABLE ticket_entry_credentials
    ADD COLUMN token_encrypted VARBINARY(96) NULL AFTER token_hash;
