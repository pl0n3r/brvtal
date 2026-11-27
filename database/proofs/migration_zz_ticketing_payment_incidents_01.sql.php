<?php
declare(strict_types=1);

return [
    'table:ticket_payment_incidents',
    'column:ticket_payment_incidents.id',
    'column:ticket_payment_incidents.attempt_id',
    'column:ticket_payment_incidents.evidence_event_id',
    "column-type:ticket_payment_incidents.kind=enum('duplicate_payment')",
    'column-type:ticket_payment_incidents.created_at_utc=datetime(6)',
    'unique-index:ticket_payment_incidents.PRIMARY=id',
    'unique-index:ticket_payment_incidents.uq_ticket_payment_incident=attempt_id,kind',
    'fk-reference:ticket_payment_incidents.fk_ticket_payment_incident_attempt=attempt_id:ticket_payment_attempts.id',
    'fk-reference:ticket_payment_incidents.fk_ticket_payment_incident_event=evidence_event_id:ticket_payment_webhook_events.id',
];
