-- BRVTAL backend hardening: optional indexes for installations with large datasets.
-- Safe to run more than once only if your MariaDB version supports IF NOT EXISTS for indexes.
-- For the current BRVTAL scale these are optional; no schema change is required by the hardened API.

ALTER TABLE analytics_events ADD INDEX idx_analytics_page_date (page_url(191), created_at);
ALTER TABLE sets_media ADD INDEX idx_sets_status_order (status, sort_order, created_at);
ALTER TABLE event_artists ADD INDEX idx_event_artists_order (event_id, lineup_order);
