-- BRVTAL ControlBot staff-ops metadata.
-- Additive/idempotent. Existing accounts default to protected superadmin so
-- ControlBot cannot gain mutation authority merely because the adapter ships.

ALTER TABLE admins
  ADD COLUMN IF NOT EXISTS staff_role VARCHAR(32) NOT NULL DEFAULT 'superadmin' AFTER name;
