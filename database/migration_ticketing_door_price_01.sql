-- Optional door tariff; NULL preserves the current stage/base fallback.
ALTER TABLE event_ticket_types
    ADD COLUMN IF NOT EXISTS door_price_cop INT UNSIGNED NULL DEFAULT NULL;
