<?php
declare(strict_types=1);

return [
    'table:event_rsvp_forms',
    'column:event_rsvp_forms.event_id',
    'column-type:event_rsvp_forms.form_public_id=binary(16)',
    'column-unsigned-nullable:event_rsvp_forms.capacity',
    'unique-index:event_rsvp_forms.PRIMARY=event_id',
    'index:event_rsvp_forms.idx_event_rsvp_form',
    'fk-reference:event_rsvp_forms.fk_event_rsvp_form_event=event_id:events.id',
    'fk-reference:event_rsvp_forms.fk_event_rsvp_form_registry=form_public_id:form_definition_registry.public_id',
    'check-constraint:event_rsvp_forms.ck_event_rsvp_capacity',
    'table:event_rsvp_entries',
    'column-type:event_rsvp_entries.entry_id=binary(16)',
    'column:event_rsvp_entries.event_id',
    'unique-index:event_rsvp_entries.PRIMARY=entry_id',
    'index:event_rsvp_entries.idx_event_rsvp_entries_event',
    'fk-reference:event_rsvp_entries.fk_event_rsvp_entry=entry_id:form_entries.entry_id',
    'fk-reference:event_rsvp_entries.fk_event_rsvp_entry_event=event_id:events.id',
];
