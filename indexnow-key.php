<?php
declare(strict_types=1);

require_once __DIR__ . '/config/bootstrap.php';
require_once __DIR__ . '/config/indexnow.php';

$key = brvtal_indexnow_key();
if ($key === '') {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo "Not configured\n";
    exit;
}

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: public, max-age=300');
echo $key . "\n";
