-- #2260: immutable quote provenance; historical sales remain unknown (NULL).
-- Additive only; producers will write the snapshot in #2176.
ALTER TABLE ticket_pos_sales
    ADD COLUMN IF NOT EXISTS quote_source ENUM('door','stage','base') NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS quote_stage_code ENUM('early_bird','preventa','general','puerta') NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS quote_channel ENUM('door','manual') NULL DEFAULT NULL;
