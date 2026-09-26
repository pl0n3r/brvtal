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

## public_gtm_measurement

- Categoría: `usage`
- Campos de software: `event_name`, `page_type`, `content_type`, `content_slug`, `content_title`, `section`, `action`, `destination`, `media_type`, `control`, `source`, `status`, `filter_type`, `relation_type`, `platform`, `result_state`, `content_id`, `position`, `depth`
- Finalidad: `public_usage_measurement`
- Base documentada: `review_required` (revisión jurídica requerida)
- Consentimiento: `review_required`
- Proveedores: `google_analytics`
- Retención: `review_required`
