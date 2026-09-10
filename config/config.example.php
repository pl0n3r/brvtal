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
    ],
];
