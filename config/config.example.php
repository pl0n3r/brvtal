<?php
declare(strict_types=1);

return [
    'app' => [
        'name' => 'BRVTAL',
        'base_url' => 'https://www.brvtal.com.co',
        'timezone' => 'America/Bogota',
        'debug' => false,
    ],
    'db' => [
        'host' => 'localhost',
        'port' => 3306,
        'name' => 'REPLACE_DB_NAME',
        'user' => 'REPLACE_DB_USER',
        'pass' => 'REPLACE_DB_PASSWORD',
        'charset' => 'utf8mb4',
    ],
    'security' => [
        'session_name' => 'BRVTAL_ADMIN',
        'csrf_key' => 'REPLACE_WITH_LONG_RANDOM_SECRET',
        'encryption_key' => 'REPLACE_WITH_32_PLUS_BYTE_RANDOM_SECRET',
    ],
    'contact' => [
        // Public Contact form recipient. BRVTAL_CONTACT_TO can override this at runtime.
        'to' => 'contact@brvtal.com.co',
        // Only peers listed here may supply CF-Connecting-IP for Contact rate limiting.
        // Keep empty on shared hosting unless the immediate proxy addresses/CIDRs are known.
        'trusted_proxies' => [],
    ],
    'ticketing' => [
        'wompi' => [
            // Solo sandbox, usar pub_test_, prv_test_ y secretos de pruebas.
            'mode' => 'sandbox', 'sandbox_enabled' => '',
            'public_key' => '', 'private_key' => '',
            'integrity_secret' => '', 'events_secret' => '',
            'enabled_methods' => '', 'return_url' => '',
        ],
        'mail' => [
            // No activa un proveedor sin puertas de privacidad y DNS.
            'mode' => 'disabled', 'api_key' => '', 'domain' => '',
            'from' => '', 'webhook_secret' => '', 'webhook_enabled' => '',
            'lookup_secret' => '', 'testing' => '', 'privacy_approved' => '',
            'dns_verified' => '', 'allow_live' => '',
        ],
    ],
    'mail' => [
        'smtp' => [
            'host' => '', 'port' => 587, 'user' => '', 'password' => '',
            'encryption' => 'tls', 'from' => '', 'from_name' => 'BRVTAL',
        ],
    ],
    'analytics' => [
        'ga4' => ['property_id' => '', 'client_email' => '', 'private_key' => ''],
    ],
    'observability' => [
        // Private config takes precedence over BRVTAL_SENTRY_DSN.
        // Keep this blank in source-controlled examples; no Composer SDK is required.
        'sentry_dsn' => '',
    ],
    'hosting' => [
        // Optional operational quota used by DISCADMIN System Status.
        // Production currently falls back safely to the known 25 GB Hostinger plan when omitted.
        'storage_quota_bytes' => 25 * 1024 * 1024 * 1024,
    ],
    'backups' => [
        // Optional v1 guardrail for creating a ZIP copy of current uploads.
        // Database SQL + media inventory remain available even when media ZIP is skipped.
        'media_archive_max_bytes' => 2 * 1024 * 1024 * 1024,
    ],
];
