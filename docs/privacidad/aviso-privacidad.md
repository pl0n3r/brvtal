# Aviso de privacidad y autorización

> Estado: borrador técnico generado; **revisión jurídica requerida**.

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
| admin_password_reset | authentication | admin_id, token_hash, expires_at, consumed_at, revoked_at, created_at | admin_password_recovery | review_required | review_required | ninguno_declarado | review_required |
| admin_recovery_codes | authentication | admin_id, code_hash, used_at, created_at | admin_account_recovery | review_required | review_required | ninguno_declarado | review_required |
| admin_recovery_delivery | contact | email, recovery_link, security_notification | admin_account_recovery_delivery | review_required | review_required | smtp_mail_provider | external_mailbox_review_required |
| admin_session | authentication | admin_id, authenticated_at, issued_at, last_activity, credential_epoch | admin_session | review_required | review_required | ninguno_declarado | session_30d |
| admin_totp | authentication | totp_enabled, totp_secret_enc, totp_confirmed_at | admin_mfa | review_required | review_required | ninguno_declarado | review_required |
| admin_totp_pending | authentication | totp_pending_admin_id, totp_pending_at, totp_pending_email | admin_mfa_challenge | review_required | review_required | ninguno_declarado | session_30d |
| auth_password_rate_limit | usage | client_key_hash, attempts, blocked_until | auth_abuse_prevention | review_required | review_required | ninguno_declarado | review_required |
| auth_totp_rate_limit | usage | client_key_hash, attempts, blocked_until | mfa_abuse_prevention | review_required | review_required | ninguno_declarado | review_required |
| contact_delivery | contact | name, email, subject, message | contact_delivery | review_required | review_required | ninguno_declarado | external_mailbox_review_required |
| contact_rate_limit | usage | client_key_hash, timestamps | contact_abuse_prevention | review_required | review_required | ninguno_declarado | review_required |
| internal_analytics_events | usage | event_name, page_url, referrer, locale, user_agent, created_at | internal_usage_analytics | review_required | review_required | ninguno_declarado | review_required |
| public_gtm_measurement | usage | event_name, page_type, content_type, content_slug, content_title, section, action, destination, media_type, control, source, status, filter_type, relation_type, platform, result_state, content_id, position, depth | public_usage_measurement | review_required | review_required | google_analytics | review_required |

## Autorización técnica pendiente

La integración que recoja autorización debe presentar una **casilla no premarcada** y un enlace visible a la política de tratamiento antes de registrar la decisión de la persona. Para tratamientos que requieran consentimiento explícito, la implementación debe conservar evidencia verificable de esa decisión.

Este borrador **no acredita que exista consentimiento**, no sustituye la revisión jurídica y no autoriza por sí mismo ningún tratamiento.
