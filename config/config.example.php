<?php
declare(strict_types=1);

return [
    'app' => [
        'name' => 'BRVTAL',
        'base_url' => 'https://brvtal.com.co',
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
    'hosting' => [
        // Optional operational quota used by DISCADMIN System Status.
        // Production currently falls back safely to the known 25 GB Hostinger plan when omitted.
        'storage_quota_bytes' => 25 * 1024 * 1024 * 1024,
    ],
];
