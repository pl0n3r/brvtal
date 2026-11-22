import {test, expect} from '@playwright/test';
import {readFileSync} from 'node:fs';

const script = readFileSync('discadmin/system-status-v2.js','utf8');
const fixture = '<!doctype html><html lang="es"><meta charset="utf-8">'
  + '<nav class="nav"><button class="active">SYSTEM STATUS</button></nav>'
  + '<main class="main"><div class="top"><h1>SYSTEM STATUS</h1></div></main>'
  + '<script src="/discadmin/system-status-v2.js"></script></html>';

async function setup(page, status) {
  await page.route('**/discadmin/provisioning-fixture.html', route =>
    route.fulfill({contentType:'text/html',body:fixture}));
  await page.route('**/discadmin/system-status-v2.js', route =>
    route.fulfill({contentType:'text/javascript',body:script}));
  await page.route('**/discadmin/technical.php?*', route =>
    route.fulfill({contentType:'application/json',body:JSON.stringify({
      ok:true, checks:[{key:'api',label:'API',status:'ok',value:'ONLINE'}],
      health:{status:'healthy',score:100,checks_ok:1,checks_total:1},
      database:{driver:'mysql',server:'test',counts:{}},runtime:{php:'8.5'},
      deployment:{version:'0.1.270',environment:'PRODUCTION',exact:false},
      repository:{github:{ok:false,backlog_state:'unavailable'},source_lines:0,source_files:0},
      issues:[],repository_diagnostics:[]
    })}));
  await page.route('**/api/content-health.php', route =>
    route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,data:{score:100,ready:1}})}));
  await page.route('**/api/admin-activity.php?*', route =>
    route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,data:{items:[],total:0}})}));
  await page.route('**/discadmin/free-quotas.php', route =>
    route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,data:{rows:[]}})}));
  await page.route('**/api/admin-dashboard-preferences.php?*', route =>
    route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,data:{modules:[]}})}));
  await page.route('**/api/admin-system-status.php', route => status === null
    ? route.abort('failed')
    : route.fulfill({contentType:'application/json',body:JSON.stringify(status)}));
  await page.goto('https://www.brvtal.com.co/discadmin/provisioning-fixture.html');
}

test('System status muestra solo estados por integración y fecha observada de cron', async ({page}) => {
  await setup(page,{
    ok:true,
    integrations:[
      {id:'wompi',label:'Wompi',status:'modo sandbox'},
      {id:'resend',label:'Resend',status:'falta'},
      {id:'smtp',label:'SMTP',status:'configurado'},
      {id:'ga4',label:'Google Analytics 4',status:'falta'},
      {id:'sentry',label:'Sentry',status:'configurado'}
    ],
    workers:[
      {id:'ticketing-mail',label:'Correo de boletas',last_execution:'2026-11-21T18:00:00+00:00'},
      {id:'admin-password-mail',label:'Recuperación DISCADMIN',last_execution:null}
    ]
  });
  const panel=page.getByTestId('system-integrations');
  await expect(panel).toBeVisible();
  await expect(panel.locator('[data-integration="wompi"] [data-integration-state]')).toHaveText('modo sandbox');
  await expect(panel.locator('[data-integration="resend"] [data-integration-state]')).toHaveText('falta');
  await expect(panel.locator('[data-integration="smtp"] [data-integration-state]')).toHaveText('configurado');
  await expect(panel.locator('[data-worker="admin-password-mail"]')).toContainText('sin ejecución registrada');
  await expect(panel).toContainText('CONFIGURADO NO SIGNIFICA');
  const dom=await page.locator('body').innerText();
  expect(dom).not.toContain('prv_test_');
  expect(dom).not.toContain('whsec_');
  expect(dom).not.toContain('SMTP_PASSWORD');
  expect(dom).not.toContain('BEGIN PRIVATE KEY');
});

test('System status no inventa disponibilidad cuando falla la API privada', async ({page}) => {
  await setup(page,null);
  const panel=page.getByTestId('system-integrations');
  await expect(panel).toContainText('ESTADO NO DISPONIBLE');
  await expect(panel).toContainText('SIN EVIDENCIA DE EJECUCIÓN');
  await expect(panel.locator('[data-integration]')).toHaveCount(0);
});

test('API de indicadores requiere el límite de autenticación del DISCADMIN', async () => {
  const php=readFileSync('api/admin-system-status.php','utf8');
  expect(php).toContain('brvtal_admin_require();');
  expect(php).toContain("Cache-Control: no-store, private");
  expect(php).not.toMatch(/json_response\(\s*\$config/);
  expect(php).not.toMatch(/echo\s+\$e->getMessage\(/);
});
