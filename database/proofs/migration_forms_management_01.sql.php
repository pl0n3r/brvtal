<?php
declare(strict_types=1);

return [
    'table:form_entry_management',
    'column-type:form_entry_management.entry_id=binary(16)',
    'column:form_entry_management.revision',
    'column-type:form_entry_management.workflow_state=varchar(16)',
    'column-type:form_entry_management.action_kind=varchar(16)',
    'column:form_entry_management.actor_admin_id',
    'column-type:form_entry_management.created_at_utc=datetime(6)',
    'column-type:form_entry_management.note_text=varchar(1000)',
    'column-nullable:form_entry_management.note_text',
    'unique-index:form_entry_management.PRIMARY=entry_id,revision',
    'fk-reference:form_entry_management.fk_form_management_entry=entry_id:form_entries.entry_id',
    'check-constraint:form_entry_management.ck_form_management_identity',
    'check-constraint:form_entry_management.ck_form_management_state',
    'check-constraint:form_entry_management.ck_form_management_action',
];
