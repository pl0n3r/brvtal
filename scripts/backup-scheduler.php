<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/config/bootstrap.php';
require_once dirname(__DIR__) . '/config/backup_automation.php';

try {
    $result = brvtal_backup_automation_run(db());
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(($result['status'] ?? '') === 'failed' ? 2 : 0);
} catch (Throwable $error) {
    fwrite(STDERR, 'BRVTAL BACKUP SCHEDULER: ' . ($error->getMessage() ?: 'FAILED') . PHP_EOL);
    exit(2);
}
