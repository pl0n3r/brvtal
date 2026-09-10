<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/admin_auth.php';
brvtal_admin_require();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

$action = (string)($_GET['action'] ?? 'system');
$root = dirname(__DIR__);

$bytes = static function ($n): string {
    if ($n === false) {
        return 'N/A';
    }

    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    $n = (float)$n;

    while ($n >= 1024 && $i < count($units) - 1) {
        $n /= 1024;
        $i++;
    }

    return number_format($n, 2) . ' ' . $units[$i];
};

try {
    if ($action === 'health') {
        db()->query('SELECT 1');

        echo json_encode([
            'ok' => true,
            'api' => 'online',
            'database' => 'connected',
            'time' => date(DATE_ATOM),
        ]);

        exit;
    }

    if ($action === 'system') {
        $pdo = db();
        $pdo->query('SELECT 1');

        echo json_encode([
            'ok' => true,
            'php' => PHP_VERSION,
            'sapi' => PHP_SAPI,
            'session' => session_status() === PHP_SESSION_ACTIVE
                ? 'active'
                : 'inactive',
            'database' => 'connected',
            'driver' => $pdo->getAttribute(PDO::ATTR_DRIVER_NAME),
            'server' => $pdo->getAttribute(PDO::ATTR_SERVER_VERSION),
            'storage_free' => $bytes(@disk_free_space($root)),
            'storage_total' => $bytes(@disk_total_space($root)),
            'uploads_writable' => is_writable($root . '/uploads'),
            'logs_writable' => is_writable($root . '/storage/logs'),
            'time' => date(DATE_ATOM),
        ]);

        exit;
    }

    if ($action === 'database') {
        $pdo = db();

        $tables = $pdo
            ->query('SHOW TABLES')
            ->fetchAll(PDO::FETCH_COLUMN);

        $counts = [];

        foreach (
            [
                'events',
                'artists',
                'sets_media',
                'media',
                'pages',
                'settings',
                'analytics_events',
            ] as $table
        ) {
            if (in_array($table, $tables, true)) {
                $counts[$table] = (int)$pdo
                    ->query("SELECT COUNT(*) FROM `{$table}`")
                    ->fetchColumn();
            }
        }

        echo json_encode([
            'ok' => true,
            'driver' => $pdo->getAttribute(PDO::ATTR_DRIVER_NAME),
            'server' => $pdo->getAttribute(PDO::ATTR_SERVER_VERSION),
            'database' => $config['db']['name'] ?? '[configured]',
            'tables' => $tables,
            'counts' => $counts,
        ]);

        exit;
    }

    if ($action === 'storage') {
        echo json_encode([
            'ok' => true,
            'root_free' => $bytes(@disk_free_space($root)),
            'root_total' => $bytes(@disk_total_space($root)),
            'uploads_exists' => is_dir($root . '/uploads'),
            'uploads_writable' => is_writable($root . '/uploads'),
            'logs_exists' => is_dir($root . '/storage/logs'),
            'logs_writable' => is_writable($root . '/storage/logs'),
            'uploads_items' => is_dir($root . '/uploads')
                ? count(array_diff(scandir($root . '/uploads'), ['.', '..']))
                : 0,
        ]);

        exit;
    }

    if ($action === 'php') {
        $extensionsToCheck = [
            'PDO',
            'pdo_mysql',
            'mbstring',
            'json',
            'curl',
            'fileinfo',
            'openssl',
            'iconv',
        ];

        $extensions = [];

        foreach ($extensionsToCheck as $extension) {
            $extensions[$extension] = extension_loaded($extension);
        }

        echo json_encode([
            'ok' => true,
            'version' => PHP_VERSION,
            'sapi' => PHP_SAPI,
            'memory_limit' => ini_get('memory_limit'),
            'upload_max_filesize' => ini_get('upload_max_filesize'),
            'post_max_size' => ini_get('post_max_size'),
            'max_execution_time' => ini_get('max_execution_time'),
            'extensions' => $extensions,
        ]);

        exit;
    }

    if ($action === 'logs') {
        $file = $root . '/storage/logs/brvtal.log';

        $lines = is_file($file)
            ? file($file, FILE_IGNORE_NEW_LINES)
            : [];

        $lines = array_slice($lines, -300);

        echo json_encode([
            'ok' => true,
            'file' => 'storage/logs/brvtal.log',
            'lines' => count($lines),
            'content' => implode("\n", $lines),
        ]);

        exit;
    }

    echo json_encode([
        'ok' => false,
        'error' => 'UNKNOWN_ACTION',
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    brvtal_log(
        'TECH_ERROR',
        'Technical endpoint failed.',
        [
            'action' => $action,
            'class' => get_class($e),
        ]
    );

    http_response_code(500);

    echo json_encode([
        'ok' => false,
        'error' => 'TECH_ERROR',
    ]);
}
