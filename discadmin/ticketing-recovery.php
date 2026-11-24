<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/admin_auth.php';
require_once __DIR__ . '/../config/ticketing_recovery.php';
header('Cache-Control: no-store, private');
header('X-Frame-Options: SAMEORIGIN');
header("Content-Security-Policy: frame-ancestors 'self'");
header('X-Robots-Tag: noindex, nofollow');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') { http_response_code(405); exit; }
brvtal_admin_require();
try {
    [$actor, $role] = brvtalRecoveryAdminIdentity(db());
    if (!brvtalTicketingCan($role,'mail') || !brvtalTicketingCan($role,'buyers')) {
        http_response_code(403); exit;
    }
} catch (Throwable) { http_response_code(503); exit; }
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Recuperación de compras · Boletería</title>
<style>
:root{--bg:#050505;--text:#f4f5f6;--line:#292d31;--p:#0a0b0c;--muted:#b5bcc3;--red:#ff2038;color-scheme:dark}
*{box-sizing:border-box}
html{background:var(--bg);color:var(--text);-webkit-font-smoothing:antialiased}
body{margin:0;font:14px/1.5 system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
main{width:100%;max-width:1100px;margin:0 auto;padding:20px 16px 32px;min-width:0}
h2{margin:0 0 8px;font-size:21px;line-height:1.25;text-wrap:balance}
h3{margin:26px 0 10px;font-size:17px}
p{max-width:68ch;color:var(--muted);text-wrap:pretty}
.tk-form{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(220px,100%),1fr));gap:16px;margin:20px 0}
.tk-field{display:flex;flex-direction:column;gap:6px;min-width:0}
.tk-step{margin:0;padding:16px;min-width:0;display:flex;flex-direction:column;gap:14px;background:var(--p);border:1px solid var(--line)}
.tk-step legend{padding:0 6px;font-weight:600;color:var(--muted);font-size:12px}
.tk-field :is(input,select){width:100%;min-height:44px;padding:10px 12px;border:1px solid var(--line);color:var(--text);background:var(--bg);font:inherit}
.tk-button{min-height:44px;padding:8px 18px;border:1px solid var(--line);color:var(--text);background:transparent;font:inherit;font-weight:600;cursor:pointer}
.tk-button:hover{border-color:var(--muted)}
.tk-button.is-primary{color:#090b0d;background:var(--red);border-color:var(--red)}
.tk-button:disabled{opacity:.55;cursor:not-allowed}
:is(button,input,select):focus-visible{outline:3px solid var(--text);outline-offset:3px}
.tk-footer{grid-column:1/-1;display:flex;flex-wrap:wrap;align-items:center;gap:10px}
.tk-status{min-height:20px;margin:16px 0;color:var(--text)}
.tk-table-wrap{max-width:100%;overflow-x:auto}
.tk-table{width:100%;border-collapse:collapse;font-size:14px;font-variant-numeric:tabular-nums}
.tk-table th,.tk-table td{padding:12px;border-bottom:1px solid var(--line);text-align:left;vertical-align:top}
.tk-table th{font-size:12px;font-weight:600;color:var(--muted)}
[data-recovery-confirm]{padding:16px;border:1px solid var(--line);background:var(--p);margin:14px 0}
[data-recovery-confirm] p{margin:0 0 14px}
[data-recovery-confirm] button+button{margin-left:8px}
@media(max-width:600px){main{padding:16px 12px}
 .tk-table thead{display:none}
 .tk-table,.tk-table tbody,.tk-table tr,.tk-table td{display:block;width:100%}
 .tk-table tr{padding:8px 0;border-bottom:1px solid var(--line)}
 .tk-table td{border:0;padding:4px 0;overflow-wrap:anywhere}
 .tk-table td[data-label]::before{content:attr(data-label) " · ";color:var(--muted)}
}
</style>
</head>
<body>
<main data-recovery-root data-csrf="<?= htmlspecialchars(brvtal_admin_csrf_token(),ENT_QUOTES,'UTF-8') ?>">
  <h2>Recuperación de compras</h2>
  <p>Consulta de vistas previas. No envía correos ni modifica pagos o reservas.</p>
  <form data-recovery-form class="tk-form">
    <fieldset class="tk-step">
      <legend>Selección</legend>
      <div class="tk-field"><label for="tk-recovery-order">Número de pedido</label>
        <input id="tk-recovery-order" type="number" name="order_id" min="1" required></div>
      <div class="tk-field"><label for="tk-recovery-campaign">Campaña</label>
        <input id="tk-recovery-campaign" name="campaign" value="checkout" maxlength="32" pattern="[a-z0-9_-]+" required></div>
    </fieldset>
    <div class="tk-footer"><button class="tk-button is-primary" type="submit">Preparar vista previa</button></div>
  </form>
  <div data-recovery-confirm hidden>
    <p>Solo se registrará una vista previa. No se enviarán mensajes, reservas ni pagos.</p>
    <button class="tk-button is-primary" type="button" data-recovery-yes>Confirmar vista previa</button>
    <button class="tk-button" type="button" data-recovery-no>Cancelar</button>
  </div>
  <output class="tk-status" data-recovery-status role="status" aria-live="polite">Consultando información…</output>
  <h3>Resumen por evento y campaña</h3>
  <div class="tk-table-wrap"><table class="tk-table">
    <thead><tr><th scope="col">Evento</th><th scope="col">Campaña</th><th scope="col">Etapa</th><th scope="col">Vistas previas</th></tr></thead>
    <tbody data-recovery-rows></tbody>
  </table></div>
</main>
<script type="module" src="/discadmin/ticketing-recovery.js"></script>
</body>
</html>
