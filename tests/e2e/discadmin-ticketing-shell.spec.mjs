import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
const source = readFileSync('discadmin/ticketing.js', 'utf8');
const core = readFileSync('discadmin/index-core.php', 'utf8');
const php = readFileSync('discadmin/ticketing.php', 'utf8');
const roles = readFileSync('config/ticketing_staff_permissions.php', 'utf8');
const names = ['Dashboard','Pedidos','Boletas','Compradores','Puerta','Finanzas','Promos','Promotores','Correos','Ajustes'];
const ids = ['dashboard','orders','entries','buyers','door','finance','promos','promoters','mail','settings'];
const fixture = (visible = ids) => '<section class="ticketing" data-admin-module="ticketing" data-wompi-mode="disabled" data-resend-mode="disabled">'
  + '<div role="tablist" aria-label="Secciones de Boletería">'
  + visible.map((id, i) => '<button role="tab" type="button" id="ticketing-tab-' + id
    + '" data-ticketing-tab="' + id + '" aria-selected="' + (i === 0) + '" tabindex="' + (i === 0 ? 0 : -1) + '">'
    + names[ids.indexOf(id)] + '</button>').join('')
  + '</div><div id="ticketing-panel" role="tabpanel" data-ticketing-panel tabindex="0"></div></section>';

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
      + '<script>window.state={authed:true,section:"dashboard"};window.go=async function(s){state.section=s;if(s==="ticketing")return window.BRVTALTicketing.mount();return true;}</script>'
  }));
  await page.goto('/discadmin/ticketing-shell-fixture.html');
  await page.addScriptTag({content:source});
}

test('the canonical shell mounts all 10 Boletería areas and supports keyboard/mobile', async ({page}) => {
  await page.setViewportSize({width:390,height:844});
  await setup(page);
  await page.getByRole('button',{name:'BOLETERÍA'}).click();
  await expect(page.locator('#app .shell aside.side')).toHaveCount(1);
  await expect(page.locator('#app .shell main.main [data-admin-module="ticketing"]')).toBeVisible();
  await expect(page.getByRole('tab')).toHaveCount(10);
  await page.getByRole('tab',{name:'Dashboard'}).focus();
  await page.keyboard.press('End');
  await expect(page.getByRole('tab',{name:'Ajustes'})).toBeFocused();
  await expect(page.getByRole('tabpanel')).toContainText('Wompi');
  await expect(page.getByRole('button',{name:'Guardar',exact:true})).toBeDisabled();
  await expect(page.getByRole('button',{name:'Guardar y cerrar'})).toBeDisabled();
  await expect(page.getByRole('checkbox')).toHaveCount(2);
  await expect(page.locator('input[type=password]')).toHaveCount(0);
  expect(new URL(page.url()).searchParams.get('module')).toBe('ticketing');
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

test('server-side permissions refuse buyer and sensitive staff operations', async () => {
  const evaluated = JSON.parse(execFileSync('php',['-r',
    "require 'config/ticketing_staff_permissions.php'; echo json_encode([brvtalTicketingCan('buyer','view'),brvtalTicketingCan('door','finance'),brvtalTicketingCan('admin','refund'),brvtalTicketingCan('superadmin','refund'),brvtalTicketingProviderStatus()]);"
  ],{encoding:'utf8'}));
  expect(evaluated.slice(0,4)).toEqual([false,false,false,true]);
  expect(evaluated[4]).toEqual({wompi:'disabled',resend:'disabled',health:'not_checked'});
  expect(core).toContain("['ticketing','BOLETERÍA']");
  expect(core).toContain("window.BRVTALTicketing.mount()");
  expect(php).toContain('brvtal_admin_require();');
  expect(php).toContain("SELECT staff_role FROM admins");
  expect(roles).toContain("brvtalTicketingCan");
  expect(core).toContain('data-admin-nav');
  expect(core).toContain('BRVTALContentCore');
});
