# Política de tratamiento de datos personales

> Estado: documento técnico generado; **no constituye aprobación jurídica**.

## Responsable

- Nombre o razón social: [COMPLETAR POR EL DUEÑO]
- Identificación: [COMPLETAR POR EL DUEÑO]
- Dirección: [COMPLETAR POR EL DUEÑO]
- Canal de derechos: [COMPLETAR POR EL DUEÑO]

## Producto

`pl0n3r/brvtal`

## Tratamientos documentados

| Tratamiento | Categoría | Campos | Finalidad | Base documentada | Consentimiento | Proveedores | Retención |
| --- | --- | --- | --- | --- | --- | --- | --- |
| admin_activity | contact | admin_id, admin_name, admin_email, action, resource, resource_id, resource_label, changed_fields, before_json, after_json, meta_json, request_id, created_at | admin_activity_audit | review_required | review_required | ninguno_declarado | review_required |
| admin_identity | contact | email, name, is_active, last_login_at, created_at, updated_at | admin_access | review_required | review_required | ninguno_declarado | review_required |
| admin_password | authentication | password_hash | admin_authentication | review_required | review_required | ninguno_declarado | review_required |
| admin_recovery_codes | authentication | admin_id, code_hash, used_at, created_at | admin_account_recovery | review_required | review_required | ninguno_declarado | review_required |
| admin_session | authentication | admin_id, authenticated_at, issued_at, last_activity | admin_session | review_required | review_required | ninguno_declarado | session_30d |
| admin_totp | authentication | totp_enabled, totp_secret_enc, totp_confirmed_at | admin_mfa | review_required | review_required | ninguno_declarado | review_required |
| admin_totp_pending | authentication | totp_pending_admin_id, totp_pending_at, totp_pending_email | admin_mfa_challenge | review_required | review_required | ninguno_declarado | pending_10m |
| auth_password_rate_limit | usage | client_key_hash, attempts, blocked_until | auth_abuse_prevention | review_required | review_required | ninguno_declarado | window_15m |
| auth_totp_rate_limit | usage | client_key_hash, attempts, blocked_until | mfa_abuse_prevention | review_required | review_required | ninguno_declarado | window_15m |
| contact_delivery | contact | name, email, subject, message | contact_delivery | review_required | review_required | ninguno_declarado | external_mailbox_review_required |
| contact_rate_limit | usage | client_key_hash, timestamps | contact_abuse_prevention | review_required | review_required | ninguno_declarado | window_15m |
| internal_analytics_events | usage | event_name, page_url, referrer, locale, user_agent, created_at | internal_usage_analytics | review_required | review_required | ninguno_declarado | review_required |
| public_gtm_measurement | usage | event_name, page_type, content_type, content_slug, content_title, section, action, destination, media_type, control, source, status, filter_type, relation_type, platform, result_state, content_id, position, depth | public_usage_measurement | review_required | review_required | google_analytics | review_required |

## Derechos y revisión

Las solicitudes de acceso, corrección, actualización, supresión o revocación se canalizan mediante el canal de derechos indicado arriba. Las finalidades, bases, consentimientos, proveedores y retenciones aquí documentadas requieren la revisión jurídica aplicable antes de declararse aprobadas.
