<?php
declare(strict_types=1);

require_once __DIR__ . '/config/public_robots.php';

$response = brvtal_public_robots_response((string) ($_SERVER['HTTP_HOST'] ?? ''));
foreach ($response['headers'] as $headerLine) {
    header($headerLine);
}
echo $response['body'];
