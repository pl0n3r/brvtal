<?php
declare(strict_types=1);

require_once __DIR__ . '/public_health.php';

$loggerPath = __DIR__ . '/logger.php';
if (is_file($loggerPath)) {
    require_once $loggerPath;
}
if (!function_exists('brvtal_log')) {
    function brvtal_log(string $level, string $message, array $context = []): void {}
}

$configPath = __DIR__ . '/config.php';
if (!is_file($configPath)) {
    brvtal_log('FATAL', 'Missing config/config.php', ['expected' => $configPath]);
    http_response_code(500);
    exit('BRVTAL: falta config/config.php.');
}

try {
    $config = require $configPath;
    if (!is_array($config)) throw new RuntimeException('Invalid configuration.');
    date_default_timezone_set((string)($config['app']['timezone'] ?? 'America/Bogota'));
} catch (Throwable $e) {
    brvtal_log('EXCEPTION', 'Configuration loading failed.', ['message' => $e->getMessage()]);
    http_response_code(500);
    exit('BRVTAL: error de configuración.');
}

if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
}

function db(): PDO {
    static $pdo = null;
    global $config;
    if ($pdo instanceof PDO) return $pdo;
    try {
        $d = $config['db'] ?? [];
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $d['host'], (int)$d['port'], $d['name'], $d['charset'] ?? 'utf8mb4');
        $pdo = new PDO($dsn, $d['user'], $d['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);
        brvtal_log('DB', 'Database connection established.', [
            'driver' => $pdo->getAttribute(PDO::ATTR_DRIVER_NAME),
            'server' => $pdo->getAttribute(PDO::ATTR_SERVER_VERSION),
        ]);
        return $pdo;
    } catch (Throwable $e) {
        brvtal_log('DB_ERROR', 'Database connection failed.', ['message' => $e->getMessage()]);
        throw $e;
    }
}

function json_response(array $data, int $status = 200, array $headers = []): never {
    if (brvtal_public_health_request((string)($_SERVER['REQUEST_URI'] ?? ''))) {
        $data = brvtal_public_health_sanitize($data);
    }
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    foreach ($headers as $name => $value) header($name . ': ' . $value);
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    echo $json === false ? '{"ok":false,"error":"JSON_ENCODE_ERROR"}' : $json;
    exit;
}

function input_json(): array {
    $contentType = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
    if ($contentType !== '' && !str_contains($contentType, 'application/json')) return [];
    $raw = file_get_contents('php://input') ?: '';
    if (strlen($raw) > 2 * 1024 * 1024) json_response(['ok'=>false,'error'=>'PAYLOAD_TOO_LARGE'],413);
    $data = json_decode($raw, true);
    if ($raw !== '' && !is_array($data)) json_response(['ok'=>false,'error'=>'INVALID_JSON'],400);
    return is_array($data) ? $data : [];
}

function slugify(string $value): string {
    $value = trim($value);
    $value = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;
    $value = strtolower($value);
    $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
    return trim($value, '-');
}

function valid_url_or_empty(?string $value): ?string {
    $value = trim((string)$value);
    if ($value === '') return null;
    if (strlen($value) > 700 || !filter_var($value, FILTER_VALIDATE_URL)) return null;
    $scheme = strtolower((string)parse_url($value, PHP_URL_SCHEME));
    return in_array($scheme, ['http','https'], true) ? $value : null;
}
