-- BRVTAL SEO metadata foundation
-- Additive + idempotent. Safe to execute more than once.

ALTER TABLE events
  ADD COLUMN IF NOT EXISTS seo_title VARCHAR(190) NULL AFTER description,
  ADD COLUMN IF NOT EXISTS seo_description VARCHAR(320) NULL AFTER seo_title;

ALTER TABLE artists
  ADD COLUMN IF NOT EXISTS seo_title VARCHAR(190) NULL AFTER bio,
  ADD COLUMN IF NOT EXISTS seo_description VARCHAR(320) NULL AFTER seo_title;

ALTER TABLE sets_media
  ADD COLUMN IF NOT EXISTS seo_title VARCHAR(190) NULL AFTER description,
  ADD COLUMN IF NOT EXISTS seo_description VARCHAR(320) NULL AFTER seo_title;

ALTER TABLE releases
  ADD COLUMN IF NOT EXISTS seo_title VARCHAR(190) NULL AFTER description,
  ADD COLUMN IF NOT EXISTS seo_description VARCHAR(320) NULL AFTER seo_title;
