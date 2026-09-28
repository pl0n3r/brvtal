-- BRVTAL ControlBot staff-ops metadata.
-- Additive/idempotent. Existing accounts default to protected superadmin so
-- ControlBot cannot gain mutation authority merely because the adapter ships.

ALTER TABLE admins
  ADD COLUMN IF NOT EXISTS staff_role VARCHAR(32) NOT NULL DEFAULT 'superadmin' AFTER name;

ALTER TABLE admins
  ADD COLUMN IF NOT EXISTS staff_invitation_state VARCHAR(16) NOT NULL DEFAULT 'none' AFTER staff_role;
