<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/admin_auth.php';
require_once __DIR__ . '/../config/ticketing_staff_permissions.php';
require_once __DIR__ . '/../config/public_visibility.php';
header('Cache-Control: no-store, private');
if (($_SERVER['HTTP_X_BRVTAL_ADMIN_FRAGMENT'] ?? '') !== '1') { http_response_code(404); exit; }
brvtal_admin_require();
try {
    $q=db()->prepare('SELECT staff_role FROM admins WHERE id=? AND is_active=1 LIMIT 1');
    $q->execute([(int)($_SESSION['admin_id'] ?? 0)]);
    if (!brvtalTicketingCan((string)($q->fetchColumn() ?: ''),'finance')) {
        http_response_code(403); exit;
    }
} catch (Throwable) { http_response_code(503); exit; }
// Only published event names and ticket types, for roles with finance access.
// The promo administration endpoint requires a stronger, unrelated "settings"
// capability; never make the staff POS depend on that capability.
$catalog=[];
try {
    // #2022: every selling state (tickets_available, last_tickets…), not only 'published'.
    $active=brvtal_public_event_statuses()['active'];
    $eventsQ=db()->prepare('SELECT id,title,event_date FROM events WHERE status IN ('
        .implode(',',array_fill(0,count($active),'?')).')
        ORDER BY event_date IS NULL,event_date DESC,id DESC LIMIT 60');
    $eventsQ->execute($active);
    $events=$eventsQ->fetchAll(PDO::FETCH_ASSOC);
    $types=[];
    if ($events !== []) {
        $ids=array_map(static fn(array $item): int => (int)$item['id'],$events);
        $tickets=db()->prepare('SELECT id,event_id,name FROM event_ticket_types WHERE event_id IN ('
            .implode(',',array_fill(0,count($ids),'?')).') ORDER BY sort_order,id');
        $tickets->execute($ids);
        foreach ($tickets->fetchAll(PDO::FETCH_ASSOC) as $ticket) {
            $types[(int)$ticket['event_id']][]=[
                'id'=>(int)$ticket['id'],'name'=>(string)$ticket['name']];
        }
    }
    foreach ($events as $event) {
        $id=(int)$event['id'];
        $catalog[]=['id'=>$id,'title'=>(string)$event['title'],
            'ticket_types'=>$types[$id]??[]];
    }
} catch (Throwable) { $catalog=[]; }
$catalogJson=json_encode(['ok'=>true,'events'=>$catalog],
    JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR);
?>

<section data-finance-module class="tk-finance">
  <script type="application/json" data-finance-catalog><?= $catalogJson ?></script>
  <h2>Finanzas de boletería</h2>
  <div class="tk-actions" role="group" aria-label="Vistas de Finanzas">
    <button class="tk-button" type="button" data-finance-summary aria-pressed="true" aria-controls="tk-finance-summary">Resumen</button>
    <button class="tk-button" type="button" data-finance-expenses aria-pressed="false" aria-controls="tk-finance-ledger">Gastos</button>
  </div>
  <div id="tk-finance-summary">
  <p class="ticketing-empty">Consulta ingresos y operaciones por evento. Los abonos, comisiones y gastos aún no tienen conciliación acreditada.</p>
  <form data-finance-form class="tk-form">
    <fieldset class="tk-step">
      <legend>Qué consultar</legend>
      <div class="tk-field"><label for="tk-finance-event">Evento</label>
        <select id="tk-finance-event" name="event_id"><option value="">Todos los eventos</option></select></div>
      <div class="tk-field"><label for="tk-finance-channel">Canal</label>
        <select id="tk-finance-channel" name="channel">
          <option value="all">Todos</option>
          <option value="wompi">Wompi</option>
          <option value="pos">POS (Caja)</option>
        </select></div>
    </fieldset>
    <fieldset class="tk-step">
      <legend>Período</legend>
      <div class="tk-field"><label for="tk-finance-from">Desde</label><input id="tk-finance-from" type="date" name="from"></div>
      <div class="tk-field"><label for="tk-finance-to">Hasta</label><input id="tk-finance-to" type="date" name="to"></div>
    </fieldset>
    <div class="tk-footer">
      <p class="tk-summary">Los importes son informativos, no un cierre contable.</p>
      <div class="tk-actions"><button class="tk-button is-primary" type="submit">Consultar</button>
        <button class="tk-button" type="button" data-finance-export>Exportar CSV</button></div>
    </div>
  </form>
  <p class="tk-status" role="status" data-finance-status aria-live="polite"></p>
  <div data-finance-results></div>
  <section aria-labelledby="tk-finance-tools-heading">
    <h3 id="tk-finance-tools-heading">Herramientas</h3>
    <p class="ticketing-empty">Caja y Devoluciones se abren por separado. No se realizan cobros reales desde estas herramientas.</p>
    <div class="tk-actions" data-finance-actions>
      <button class="tk-button" type="button" data-finance-open-pos aria-pressed="false">Caja</button>
    </div>
    <div data-finance-tool-panel></div>
  </section>
  </div>
  <section id="tk-finance-ledger" hidden aria-labelledby="tk-ledger-title"
    data-ledger-csrf="<?= htmlspecialchars(brvtal_admin_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    <h3 id="tk-ledger-title">Gastos y presupuesto</h3>
    <p>Registra compromisos, gastos pagados y presupuestos por categoría. Los ajustes conservan el historial; no efectúan pagos.</p>
    <div class="tk-field"><label for="tk-ledger-event">Evento del libro</label>
      <select id="tk-ledger-event"><option value="">Elige un evento</option></select></div>
    <div class="tk-actions"><button class="tk-button" type="button" data-ledger-refresh>Actualizar libro</button></div>
    <p class="tk-status" role="status" aria-live="polite" data-ledger-status></p>
    <div data-ledger-results></div>
    <template data-ledger-template>
      <form data-ledger-form>
        <fieldset class="tk-step" disabled>
          <legend data-ledger-heading>Nuevo registro</legend>
          <label class="tk-field">Tipo de registro<select name="kind"><option value="expense">Gasto</option><option value="budget">Presupuesto</option></select></label>
          <label class="tk-field">Categoría<select name="category" required>
            <option value="artistas">Artistas</option><option value="lugar">Lugar</option><option value="produccion">Producción</option>
            <option value="publicidad">Publicidad</option><option value="logistica">Logística</option><option value="otros">Otros</option>
          </select></label>
          <div data-expense-only><label class="tk-field">Concepto<input name="concept" maxlength="180" required></label></div>
          <label class="tk-field">Importe (COP)<input name="amount_cop" type="number" min="1" max="9007199254740991" step="1" required></label>
          <label class="tk-field">Fecha (Bogotá)<input name="entry_date" type="date" required></label>
          <div data-expense-only><label class="tk-field">Estado<select name="status"><option value="committed">Comprometido</option><option value="paid">Pagado</option></select></label></div>
          <label class="tk-field">Motivo del registro o ajuste<input name="reason" maxlength="320" required></label>
          <div class="tk-actions"><button class="tk-button is-primary" type="submit">Guardar registro</button>
            <button class="tk-button" type="button" data-ledger-new>Nuevo registro</button>
            <button class="tk-button" type="button" data-ledger-void hidden>Anular registro</button></div>
        </fieldset>
      </form>
    </template>
  </section>
</section>
