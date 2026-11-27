import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

test.use({locale:'es-CO',timezoneId:'America/Bogota'});

const root = process.cwd();
const html = readFileSync(join(root, 'buyer/index.php'), 'utf8');
const config = readFileSync(join(root, 'config/buyer_account.php'), 'utf8');
const api = readFileSync(join(root, 'api/buyer/account.php'), 'utf8');
const url = 'http://127.0.0.1:4173/buyer/index.php';
const base = { public_id:'0123456789abcdef0123456789abcdef', event_title:'Noche sintética', event_date:'2026-12-12 21:00:00', venue:'Club sintético', currency:'COP', total_cop:25000, created_at_utc:'2026-11-01 21:00:00' };
const paid = { ...base, order_number:77, status:'paid', payment_status:'approved', items:[
  {name:'General',quantity:1,ticket_id:'abcdefabcdefabcdefabcdefabcdefab',status:'issued',qr_requires_original_token:true},
  {name:'General',quantity:1,ticket_id:'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',status:'used',qr_requires_original_token:true},
  {name:'General',quantity:1,ticket_id:'cccccccccccccccccccccccccccccccc',status:'revoked',qr_requires_original_token:false},
]};
async function fakeAccount(page, rows, qrStatus=200) {
  const requests = [];
  await page.route(url, route=>route.fulfill({ contentType:'text/html', body:html }));
  await page.route('**/api/buyer/auth.php', route=>route.fulfill({ contentType:'application/json', body:JSON.stringify({ok:true,authenticated:true,csrf:'fake-csrf'}) }));
  await page.route('**/api/buyer/account.php**', route=>{
    requests.push({method:route.request().method(),url:route.request().url(),headers:route.request().headers(),data:route.request().postDataJSON?.()});
    if(route.request().method()==='POST') return route.fulfill({status:qrStatus,contentType:'application/json',body:JSON.stringify(qrStatus===200
      ? {ok:true,qr:'BRVTAL:T1:'+route.request().postDataJSON().ticket_id+':'+'A'.repeat(43),filename:'entrada.txt'}
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
  const calls=await fakeAccount(page,[]);
  await page.goto(url);
  await expect(page.getByRole('heading',{name:'Mis compras'})).toBeVisible();
  await expect(page.getByRole('status').first()).toHaveText('');
  await expect(page.getByRole('heading',{name:'Todavía no tienes compras'})).toBeVisible();
  await expect(page.getByRole('heading',{name:'Todavía no tienes compras'})).toHaveAttribute('aria-live','polite');
  await expect(page.getByRole('region',{name:'Entrada QR'})).toBeHidden();
  await page.getByLabel('Desde').fill('2026-11-01');
  await page.getByRole('button',{name:'Aplicar filtros'}).click();
  await expect(page.getByRole('heading',{name:'No encontramos compras con esos filtros'})).toBeVisible();
  expect(calls.some(x=>x.url.includes('from=2026-11-01'))).toBe(true);
});

test('desktop: pending, expired, used, revoked, paid and secure scannable PNG download', async ({page}) => {
  await page.setViewportSize({width:1280,height:900});
  const calls=await fakeAccount(page,[paid,{...base,public_id:'p',status:'pending',payment_status:'pending',items:[]},
    {...base,public_id:'e',status:'expired',payment_status:'declined',items:[]}]);
  await page.goto(url);
  await expect(page.getByText('Noche sintética').first()).toBeVisible();
  await expect(page.getByText('Pago pendiente').first()).toBeVisible();
  // #1950: the order number matches the Wompi reference BW7-<n>-….
  await expect(page.getByText(/^Pedido #77 · /)).toBeVisible();
  await expect(page.getByText(/Expirado/)).toBeVisible();
  await expect(page.getByText(/Rechazado/)).toBeVisible();
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
  await page.getByRole('button',{name:'Descargar QR PNG'}).click();
  expect((await download).suggestedFilename()).toBe('entrada-brvtal.png');
});

test('mobile: invalid/revoked token remains hidden and pagination stays read-only', async ({page}) => {
  await page.setViewportSize({width:360,height:720});
  const calls=await fakeAccount(page,[paid],404);
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

// #1978: account-owned QR without manual token, with real responsive captures.
test('buyer can open and download a stored QR at 390 and 1440 px', async ({page},testInfo) => {
  for(const width of [390,1440]){
    await page.setViewportSize({width,height:900});
    const stored={...paid,items:[{...paid.items[0],qr_stored:true}]};
    const calls=await fakeAccount(page,[stored]);
    await page.goto(url);
    await expect(page.getByRole('button',{name:'Ver QR'})).toBeVisible();
    await page.screenshot({path:testInfo.outputPath('account-'+width+'-before.png')});
    await page.getByRole('button',{name:'Ver QR'}).click();
    await expect(page.locator('#qr-output svg')).toBeVisible();
    await expect(page.getByLabel('Credencial original')).toBeHidden();
    expect(calls.find(x=>x.method==='POST')?.data?.action).toBe('qr_stored');
    expect(calls.find(x=>x.method==='POST')?.headers['x-csrf-token']).toBe('fake-csrf');
    await page.screenshot({path:testInfo.outputPath('account-'+width+'-after.png')});
    const download=page.waitForEvent('download');
    await page.getByRole('button',{name:'Descargar QR PNG'}).click();
    expect((await download).suggestedFilename()).toBe('entrada-brvtal.png');
  }
});

test('stored QR owner mismatch yields 404-equivalent and no visible QR', async ({page}) => {
  const stored={...paid,items:[{...paid.items[0],qr_stored:true}]};
  await fakeAccount(page,[stored],404);
  await page.goto(url);
  await page.getByRole('button',{name:'Ver QR'}).click();
  await expect(page.getByText(/No se pudo autorizar esta entrada/)).toBeVisible();
  await expect(page.locator('#qr-output svg')).toHaveCount(0);
  await expect(page.getByLabel('Credencial original')).toBeVisible();
});

test('stale QR response cannot replace a newer selected ticket', async ({page}) => {
  const first='abcdefabcdefabcdefabcdefabcdefab', second='dddddddddddddddddddddddddddddddd';
  const withTwo={...paid,items:[
    {...paid.items[0],ticket_id:first,qr_stored:true},
    {...paid.items[0],ticket_id:second,qr_stored:true}
  ]};
  let releaseFirst;
  const delayed=new Promise(resolve=>{releaseFirst=resolve;});
  await page.route(url,route=>route.fulfill({contentType:'text/html',body:html}));
  await page.route('**/api/buyer/auth.php',route=>route.fulfill({contentType:'application/json',
    body:JSON.stringify({ok:true,authenticated:true,csrf:'fake-csrf'})}));
  await page.route('**/api/buyer/account.php**',async route=>{
    if(route.request().method()==='GET')return route.fulfill({contentType:'application/json',
      body:JSON.stringify({ok:true,data:{orders:[withTwo],has_more:false,page:0}})});
    const id=route.request().postDataJSON()?.ticket_id;
    if(id===first)await delayed;
    return route.fulfill({contentType:'application/json',
      body:JSON.stringify({ok:true,qr:'BRVTAL:T1:'+id+':'+'A'.repeat(43)})});
  });
  await page.goto(url);
  const buttons=page.getByRole('button',{name:'Ver QR'});
  await expect(buttons).toHaveCount(2);
  await buttons.nth(0).click();
  await buttons.nth(1).click();
  await expect(page.locator('#qr-output svg')).toBeVisible();
  const freshSvg=await page.locator('#qr-output').innerHTML();
  releaseFirst();
  await page.waitForTimeout(100);
  expect(await page.locator('#qr-output').innerHTML()).toBe(freshSvg);
  await expect(page.locator('#qr-feedback')).toContainText('QR autorizado');
});

test('switching from pending stored QR to legacy ticket restores the manual submit', async ({page}) => {
  const storedId='abcdefabcdefabcdefabcdefabcdefab';
  const legacyId='eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee';
  const two={...paid,items:[
    {...paid.items[0],ticket_id:storedId,qr_stored:true},
    {...paid.items[0],ticket_id:legacyId,qr_stored:false}
  ]};
  let releaseStored;
  const pending=new Promise(resolve=>{releaseStored=resolve;});
  await page.route(url,route=>route.fulfill({contentType:'text/html',body:html}));
  await page.route('**/api/buyer/auth.php',route=>route.fulfill({contentType:'application/json',
    body:JSON.stringify({ok:true,authenticated:true,csrf:'fake-csrf'})}));
  const sent=[];
  await page.route('**/api/buyer/account.php**',async route=>{
    if(route.request().method()==='GET')return route.fulfill({contentType:'application/json',
      body:JSON.stringify({ok:true,data:{orders:[two],has_more:false,page:0}})});
    const body=route.request().postDataJSON();sent.push(body);
    if(body.ticket_id===storedId)await pending;
    return route.fulfill({contentType:'application/json',
      body:JSON.stringify({ok:true,qr:'BRVTAL:T1:'+body.ticket_id+':'+'A'.repeat(43)})});
  });
  await page.goto(url);
  const buttons=page.getByRole('button',{name:'Ver QR'});
  await expect(buttons).toHaveCount(2);
  await buttons.nth(0).click();
  await buttons.nth(1).click();
  const submit=page.getByRole('button',{name:'Mostrar QR'});
  await expect(submit).toBeEnabled();
  await page.getByLabel('Credencial original').fill('A'.repeat(43));
  await submit.click();
  await expect(page.locator('#qr-output svg')).toBeVisible();
  const expectedSvg=await page.locator('#qr-output').innerHTML();
  releaseStored();
  await page.waitForTimeout(100);
  expect(await page.locator('#qr-output').innerHTML()).toBe(expectedSvg);
  expect(sent.some(x=>x.ticket_id===legacyId&&x.action==='qr')).toBe(true);
  await expect(page.locator('#qr-feedback')).toContainText('QR autorizado');
});

// #2184: actual rendered screen and print media, synthetic credentials only.
for(const width of [390,1440]){
  test(`authenticated account PDF pass at ${width}px`,async ({page},testInfo)=>{
    await page.setViewportSize({width,height:900});
    for(const name of ['buyer-access.css','buyer-account.css']){
      await page.route('**/css/'+name+'*',route=>route.fulfill({contentType:'text/css',body:readFileSync(join(root,'css',name))}));
    }
    const eligible={...paid,items:[{...paid.items[0],qr_stored:true},...paid.items.slice(1)]};
    const cancelled={...base,public_id:'cancelled',status:'cancelled',items:[{...paid.items[0],ticket_id:'f'.repeat(32)}]};
    const calls=await fakeAccount(page,[eligible,cancelled]);
    await page.goto(url);
    const action=page.getByRole('button',{name:'Descargar pase (PDF)'});
    await expect(action).toHaveCount(1);
    await expect(page.locator('#pass-details')).toBeHidden();
    await testInfo.attach('account-pass-before-'+width,{body:await page.screenshot({fullPage:true}),contentType:'image/png'});
    await page.evaluate(()=>{window.__passPrints=0;window.print=()=>{window.__passPrints++;};});
    await action.click();
    const print=page.getByRole('button',{name:'Imprimir / guardar PDF'});
    await expect(print).toBeVisible();
    await expect(page.locator('#pass-details')).toContainText('Noche sintética');
    await expect(page.locator('#pass-details')).toContainText('12 de diciembre de 2026');
    await expect(page.locator('#pass-details')).toContainText('Club sintético');
    await expect(page.locator('#pass-details')).toContainText('General');
    await expect(page.locator('#pass-details')).toContainText('ABCDEFAB');
    await expect(page.locator('#qr-output svg')).toBeVisible();
    expect(calls.find(x=>x.method==='POST')?.data?.ticket_id).toBe(paid.items[0].ticket_id);
    await print.focus();await print.press('Enter');
    expect(await page.evaluate(()=>window.__passPrints)).toBe(1);
    expect(await page.evaluate(()=>document.documentElement.scrollWidth)).toBeLessThanOrEqual(width);
    await page.emulateMedia({media:'print'});
    await expect(page.locator('#pass-details')).toBeVisible();
    await expect(page.locator('#qr-output svg')).toBeVisible();
    await expect(page.locator('#orders')).toBeHidden();
    await expect(page.locator('#qr-form')).toBeHidden();
    await expect(print).toBeHidden();
    await testInfo.attach('account-pass-after-'+width,{body:await page.screenshot({fullPage:true}),contentType:'image/png'});
    await page.emulateMedia({media:'screen'});
    await page.getByRole('button',{name:'Aplicar filtros'}).click();
    await expect(page.locator('#pass-details')).toBeHidden();
    await expect(print).toBeHidden();
    expect(await page.evaluate(()=>document.body.classList.contains('account-pass-ready'))).toBe(false);
  });
}

test('failed legacy pass authorization never enables print',async ({page})=>{
  await fakeAccount(page,[paid],404);await page.goto(url);
  await page.getByRole('button',{name:'Descargar pase (PDF)'}).click();
  await page.getByLabel('Credencial original').fill('A'.repeat(43));
  await page.getByRole('button',{name:'Mostrar QR'}).click();
  await expect(page.locator('#qr-feedback')).toContainText('No se pudo autorizar');
  await expect(page.locator('#pass-details')).toBeHidden();
  await expect(page.locator('#print-pass')).toBeHidden();
});


// #2188: separate courtesy projection, actual base/candidate CSS and synthetic credentials.
const courtesyBase={origin:'courtesy',event_id:91,event_title:'Invitación sintética',
  event_date:'2026-12-12 21:00:00',venue:'Club sintético',name:'Acceso general',
  created_at_utc:'2026-11-01 21:00:00',refundable:false};
const courtesyRows=[
  {...courtesyBase,status:'issued',ticket_id:'dddddddddddddddddddddddddddddddd',qr_stored:true},
  {...courtesyBase,status:'revoked',ticket_id:'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee',qr_stored:false},
  {...courtesyBase,status:'used',ticket_id:'ffffffffffffffffffffffffffffffff',qr_stored:false},
  {...courtesyBase,status:'expired',ticket_id:'11111111111111111111111111111111',qr_stored:false},
  {...courtesyBase,status:'issued',ticket_id:'22222222222222222222222222222222',qr_stored:false},
];
for(const width of [390,1440]){
  test(`#2188 separate courtesy and stored QR at ${width}px`,async ({page},testInfo)=>{
    await page.setViewportSize({width,height:900});
    const baseline=process.env.BRVTAL_COURTESY_VISUAL_BASE_DIR;
    let source=baseline||root,deny=false;
    const calls=[];
    await page.route(url,route=>route.fulfill({contentType:'text/html',
      body:readFileSync(join(source,'buyer/index.php'),'utf8')}));
    for(const file of ['buyer-account.css','buyer-access.css']){
      await page.route('**/css/'+file+'*',route=>route.fulfill({contentType:'text/css',
        body:readFileSync(join(source,'css',file),'utf8')}));
    }
    await page.route('**/api/buyer/auth.php',route=>route.fulfill({contentType:'application/json',
      body:JSON.stringify({ok:true,authenticated:true,csrf:'synthetic-csrf'})}));
    await page.route('**/api/buyer/refunds.php',route=>route.fulfill({contentType:'application/json',
      body:JSON.stringify({ok:true,data:[]})}));
    await page.route('**/api/buyer/account.php**',route=>{
      const request=route.request();
      if(request.method()==='POST'){
        calls.push(request.postDataJSON());
        return route.fulfill({status:deny?404:200,contentType:'application/json',
          body:JSON.stringify(deny?{ok:false}:{ok:true,qr:'BRVTAL:T1:'+courtesyRows[0].ticket_id+':'+'A'.repeat(43)})});
      }
      return route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,data:{
        orders:[],has_more:false,courtesies:courtesyRows,courtesies_has_more:false,page:0}})});
    });
    const capture=async stage=>{
      await page.evaluate(async()=>{await document.fonts.ready;});
      await testInfo.attach(`2188-${stage}-${width}.png`,{
        body:await page.screenshot({fullPage:true,animations:'disabled'}),contentType:'image/png'});
    };
    if(baseline){
      await page.goto(url);
      await expect(page.getByRole('heading',{name:'Todavía no tienes compras'})).toBeVisible();
      await expect(page.locator('#refund-items')).toContainText('Sin solicitudes');
      await capture('before');
      source=root;
    }
    await page.goto(url);
    const section=page.getByRole('region',{name:'Tus cortesías'});
    await expect(section).toBeVisible();
    await expect(page.locator('#orders')).toContainText('No tienes compras');
    await expect(section.getByText('Cortesía',{exact:true})).toHaveCount(5);
    for(const state of ['Vigente','Usada','Anulada','Vencida'])await expect(section.getByText(state,{exact:true}).first()).toBeVisible();
    await expect(section.getByRole('button',{name:'Ver QR'})).toHaveCount(1);
    await expect(section.locator('article').filter({hasText:'Anulada'}).getByRole('button')).toHaveCount(0);
    await expect(section).not.toContainText(/Pago:|Reembolso|Solicitar devolución|COP/);
    await expect(page.locator('#event-select option[value="91"]')).toHaveText('Invitación sintética');
    await section.getByRole('button',{name:'Ver QR'}).click();
    await expect(page.locator('#qr-output svg')).toBeVisible();
    await expect(page.getByLabel('Credencial original')).toBeHidden();
    expect(calls).toEqual([{action:'qr_stored',ticket_id:courtesyRows[0].ticket_id}]);
    expect(await page.locator('body').innerText()).not.toContain('A'.repeat(43));
    expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth)).toBe(true);
    if(baseline)await capture('after');
    deny=true;
    await section.getByRole('button',{name:'Ver QR'}).click();
    await expect(page.locator('#qr-feedback')).toContainText('No se pudo autorizar');
    await expect(page.locator('#qr-output svg')).toHaveCount(0);
    await expect(page.getByLabel('Credencial original')).toBeHidden();
  });
}

test('#2188 courtesy-only pagination, filtering and empty state',async ({page})=>{
  const queries=[];
  await page.route(url,route=>route.fulfill({contentType:'text/html',body:html}));
  await page.route('**/api/buyer/auth.php',route=>route.fulfill({contentType:'application/json',
    body:JSON.stringify({ok:true,authenticated:true,csrf:'fake-csrf'})}));
  await page.route('**/api/buyer/refunds.php',route=>route.fulfill({contentType:'application/json',body:'{"data":[]}'}));
  await page.route('**/api/buyer/account.php**',route=>{
    const q=new URL(route.request().url()).searchParams;queries.push(q);
    const filtered=q.has('event_id'),next=q.get('page')==='1';
    return route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,data:{
      orders:[],has_more:false,courtesies:filtered?[]:[courtesyRows[next?1:0]],
      courtesies_has_more:!filtered&&!next}})});
  });
  await page.goto(url);
  const section=page.getByRole('region',{name:'Tus cortesías'});
  await expect(section.getByText('Vigente')).toBeVisible();
  await page.getByRole('button',{name:'Ver más entradas'}).click();
  await expect(section.getByText('Anulada')).toBeVisible();
  expect(queries.some(q=>q.get('page')==='1')).toBe(true);
  await page.getByLabel('Evento',{exact:true}).selectOption('91');
  await page.getByRole('button',{name:'Aplicar filtros'}).click();
  await expect(section).toContainText('No tienes cortesías en esta vista.');
  expect(queries.some(q=>q.get('event_id')==='91'&&q.get('page')==='0')).toBe(true);
  await expect(page.locator('#qr-output')).toBeHidden();
});
