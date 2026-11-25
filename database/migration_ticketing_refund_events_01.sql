-- BRVTAL #1980: owner-authoritative event refund exception, additive schema.
-- Apply explicitly through migration workflow only after human approval.
-- No live refund, payment, Wompi API, external state change or destructive DDL.
CREATE TABLE IF NOT EXISTS ticket_refund_event_decisions (
    event_id INT UNSIGNED NOT NULL,
    cause ENUM('cancelled','postponed') NOT NULL,
    owner_admin_id INT UNSIGNED NOT NULL,
    declared_at_utc DATETIME(6) NOT NULL DEFAULT (UTC_TIMESTAMP(6)),
    PRIMARY KEY (event_id),
    KEY idx_ticket_refund_incident_owner (owner_admin_id,declared_at_utc),
    CONSTRAINT fk_ticket_refund_incident_event FOREIGN KEY (event_id)
        REFERENCES events(id) ON DELETE RESTRICT,
    CONSTRAINT fk_ticket_refund_incident_owner FOREIGN KEY (owner_admin_id)
        REFERENCES admins(id) ON DELETE RESTRICT
) ENGINE=InnoDB;
