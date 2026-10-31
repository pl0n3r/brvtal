<?php
declare(strict_types=1);

/**
 * Private Concept 05 image-choice workbench (#1357).
 * Decisions are draft-only and never update settings.site or publish media.
 * Library mode exists solely for PHP CLI unit tests.
 */
function c05_picker_slots(): array
{
    return [
        'hero_face' => 'ROSTRO / HERO',
        'crowd' => 'PÚBLICO',
        'artists' => 'ARTISTAS',
        'nights' => 'NOCHES',
        'memories' => 'MEMORIAS',
    ];
}

function c05_picker_sources(): array
{
    $sources = [];
    foreach ([
        'hero-face' => 'ROSTRO',
        'crowd' => 'PÚBLICO',
        'artists' => 'ARTISTAS',
        'nights' => 'NOCHES',
        'memories' => 'MEMORIAS',
    ] as $name => $label) {
        $relative = 'assets/placeholders/concept05/' . $name . '.webp';
        if (!is_file(dirname(__DIR__) . '/' . $relative)) {
            continue;
        }
        $sources['source:' . $name] = [
            'title' => $label . ' — composición sintética',
            'url' => '/' . $relative,
            'origin' => 'source',
            'rights' => 'Fuente editorial sintética original: docs/reference/home-placeholders.md. Uso definitivo pendiente de aprobación.',
        ];
    }
    return $sources;
}

function c05_picker_media_path(mixed $path): ?string
{
    if (!is_string($path) || strlen($path) > 350
        || preg_match('~\A/uploads/media/(?:[A-Za-z0-9_-]+/)*[A-Za-z0-9_-]+\.(?:webp|png|jpe?g|gif)\z~iD', $path) !== 1) {
        return null;
    }
    return $path;
}

function c05_picker_candidates(PDO $pdo): array
{
    $sources = c05_picker_sources();
    $sql = "SELECT id,title,file_path,mime_type,status FROM media WHERE type='image' AND status='published' ORDER BY id DESC LIMIT 80";
    foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $id = filter_var($row['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $url = c05_picker_media_path($row['file_path'] ?? null);
        if ($id === false || $url === null || !str_starts_with((string)($row['mime_type'] ?? ''), 'image/')) {
            continue;
        }
        $sources['cms:' . $id] = [
            'title' => mb_substr(trim((string)($row['title'] ?? 'Imagen CMS')), 0, 120),
            'url' => $url,
            'origin' => 'cms',
            'rights' => 'Derechos sin acreditar en la ficha CMS: exige referencia escrita del dueño para seleccionar.',
        ];
    }
    return $sources;
}

function c05_picker_validate_choice(string $slot, string $candidate, array $candidates, string $rightsNote): array
{
    if (!array_key_exists($slot, c05_picker_slots()) || !isset($candidates[$candidate])) {
        throw new InvalidArgumentException('SLOT_OR_CANDIDATE_NOT_AVAILABLE');
    }
    $option = $candidates[$candidate];
    if (!in_array($option['origin'] ?? '', ['source', 'cms'], true)) {
        throw new InvalidArgumentException('INVALID_ORIGIN');
    }
    if ($option['origin'] === 'cms') {
        $rightsNote = trim($rightsNote);
        if (strlen($rightsNote) < 12 || strlen($rightsNote) > 300) {
            throw new InvalidArgumentException('RIGHTS_EVIDENCE_REQUIRED');
        }
    } else {
        $rightsNote = 'docs/reference/home-placeholders.md — composición sintética original; aprobación editorial pendiente';
    }
    $now = new DateTimeImmutable('now', new DateTimeZone('America/Bogota'));
    return [
        'slot' => $slot,
        'candidate' => $candidate,
        'source' => $option['url'],
        'rights_evidence' => $rightsNote,
        'status' => 'draft-not-published',
        'saved_at' => $now->format(DateTimeInterface::ATOM),
        'saved_at_colombia_12h' => $now->format('d/m/Y h:i:s A') . ' (Colombia)',
    ];
}

function c05_picker_decode(string $json): array
{
    if (trim($json) === '') {
        return ['choices' => [], 'history' => []];
    }
    $decoded = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($decoded) || !isset($decoded['choices'], $decoded['history'])
        || !is_array($decoded['choices']) || !is_array($decoded['history'])
        || count($decoded['history']) > 100) {
        throw new RuntimeException('INVALID_SELECTION_STORE');
    }
    return $decoded;
}

function c05_picker_read(string $file): array
{
    if (is_link($file)) {
        throw new RuntimeException('INVALID_STORE_PATH');
    }
    if (!is_file($file)) {
        return ['choices' => [], 'history' => []];
    }
    $handle = fopen($file, 'rb');
    if ($handle === false) {
        throw new RuntimeException('STORE_UNAVAILABLE');
    }
    try {
        if (!flock($handle, LOCK_SH)) {
            throw new RuntimeException('STORE_LOCK_FAILED');
        }
        $content = stream_get_contents($handle, 1024 * 1024 + 1);
        if (!is_string($content) || strlen($content) > 1024 * 1024) {
            throw new RuntimeException('STORE_TOO_LARGE');
        }
        return c05_picker_decode($content);
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

function c05_picker_save(string $file, array $choice): array
{
    if (is_link($file) || !is_dir(dirname($file)) || !is_writable(dirname($file))) {
        throw new RuntimeException('PRIVATE_STORAGE_UNAVAILABLE');
    }
    if (!isset($choice['slot'], $choice['candidate'], $choice['saved_at'])
        || !array_key_exists((string)$choice['slot'], c05_picker_slots())) {
        throw new InvalidArgumentException('INVALID_CHOICE');
    }
    $handle = fopen($file, 'c+');
    if ($handle === false) {
        throw new RuntimeException('STORE_UNAVAILABLE');
    }
    try {
        if (!flock($handle, LOCK_EX)) {
            throw new RuntimeException('STORE_LOCK_FAILED');
        }
        rewind($handle);
        $current = stream_get_contents($handle, 1024 * 1024 + 1);
        if (!is_string($current) || strlen($current) > 1024 * 1024) {
            throw new RuntimeException('STORE_TOO_LARGE');
        }
        $data = c05_picker_decode($current);
        $data['choices'][$choice['slot']] = $choice;
        $data['history'][] = $choice;
        $data['history'] = array_slice($data['history'], -100);
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if ($json === false || strlen($json) > 1024 * 1024) {
            throw new RuntimeException('STORE_TOO_LARGE');
        }
        rewind($handle);
        if (!ftruncate($handle, 0)) {
            throw new RuntimeException('STORE_TRUNCATE_FAILED');
        }
        $count = strlen($json);
        for ($written = 0; $written < $count;) {
            $bytes = fwrite($handle, substr($json, $written));
            if ($bytes === false || $bytes === 0) {
                throw new RuntimeException('STORE_WRITE_FAILED');
            }
            $written += $bytes;
        }
        if (!fflush($handle)) {
            throw new RuntimeException('STORE_FLUSH_FAILED');
        }
        return $data;
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

function c05_picker_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

if (defined('BRVTAL_PICKER_LIBRARY_TEST') && BRVTAL_PICKER_LIBRARY_TEST === true
    && PHP_SAPI === 'cli') {
    return;
}

require_once __DIR__ . '/../config/admin_auth.php';
brvtal_admin_require();
header('Cache-Control: no-store, private, max-age=0');
header('X-Robots-Tag: noindex, nofollow, noarchive');
header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
header('Content-Type: text/html; charset=utf-8');
$store = dirname(__DIR__) . '/storage/home-image-decisions.json';
$error = '';
$notice = '';
try {
    $candidates = c05_picker_candidates(db());
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        brvtal_admin_require_csrf();
        foreach (['slot', 'candidate', 'rights_note'] as $field) {
            if (isset($_POST[$field]) && !is_string($_POST[$field])) {
                throw new InvalidArgumentException('INVALID_FORM_FIELD');
            }
        }
        $slot = trim((string)($_POST['slot'] ?? ''));
        $candidate = trim((string)($_POST['candidate'] ?? ''));
        if ($candidate !== '') {
            $choice = c05_picker_validate_choice($slot, $candidate, $candidates, (string)($_POST['rights_note'] ?? ''));
            c05_picker_save($store, $choice);
            $notice = 'Elección guardada como BORRADOR. Las imágenes públicas siguen intactas.';
        } else {
            $notice = 'Sin nueva elección. No se cambió nada.';
        }
    }
    $saved = c05_picker_read($store);
    $siteRow = db()->query("SELECT setting_value FROM settings WHERE setting_key='site' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $site = is_array($siteRow) ? json_decode((string)($siteRow['setting_value'] ?? ''), true) : [];
    if (!is_array($site)) {
        $site = [];
    }
} catch (Throwable $e) {
    http_response_code(503);
    $error = 'No se pudo cargar o guardar la selección privada. Ninguna imagen pública fue modificada.';
    $candidates = [];
    $saved = ['choices' => [], 'history' => []];
    $site = [];
}
$csrf = brvtal_admin_csrf_token();
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow,noarchive">
<title>BRVTAL // Comparar imágenes HOME</title>
<style>
:root{color-scheme:dark;font-family:system-ui,Arial,sans-serif;background:#0b0d0f;color:#eee}
*{box-sizing:border-box}body{margin:0}.wrap{max-width:1440px;margin:auto;padding:24px}
header{border-bottom:1px solid #3e4145;margin-bottom:26px;padding-bottom:20px}
h1{font-size:clamp(26px,5vw,50px);line-height:1.05;margin:8px 0}
p{line-height:1.5;color:#c4c8ca}small{color:#c4c8ca}.eyebrow{letter-spacing:.18em;text-transform:uppercase;font-size:12px;color:#ff6575}
a{color:#fff}.status{padding:12px 16px;border:1px solid #7d7d7d;margin:12px 0}
.slot{margin:28px 0;padding:16px;border:1px solid #414448}
fieldset{border:0;padding:0;min-width:0}legend{font-weight:800;font-size:20px;margin-bottom:12px}
.options{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,320px),1fr));gap:14px}
.option{border:1px solid #51565b;padding:12px;min-width:0}
.option:focus-within{outline:2px solid #fff;outline-offset:3px}
.option label{display:block;cursor:pointer;font-weight:700}
.option label input{width:22px;height:22px;vertical-align:middle;margin-right:8px}
.previews{display:grid;grid-template-columns:minmax(0,2fr) minmax(110px,1fr);align-items:start;gap:8px;margin-top:8px}
.frame{overflow:hidden;border:1px solid #4c5055;background:#151719}
.frame.desktop{aspect-ratio:1440/900}.frame.mobile{aspect-ratio:390/844}
.frame img{width:100%;height:100%;display:block;object-fit:cover}
.frame figcaption{background:#151719;padding:5px;font-size:12px}
figure{margin:0}.right{font-size:12px;word-break:break-word}
.current{padding:10px;border-left:3px solid #f34956;background:#171a1c;margin:12px 0}
.actions{margin:20px 0;display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end}
.actions label{display:grid;gap:5px;flex:1;min-width:220px}
input[type=text]{background:#111519;color:white;border:1px solid #9ca4ac;border-radius:0;padding:12px;font:inherit}
button{min-height:44px;padding:10px 18px;background:#ed2b43;color:white;border:1px solid #f38591;font-weight:800;cursor:pointer}
button:active{transform:scale(.96)}:focus-visible{outline:3px solid #fff;outline-offset:3px}
@media(prefers-reduced-motion:reduce){button{transition:none;transform:none!important}}
</style>
</head>
<body><main class="wrap">
<header><div class="eyebrow">DISCADMIN / WORKBENCH PRIVADO</div>
<h1>Comparar imágenes del HOME</h1>
<p>Previsualización de recorte a <strong>1440 px</strong> (escritorio) y <strong>390 px</strong> (móvil). La selección se almacena como <strong>borrador privado</strong>: NO publica, no cambia settings.site y no reemplaza las imágenes del sitio.</p>
<p>Derechos: el material sintético cuenta con registro de origen editorial. Para imágenes CMS, el dueño debe indicar una referencia documental; la página NO equivale a aprobación jurídica ni editorial.</p>
<a href="/discadmin/">Volver al panel</a>
</header>
<?php if ($error !== ''): ?><div class="status" role="alert"><?=c05_picker_escape($error)?></div><?php endif; ?>
<?php if ($notice !== ''): ?><div class="status" role="status"><?=c05_picker_escape($notice)?></div><?php endif; ?>
<?php foreach (c05_picker_slots() as $slot => $name): ?>
<section class="slot" aria-label="<?=c05_picker_escape($name)?>">
<form method="post" action="/discadmin/comparar-imagenes-home.php">
<fieldset><legend><?=c05_picker_escape($name)?></legend>
<?php $current = $site['c05_media_' . $slot] ?? null; $currentUrl = is_array($current) && ($current['status'] ?? '') === 'published' ? c05_picker_media_path($current['url'] ?? null) : null; ?>
<div class="current">Imagen publicada actualmente:
<?php if ($currentUrl !== null): ?>
<img src="<?=c05_picker_escape($currentUrl)?>" width="150" alt="Imagen actualmente publicada" loading="lazy">
<?php else: ?>Sin referencia CMS publicada verificable. El sitio mantiene su comportamiento actual.<?php endif; ?>
<?php $selected = $saved['choices'][$slot] ?? null; if (is_array($selected)): ?>
<p>Borrador guardado: <?=c05_picker_escape((string)($selected['candidate'] ?? ''))?> · <?=c05_picker_escape((string)($selected['saved_at_colombia_12h'] ?? ''))?> · sin publicar</p>
<?php endif; ?>
</div>
<div class="options">
<?php foreach ($candidates as $key => $option): ?>
<div class="option">
<label><input type="radio" name="candidate" value="<?=c05_picker_escape($key)?>" <?=is_array($selected) && ($selected['candidate'] ?? '') === $key ? 'checked' : ''?>>
<?=c05_picker_escape((string)$option['title'])?></label>
<div class="previews">
<figure><div class="frame desktop"><img loading="lazy" src="<?=c05_picker_escape((string)$option['url'])?>" alt="Recorte escritorio, <?=c05_picker_escape((string)$option['title'])?>"></div><figcaption>1440 × 900 / DESKTOP</figcaption></figure>
<figure><div class="frame mobile"><img loading="lazy" src="<?=c05_picker_escape((string)$option['url'])?>" alt="Recorte móvil, <?=c05_picker_escape((string)$option['title'])?>"></div><figcaption>390 × 844 / MOBILE</figcaption></figure>
</div>
<p class="right"><strong>DERECHOS / FUENTE:</strong> <?=c05_picker_escape((string)$option['rights'])?></p>
</div>
<?php endforeach; ?>
</div>
</fieldset>
<div class="actions">
<input type="hidden" name="slot" value="<?=c05_picker_escape($slot)?>">
<input type="hidden" name="csrf" value="<?=c05_picker_escape($csrf)?>">
<label>Documento que respalda los derechos de una imagen CMS (referencia de 12 a 300 caracteres):
<input type="text" name="rights_note" maxlength="300" autocomplete="off" placeholder="Contrato, licencia, archivo o decisión registrada"></label>
<button type="submit">Guardar elección como borrador</button>
</div>
</form></section>
<?php endforeach; ?>
<footer><p>Registro de selecciones: <?=count($saved['history'])?> eventos, hora local America/Bogota en formato de 12 horas. Sin selección nueva no se guarda nada.</p></footer>
</main></body></html>
