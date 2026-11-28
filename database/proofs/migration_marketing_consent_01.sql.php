<?php
declare(strict_types=1);

return [
    'table:marketing_consents',
    'column:marketing_consents.id',
    'column-type:marketing_consents.email=varchar(254)',
    'column-type:marketing_consents.consent_version=varchar(64)',
    "column-type:marketing_consents.origin=enum('checkout','registration','account')",
    'column-type:marketing_consents.granted_at_utc=datetime(6)',
    'column-type:marketing_consents.revoked_at_utc=datetime(6)',
    'column-nullable:marketing_consents.revoked_at_utc',
    'column-type:marketing_consents.unsubscribe_hash=char(64)',
    'column-nullable:marketing_consents.active_slot',
    'unique-index:marketing_consents.PRIMARY=id',
    'unique-index:marketing_consents.uq_marketing_active=email,active_slot',
    'unique-index:marketing_consents.uq_marketing_unsubscribe=unsubscribe_hash',
    'check-constraint:marketing_consents.ck_marketing_active',
];
