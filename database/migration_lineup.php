<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';
try {
    db()->exec("
        CREATE TABLE IF NOT EXISTS event_artists (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            event_id INT UNSIGNED NOT NULL,
            artist_id INT UNSIGNED NOT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_event_artist (event_id, artist_id),
            KEY idx_event (event_id),
            KEY idx_artist (artist_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo 'BRVTAL lineup migration OK. Elimina este archivo después de ejecutarlo.';
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Migration error.';
}
