-- BRVTAL #989: authentic optional duration for Sets.
-- This static ALTER is applied only after schema proof confirms absence;
-- the reconciliation registry baselines existing correct columns and blocks drift.
-- SQL is not replay-safe directly: use gated reconciliation with backup evidence.
ALTER TABLE sets_media ADD COLUMN duration_seconds INT UNSIGNED NULL AFTER description;
