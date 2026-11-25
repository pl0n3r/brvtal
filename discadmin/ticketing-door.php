<?php
declare(strict_types=1);
require_once __DIR__.'/../config/admin_auth.php';
require_once __DIR__.'/../config/ticketing_staff_permissions.php';
require_once __DIR__.'/../config/public_visibility.php';
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow');
brvtal_admin_require();
try {
    $pdo=db();
    $operator=(int)($_SESSION['admin_id']??0);
    $role=$pdo->prepare('SELECT staff_role FROM admins WHERE id=? AND is_active=1 LIMIT 1');
    $role->execute([$operator]);
    if (!brvtalTicketingCan((string)($role->fetchColumn()?:''), 'door')) {
        http_response_code(403);
        exit('Acceso no autorizado.');
    }
    if (isset($_GET['summary'])) {
        header('Content-Type: application/json; charset=utf-8');
        $id=filter_var($_GET['event_id']??null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
        if (!is_int($id)) {
            http_response_code(422);
            exit('{"ok":false}');
        }
        // #2022: the door must open for every selling state, not only 'published'.
        $active=brvtal_public_event_statuses()['active'];
        $event=$pdo->prepare('SELECT id FROM events WHERE id=? AND status IN ('
            .implode(',',array_fill(0,count($active),'?')).') LIMIT 1');
        $event->execute(array_merge([$id],$active));
        if (!$event->fetchColumn()) {
            http_response_code(404);
            exit('{"ok":false}');
        }
        $count=$pdo->prepare('SELECT outcome, COUNT(*) AS n FROM ticket_checkins WHERE event_id=? GROUP BY outcome');
        $count->execute([$id]);
        $summary=['accepted'=>0, 'rejected'=>0];
        foreach ($count->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (array_key_exists($row['outcome'], $summary)) {
                $summary[$row['outcome']] = (int)$row['n'];
            }
        }
        $recent=$pdo->prepare('SELECT operator_admin_id, device_id, outcome, reason_code, attempted_at_utc
            FROM ticket_checkins WHERE event_id=? ORDER BY id DESC LIMIT 12');
        $recent->execute([$id]);
        $audit=[];
        foreach ($recent->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $audit[]=['operator'=>(int)$row['operator_admin_id'],
                'device'=>substr(hash('sha256', (string)$row['device_id']), 0, 8),
                'outcome'=>$row['outcome'], 'reason'=>$row['reason_code'],
                'at'=>$row['attempted_at_utc'].' UTC'];
        }
        echo json_encode(['ok'=>true, 'summary'=>$summary, 'recent'=>$audit], JSON_THROW_ON_ERROR);
        exit;
    }
    if (($_SERVER['HTTP_X_BRVTAL_ADMIN_FRAGMENT'] ?? '') !== '1') {
        http_response_code(404);
        exit;
    }
    $active=brvtal_public_event_statuses()['active'];
    $eventsQ=$pdo->prepare('SELECT id, title FROM events WHERE status IN ('
        .implode(',',array_fill(0,count($active),'?')).') ORDER BY event_date DESC, id DESC LIMIT 100');
    $eventsQ->execute($active);
    $events=$eventsQ->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    exit('No se pudo verificar el acceso o cargar los eventos.');
}
$csrf=brvtal_admin_csrf_token();
?>
<section class="door" data-door data-csrf="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
<header><p class="eyebrow">BRVTAL · STAFF</p><h1>Control de puerta</h1>
<p>Solo confirma la API del servidor. Sin conexión, no hay ingreso.</p>
</header>
<form data-door-form autocomplete="off">
<label for="door-event">Evento autorizado</label>
<select id="door-event" data-door-event required>
<option value="">Selecciona un evento publicado</option>
<?php foreach ($events as $event): ?>
<option value="<?= (int)$event['id'] ?>"><?= htmlspecialchars((string)$event['title'], ENT_QUOTES, 'UTF-8') ?></option>
<?php endforeach; ?></select>
<label for="door-qr">Código QR de entrada</label>
<input id="door-qr" name="qr" maxlength="256" autocomplete="off" autocapitalize="off"
 spellcheck="false" placeholder="Escanea o pega el código" required>
<div class="actions"><button type="submit" data-door-submit>Validar entrada</button>
<button type="button" data-door-camera>Usar cámara</button>
<button type="button" data-door-stop hidden>Detener cámara</button></div>
<video data-door-video playsinline muted hidden aria-label="Cámara para leer QR"></video>
<output data-door-camera-status>También puedes ingresar el código manualmente.</output>
</form>
<output data-door-result aria-live="assertive" data-state="idle">Ninguna entrada confirmada.</output>
<section aria-labelledby="door-summary-title"><h2 id="door-summary-title">Asistencia del evento</h2>
<output data-door-summary>Elige un evento para consultar la asistencia.</output>
<div data-door-audit aria-label="Últimos intentos de ingreso"></div></section>
</section>
