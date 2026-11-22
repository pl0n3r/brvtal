import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';

const source=readFileSync(new URL('../../discadmin/settings-v2.js',import.meta.url),'utf8');
const css=readFileSync(new URL('../../discadmin/settings-v2.css',import.meta.url),'utf8');
const url='http://127.0.0.1:4173/discadmin/mail-test-fixture.html?settings=integrations';

async function setup(page,{writable=true,denyTest=false}={}){
  await page.route('**/discadmin/mail-test-fixture.html?*',route=>route.fulfill({
    contentType:'text/html',
    body:'<!doctype html><html lang="es"><head><meta name="viewport" content="width=device-width,initial-scale=1"></head><body style="margin:0;background:#080a0b;color:#f4f4f2;font-family:Arial,sans-serif"><div class="main" style="padding:16px"><div id="fixture"></div></div></body></html>'
  }));
  await page.goto(url);
  await page.evaluate(({writable,denyTest})=>{
    window.state={rows:[]};
    window.csrf='synthetic-csrf-1875';
    window.req=async()=>({data:[]});
    window.mailFixture={writes:[],requests:[],responses:[],stored:false,writable,denyTest};
    const json=(payload,status=200)=>{
      const body=JSON.stringify(payload);
      window.mailFixture.responses.push(body);
      return new Response(body,{status,headers:{'Content-Type':'application/json'}});
    };
    window.fetch=async(path,options={})=>{
      const pathname=new URL(path,location.href).pathname;
      const method=options.method||'GET';
      if(pathname==='/api/admin-integration-secrets.php'){
        const state=window.mailFixture;
        if(method==='POST'){
          const input=JSON.parse(options.body||'{}');
          state.writes.push({key:input.key,csrf:options.headers?.['X-CSRF-Token'],value:input.value});
          if(!state.writable)return json({ok:false,error:'SECRET_WRITE_DISABLED'},403);
          if(input.key==='BRVTAL_TICKET_MAIL_TEST_RECIPIENTS')state.stored=true;
        }
        return json({ok:true,write_enabled:state.writable,master_key_configured:state.writable,
          items:[{key:'BRVTAL_TICKET_MAIL_TEST_RECIPIENTS',provider:'resend',
            field:'test_recipients',stored:state.stored,updated_at:state.stored?'2026-11-22 08:00:00':null}]});
      }
      if(pathname==='/api/admin-integration-test-mail.php'){
        window.mailFixture.requests.push({method,csrf:options.headers?.['X-CSRF-Token'],body:options.body});
        if(window.mailFixture.denyTest)return json({ok:false,error:'TEST_MODE_REQUIRED'},409);
        return json({ok:true,state:'accepted'});
      }
      if(pathname==='/api/index.php/auth')return json({authenticated:true,csrf:'synthetic-csrf-1875'});
      throw Error('Unexpected request '+method+' '+pathname);
    };
  },{writable,denyTest});
  await page.addStyleTag({content:css});
  await page.addScriptTag({content:source});
  await page.evaluate(()=>{document.getElementById('fixture').innerHTML=window.settingsHome();});
  const card=page.locator('[data-integration-provider="resend"]');
  await expect(card).toBeVisible();
  return card;
}

test('AC-02: solo administrador con controles activos configura allowlist cifrada y envía prueba con CSRF',async({page})=>{
  const card=await setup(page);
  const recipients=card.getByRole('textbox',{name:'Destinatarios permitidos para pruebas'});
  await recipients.fill('qa@example.test, otras@example.test');
  await card.getByRole('button',{name:'Guardar',exact:true}).click();
  await expect(card.locator('[data-mail-allowlist-status]')).toHaveText('Lista guardada');
  await expect(recipients).toHaveValue('');
  await card.getByRole('button',{name:'Enviar correo de prueba'}).click();
  await expect(card.locator('[data-secret-feedback]')).toContainText('Correo de prueba aceptado');
  const fixture=await page.evaluate(()=>window.mailFixture);
  expect(fixture.writes).toHaveLength(1);
  expect(fixture.writes[0]).toMatchObject({key:'BRVTAL_TICKET_MAIL_TEST_RECIPIENTS',csrf:'synthetic-csrf-1875'});
  expect(fixture.requests).toEqual([{method:'POST',csrf:'synthetic-csrf-1875',body:'{}'}]);
  expect(fixture.responses.every(s=>!s.includes('qa@example.test'))).toBe(true);
  expect((await page.locator('body').innerText())).not.toContain('qa@example.test');
});

test('AC-02: no existe envío habilitado sin permiso y los errores no filtran claves',async({page})=>{
  const card=await setup(page,{writable:false});
  await expect(card.getByRole('button',{name:'Enviar correo de prueba'})).toBeDisabled();
  await expect(card.getByRole('textbox',{name:'Destinatarios permitidos para pruebas'})).toBeDisabled();
  expect((await page.evaluate(()=>window.mailFixture)).requests).toHaveLength(0);
});

test('AC-02: fallo de modo y diseño móvil sin destinatarios visibles',async({page})=>{
  await page.setViewportSize({width:390,height:844});
  const card=await setup(page,{denyTest:true});
  await card.getByRole('button',{name:'Enviar correo de prueba'}).click();
  await expect(card.locator('[data-secret-feedback]')).toContainText('Activa el modo test');
  expect(await page.evaluate(()=>document.documentElement.scrollWidth)).toBeLessThanOrEqual(391);
});
