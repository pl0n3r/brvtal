import {test, expect} from '@playwright/test';
import {readFileSync} from 'node:fs';
import {execFileSync} from 'node:child_process';
if (process.env.BRVTAL_DOOR_REAL_URL) test.use({trace:'off',screenshot:'off'});
const script = readFileSync('discadmin/ticketing-door.js','utf8');
const css = readFileSync('discadmin/ticketing-door.css','utf8');
const php = readFileSync('discadmin/ticketing-door.php','utf8');
const shell = readFileSync('discadmin/ticketing.js','utf8');
const fixture = '<!doctype html><html lang="es"><meta name="viewport" content="width=device-width,initial-scale=1">'
  + '<style>' + css + '</style><main class="door" data-door data-csrf="synthetic-csrf">'
  + '<form data-door-form><label>Evento<select data-door-event><option value="">Seleccione</option><option value="7">Evento A</option><option value="9">Evento B</option></select></label>'
  + '<label for="door-qr">Código QR de entrada</label><input id="door-qr" name="qr">'
  + '<button type="submit" data-door-submit>Validar entrada</button>'
  + '<button type="button" data-door-camera>Usar cámara</button><button type="button" data-door-stop hidden>Detener cámara</button>'
  + '<video data-door-video hidden></video><p data-door-camera-status></p></form>'
  + '<output role="status" data-door-result data-state="idle"></output>'
  + '<p data-door-summary></p><div data-door-audit></div></main>'
  + '<script type="module">import {mount} from "/discadmin/ticketing-door.js";'
  + 'mount(document.querySelector("[data-door]"));</script></html>';
async function setup(page, scan, source=script) {
  await page.route('**/discadmin/t16-fixture.html', route => route.fulfill({contentType:'text/html',body:fixture}));
  await page.route('**/discadmin/ticketing-door.js', route => route.fulfill({contentType:'text/javascript',body:source}));
  await page.route('**/discadmin/ticketing-door.php?*', route => route.fulfill({contentType:'application/json',
    body:JSON.stringify({ok:true,summary:{accepted:4,rejected:2},
      recent:[{at:'2026-11-21 UTC',operator:3,device:'abc12345',outcome:'rejected',reason:'already_used'}]})}));
  await page.route('**/api/ticketing/checkin.php', scan);
  await page.goto('https://www.brvtal.com.co/discadmin/t16-fixture.html');
  await page.locator('[data-door-event]').selectOption('7');
  await expect(page.locator('[data-door-summary]')).toContainText('Ingresos confirmados: 4');
}
test('T16 móvil: solo respuesta exacta del servidor permite ingreso; duplicado, revocado, otro evento y red caída deniegan', async ({page}) => {
  await page.setViewportSize({width:390,height:844});
  const responses = [
    {status:200,body:{ok:true,reason:'accepted'}},
    {status:409,body:{ok:false,reason:'already_used'}},
    {status:409,body:{ok:false,reason:'entry_revoked'}},
    {status:409,body:{ok:false,reason:'wrong_event'}},
    {error:true}
  ];
  const requests = [];
  await setup(page,route => {
    requests.push(JSON.parse(route.request().postData()));
    const next = responses.shift();
    if (next.error) return route.abort('failed');
    return route.fulfill({status:next.status,contentType:'application/json',body:JSON.stringify(next.body)});
  });
  for (let i=0;i<5;i++) {
    await page.locator('#door-qr').fill('BRVTAL:T1:synthetic-token-' + i);
    await page.getByRole('button',{name:'Validar entrada'}).click();
    await expect(page.locator('[data-door-result]')).toHaveAttribute('data-state',i===0?'allow':'deny');
  }
  expect(requests).toHaveLength(5);
  expect(requests.every(x=>x.event_id===7 && x.device_id.startsWith('door-'))).toBe(true);
  expect(requests.every(x=>Object.keys(x).sort().join(',')==='device_id,event_id,qr,request_id' && /^[a-f0-9]{32}$/.test(x.request_id))).toBe(true);
  expect(new Set(requests.map(x=>x.request_id)).size).toBe(5);
  await expect(page.locator('#door-qr')).toBeEmpty();
  await expect(page.locator('[data-door-audit]')).not.toContainText('email');
});
test('T16 permiso de cámara denegado: fallback manual y ninguna entrada falsa', async ({page}) => {
  await setup(page,route => route.fulfill({status:409,contentType:'application/json',
    body:JSON.stringify({ok:false,reason:'malformed_qr'})}));
  await page.evaluate(() => {
    window.BarcodeDetector = class {};
    Object.defineProperty(navigator,'mediaDevices',{
      configurable:true,value:{getUserMedia:async()=>{throw new Error('denied');}}
    });
  });
  await expect(page.locator('[data-door-camera]')).toBeVisible();
  await page.locator('[data-door-camera]').click();
  await expect(page.locator('[data-door-camera-status]')).toContainText('Permiso de cámara denegado');
  await expect(page.locator('[data-door-result]')).not.toHaveAttribute('data-state','allow');
  await page.locator('#door-qr').fill('invalid');
  await page.getByRole('button',{name:'Validar entrada'}).click();
  await expect(page.locator('[data-door-result]')).toHaveAttribute('data-state','deny');
});
test('T16 cámara simulada: un QR leído inicia una única validación server-side', async ({page}) => {
  let hits=0;
  await setup(page,route => { hits++; return route.fulfill({status:200,contentType:'application/json',
    body:JSON.stringify({ok:true,reason:'accepted'})}); });
  await page.evaluate(() => {
    window.BarcodeDetector = class { async detect(){ return [{rawValue:'BRVTAL:T1:synthetic-camera'}]; } };
    Object.defineProperty(navigator,'mediaDevices',{
      configurable:true,value:{getUserMedia:async()=>new MediaStream()}
    });
    HTMLMediaElement.prototype.play = async function(){};
  });
  await expect(page.locator('[data-door-camera]')).toBeVisible();
  await page.locator('[data-door-camera]').click();
  await expect(page.locator('[data-door-result]')).toHaveAttribute('data-state','allow');
  expect(hits).toBe(1);
  await expect(page.locator('[data-door-video]')).toBeHidden();
});
test('T16 rutas PHP exigen staff door y no exponen finanzas ni compradores', async () => {
  expect(php).toContain('brvtal_admin_require();');
  expect(php).toContain("brvtalTicketingCan((string)($role->fetchColumn()?:''), 'door')");
  // #2022: the door opens for every canonical selling state, not only 'published'.
  expect(php).toContain("brvtal_public_event_statuses()['active']");
  expect(php).not.toContain("status='published'");
  expect(php).not.toMatch(/SELECT.+email/i);
  expect(php).toContain("Cache-Control: no-store, private");
  expect(shell).toContain('Abrir lector móvil de puerta');
  expect(shell).toContain("'X-BRVTAL-ADMIN-FRAGMENT': '1'");
  expect(shell).toContain('document.importNode(door, true)');
  expect(php).toContain('HTTP_X_BRVTAL_ADMIN_FRAGMENT');
  expect(php).not.toContain('<!doctype html>');
});

test('T16 cambio de evento 7 → 9 → 7 durante POST nunca concede entrada', async ({page}) => {
  let release;
  const responseGate = new Promise(resolve => { release = resolve; });
  await setup(page, async route => {
    await responseGate;
    await route.fulfill({status:200,contentType:'application/json',
      body:JSON.stringify({ok:true,reason:'accepted'})});
  });
  await page.locator('#door-qr').fill('BRVTAL:T1:synthetic-delayed');
  await page.getByRole('button',{name:'Validar entrada'}).click();
  await expect(page.locator('[data-door-result]')).toHaveAttribute('data-state','pending');
  await page.locator('[data-door-event]').selectOption('9');
  await page.locator('[data-door-event]').selectOption('7');
  release();
  await expect(page.locator('[data-door-result]')).toHaveAttribute('data-state','deny');
  await expect(page.locator('[data-door-result]')).not.toContainText('ENTRADA VÁLIDA');
  await expect(page.locator('[data-door-receipt]')).toBeHidden();
});
test('T16 detección retrasada tras detener cámara no consume QR', async ({page}) => {
  let calls=0;
  await setup(page, route => { calls++; return route.fulfill({
    status:200,contentType:'application/json',body:JSON.stringify({ok:true,reason:'accepted'})
  }); });
  await page.evaluate(() => {
    window.BarcodeDetector = class {
      detect() { return new Promise(resolve => { window.__resolveDetectedQR = resolve; }); }
    };
    Object.defineProperty(navigator,'mediaDevices',{
      configurable:true,value:{getUserMedia:async()=>new MediaStream()}
    });
    HTMLMediaElement.prototype.play = async function(){};
  });
  await page.locator('[data-door-camera]').click();
  await page.waitForFunction(() => typeof window.__resolveDetectedQR === 'function');
  await page.locator('[data-door-stop]').click();
  await page.evaluate(() => window.__resolveDetectedQR([{rawValue:'BRVTAL:T1:synthetic-stopped'}]));
  await page.waitForTimeout(100);
  expect(calls).toBe(0);
  await expect(page.locator('[data-door-result]')).not.toHaveAttribute('data-state','allow');
  await expect(page.locator('[data-door-video]')).toBeHidden();
});
test('T16 evita adquisiciones concurrentes y apaga stream tardío tras detener cámara', async ({page}) => {
  await setup(page, route => route.abort('failed'));
  await page.evaluate(() => {
    window.BarcodeDetector = class {};
    window.__cameraAcquires = 0;
    window.__cameraStops = 0;
    Object.defineProperty(navigator,'mediaDevices',{configurable:true,value:{
      getUserMedia:() => {
        window.__cameraAcquires++;
        return new Promise(resolve => { window.__resolveAcquiredStream = resolve; });
      }
    }});
    HTMLMediaElement.prototype.play = async function(){};
  });
  await page.locator('[data-door-camera]').evaluate(button => { button.click(); button.click(); });
  await page.waitForFunction(() => typeof window.__resolveAcquiredStream === 'function');
  expect(await page.evaluate(() => window.__cameraAcquires)).toBe(1);
  await page.locator('[data-door-stop]').click();
  await page.evaluate(() => {
    const stream = new MediaStream();
    stream.getTracks = () => [{stop:() => {window.__cameraStops++;}}];
    window.__resolveAcquiredStream(stream);
  });
  await expect.poll(() => page.evaluate(() => window.__cameraStops)).toBe(1);
  await expect(page.locator('[data-door-video]')).toBeHidden();
});
test('T16 lector funciona dentro del único workspace DISCADMIN, sin segunda página', async ({page}) => {
  // The navigation/fragment lifecycle is independently exercised by discadmin-ticketing-shell.spec.mjs.
  // Mount the actual T16 module within the existing shell and validate server confirmation in place.
  const doorMarkup = fixture.slice(fixture.indexOf('<main class="door"'),fixture.indexOf('</main>') + 7);
  const fixtureShell = '<!doctype html><html lang="es"><body><div id="app"><div class="shell">'
    + '<aside class="side">Navegación</aside><main class="main"><div id="admin-module-host">'
    + '<section data-admin-module="ticketing"><div role="tabpanel" data-ticketing-panel>'
    + doorMarkup + '</div></section></div></main></div></div>'
    + '<script type="module">import {mount} from "/discadmin/ticketing-door.js";'
    + 'mount(document.querySelector("#app [data-door]"));</script></body></html>';
  await page.route('**/discadmin/t16-shell-fixture.html',route=>route.fulfill({
    contentType:'text/html',body:fixtureShell
  }));
  await page.route('**/discadmin/ticketing-door.js',route=>route.fulfill({
    contentType:'text/javascript',body:script
  }));
  await page.route('**/discadmin/ticketing-door.php?*',route=>route.fulfill({
    contentType:'application/json',body:JSON.stringify({ok:true,summary:{accepted:0,rejected:0},recent:[]})
  }));
  await page.route('**/api/ticketing/checkin.php',route=>route.fulfill({
    status:200,contentType:'application/json',body:JSON.stringify({ok:true,reason:'accepted'})
  }));
  await page.goto('https://www.brvtal.com.co/discadmin/t16-shell-fixture.html');
  await expect(page.locator('#app .shell aside.side')).toHaveCount(1);
  await expect(page.locator('#app .shell main.main [data-door]')).toBeVisible();
  await page.locator('[data-door-event]').selectOption('7');
  await page.locator('#door-qr').fill('BRVTAL:T1:synthetic-shell-token');
  await page.getByRole('button',{name:'Validar entrada'}).click();
  await expect(page.locator('#app .shell [data-door-result]')).toHaveAttribute('data-state','allow');
  await expect(page.locator('html')).toHaveCount(1);
});
function baselineDoorSource() {
  const sha='a0d17e831a3fd301d075665da7867a02e02c737a', file=sha+':discadmin/ticketing-door.js';
  try {execFileSync('git',['cat-file','-e',file]);} catch {execFileSync('git',['fetch','--no-tags','--depth=1','origin',sha],{timeout:20000});}
  return execFileSync('git',['show',file],{encoding:'utf8'});
}
for (const width of [390,1440]) {
  test('T16 recuperación explícita sin verde histórico a '+width+'px', async ({page},testInfo) => {
    await page.setViewportSize({width,height:900});
    let scan, lookups=0;
    const result=page.locator('[data-door-result]');
    if (testInfo.project.name==='chromium') {
      await setup(page,route=>route.abort('failed'),baselineDoorSource());
      await page.locator('#door-qr').fill('synthetic-before');
      await page.getByRole('button',{name:'Validar entrada'}).click();
      await expect(result).toContainText('Estado desconocido');
      await testInfo.attach('door-before-'+width,{body:await page.screenshot(),contentType:'image/png'});
      await page.unrouteAll({behavior:'wait'});
    }
    await setup(page,async route=>{
      const body=route.request().postDataJSON();
      if (body.action!=='receipt') {
        scan=body;
        return route.abort('failed');
      }
      expect(body).toEqual({action:'receipt',event_id:7,device_id:scan.device_id,request_id:scan.request_id});
      lookups++;
      const response=lookups===1 ? {status:404,body:{ok:false,error:'RECEIPT_NOT_FOUND'}}
        : lookups===2 ? {status:403,body:{ok:false,error:'FORBIDDEN'}}
        : {status:200,body:{ok:false,receipt:{outcome:'accepted',reason:'accepted'}}};
      return route.fulfill({status:response.status,contentType:'application/json',body:JSON.stringify(response.body)});
    });
    await page.locator('#door-qr').fill('synthetic-not-persisted');
    await page.getByRole('button',{name:'Validar entrada'}).click();
    await expect(result).toContainText('Estado desconocido');
    await expect(page.locator('#door-qr')).toBeEmpty();
    expect(await page.evaluate(()=>JSON.stringify(sessionStorage))).not.toContain('synthetic-not-persisted');
    await expect(page.locator('[data-door-receipt]')).toBeFocused();
    await page.reload();
    await page.locator('[data-door-event]').selectOption('9');
    const receipt=page.getByRole('button',{name:'Consultar intento anterior'});
    await expect(receipt).toBeVisible();
    await expect(page.locator('#door-qr')).toBeDisabled();
    await receipt.focus();
    await receipt.press('Enter');
    await expect(result).toContainText('No se encontró un recibo confirmado');
    await receipt.click();
    await expect(result).toContainText('Sin permiso');
    await testInfo.attach('door-receipt-denied-'+width,{body:await page.screenshot(),contentType:'image/png'});
    await receipt.click();
    await expect(result).toContainText('ingreso registrado anteriormente');
    await expect(result).toHaveAttribute('data-state','deny');
    await expect(page.locator('#door-qr')).toBeEnabled();
    expect(await page.evaluate(()=>sessionStorage.getItem('brvtal-door-attempt'))).toBeNull();
    expect(await page.evaluate(()=>document.documentElement.scrollWidth)).toBeLessThanOrEqual(width);
    await testInfo.attach('door-receipt-historical-'+width,{body:await page.screenshot(),contentType:'image/png'});
  });
}
  test('T16 real HTTP pérdida después del commit y antes del envío',async ({page})=>{
    const origin=process.env.BRVTAL_DOOR_REAL_URL;
    test.skip(!origin,'disposable T15 real-stack only');
    expect(origin).toMatch(/^http:\/\/127\.0\.0\.1:\d+$/);
    const qr=process.env.BRVTAL_DOOR_TEST_QR;
    const result=page.locator('[data-door-result]');
    await page.goto(origin+'/fixture');
    await page.locator('[data-door-event]').selectOption('1');
    let sent;
    await page.route('**/api/ticketing/checkin.php',async route=>{
      const body=route.request().postDataJSON();
      if (body.action==='receipt') return route.continue();
      sent=body.request_id;
      const actual=await route.fetch();
      expect(actual.status()).toBe(200);
      expect(await actual.json()).toEqual({ok:true,reason:'accepted'});
      await route.abort('failed');
    });
    await page.locator('#door-qr').fill(qr);
    await page.getByRole('button',{name:'Validar entrada'}).click();
    await expect(result).toContainText('Estado desconocido');
    await page.reload();
    await page.locator('[data-door-event]').selectOption('2');
    const receipt=page.getByRole('button',{name:'Consultar intento anterior'});
    await receipt.click();
    await expect(result).toContainText('ingreso registrado anteriormente');
    await expect(result).toHaveAttribute('data-state','deny');
    await page.unroute('**/api/ticketing/checkin.php');
    await page.locator('[data-door-event]').selectOption('1');
    await page.locator('#door-qr').fill(qr);
    await page.getByRole('button',{name:'Validar entrada'}).click();
    await expect(result).toContainText('Entrada ya utilizada');
    await page.route('**/api/ticketing/checkin.php',route=>{
      const body=route.request().postDataJSON();
      if (body.action==='receipt') return route.continue();
      expect(body.request_id).not.toBe(sent);
      return route.abort('failed');
    });
    await page.locator('#door-qr').fill('synthetic-before-transport');
    await page.getByRole('button',{name:'Validar entrada'}).click();
    await expect(result).toContainText('Estado desconocido');
    await receipt.click();
    await expect(result).toContainText('No se encontró un recibo confirmado');
    await expect(page.locator('#door-qr')).toBeDisabled();
    await expect(result).not.toHaveAttribute('data-state','allow');
  });
