import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
const source = readFileSync('discadmin/ticketing.js', 'utf8');
const core = readFileSync('discadmin/index-core.php', 'utf8');
const php = readFileSync('discadmin/ticketing.php', 'utf8');
const roles = readFileSync('config/ticketing_staff_permissions.php', 'utf8');
// #1964 (A): sections live in four groups; only the active group's sections show.
const sections = [
  ['dashboard','Resumen','sale'], ['orders','Pedidos','sale'], ['entries','Boletas','sale'], ['buyers','Compradores','sale'],
  ['door','Puerta','live'], ['courtesies','Cortesías','live'], ['finance','Finanzas','money'],
  ['promos','Cupones','marketing'], ['promoters','Promotores','marketing'], ['recovery','Recuperación','marketing'], ['mail','Correos','marketing'],
];
const groupNames = {sale:'Venta', live:'En vivo', money:'Dinero', marketing:'Marketing'};
const ids = sections.map(([id]) => id);
const fixture = (visible = ids) => {
  const shown = sections.filter(([id]) => visible.includes(id));
  const firstGroup = shown[0][2];
  const groups = [...new Set(shown.map(([, , group]) => group))];
  return '<section class="ticketing" data-admin-module="ticketing" data-ticketing-role="superadmin"' + (visible.includes('courtesies')?' data-ticketing-csrf="synthetic-csrf"':'') + '>'
    + '<p class="ticketing-status"><span data-ticketing-sale-status>Venta en línea: pruebas</span>'
    + '<span data-ticketing-mail-status>Correo: apagado</span>'
    + '<button type="button" class="ticketing-link" data-ticketing-configure>Configurar en Integraciones</button></p>'
    + '<nav class="ticketing-groups" aria-label="Grupos de Boletería">'
    + groups.map(group => '<button type="button" data-ticketing-group="' + group + '" aria-pressed="' + (group === firstGroup) + '">'
      + groupNames[group] + '</button>').join('')
    + '</nav><div role="tablist" aria-label="Secciones de Boletería">'
    + shown.map(([id, name, group], i) => '<button role="tab" type="button" id="ticketing-tab-' + id
      + '" data-ticketing-tab="' + id + '" data-ticketing-tab-group="' + group + '" aria-selected="' + (i === 0)
      + '" tabindex="' + (i === 0 ? 0 : -1) + '"' + (group === firstGroup ? '' : ' hidden') + '>' + name + '</button>').join('')
    + '</div><div id="ticketing-panel" role="tabpanel" data-ticketing-panel tabindex="0"></div></section>';
};

async function setup(page, response = {status:200,body:fixture()}) {
  await page.route('**/discadmin/ticketing.php', route => route.fulfill({
    contentType:'text/html; charset=utf-8',...response
  }));
  await page.route('**/discadmin/ticketing-shell-fixture.html', route => route.fulfill({
    contentType:'text/html; charset=utf-8',
    body:'<!doctype html><html lang="es"><meta name="viewport" content="width=device-width,initial-scale=1">'
      + '<body><div id="app"><div class="shell"><aside class="side"><nav class="nav">'
      + '<button data-admin-nav="ticketing">BOLETERÍA</button><button data-admin-nav="events">EVENTS</button>'
      + '</nav></aside><main class="main"><div class="top"><h1>BOLETERÍA</h1></div>'
      + '<div id="admin-module-host"></div></main></div></div>'
      + '<script>window.state={authed:true,section:"dashboard"};window.go=async function(s){state.section=s;if(s==="ticketing"){const loaded=await window.BRVTALTicketing.mount();if(loaded===false){state.section="dashboard";document.getElementById("admin-module-host").textContent="Dashboard";}return loaded;}return true;}</script>'
  }));
  await page.goto('/discadmin/ticketing-shell-fixture.html');
  await page.addScriptTag({content:source});
}

test('Boletería groups its sections, keeps keyboard and deep links, and shows real status (#1964)', async ({page}) => {
  await page.setViewportSize({width:390,height:844});
  await setup(page);
  await page.getByRole('button',{name:'BOLETERÍA'}).click();
  await expect(page.locator('#app .shell aside.side')).toHaveCount(1);
  await expect(page.locator('#app .shell main.main [data-admin-module="ticketing"]')).toBeVisible();
  const groups = page.getByRole('navigation',{name:'Grupos de Boletería'}).getByRole('button');
  await expect(groups).toHaveText(['Venta','En vivo','Dinero','Marketing']);
  await expect(page.getByRole('tab')).toHaveText(['Resumen','Pedidos','Boletas','Compradores']);
  await expect(page.getByRole('tab',{name:'Ajustes'})).toHaveCount(0);
  await expect(page.locator('.ticketing-notice')).toHaveCount(0);
  await expect(page.locator('[data-ticketing-sale-status]')).toHaveText('Venta en línea: pruebas');
  await page.getByRole('button',{name:'Marketing'}).click();
  await expect(page.getByRole('button',{name:'Marketing'})).toHaveAttribute('aria-pressed','true');
  await expect(page.getByRole('tab')).toHaveText(['Cupones','Promotores','Recuperación','Correos']);
  await expect(page.getByRole('tab',{name:'Cupones'})).toHaveAttribute('aria-selected','true');
  expect(new URL(page.url()).searchParams.get('tab')).toBe('promos');
  await page.getByRole('tab',{name:'Cupones'}).focus();
  await page.keyboard.press('End');
  await expect(page.getByRole('tab',{name:'Correos'})).toBeFocused();
  await page.keyboard.press('ArrowRight');
  await expect(page.getByRole('tab',{name:'Cupones'})).toBeFocused();
  await page.getByRole('tab',{name:'Recuperación'}).click();
  await expect(page.locator('[data-ticketing-panel] iframe[title="Panel de recuperación de compras"]')).toHaveCount(1);
  expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(390);
  expect(new URL(page.url()).searchParams.get('module')).toBe('ticketing');
  // A deep link opens its group directly.
  await page.evaluate(() => window.BRVTALTicketing.selectTab('door'));
  await expect(page.getByRole('button',{name:'En vivo'})).toHaveAttribute('aria-pressed','true');
  await expect(page.getByRole('tab')).toHaveText(['Puerta','Cortesías']);
});

test('door role sees only Puerta, and denied fragment cannot mount data', async ({page}) => {
  await setup(page,{status:200,body:fixture(['door'])});
  await page.getByRole('button',{name:'BOLETERÍA'}).click();
  await expect(page.getByRole('tab')).toHaveCount(1);
  await expect(page.getByRole('tab',{name:'Puerta'})).toBeVisible();
  await page.route('**/discadmin/ticketing.php', route => route.fulfill({status:403,body:'Forbidden'}));
  await page.evaluate(() => window.BRVTALTicketing.mount());
  await expect(page.locator('.ticketing-denied')).toBeVisible();
  await expect(page.getByRole('tab')).toHaveCount(0);
});

test('Cortesías: ruta canónica, contexto Pedidos y Back/Forward',async({page})=>{
  await setup(page);
  await page.route('**/discadmin/ticketing-courtesy.js*',route=>route.fulfill({contentType:'text/javascript',body:readFileSync('discadmin/ticketing-courtesy.js','utf8')}));
  await page.route('**/api/ticketing/admin-courtesy-list.php*',route=>route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,data:{rows:[],catalog:[],totals:{},has_more:false,next_cursor:null}})}));
  await page.route('**/discadmin/ticketing-orders.js*',route=>route.fulfill({contentType:'text/javascript',body:readFileSync('discadmin/ticketing-orders.js','utf8')}));
  await page.route('**/discadmin/ticketing-orders.php*',route=>route.fulfill({contentType:'text/html',body:'<section data-ticketing-records><form data-ticketing-records-form><input name="kind" value="orders"><select name="status"></select></form><p data-ticketing-records-status></p><div data-ticketing-records-results></div><button data-ticketing-records-more></button><div data-ticketing-records-detail></div></section>'}));
  await page.route('**/api/ticketing/admin-orders.php*',route=>route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,data:{rows:[],has_more:false,next_cursor:null}})}));
  await page.evaluate(()=>{history.replaceState({},'', '?module=ticketing&tab=courtesies');return window.BRVTALTicketing.open({replace:true});});
  await expect(page.getByRole('tab',{name:'Cortesías'})).toHaveAttribute('aria-selected','true');
  await expect(page.locator('[data-ticketing-courtesy]')).toHaveCount(1);
  await page.getByRole('button',{name:'Venta'}).click();
  await page.getByRole('tab',{name:'Pedidos'}).click();
  await expect(page.getByRole('button',{name:'Gestionar cortesías'})).toBeVisible();
  await expect(page.locator('[data-ticketing-courtesy]')).toHaveCount(0);
  await page.getByRole('button',{name:'Gestionar cortesías'}).click();
  await expect(page.locator('[data-ticketing-courtesy]')).toHaveCount(1);
  await page.goBack();
  await expect(page.getByRole('tab',{name:'Pedidos'})).toHaveAttribute('aria-selected','true');
  await page.goForward();
  await expect(page.getByRole('tab',{name:'Cortesías'})).toHaveAttribute('aria-selected','true');
  await expect(page.locator('[data-ticketing-courtesy]')).toHaveCount(1);
});
test('server-side permissions refuse buyer and sensitive staff operations', async () => {
  const evaluated = JSON.parse(execFileSync('php',['-r',
    "require 'config/ticketing_staff_permissions.php'; echo json_encode([brvtalTicketingCan('buyer','view'),brvtalTicketingCan('door','finance'),brvtalTicketingCan('admin','refund'),brvtalTicketingCan('superadmin','refund'),brvtalTicketingCan('root','settings'),brvtalTicketingCan('untrusted','view'),brvtalTicketingProviderStatus(),brvtalTicketingCan('owner','courtesy'),brvtalTicketingCan('admin','courtesy'),brvtalTicketingCan('door','courtesy')]);"
  ],{encoding:'utf8'}));
  expect(evaluated.slice(0,6)).toEqual([false,false,false,true,true,false]);
  expect(evaluated[6]).toEqual({wompi:'disabled',resend:'disabled',health:'not_checked'});
  expect(evaluated.slice(7)).toEqual([true,false,false]);
  expect(core).toContain("['ticketing','BOLETERÍA']");
  expect(core).toContain("window.BRVTALTicketing.mount()");
  expect(php).toContain('brvtal_admin_require();');
  expect(php).not.toContain('Sin ventas, emisión QR ni proveedores activos');
  expect(php).toContain('data-ticketing-sale-status');
  expect(php).toContain('brvtal_ticket_wompi_status()');
  expect(php).toContain("SELECT staff_role FROM admins");
  expect(roles).toContain("brvtalTicketingCan");
  expect(core).toContain('data-admin-nav');
  expect(core).toContain("state.section==='content-core'||state.section==='security'");
});

test('fragment failure leaves a retry action in the single workspace', async ({page}) => {
  await setup(page,{status:503,body:'Service unavailable'});
  await page.getByRole('button',{name:'BOLETERÍA'}).click();
  await expect(page.locator('#app .shell')).toHaveCount(1);
  await expect(page.locator('.ticketing-error')).toContainText('No se pudo cargar');
  await expect(page.getByRole('button',{name:'Reintentar'})).toBeVisible();
});
