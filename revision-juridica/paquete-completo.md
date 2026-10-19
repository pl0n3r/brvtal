# Paquete completo para revisión jurídica · BRVTAL

Estado: `documented_not_legally_approved` (sin aprobación jurídica).

---

## docs/privacidad/aviso-privacidad.md

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
| public_gtm_measurement | usage | event_name, page_type, content_type, content_slug, content_title, section, action, destination, media_type, control, source, status, filter_type, relation_type, platform, result_state, content_id, position, depth | public_usage_measurement | review_required | review_required | google_analytics | review_required |
| staff_access_metadata | usage | last_access_at, failed_login_count | staff_security_monitoring | review_required | review_required | ninguno_declarado | review_required |
| staff_contact | contact | email | staff_invitation_and_recovery | review_required | review_required | ninguno_declarado | staff_account_lifecycle |
| staff_identity | identification | staff_id, name, role, status | staff_administration | review_required | review_required | ninguno_declarado | staff_account_lifecycle |

## Autorización técnica pendiente

La integración que recoja autorización debe presentar una **casilla no premarcada** y un enlace visible a la política de tratamiento antes de registrar la decisión de la persona. Para tratamientos que requieran consentimiento explícito, la implementación debe conservar evidencia verificable de esa decisión.

Este borrador **no acredita que exista consentimiento**, no sustituye la revisión jurídica y no autoriza por sí mismo ningún tratamiento.


---

## docs/privacidad/canal-derechos.md

# Canal para ejercer derechos sobre datos personales

> Estado: borrador técnico generado; **revisión jurídica requerida**.

Producto: `pl0n3r/brvtal`

## Responsable y canal

- Nombre o razón social: [COMPLETAR POR EL DUEÑO]
- Identificación: [COMPLETAR POR EL DUEÑO]
- Dirección: [COMPLETAR POR EL DUEÑO]
- Canal de derechos: [COMPLETAR POR EL DUEÑO]

## Procedimiento pendiente

- Requisitos de la solicitud: [COMPLETAR POR EL DUEÑO] — revisión jurídica requerida.
- Flujo interno de atención: [COMPLETAR POR EL DUEÑO] — revisión jurídica requerida.
- Plazos aplicables: [COMPLETAR POR EL DUEÑO] — deben ser definidos con revisión jurídica; este kit no inventa un plazo legal.

El canal debe permitir solicitudes de acceso, corrección, actualización, supresión o revocación según corresponda. Este documento describe una frontera técnica y no constituye aprobación jurídica.


---

## docs/privacidad/politica-tratamiento.md

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
| public_gtm_measurement | usage | event_name, page_type, content_type, content_slug, content_title, section, action, destination, media_type, control, source, status, filter_type, relation_type, platform, result_state, content_id, position, depth | public_usage_measurement | review_required | review_required | google_analytics | review_required |
| staff_access_metadata | usage | last_access_at, failed_login_count | staff_security_monitoring | review_required | review_required | ninguno_declarado | review_required |
| staff_contact | contact | email | staff_invitation_and_recovery | review_required | review_required | ninguno_declarado | staff_account_lifecycle |
| staff_identity | identification | staff_id, name, role, status | staff_administration | review_required | review_required | ninguno_declarado | staff_account_lifecycle |

## Derechos y revisión

Las solicitudes de acceso, corrección, actualización, supresión o revocación se canalizan mediante el canal de derechos indicado arriba. Las finalidades, bases, consentimientos, proveedores y retenciones aquí documentadas requieren la revisión jurídica aplicable antes de declararse aprobadas.


---

## docs/privacidad/registro-tratamientos.md

# Registro de tratamientos

> Estado: inventario técnico generado; **revisión jurídica requerida**.

Producto: `pl0n3r/brvtal`

## admin_activity

- Categoría: `contact`
- Campos de software: `admin_id`, `admin_name`, `admin_email`, `action`, `resource`, `resource_id`, `resource_label`, `changed_fields`, `before_json`, `after_json`, `meta_json`, `request_id`, `created_at`
- Finalidad: `admin_activity_audit`
- Base documentada: `review_required` (revisión jurídica requerida)
- Consentimiento: `review_required`
- Proveedores: ninguno_declarado
- Retención: `review_required`

## admin_identity

- Categoría: `contact`
- Campos de software: `email`, `name`, `is_active`, `last_login_at`, `created_at`, `updated_at`
- Finalidad: `admin_access`
- Base documentada: `review_required` (revisión jurídica requerida)
- Consentimiento: `review_required`
- Proveedores: ninguno_declarado
- Retención: `review_required`

## admin_password

- Categoría: `authentication`
- Campos de software: `password_hash`
- Finalidad: `admin_authentication`
- Base documentada: `review_required` (revisión jurídica requerida)
- Consentimiento: `review_required`
- Proveedores: ninguno_declarado
- Retención: `review_required`

## admin_password_reset

- Categoría: `authentication`
- Campos de software: `admin_id`, `token_hash`, `expires_at`, `consumed_at`, `revoked_at`, `created_at`
- Finalidad: `admin_password_recovery`
- Base documentada: `review_required` (revisión jurídica requerida)
- Consentimiento: `review_required`
- Proveedores: ninguno_declarado
- Retención: `review_required`

## admin_recovery_codes

- Categoría: `authentication`
- Campos de software: `admin_id`, `code_hash`, `used_at`, `created_at`
- Finalidad: `admin_account_recovery`
- Base documentada: `review_required` (revisión jurídica requerida)
- Consentimiento: `review_required`
- Proveedores: ninguno_declarado
- Retención: `review_required`

## admin_recovery_delivery

- Categoría: `contact`
- Campos de software: `email`, `recovery_link`, `security_notification`
- Finalidad: `admin_account_recovery_delivery`
- Base documentada: `review_required` (revisión jurídica requerida)
- Consentimiento: `review_required`
- Proveedores: `smtp_mail_provider`
- Retención: `external_mailbox_review_required`

## admin_session

- Categoría: `authentication`
- Campos de software: `admin_id`, `authenticated_at`, `issued_at`, `last_activity`, `credential_epoch`
- Finalidad: `admin_session`
- Base documentada: `review_required` (revisión jurídica requerida)
- Consentimiento: `review_required`
- Proveedores: ninguno_declarado
- Retención: `session_30d`

## admin_totp

- Categoría: `authentication`
- Campos de software: `totp_enabled`, `totp_secret_enc`, `totp_confirmed_at`
- Finalidad: `admin_mfa`
- Base documentada: `review_required` (revisión jurídica requerida)
- Consentimiento: `review_required`
- Proveedores: ninguno_declarado
- Retención: `review_required`

## admin_totp_pending

- Categoría: `authentication`
- Campos de software: `totp_pending_admin_id`, `totp_pending_at`, `totp_pending_email`
- Finalidad: `admin_mfa_challenge`
- Base documentada: `review_required` (revisión jurídica requerida)
- Consentimiento: `review_required`
- Proveedores: ninguno_declarado
- Retención: `session_30d`

## auth_password_rate_limit

- Categoría: `usage`
- Campos de software: `client_key_hash`, `attempts`, `blocked_until`
- Finalidad: `auth_abuse_prevention`
- Base documentada: `review_required` (revisión jurídica requerida)
- Consentimiento: `review_required`
- Proveedores: ninguno_declarado
- Retención: `review_required`

## auth_totp_rate_limit

- Categoría: `usage`
- Campos de software: `client_key_hash`, `attempts`, `blocked_until`
- Finalidad: `mfa_abuse_prevention`
- Base documentada: `review_required` (revisión jurídica requerida)
- Consentimiento: `review_required`
- Proveedores: ninguno_declarado
- Retención: `review_required`

## contact_delivery

- Categoría: `contact`
- Campos de software: `name`, `email`, `subject`, `message`
- Finalidad: `contact_delivery`
- Base documentada: `review_required` (revisión jurídica requerida)
- Consentimiento: `review_required`
- Proveedores: ninguno_declarado
- Retención: `external_mailbox_review_required`

## contact_rate_limit

- Categoría: `usage`
- Campos de software: `client_key_hash`, `timestamps`
- Finalidad: `contact_abuse_prevention`
- Base documentada: `review_required` (revisión jurídica requerida)
- Consentimiento: `review_required`
- Proveedores: ninguno_declarado
- Retención: `review_required`

## internal_analytics_events

- Categoría: `usage`
- Campos de software: `event_name`, `page_url`, `referrer`, `locale`, `user_agent`, `created_at`
- Finalidad: `internal_usage_analytics`
- Base documentada: `review_required` (revisión jurídica requerida)
- Consentimiento: `review_required`
- Proveedores: ninguno_declarado
- Retención: `review_required`

## legacy_admin_activity_ip

- Categoría: `usage`
- Campos de software: `ip_address`
- Finalidad: `legacy_admin_activity_audit`
- Base documentada: `review_required` (revisión jurídica requerida)
- Consentimiento: `review_required`
- Proveedores: ninguno_declarado
- Retención: `review_required`

## public_gtm_measurement

- Categoría: `usage`
- Campos de software: `event_name`, `page_type`, `content_type`, `content_slug`, `content_title`, `section`, `action`, `destination`, `media_type`, `control`, `source`, `status`, `filter_type`, `relation_type`, `platform`, `result_state`, `content_id`, `position`, `depth`
- Finalidad: `public_usage_measurement`
- Base documentada: `review_required` (revisión jurídica requerida)
- Consentimiento: `review_required`
- Proveedores: `google_analytics`
- Retención: `review_required`

## staff_access_metadata

- Categoría: `usage`
- Campos de software: `last_access_at`, `failed_login_count`
- Finalidad: `staff_security_monitoring`
- Base documentada: `review_required` (revisión jurídica requerida)
- Consentimiento: `review_required`
- Proveedores: ninguno_declarado
- Retención: `review_required`

## staff_contact

- Categoría: `contact`
- Campos de software: `email`
- Finalidad: `staff_invitation_and_recovery`
- Base documentada: `review_required` (revisión jurídica requerida)
- Consentimiento: `review_required`
- Proveedores: ninguno_declarado
- Retención: `staff_account_lifecycle`

## staff_identity

- Categoría: `identification`
- Campos de software: `staff_id`, `name`, `role`, `status`
- Finalidad: `staff_administration`
- Base documentada: `review_required` (revisión jurídica requerida)
- Consentimiento: `review_required`
- Proveedores: ninguno_declarado
- Retención: `staff_account_lifecycle`


---

## docs/privacidad/retencion.md

# Tabla de retención

> Estado: calendario técnico documentado; **revisión jurídica requerida**.

Producto: `pl0n3r/brvtal`

| Tratamiento | Categoría | Retención declarada | Máximo común |
| --- | --- | --- | --- |
| admin_activity | contact | review_required | review_required |
| admin_identity | contact | review_required | review_required |
| admin_password | authentication | review_required | review_required |
| admin_password_reset | authentication | review_required | review_required |
| admin_recovery_codes | authentication | review_required | review_required |
| admin_recovery_delivery | contact | external_mailbox_review_required | review_required |
| admin_session | authentication | session_30d | review_required |
| admin_totp | authentication | review_required | review_required |
| admin_totp_pending | authentication | session_30d | review_required |
| auth_password_rate_limit | usage | review_required | review_required |
| auth_totp_rate_limit | usage | review_required | review_required |
| contact_delivery | contact | external_mailbox_review_required | review_required |
| contact_rate_limit | usage | review_required | review_required |
| internal_analytics_events | usage | review_required | review_required |
| legacy_admin_activity_ip | usage | review_required | review_required |
| public_gtm_measurement | usage | review_required | review_required |
| staff_access_metadata | usage | review_required | review_required |
| staff_contact | contact | staff_account_lifecycle | review_required |
| staff_identity | identification | staff_account_lifecycle | review_required |


---

## docs/privacidad/terminos-condiciones.md

# Términos y condiciones

> Estado: borrador técnico generado; **revisión jurídica requerida antes de publicación**.

Producto: `pl0n3r/brvtal`

## Responsable

- Nombre o razón social: [COMPLETAR POR EL DUEÑO]
- Identificación: [COMPLETAR POR EL DUEÑO]
- Dirección: [COMPLETAR POR EL DUEÑO]
- Canal de derechos: [COMPLETAR POR EL DUEÑO]

## Condiciones pendientes de definición

- Condiciones comerciales: [COMPLETAR POR EL DUEÑO] — revisión jurídica requerida.
- Niveles de servicio (SLA), si aplican: [COMPLETAR POR EL DUEÑO] — revisión jurídica requerida.
- Garantías y limitaciones aplicables: [COMPLETAR POR EL DUEÑO] — revisión jurídica requerida.
- Jurisdicción y mecanismo de solución de controversias: [COMPLETAR POR EL DUEÑO] — revisión jurídica requerida.

Este documento no inventa ni presume condiciones del producto. Los hechos comerciales y jurídicos anteriores deben completarse y aprobarse antes de su uso público.


