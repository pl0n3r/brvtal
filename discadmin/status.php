<?php
declare(strict_types=1);

/*
 * BRVTAL DISCADMIN — SYSTEM STATUS
 * Upload this file as:
 * /discadmin/status.php
 *
 * This diagnostic page is intentionally independent from the admin UI.
 * It will continue reporting WEB/PHP status even when the database fails.
 */

error_reporting(E_ALL);
ini_set('display_errors', '0');

$root = dirname(__DIR__);
$configFile = $root . '/config/config.php';
$bootstrapFile = $root . '/config/bootstrap.php';

$checks = [];

function checkRow(string $name, string $status, string $detail = ''): array {
    return ['name' => $name, 'status' => $status, 'detail' => $detail];
}

/* WEB / PHP */
$checks[] = checkRow('WEB', 'ONLINE', 'PHP executed this diagnostic successfully.');
$checks[] = checkRow('PHP', 'ONLINE', PHP_VERSION);

/* CONFIG */
$configLoaded = false;
$configError = '';
if (is_file($configFile)) {
    try {
        require_once $configFile;
        $configLoaded = true;
    } catch (Throwable $e) {
        $configError = $e->getMessage();
    }
}
$checks[] = checkRow(
    'CONFIG',
    $configLoaded ? 'LOADED' : 'FAILED',
    $configLoaded ? basename($configFile) : ($configError ?: 'config/config.php not found')
);

/* SESSION */
$sessionOk = false;
$sessionDetail = '';
try {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $sessionOk = session_status() === PHP_SESSION_ACTIVE;
    $sessionDetail = $sessionOk ? 'PHP session is available.' : 'Session could not be started.';
} catch (Throwable $e) {
    $sessionDetail = $e->getMessage();
}
$checks[] = checkRow('SESSION', $sessionOk ? 'READY' : 'FAILED', $sessionDetail);

/* FILESYSTEM */
foreach ([
    'STORAGE' => $root . '/storage',
    'UPLOADS' => $root . '/uploads',
] as $label => $path) {
    $exists = is_dir($path);
    $writable = $exists && is_writable($path);
    $checks[] = checkRow(
        $label,
        !$exists ? 'MISSING' : ($writable ? 'READY' : 'READ-ONLY'),
        $path
    );
}

/* DATABASE — isolated so a DB failure never blanks the page */
$dbOk = false;
$dbDetail = 'Not tested.';
$dbVersion = 'Unknown';

if ($configLoaded) {
    try {
        $pdo = null;

        /*
         * Supports the BRVTAL V4 bootstrap/config convention.
         * If bootstrap defines $pdo, reuse it; otherwise attempt to
         * construct PDO from common constants/variables.
         */
        if (is_file($bootstrapFile)) {
            require_once $bootstrapFile;
        }

        if (isset($pdo) && $pdo instanceof PDO) {
            $pdo->query('SELECT 1');
            $dbVersion = (string)$pdo->query('SELECT VERSION()')->fetchColumn();
            $dbOk = true;
            $dbDetail = 'Connection and SELECT 1 succeeded.';
        } elseif (isset($db) && $db instanceof PDO) {
            $db->query('SELECT 1');
            $dbVersion = (string)$db->query('SELECT VERSION()')->fetchColumn();
            $dbOk = true;
            $dbDetail = 'Connection and SELECT 1 succeeded.';
        } else {
            $dbDetail = 'PDO connection was not exposed by config/bootstrap.';
        }
    } catch (Throwable $e) {
        $dbDetail = $e->getMessage();
    }
}

$checks[] = checkRow('DATABASE', $dbOk ? 'CONNECTED' : 'FAILED', $dbDetail);
$checks[] = checkRow('DB ENGINE', $dbOk ? 'ONLINE' : 'UNKNOWN', $dbVersion);

/* API */
$apiUrl = rtrim(
    (isset($_SERVER['REQUEST_SCHEME']) ? $_SERVER['REQUEST_SCHEME'] : 'https')
    . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'),
    '/'
) . '/api/index.php/health';

$apiStatus = 'UNKNOWN';
$apiDetail = 'Health endpoint could not be tested from this server.';

if (function_exists('curl_init')) {
    try {
        $ch = curl_init($apiUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($body !== false && $code >= 200 && $code < 500) {
            $apiStatus = ($code >= 200 && $code < 300) ? 'ONLINE' : 'DEGRADED';
            $apiDetail = "HTTP {$code}";
        } else {
            $apiStatus = 'OFFLINE';
            $apiDetail = $err ?: "HTTP {$code}";
        }
    } catch (Throwable $e) {
        $apiStatus = 'OFFLINE';
        $apiDetail = $e->getMessage();
    }
} else {
    $apiDetail = 'PHP cURL extension is not enabled.';
}
$checks[] = checkRow('API', $apiStatus, $apiDetail);

/* Overall */
$bad = array_filter($checks, fn($c) => in_array($c['status'], ['FAILED', 'OFFLINE', 'MISSING'], true));
$overall = count($bad) === 0 ? 'HEALTHY' : 'CHECK REQUIRED';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>BRVTAL // System Status</title>
<style>
:root{color-scheme:dark}
*{box-sizing:border-box}
body{margin:0;background:#050505;color:#eee;font-family:Arial,Helvetica,sans-serif;min-height:100vh}
body:before{content:"";position:fixed;inset:0;pointer-events:none;opacity:.08;background:repeating-linear-gradient(0deg,transparent 0 3px,#fff 4px,#fff 5px)}
.wrap{width:min(920px,calc(100% - 32px));margin:0 auto;padding:42px 0}
header{border-bottom:1px solid #333;padding-bottom:24px;margin-bottom:24px}
.kicker{font:700 11px/1 monospace;letter-spacing:.3em;color:#aaa}
h1{font-size:clamp(38px,8vw,82px);line-height:.85;margin:12px 0;text-transform:uppercase;letter-spacing:-.06em}
h1 span{color:#e6ff00}
.meta{font:12px monospace;color:#888}
.overall{border:1px solid #333;padding:18px;margin-bottom:18px;display:flex;justify-content:space-between;gap:20px;align-items:center}
.overall strong{font:700 18px monospace;letter-spacing:.08em}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:1px;background:#292929;border:1px solid #292929}
.item{background:#0b0b0b;padding:18px;min-height:96px}
.top{display:flex;justify-content:space-between;gap:15px;align-items:center}
.name{font:700 13px monospace;letter-spacing:.12em}
.status{font:700 11px monospace;letter-spacing:.08em}
.dot{display:inline-block;width:8px;height:8px;border-radius:50%;margin-right:7px;background:#777}
.ok .dot{background:#e6ff00;box-shadow:0 0 12px #e6ff00}
.bad .dot{background:#ff3030;box-shadow:0 0 12px #ff3030}
.warn .dot{background:#ffad00}
.detail{font:11px/1.5 monospace;color:#777;margin-top:12px;word-break:break-word}
footer{margin-top:24px;color:#555;font:10px monospace;display:flex;justify-content:space-between;gap:15px}
a{color:#e6ff00;text-decoration:none}
@media(max-width:650px){.grid{grid-template-columns:1fr}.overall{align-items:flex-start;flex-direction:column}footer{flex-direction:column}}
</style>
</head>
<body>
<div class="wrap">
<header>
<div class="kicker">BRVTAL // DISCADMIN // DIAGNOSTICS</div>
<h1>SYSTEM <span>STATUS</span></h1>
<div class="meta"><?=htmlspecialchars(date('Y-m-d H:i:s T'))?></div>
</header>

<div class="overall">
<div>
<div class="kicker">SYSTEM</div>
<strong><?=htmlspecialchars($overall)?></strong>
</div>
<div class="meta">BRVTAL / RAVE TILL GRAVE</div>
</div>

<div class="grid">
<?php foreach ($checks as $c):
    $class = in_array($c['status'], ['FAILED','OFFLINE','MISSING'], true) ? 'bad'
        : (in_array($c['status'], ['DEGRADED','READ-ONLY','UNKNOWN'], true) ? 'warn' : 'ok');
?>
<div class="item <?=$class?>">
  <div class="top">
    <div class="name"><span class="dot"></span><?=htmlspecialchars($c['name'])?></div>
    <div class="status"><?=htmlspecialchars($c['status'])?></div>
  </div>
  <div class="detail"><?=htmlspecialchars($c['detail'])?></div>
</div>
<?php endforeach; ?>
</div>

<footer>
<span>BRVTAL DISCADMIN DIAGNOSTIC</span>
<a href="./">BACK TO ADMIN</a>
</footer>
</div>
</body>
</html>
