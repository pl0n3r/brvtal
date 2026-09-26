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
    $workspace = strtolower(trim((string)($_GET['workspace'] ?? 'dashboard')));
    $key = brvtalAdminWorkspaceSettingKey($adminId, $workspace);
    $pdo = db();

    if ($method === 'GET') {
        $st = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key=? AND is_json=1 LIMIT 1');
        $st->execute([$key]);
        $raw = $st->fetchColumn();
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        $preferences = brvtalAdminWorkspaceNormalizePreferences(is_array($decoded) ? $decoded : [], $workspace);
        json_response(['ok'=>true,'data'=>$preferences], 200, ['Cache-Control'=>'no-store']);
    }

    brvtal_admin_require_csrf();
    $input = input_json();
    $preferences = brvtalAdminWorkspaceNormalizePreferences(is_array($input) ? $input : [], $workspace);
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
    brvtal_log('ADMIN_DASHBOARD_PREFERENCES_ERROR', 'Admin configurable workspace preferences failed', [
        'admin_id'=>$adminId,
        'workspace'=>$workspace ?? 'invalid',
        'class'=>$e::class,
    ]);
    json_response(['ok'=>false,'error'=>'INTERNAL_ERROR'], 500, ['Cache-Control'=>'no-store']);
}
