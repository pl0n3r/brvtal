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

## contact_capture

- Categoría: `contact`
- Campos de software: `name`, `email`, `subject`, `message`, `entry_id`, `form_key`, `version_number`, `definition_sha256`, `idempotency_digest`, `created_at_utc`, `purpose_key`, `notice_version`, `retention_policy_key`, `retention_until_utc`, `state`, `redacted_at_utc`, `action_key`
- Finalidad: `contact_capture`
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

## privacy_controller_contact

- Categoría: `contact`
- Campos de software: `phone`
- Finalidad: `privacy_rights_contact_publication`
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

## sentry_php_error_telemetry

- Categoría: `usage`
- Campos de software: `event_id`, `timestamp`, `platform`, `level`, `environment`, `release`, `php_version`, `sapi`, `release_sha`, `exception_type`, `stack_frame`
- Finalidad: `php_runtime_error_monitoring`
- Base documentada: `review_required` (revisión jurídica requerida)
- Consentimiento: `review_required`
- Proveedores: `sentry`
- Retención: `review_required`

## staff_access_metadata

- Categoría: `usage`
- Campos de software: `last_access_at`, `failed_login_count`, `device_id`
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

## ticket_buyer_account

- Categoría: `contact`
- Campos de software: `public_id`, `email`, `full_name`, `email_verified_at`, `consent_version`, `consent_recorded_at`, `status`, `created_at`, `updated_at`
- Finalidad: `ticket_buyer_account`
- Base documentada: `review_required` (revisión jurídica requerida)
- Consentimiento: `review_required`
- Proveedores: ninguno_declarado
- Retención: `review_required`

## ticket_buyer_authentication

- Categoría: `authentication`
- Campos de software: `password_hash`, `security_epoch`, `token_hash`, `purpose`, `expires_at_utc`, `consumed_at_utc`, `revoked_at_utc`, `session_hash`, `issued_epoch`
- Finalidad: `ticket_buyer_authentication`
- Base documentada: `review_required` (revisión jurídica requerida)
- Consentimiento: `review_required`
- Proveedores: ninguno_declarado
- Retención: `review_required`

## ticket_mail_delivery

- Categoría: `contact`
- Campos de software: `message_key`, `payload_hash`, `related_ref`, `recipient_email`, `subject`, `body_text`, `state`, `attempts`, `first_attempt_at`, `next_attempt_at`, `locked_until`, `provider_message_id`, `last_error_code`, `accepted_at`, `created_at`, `updated_at`
- Finalidad: `ticket_transactional_email_delivery`
- Base documentada: `review_required` (revisión jurídica requerida)
- Consentimiento: `review_required`
- Proveedores: `resend`
- Retención: `review_required`

## ticket_order_payment

- Categoría: `financial`
- Campos de software: `buyer_id`, `event_id`, `public_id`, `checkout_key_hash`, `status`, `total_cop`, `ticket_type_id`, `ticket_name_snapshot`, `quantity`, `unit_price_cop`, `subtotal_cop`, `provider`, `provider_reference`, `provider_transaction_id`, `gross_amount_cop`, `fee_amount_cop`, `fee_vat_cop`, `withheld_amount_cop`, `net_amount_cop`, `created_at_utc`, `updated_at_utc`, `payment_reference`, `note_text`
- Finalidad: `ticket_order_payment_reconciliation`
- Base documentada: `review_required` (revisión jurídica requerida)
- Consentimiento: `review_required`
- Proveedores: ninguno_declarado
- Retención: `review_required`

## ticket_waitlist

- Categoría: `contact`
- Campos de software: `buyer_id`, `event_id`, `ticket_type_id`, `consent_version`, `consent_at_utc`, `status`, `unsubscribe_hash`, `offer_hash`, `offer_expires_at_utc`, `created_at_utc`, `updated_at_utc`
- Finalidad: `verified_opt_in_event_ticket_waitlist_and_expiring_offers`
- Base documentada: `review_required` (revisión jurídica requerida)
- Consentimiento: `review_required`
- Proveedores: `resend`
- Retención: `review_required`
