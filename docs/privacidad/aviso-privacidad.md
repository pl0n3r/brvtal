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
| legacy_admin_activity_ip | usage | ip_address | legacy_admin_activity_audit | review_required | review_required | ninguno_declarado | review_required |
| privacy_controller_contact | contact | phone | privacy_rights_contact_publication | review_required | review_required | ninguno_declarado | review_required |
| public_gtm_measurement | usage | event_name, page_type, content_type, content_slug, content_title, section, action, destination, media_type, control, source, status, filter_type, relation_type, platform, result_state, content_id, position, depth | public_usage_measurement | review_required | review_required | google_analytics | review_required |
| sentry_php_error_telemetry | usage | event_id, timestamp, platform, level, environment, release, php_version, sapi, release_sha, exception_type, stack_frame | php_runtime_error_monitoring | review_required | review_required | sentry | review_required |
| staff_access_metadata | usage | last_access_at, failed_login_count, device_id | staff_security_monitoring | review_required | review_required | ninguno_declarado | review_required |
| staff_contact | contact | email | staff_invitation_and_recovery | review_required | review_required | ninguno_declarado | staff_account_lifecycle |
| staff_identity | identification | staff_id, name, role, status | staff_administration | review_required | review_required | ninguno_declarado | staff_account_lifecycle |
| ticket_buyer_account | contact | public_id, email, full_name, email_verified_at, consent_version, consent_recorded_at, status, created_at, updated_at | ticket_buyer_account | review_required | review_required | ninguno_declarado | review_required |
| ticket_buyer_authentication | authentication | password_hash, security_epoch, token_hash, purpose, expires_at_utc, consumed_at_utc, revoked_at_utc, session_hash, issued_epoch | ticket_buyer_authentication | review_required | review_required | ninguno_declarado | review_required |
| ticket_mail_delivery | contact | message_key, payload_hash, related_ref, recipient_email, subject, body_text, state, attempts, first_attempt_at, next_attempt_at, locked_until, provider_message_id, last_error_code, accepted_at, created_at, updated_at | ticket_transactional_email_delivery | review_required | review_required | resend | review_required |
| ticket_order_payment | financial | buyer_id, event_id, public_id, checkout_key_hash, status, total_cop, ticket_type_id, ticket_name_snapshot, quantity, unit_price_cop, subtotal_cop, provider, provider_reference, provider_transaction_id, gross_amount_cop, fee_amount_cop, fee_vat_cop, withheld_amount_cop, net_amount_cop, created_at_utc, updated_at_utc, payment_reference, note_text | ticket_order_payment_reconciliation | review_required | review_required | ninguno_declarado | review_required |
| ticket_waitlist | contact | buyer_id, event_id, ticket_type_id, consent_version, consent_at_utc, status, unsubscribe_hash, offer_hash, offer_expires_at_utc, created_at_utc, updated_at_utc | verified_opt_in_event_ticket_waitlist_and_expiring_offers | review_required | review_required | resend | review_required |

## Autorización técnica pendiente

La integración que recoja autorización debe presentar una **casilla no premarcada** y un enlace visible a la política de tratamiento antes de registrar la decisión de la persona. Para tratamientos que requieran consentimiento explícito, la implementación debe conservar evidencia verificable de esa decisión.

Este borrador **no acredita que exista consentimiento**, no sustituye la revisión jurídica y no autoriza por sí mismo ningún tratamiento.
