<?php
declare(strict_types=1);

require_once __DIR__ . '/config/admin_auth.php';
require_once __DIR__ . '/config/deployment.php';
require_once __DIR__ . '/config/public_seo.php';
require_once __DIR__ . '/config/public_preview.php';
require_once __DIR__ . '/config/public_entity_delivery.php';

function brvtal_preview_error(int $status, string $message): never
{
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex, nofollow');
    header('Referrer-Policy: no-referrer');
    $safe = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    echo '<!doctype html><html><head><meta charset="utf-8"><meta name="robots" content="noindex,nofollow">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1"><title>BRVTAL Preview</title>'
        . '<style>body{margin:0;background:#050505;color:#f4f1e8;font:14px/1.5 ui-monospace,monospace;display:grid;place-items:center;min-height:100vh}main{max-width:620px;padding:32px;border:1px solid #30302d}a{color:#b6ff00}</style>'
        . '</head><body><main><b>PRIVATE PREVIEW UNAVAILABLE</b><p>' . $safe . '</p>'
        . '<a href="/discadmin">RETURN TO DISCADMIN</a></main></body></html>';
    exit;
}

if (!brvtal_admin_is_authenticated()) {
    brvtal_preview_error(401, 'Your DISCADMIN session is required to view this preview.');
}

$token = strtolower(trim((string)($_GET['token'] ?? '')));
$snapshot = brvtal_public_preview_load($token);
if ($snapshot === null) {
    brvtal_preview_error(410, 'This preview has expired or is not available in this session.');
}

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Robots-Tag: noindex, nofollow');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

if (($_GET['render'] ?? '') === '1') {
    $pdo = db();
    $page = brvtal_public_preview_page($pdo, $snapshot);
    $baseUrl = brvtal_public_base_url($config);
    $seo = brvtal_public_seo_document(
        $page['entity'],
        $baseUrl,
        brvtal_public_global_seo($pdo)
    );
    $previewCanonical = $baseUrl . '/preview/' . rawurlencode($token);
    $seo['canonical'] = $previewCanonical;
    if (is_array($seo['schema'] ?? null)) {
        $seo['schema']['url'] = $previewCanonical;
    }

    $html = brvtal_public_entity_document(
        $pdo,
        $page,
        $seo,
        '',
        brvtalDeploymentCacheKey()
    );
    $html = str_replace(
        '</head>',
        "  <meta name=\"robots\" content=\"noindex,nofollow\">\n"
            . "  <meta name=\"brvtal-preview\" content=\"private-session-snapshot\">\n</head>",
        $html
    );
    $html = str_replace(
        '<body ',
        '<body data-public-preview="1" ',
        $html
    );
    header('Content-Type: text/html; charset=utf-8');
    session_write_close();
    echo $html;
    exit;
}

$safeToken = htmlspecialchars($token, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$src = '/preview/' . rawurlencode($token) . '?render=1';
header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="robots" content="noindex,nofollow">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>BRVTAL · Private Public Preview</title>
  <style>
    *{box-sizing:border-box}
    html,body{margin:0;min-height:100%;background:#050505;color:#f4f1e8;font-family:ui-monospace,SFMono-Regular,Menlo,monospace}
    .bar{position:sticky;top:0;z-index:3;display:flex;align-items:center;gap:10px;min-height:58px;padding:8px 14px;border-bottom:1px solid #30302d;background:#050505}
    .brand{font-weight:900;letter-spacing:-1px;margin-right:auto}.meta{font-size:10px;color:#8a8a82;letter-spacing:1px}
    button,a{min-height:44px;border:1px solid #3c3c38;background:#0a0b0c;color:#f4f1e8;padding:10px 13px;font:700 10px/1 ui-monospace,monospace;letter-spacing:1px;text-decoration:none}
    button[aria-pressed="true"]{border-color:#b6ff00;color:#b6ff00}.private{color:#e31b23}
    .stage{padding:18px;overflow:auto;min-height:calc(100vh - 58px)}
    .frame{position:relative;margin:0 auto;transform-origin:top left}
    iframe{display:block;border:1px solid #30302d;background:#fff;transform-origin:top left}
    @media(max-width:720px){.bar{flex-wrap:wrap}.brand{width:100%}.meta{display:none}.stage{padding:10px}}
  </style>
</head>
<body data-preview-token="<?= $safeToken ?>">
  <nav class="bar" aria-label="Preview viewport">
    <span class="brand">BRVTAL / PUBLIC PREVIEW</span>
    <span class="meta private">PRIVATE · NOINDEX · 10 MIN</span>
    <button type="button" data-width="1440" data-height="900" aria-pressed="true">DESKTOP 1440</button>
    <button type="button" data-width="390" data-height="844" aria-pressed="false">MOBILE 390</button>
    <a href="/discadmin">BACK TO DISCADMIN</a>
  </nav>
  <main class="stage">
    <div class="frame" data-preview-frame>
      <iframe title="Canonical public preview" src="<?= htmlspecialchars($src, ENT_QUOTES, 'UTF-8') ?>"></iframe>
    </div>
  </main>
  <script>
    (() => {
      const stage=document.querySelector('.stage');
      const frame=document.querySelector('[data-preview-frame]');
      const iframe=frame.querySelector('iframe');
      const buttons=[...document.querySelectorAll('[data-width]')];
      let target={width:1440,height:900};

      function layout(){
        const available=Math.max(280,stage.clientWidth-2);
        const scale=Math.min(1,available/target.width);
        iframe.style.width=target.width+'px';
        iframe.style.height=target.height+'px';
        iframe.style.transform='scale('+scale+')';
        frame.style.width=(target.width*scale)+'px';
        frame.style.height=(target.height*scale)+'px';
      }
      buttons.forEach(button=>button.addEventListener('click',()=>{
        target={width:Number(button.dataset.width),height:Number(button.dataset.height)};
        buttons.forEach(item=>item.setAttribute('aria-pressed',String(item===button)));
        layout();
      }));
      new ResizeObserver(layout).observe(stage);
      layout();
    })();
  </script>
</body>
</html>
