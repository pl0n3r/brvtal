<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/admin_auth.php';
brvtal_admin_require();

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow, noarchive');

error_reporting(E_ALL);
ini_set('display_errors', '0');

$root = dirname(__DIR__);
$logDir = $root . '/storage/logs';
$logFile = $logDir . '/brvtal.log';

$action = (string)($_GET['action'] ?? '');
$wantsJson = (string)($_GET['format'] ?? '') === 'json'
    || str_contains(strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json');

$respondJson = static function (array $payload, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
};

if ($action === 'clear') {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        header('Allow: POST');
        if ($wantsJson) {
            $respondJson(['ok'=>false,'error'=>'METHOD_NOT_ALLOWED'], 405);
        }
        http_response_code(405);
        exit('METHOD NOT ALLOWED');
    }

    $csrf = (string)($_POST['csrf'] ?? '');

    if (
        empty($_SESSION['csrf']) ||
        $csrf === '' ||
        !hash_equals((string)$_SESSION['csrf'], $csrf)
    ) {
        if ($wantsJson) {
            $respondJson(['ok'=>false,'error'=>'CSRF'], 419);
        }
        http_response_code(419);
        exit('CSRF');
    }

    if (is_file($logFile) && file_put_contents($logFile, '', LOCK_EX) === false) {
        if ($wantsJson) {
            $respondJson(['ok'=>false,'error'=>'LOG_CLEAR_FAILED'], 500);
        }
        http_response_code(500);
        exit('LOG CLEAR FAILED');
    }

    if ($wantsJson) {
        $respondJson(['ok'=>true,'file'=>'storage/logs/brvtal.log','bytes'=>0,'lines'=>0]);
    }

    header('Location: logs.php');
    exit;
}

if ($action === 'download' && is_file($logFile)) {
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="brvtal.log"');
    header('Content-Length: ' . filesize($logFile));

    readfile($logFile);
    exit;
}

$log = is_file($logFile) ? (string)@file_get_contents($logFile) : '';

$lines = $log === ''
    ? []
    : preg_split('/\R/', trim($log));

$lines = array_slice($lines ?: [], -300);

$size = is_file($logFile)
    ? filesize($logFile)
    : 0;

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow,noarchive">
<title>BRVTAL // Debug Log</title>
<style>
*{box-sizing:border-box}
html,body{margin:0;background:#030303;color:#eee;font-family:Arial,Helvetica,sans-serif}
body{min-height:100vh}
.wrap{width:min(1200px,calc(100% - 28px));margin:auto;padding:28px 0}
header{border-bottom:1px solid #292929;padding-bottom:20px;margin-bottom:18px}
.kicker{font:700 10px monospace;letter-spacing:.25em;color:#777}
h1{font-size:clamp(36px,7vw,72px);line-height:.85;letter-spacing:-.06em;margin:12px 0}
h1 span{color:#e6ff00}
.info{font:11px monospace;color:#666}
.actions{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px}
a,button{display:inline-block;border:1px solid #383838;background:#0b0b0b;color:#ddd;padding:10px 14px;text-decoration:none;font:700 11px monospace;cursor:pointer}
a:hover,button:hover{border-color:#e6ff00;color:#e6ff00}
.danger:hover{border-color:#ff3030;color:#ff3030}
.log{border:1px solid #292929;background:#070707;padding:16px;overflow:auto;white-space:pre-wrap;word-break:break-word;font:11px/1.55 monospace;min-height:280px}
.line{border-bottom:1px solid #151515;padding:4px 0}
.empty{color:#555}
footer{margin-top:15px;font:10px monospace;color:#555}
</style>
</head>
<body>
<div class="wrap">
<header>
<div class="kicker">BRVTAL // DISCADMIN // DEBUG</div>
<h1>DEBUG <span>LOG</span></h1>
<div class="info">
FILE: /storage/logs/brvtal.log
&nbsp; // &nbsp;
SIZE: <?=h((string)$size)?> bytes
&nbsp; // &nbsp;
LAST 300 LINES
</div>
</header>

<div class="actions">
<a href="logs.php">REFRESH</a>
<a href="logs.php?action=download">DOWNLOAD LOG</a>
<form method="post" action="logs.php?action=clear" style="display:inline" onsubmit="return confirm('Clear the log?')">
<input type="hidden" name="csrf" value="<?=h((string)($_SESSION['csrf'] ?? ''))?>">
<button class="danger" type="submit">CLEAR LOG</button>
</form>
<a href="./">BACK TO ADMIN</a>
</div>

<div class="log">
<?php if (!$lines): ?>
<span class="empty">NO LOG ENTRIES YET.</span>
<?php else: ?>
<?php foreach ($lines as $line): ?>
<div class="line"><?=h($line)?></div>
<?php endforeach; ?>
<?php endif; ?>
</div>

<footer>
BRVTAL RAVE TILL GRAVE // SERVER DIAGNOSTICS
</footer>
</div>
</body>
</html>
