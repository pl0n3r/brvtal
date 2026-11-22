import {test,expect} from '@playwright/test';
import {readFileSync} from 'node:fs';
import {generateKeyPairSync,createPrivateKey} from 'node:crypto';
import {execFileSync} from 'node:child_process';

const source=readFileSync(new URL('../../discadmin/system-status-v2.js',import.meta.url),'utf8');
const harness='http://127.0.0.1:4173/discadmin/integration-secrets-harness.html';
const key='BRVTAL_SENTRY_DSN';
const synthetic='https://synthetic-only@sentry.invalid/101';

async function setup(page,{writable=true,ga4Key=''}={}) {
  await page.route(harness,route=>route.fulfill({
    contentType:'text/html',
    body:'<!doctype html><html><body><div class="main"><div class="top"><h1>SYSTEM STATUS</h1></div></div></body></html>',
  }));
  await page.goto(harness);
  await page.evaluate(({key,writable,ga4Key})=>{
    window.csrf='synthetic-csrf-token';
    const db={stored:false,requests:[],value:null,writable,ga4Stored:false,ga4Value:null};
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
        ok:true,integrations:[
          {id:'sentry',label:'Sentry',status:db.stored?'configurado':'falta'},
          {id:'smtp',label:'SMTP',status:'falta'},
          {id:'ga4',label:'Google Analytics 4',status:'falta'}
        ],workers:[]
      });
      if(path==='/api/admin-integration-secrets.php'){
        if(method!=='GET'){
          const body=JSON.parse(options.body||'{}');
          db.requests.push({method,key:body.key,csrf:options.headers?.['X-CSRF-Token'],
            containsValue:Object.hasOwn(body,'value')});
          if(!db.writable) return json({ok:false,error:'SECRET_WRITE_DISABLED'},403);
          if(options.headers?.['X-CSRF-Token']!=='synthetic-csrf-token')
            return json({ok:false,error:'CSRF'},419);
          if(![key,'BRVTAL_GA4_PRIVATE_KEY'].includes(body.key))
            return json({ok:false,error:'SECRET_KEY_NOT_ALLOWED'},422);
          if(body.key==='BRVTAL_GA4_PRIVATE_KEY'){
            if(method==='POST' && body.value!==ga4Key)
              return json({ok:false,error:'SECRET_FORMAT_INVALID'},422);
            db.ga4Value=method==='POST'?body.value:null;
            db.ga4Stored=method==='POST';
          } else if(method==='POST'){
            if(body.value!== 'https://synthetic-only@sentry.invalid/101')
              return json({ok:false,error:'SECRET_FORMAT_INVALID'},422);
            db.value=body.value;
            db.stored=true;
          } else if(method==='DELETE'){
            db.value=null;
            db.stored=false;
          }
        }
        return json({ok:true,write_enabled:db.writable,master_key_configured:db.writable,items:[
          {key,provider:'sentry',field:'dsn',stored:db.stored,
           updated_at:db.stored?'2026-11-22 00:00:00':null},
          {key:'BRVTAL_SMTP_HOST',provider:'smtp',field:'host',stored:false,updated_at:null},
          {key:'BRVTAL_GA4_PRIVATE_KEY',provider:'ga4',field:'private_key',
           stored:db.ga4Stored,updated_at:db.ga4Stored?'2026-11-22 00:00:00':null}
        ]});
      }
      throw Error('Unexpected '+method+' '+path);
    };
  },{key,writable,ga4Key});
  await page.addScriptTag({content:source});
  await expect(page.locator('[data-secret-row="'+key+'"]')).toBeVisible();
}

test('AC-02: System status saves and removes a synthetic Sentry DSN without exposing its value',async({page})=>{
  await setup(page);
  const row=page.locator('[data-secret-row="'+key+'"]');
  const field=row.locator('input[type=password]');
  const smtpDraft=page.locator('[data-secret-row="BRVTAL_SMTP_HOST"] input');
  await smtpDraft.fill('smtp.synthetic.invalid');
  await field.fill(synthetic);
  await row.getByRole('button',{name:'Guardar clave'}).click();
  await expect(row.locator('[data-secret-feedback]')).toHaveText('Clave guardada');
  await expect(row.locator('small')).toContainText('Guardada');
  await expect(smtpDraft).toHaveValue('smtp.synthetic.invalid');
  await page.locator('#ssv2-refresh').click();
  await expect(smtpDraft).toHaveValue('smtp.synthetic.invalid');
  await expect(page.locator('[data-integration="sentry"] [data-integration-state]')).toHaveText('configurado');
  await expect(field).toHaveValue('');
  expect(await page.locator('body').textContent()).not.toContain(synthetic);
  const stored=await page.evaluate(()=>window.integrationFixture);
  expect(stored.requests[0]).toMatchObject({method:'POST',key,csrf:'synthetic-csrf-token',containsValue:true});
  await row.getByRole('button',{name:'Eliminar clave'}).click();
  await expect(row.getByText('Sin clave almacenada')).toBeVisible();
  await expect(smtpDraft).toHaveValue('smtp.synthetic.invalid');
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


test('AC-02: multiline synthetic GA4 RSA PEM survives the write-only editor and round-trip',async({page})=>{
  const {privateKey}=generateKeyPairSync('rsa',{
    modulusLength:2048,
    privateKeyEncoding:{type:'pkcs8',format:'pem'},
    publicKeyEncoding:{type:'spki',format:'pem'}
  });
  await setup(page,{ga4Key:privateKey});
  const row=page.locator('[data-secret-row="BRVTAL_GA4_PRIVATE_KEY"]');
  const editor=row.locator('textarea[data-secret-input]');
  await expect(editor).toBeVisible();
  await editor.fill(privateKey);
  await expect(editor).toHaveValue(privateKey);
  await row.getByRole('button',{name:'Guardar clave'}).click();
  await expect(row.locator('[data-secret-feedback]')).toHaveText('Clave guardada');
  await expect(row.locator('small')).toContainText('Guardada');
  await expect(editor).toHaveValue('');
  const stored=await page.evaluate(()=>window.integrationFixture.ga4Value);
  expect(stored).toBe(privateKey);
  expect(createPrivateKey(stored).asymmetricKeyType).toBe('rsa');
  expect(await page.locator('body').textContent()).not.toContain(privateKey);
  await page.locator('#ssv2-refresh').click();
  await expect(editor).toHaveValue('');
});

test('AC-02: real PHP endpoint rejects guest and invalid admin CSRF without changing synthetic data',async({page})=>{
  const base=process.env.BRVTAL_REAL_STACK_URL;
  test.skip(!base || process.env.BRVTAL_REAL_STACK_FORCE!=='1',
    'Only exercise against a disposable authenticated PHP/MariaDB fixture');
  const dbName=process.env.BRVTAL_TEST_DB_NAME || '';
  expect(dbName).toMatch(/^brvtal_test[a-zA-Z0-9_]*$/);
  const api=base+'/api/admin-integration-secrets.php';
  const sentinel={key:'BRVTAL_SENTRY_DSN',value:'https://synthetic-only@sentry.invalid/101'};
  const count=()=>{
    const args=['-N','-B','-h',process.env.BRVTAL_TEST_DB_HOST||'127.0.0.1',
      '-P',String(process.env.BRVTAL_TEST_DB_PORT||'3306'),
      '-u',process.env.BRVTAL_TEST_DB_USER||'root',
      '-D',dbName,'-e',
      "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='integration_secrets'"];
    return execFileSync('mysql',args,{encoding:'utf8',
      env:{...process.env,MYSQL_PWD:process.env.BRVTAL_TEST_DB_PASS||''}}).trim();
  };
  const before=count();
  expect(before).toMatch(/^[01]$/);
  const guest=await page.request.post(api,{data:sentinel});
  expect(guest.status()).toBe(401);
  await page.goto(base+'/discadmin/');
  await page.locator('#admin-login-email').fill(process.env.BRVTAL_REAL_STACK_ADMIN_EMAIL||'ci-admin@brvtal.test');
  await page.locator('#admin-login-password').fill(process.env.BRVTAL_REAL_STACK_ADMIN_PASSWORD||'brvtal-ci-password');
  await page.getByRole('button',{name:'ENTER'}).click();
  await page.waitForFunction(()=>Boolean(window.state?.authed));
  const missing=await page.request.post(api,{data:sentinel});
  const invalid=await page.request.post(api,{data:sentinel,headers:{'X-CSRF-Token':'synthetic-invalid'}});
  const forbiddenDelete=await page.request.delete(api,{data:{key:sentinel.key}});
  for(const result of [missing,invalid,forbiddenDelete]) {
    expect(result.status()).toBe(403);
    expect((await result.json()).error).toBe('CSRF');
  }
  expect(count()).toBe(before);
});
