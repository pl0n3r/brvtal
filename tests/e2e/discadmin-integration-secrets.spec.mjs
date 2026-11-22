import {test,expect} from '@playwright/test';
import {readFileSync} from 'node:fs';

const source=readFileSync(new URL('../../discadmin/system-status-v2.js',import.meta.url),'utf8');
const harness='http://127.0.0.1:4173/discadmin/integration-secrets-harness.html';
const key='BRVTAL_SENTRY_DSN';
const synthetic='https://synthetic-only@sentry.invalid/101';

async function setup(page,{writable=true}={}) {
  await page.route(harness,route=>route.fulfill({
    contentType:'text/html',
    body:'<!doctype html><html><body><div class="main"><div class="top"><h1>SYSTEM STATUS</h1></div></div></body></html>',
  }));
  await page.goto(harness);
  await page.evaluate(({key,writable})=>{
    window.csrf='synthetic-csrf-token';
    const db={stored:false,requests:[],value:null,writable};
    window.integrationFixture=db;
    window.fetch=async (url,options={})=>{
      const path=new URL(url,location.href).pathname;
      const method=options.method||'GET';
      const json=(data,status=200)=>new Response(JSON.stringify(data),{
        status,headers:{'Content-Type':'application/json'}
      });
      if(path==='/discadmin/technical.php') return json({
        ok:true,health:{status:'unknown'},database:{counts:{}},
        repository:{github:{backlog_state:'unavailable'}},checks:[],issues:[]
      });
      if(path==='/api/content-health.php') return json({ok:true,data:{score:100}});
      if(path==='/api/admin-activity.php') return json({ok:true,data:{total:0,items:[]}});
      if(path==='/discadmin/free-quotas.php') return json({ok:true,data:{rows:[]}});
      if(path==='/api/admin-dashboard-preferences.php') return json({ok:true,data:{modules:[]}});
      if(path==='/api/admin-system-status.php') return json({
        ok:true,integrations:[{id:'sentry',label:'Sentry',status:db.stored?'configurado':'falta'}],workers:[]
      });
      if(path==='/api/admin-integration-secrets.php'){
        if(method!=='GET'){
          const body=JSON.parse(options.body||'{}');
          db.requests.push({method,key:body.key,csrf:options.headers?.['X-CSRF-Token'],
            containsValue:Object.hasOwn(body,'value')});
          if(!db.writable) return json({ok:false,error:'SECRET_WRITE_DISABLED'},403);
          if(options.headers?.['X-CSRF-Token']!=='synthetic-csrf-token')
            return json({ok:false,error:'CSRF'},419);
          if(body.key!==key) return json({ok:false,error:'SECRET_KEY_NOT_ALLOWED'},422);
          if(method==='POST'){
            if(body.value!== 'https://synthetic-only@sentry.invalid/101')
              return json({ok:false,error:'SECRET_FORMAT_INVALID'},422);
            db.value=body.value;
            db.stored=true;
          } else if(method==='DELETE'){
            db.value=null;
            db.stored=false;
          }
        }
        return json({ok:true,write_enabled:db.writable,master_key_configured:db.writable,items:[{
          key,provider:'sentry',field:'dsn',stored:db.stored,
          updated_at:db.stored?'2026-11-22 00:00:00':null
        }]});
      }
      throw Error('Unexpected '+method+' '+path);
    };
  },{key,writable});
  await page.addScriptTag({content:source});
  await expect(page.locator('[data-secret-row="'+key+'"]')).toBeVisible();
}

test('AC-02: System status saves and removes a synthetic Sentry DSN without exposing its value',async({page})=>{
  await setup(page);
  const row=page.locator('[data-secret-row="'+key+'"]');
  const field=row.locator('input[type=password]');
  await field.fill(synthetic);
  await row.getByRole('button',{name:'Guardar clave'}).click();
  await expect(row.getByText('Guardada', {exact:false})).toBeVisible();
  await expect(page.locator('[data-integration="sentry"] [data-integration-state]')).toHaveText('configurado');
  await expect(field).toHaveValue('');
  expect(await page.locator('body').textContent()).not.toContain(synthetic);
  const stored=await page.evaluate(()=>window.integrationFixture);
  expect(stored.requests[0]).toMatchObject({method:'POST',key,csrf:'synthetic-csrf-token',containsValue:true});
  await row.getByRole('button',{name:'Eliminar clave'}).click();
  await expect(row.getByText('Sin clave almacenada')).toBeVisible();
  await expect(page.locator('[data-integration="sentry"] [data-integration-state]')).toHaveText('falta');
  const deleted=await page.evaluate(()=>window.integrationFixture);
  expect(deleted.requests[1]).toMatchObject({method:'DELETE',key,csrf:'synthetic-csrf-token',containsValue:false});
  expect(deleted.value).toBe(null);
});

test('AC-02: missing master/write switch leaves all secret controls disabled',async({page})=>{
  await setup(page,{writable:false});
  const row=page.locator('[data-secret-row="'+key+'"]');
  await expect(row.locator('input[type=password]')).toBeDisabled();
  await expect(row.getByRole('button',{name:'Guardar clave'})).toBeDisabled();
  await expect(row.getByRole('button',{name:'Eliminar clave'})).toBeDisabled();
  await expect(page.locator('[data-secret-write-status]')).toContainText('Escritura bloqueada');
  expect((await page.evaluate(()=>window.integrationFixture.requests)).length).toBe(0);
});
