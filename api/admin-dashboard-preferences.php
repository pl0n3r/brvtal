<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/admin_auth.php';
require_once __DIR__ . '/../config/admin_dashboard.php';

brvtal_admin_require();

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($method, ['GET','POST'], true)) {
    json_response(['ok'=>false,'error'=>'METHOD_NOT_ALLOWED'], 405, ['Allow'=>'GET, POST']);
}

$adminId = (int)($_SESSION['admin_id'] ?? 0);
if ($adminId < 1) {
    json_response(['ok'=>false,'error'=>'AUTH_REQUIRED'], 401);
}

try {
    $key = brvtalAdminDashboardSettingKey($adminId);
    $pdo = db();

    if ($method === 'GET') {
        $st = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key=? AND is_json=1 LIMIT 1');
        $st->execute([$key]);
        $raw = $st->fetchColumn();
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        $preferences = brvtalAdminDashboardNormalizePreferences(is_array($decoded) ? $decoded : []);
        json_response(['ok'=>true,'data'=>$preferences], 200, ['Cache-Control'=>'no-store']);
    }

    brvtal_admin_require_csrf();
    $input = input_json();
    $preferences = brvtalAdminDashboardNormalizePreferences(is_array($input) ? $input : []);
    $encoded = json_encode($preferences, JSON_UNESCAPED_SLASHES);
    if (!is_string($encoded)) throw new RuntimeException('DASHBOARD_PREFERENCE_ENCODING_FAILED');

    $st = $pdo->prepare(
        'INSERT INTO settings(setting_key,setting_value,is_json) VALUES(?,?,1) '
        . 'ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),is_json=1'
    );
    $st->execute([$key,$encoded]);

    json_response(['ok'=>true,'data'=>$preferences], 200, ['Cache-Control'=>'no-store']);
} catch (InvalidArgumentException $e) {
    json_response(['ok'=>false,'error'=>$e->getMessage()], 422, ['Cache-Control'=>'no-store']);
} catch (Throwable $e) {
    brvtal_log('ADMIN_DASHBOARD_PREFERENCES_ERROR', 'Admin Dashboard preferences failed', [
        'admin_id'=>$adminId,
        'class'=>$e::class,
    ]);
    json_response(['ok'=>false,'error'=>'INTERNAL_ERROR'], 500, ['Cache-Control'=>'no-store']);
}
