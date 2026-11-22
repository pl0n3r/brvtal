import {test, expect} from '@playwright/test';
import {readFileSync} from 'node:fs';

const source=readFileSync(new URL('../../discadmin/settings-v2.js', import.meta.url),'utf8');
const url='http://127.0.0.1:4173/discadmin/public-values-fixture.html?settings=integrations';

async function setup(page){
  await page.route('**/discadmin/public-values-fixture.html?*',route=>route.fulfill({
    contentType:'text/html',
    body:'<!doctype html><html lang="es"><meta charset="utf-8"><body>'+
      '<div class="main"><div class="top"><h1>SETTINGS</h1></div><div id="fixture"></div></div></body></html>'
  }));
  await page.goto(url);
  await page.evaluate(()=>{
    window.state={rows:[]};
    window.csrf='synthetic-csrf-token';
    window.req=async()=>({data:[]});
    const publicKeys=new Set([
      'BRVTAL_TICKET_MAIL_FROM','BRVTAL_TICKET_MAIL_DOMAIN',
      'BRVTAL_SMTP_HOST','BRVTAL_SMTP_USER','BRVTAL_MAIL_FROM',
      'BRVTAL_GA4_PROPERTY_ID','BRVTAL_GA4_CLIENT_EMAIL'
    ]);
    const fields=[
      ['BRVTAL_TICKET_MAIL_API_KEY','resend','api_key'],
      ['BRVTAL_TICKET_MAIL_DOMAIN','resend','domain'],
      ['BRVTAL_TICKET_MAIL_FROM','resend','from'],
      ['BRVTAL_TICKET_MAIL_WEBHOOK_SECRET','resend','webhook_secret'],
      ['BRVTAL_TICKET_MAIL_TEST_RECIPIENTS','resend','test_recipients'],
      ['BRVTAL_TICKET_WOMPI_PUBLIC_KEY','wompi','public_key'],
      ['BRVTAL_TICKET_WOMPI_PRIVATE_KEY','wompi','private_key'],
      ['BRVTAL_SMTP_HOST','smtp','host'],
      ['BRVTAL_SMTP_USER','smtp','user'],
      ['BRVTAL_SMTP_PASSWORD','smtp','password'],
      ['BRVTAL_MAIL_FROM','smtp','from'],
      ['BRVTAL_GA4_PROPERTY_ID','ga4','property_id'],
      ['BRVTAL_GA4_CLIENT_EMAIL','ga4','client_email'],
      ['BRVTAL_GA4_PRIVATE_KEY','ga4','private_key'],
      ['BRVTAL_SENTRY_DSN','sentry','dsn']
    ];
    window.publicFixture={
      requests:[],
      values:{
        BRVTAL_TICKET_MAIL_API_KEY:'re_SYNTHETIC_DO_NOT_SHOW_12345',
        BRVTAL_TICKET_MAIL_FROM:'primero@synthetic.invalid',
        BRVTAL_TICKET_MAIL_DOMAIN:'correo.synthetic.invalid',
        BRVTAL_SMTP_USER:'" autofocus onfocus="alert(1)',
        BRVTAL_SMTP_PASSWORD:'PRIVATE_SMTP_TOKEN_NEVER_SHOW',
        BRVTAL_GA4_PRIVATE_KEY:'-----BEGIN PRIVATE KEY-----PRIVATE',
        BRVTAL_SENTRY_DSN:'https://PRIVATE@sentry.invalid/1',
        BRVTAL_TICKET_WOMPI_PUBLIC_KEY:'pub_test_PRIVATE'
      }
    };
    const json=(body,status=200)=>new Response(JSON.stringify(body),{
      status,headers:{'Content-Type':'application/json'}
    });
    window.fetch=async(path,options={})=>{
      const pathname=new URL(path,location.href).pathname;
      const method=options.method||'GET';
      if(pathname==='/api/index.php/auth')
        return json({ok:true,authenticated:true,csrf:'synthetic-csrf-token'});
      if(pathname==='/api/admin-integration-secrets.php'){
        const fixture=window.publicFixture;
        if(method!=='GET'){
          const req=JSON.parse(options.body||'{}');
          fixture.requests.push({method,key:req.key,value:req.value});
          if(options.headers?.['X-CSRF-Token']!=='synthetic-csrf-token')
            return json({ok:false,error:'CSRF'},403);
          if(method==='POST')fixture.values[req.key]=req.value;
          else delete fixture.values[req.key];
        }
        const items=fields.map(([key,provider,field])=>{
          const stored=Object.hasOwn(fixture.values,key);
          const row={key,provider,field,stored,updated_at:stored?'2026-11-22 10:00:00':null};
          if(stored && publicKeys.has(key))row.value=fixture.values[key];
          return row;
        });
        return json({ok:true,items,write_enabled:true,master_key_configured:true});
      }
      if(pathname==='/api/admin-integration-test-mail.php')
        return json({ok:false,error:'MAIL_TEST_UNAVAILABLE'},503);
      if(pathname==='/api/content-health.php')return json({ok:true,data:{score:100}});
      if(pathname==='/api/admin-activity.php')return json({ok:true,data:{total:0,items:[]}});
      if(pathname==='/discadmin/technical.php')return json({ok:true,checks:[],database:{counts:{}},repository:{github:{}}});
      if(pathname==='/discadmin/free-quotas.php')return json({ok:true,data:{rows:[]}});
      if(pathname==='/api/admin-dashboard-preferences.php')return json({ok:true,data:{modules:[]}});
      if(pathname==='/api/admin-system-status.php')return json({ok:true,integrations:[],workers:[]});
      throw Error('Unexpected synthetic request '+pathname);
    };
    window.go=section=>{
      if(section==='settings')document.getElementById('fixture').innerHTML=window.settingsHome();
    };
  });
  await page.addScriptTag({content:source});
  await page.evaluate(()=>{document.getElementById('fixture').innerHTML=window.settingsHome();});
  await expect(page.locator('[data-integration-provider]')).toHaveCount(5);
}
const input=(page,key)=>page.locator('[data-secret-input][data-secret-key="'+key+'"]');

test('AC-02: remitente y dominio guardados se ven; credenciales nunca se rellenan',async({page})=>{
  await setup(page);
  await expect(input(page,'BRVTAL_TICKET_MAIL_FROM')).toHaveValue('primero@synthetic.invalid');
  await expect(input(page,'BRVTAL_TICKET_MAIL_DOMAIN')).toHaveValue('correo.synthetic.invalid');
  await expect(input(page,'BRVTAL_TICKET_MAIL_API_KEY')).toHaveValue('');
  await expect(input(page,'BRVTAL_SMTP_PASSWORD')).toHaveValue('');
  await expect(input(page,'BRVTAL_GA4_PRIVATE_KEY')).toHaveValue('');
  await expect(input(page,'BRVTAL_SENTRY_DSN')).toHaveValue('');
  await expect(input(page,'BRVTAL_TICKET_WOMPI_PUBLIC_KEY')).toHaveValue('');
  await expect(page.locator('[data-secret-row="BRVTAL_TICKET_MAIL_API_KEY"] [data-secret-stored]'))
    .toContainText('Guardada');
  await expect(input(page,'BRVTAL_SMTP_USER')).toHaveValue('" autofocus onfocus="alert(1)');
  expect(await page.locator('[data-secret-input][onfocus]').count()).toBe(0);
  const html=await page.locator('[data-settings-integrations]').innerHTML();
  expect(html).not.toContain('PRIVATE_SMTP_TOKEN_NEVER_SHOW');
  expect(html).not.toContain('re_SYNTHETIC_DO_NOT_SHOW_12345');
  expect(html).not.toContain('-----BEGIN PRIVATE KEY-----PRIVATE');
  expect(html).not.toContain('https://PRIVATE@sentry.invalid');
});

test('AC-02: guardar remitente restaura su valor y no reenvía campos públicos intactos',async({page})=>{
  await setup(page);
  const resend=page.locator('[data-integration-provider="resend"]');
  await input(page,'BRVTAL_TICKET_MAIL_FROM').fill('corregido@synthetic.invalid');
  await input(page,'BRVTAL_TICKET_MAIL_API_KEY').fill('re_NEW_SYNTHETIC_12345');
  await resend.getByRole('button',{name:'Guardar',exact:true}).click();
  await expect(resend.locator('[data-secret-feedback]')).toContainText('2 claves guardadas');
  await expect(input(page,'BRVTAL_TICKET_MAIL_FROM')).toHaveValue('corregido@synthetic.invalid');
  await expect(input(page,'BRVTAL_TICKET_MAIL_DOMAIN')).toHaveValue('correo.synthetic.invalid');
  await expect(input(page,'BRVTAL_TICKET_MAIL_API_KEY')).toHaveValue('');
  const writes=await page.evaluate(()=>window.publicFixture.requests);
  expect(writes).toHaveLength(2);
  expect(writes.map(x=>x.key)).toEqual(['BRVTAL_TICKET_MAIL_API_KEY','BRVTAL_TICKET_MAIL_FROM']);
  await resend.getByRole('button',{name:'Guardar',exact:true}).click();
  await expect(resend.locator('[data-secret-feedback]')).toContainText('Escribe al menos un valor');
  expect(await page.evaluate(()=>window.publicFixture.requests.length)).toBe(2);
  await page.evaluate(()=>window.BRVTALSettingsV2.reloadIntegrations());
  await expect(input(page,'BRVTAL_TICKET_MAIL_FROM')).toHaveValue('corregido@synthetic.invalid');
  await expect(input(page,'BRVTAL_TICKET_MAIL_API_KEY')).toHaveValue('');
});
