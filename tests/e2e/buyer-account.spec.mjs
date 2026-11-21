import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const root = process.cwd();
const html = readFileSync(join(root, 'buyer/index.php'), 'utf8');
const config = readFileSync(join(root, 'config/buyer_account.php'), 'utf8');
const api = readFileSync(join(root, 'api/buyer/account.php'), 'utf8');
const url = 'http://127.0.0.1:4173/buyer/index.php';
const base = { public_id:'0123456789abcdef0123456789abcdef', event_title:'Noche sintética', currency:'COP', total_cop:25000, created_at_utc:'2026-11-01 21:00:00' };
const paid = { ...base, status:'paid', payment_status:'approved', items:[
  {name:'General',quantity:1,ticket_id:'abcdefabcdefabcdefabcdefabcdefab',status:'issued',qr_requires_original_token:true},
  {name:'General',quantity:1,ticket_id:'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',status:'used',qr_requires_original_token:true},
  {name:'General',quantity:1,ticket_id:'cccccccccccccccccccccccccccccccc',status:'revoked',qr_requires_original_token:false},
]};
function fakeAccount(page, rows, qrStatus=200) {
  const requests = [];
  page.route(url, route=>route.fulfill({ contentType:'text/html', body:html }));
  page.route('**/api/buyer/auth.php', route=>route.fulfill({ contentType:'application/json', body:JSON.stringify({ok:true,authenticated:true,csrf:'fake-csrf'}) }));
  page.route('**/api/buyer/account.php**', route=>{
    requests.push({method:route.request().method(),url:route.request().url(),headers:route.request().headers(),data:route.request().postDataJSON?.()});
    if(route.request().method()==='POST') return route.fulfill({status:qrStatus,contentType:'application/json',body:JSON.stringify(qrStatus===200
      ? {ok:true,qr:'BRVTAL:T1:'+'abcdef0123456789'.repeat(2)+':'+'A'.repeat(43),filename:'entrada.txt'}
      : {ok:false,error:'TICKET_UNAVAILABLE'})});
    const pageNo=Number(new URL(route.request().url()).searchParams.get('page')||0);
    return route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,data:{orders:pageNo?[]:rows,has_more:!!rows.length&&!pageNo,page:pageNo}})});
  });
  return requests;
}

test('T14 protects buyer, order and credential boundaries without Resend coupling', () => {
  expect(api).toContain('brvtalBuyerSessionStart()');
  expect(api).toContain('brvtalBuyerAccountAuthorized($db,$buyer)');
  expect(api).toContain('brvtalBuyerCsrfValid(');
  expect(api).toContain("'TICKET_UNAVAILABLE'");
  expect(api).toContain('Cache-Control: private, no-store');
  expect(config).toContain('o.buyer_id=?');
  expect(config).toContain('t.public_id=UNHEX(?) AND o.buyer_id=?');
  expect(config).toContain('brvtal_ticket_issue_download($db,$buyerId');
  expect(config).not.toMatch(/resend|admin_id|UPDATE ticket_|DELETE FROM ticket_/i);
  expect(api).not.toMatch(/admin_id|password_hash|ticket_payment_attempts|resend/i);
});

test('mobile: empty orders and filters are clear and do not reveal a QR', async ({page}) => {
  await page.setViewportSize({width:390,height:780});
  const calls=fakeAccount(page,[]);
  await page.goto(url);
  await expect(page.getByRole('heading',{name:'Mis compras'})).toBeVisible();
  await expect(page.getByRole('status').first()).toContainText('No hay más pedidos');
  await expect(page.getByText('Todavía no hay compras para estos filtros.')).toBeVisible();
  await expect(page.getByRole('region',{name:'Entrada QR'})).toBeHidden();
  await page.getByLabel('Desde (UTC)').fill('2026-11-01');
  await page.getByRole('button',{name:'Filtrar'}).click();
  await expect(page.getByText('Todavía no hay compras para estos filtros.')).toBeVisible();
  expect(calls.some(x=>x.url.includes('from=2026-11-01'))).toBe(true);
});

test('desktop: pending, expired, used, revoked, paid and secure scannable SVG download', async ({page}) => {
  await page.setViewportSize({width:1280,height:900});
  const calls=fakeAccount(page,[paid,{...base,public_id:'p',status:'pending',payment_status:'pending',items:[]},
    {...base,public_id:'e',status:'expired',payment_status:'declined',items:[]}]);
  await page.goto(url);
  await expect(page.getByText('Noche sintética').first()).toBeVisible();
  await expect(page.getByText('Pago pendiente').first()).toBeVisible();
  await expect(page.getByText(/Expirado/)).toBeVisible();
  await expect(page.getByText(/Usada/)).toBeVisible();
  await expect(page.getByText(/Revocada/)).toBeVisible();
  expect(await page.getByRole('button',{name:'Ver QR'}).count()).toBe(1);
  await page.getByRole('button',{name:'Ver QR'}).click();
  await page.getByLabel('Credencial original').fill('A'.repeat(43));
  await page.getByRole('button',{name:'Mostrar QR'}).click();
  const code=page.locator('#qr-output svg');
  await expect(code).toBeVisible();
  expect(await code.locator('rect').count()).toBeGreaterThan(400);
  expect(calls.find(x=>x.method==='POST')?.headers['x-csrf-token']).toBe('fake-csrf');
  expect(calls.find(x=>x.method==='POST')?.data?.ticket_id).toBe('abcdefabcdefabcdefabcdefabcdefab');
  expect(page.url()).not.toContain('BRVTAL:T1:');
  const download=page.waitForEvent('download');
  await page.getByRole('button',{name:'Descargar QR SVG'}).click();
  expect((await download).suggestedFilename()).toBe('entrada-brvtal.svg');
});

test('mobile: invalid/revoked token remains hidden and pagination stays read-only', async ({page}) => {
  await page.setViewportSize({width:360,height:720});
  const calls=fakeAccount(page,[paid],404);
  await page.goto(url);
  await page.getByRole('button',{name:'Ver QR'}).click();
  await page.getByLabel('Credencial original').fill('A'.repeat(43));
  await page.getByRole('button',{name:'Mostrar QR'}).click();
  await expect(page.getByText(/No se pudo autorizar esta entrada/)).toBeVisible();
  await expect(page.locator('#qr-output svg')).toHaveCount(0);
  await page.getByRole('button',{name:'Ver más pedidos'}).click();
  expect(calls.some(x=>x.method==='GET'&&x.url.includes('page=1'))).toBe(true);
});

test('API read failure is accessible, without misreporting success', async ({page}) => {
  await page.route(url, route=>route.fulfill({contentType:'text/html',body:html}));
  await page.route('**/api/buyer/auth.php',route=>route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,authenticated:true,csrf:'test'})}));
  await page.route('**/api/buyer/account.php**',route=>route.fulfill({status:503,contentType:'application/json',body:'{}'}));
  await page.goto(url);
  await expect(page.getByRole('status').first()).toContainText('No pudimos cargar tus compras');
  await expect(page.getByRole('button',{name:'Ver más pedidos'})).toBeHidden();
});
