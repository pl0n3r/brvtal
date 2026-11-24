import { test, expect } from '@playwright/test';
import { readFileSync, mkdirSync, writeFileSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { generateKeyPairSync, createPrivateKey } from 'node:crypto';

const settingsSource=readFileSync(new URL('../../discadmin/settings-v2.js',import.meta.url),'utf8');
const settingsCss=readFileSync(new URL('../../discadmin/settings-v2.css',import.meta.url),'utf8');
const systemSource=readFileSync(new URL('../../discadmin/system-status-v2.js',import.meta.url),'utf8');
const url='http://127.0.0.1:4173/discadmin/integrations-fixture.html?settings=integrations';

async function setup(page,{writable=true}={}) {
  await page.route('**/discadmin/integrations-fixture.html?*',route=>route.fulfill({
    contentType:'text/html',
    body:'<!doctype html><html lang="es"><head><meta name="viewport" content="width=device-width,initial-scale=1"></head>'+
      '<body style="margin:0;background:#050606;color:#f6f6f4;font-family:Arial,sans-serif">'+
      '<div class="main" style="padding:18px;max-width:1500px;margin:auto"><div class="top"><h1 style="font-size:16px">SETTINGS</h1></div>'+
      '<div id="fixture"></div></div></body></html>'
  }));
  await page.goto(url);
  await page.evaluate(({writable})=>{
    window.state={rows:[]};
    window.csrf='synthetic-csrf-token';
    window.req=async()=>({data:[]});
    window.integrationFixture={values:{},writes:[],writable,responses:[],holdWrites:false,pendingWrites:[],testMailRequests:[]};
    const fields=[
      ['BRVTAL_TICKET_MAIL_API_KEY','resend','api_key'],
      ['BRVTAL_TICKET_MAIL_DOMAIN','resend','domain'],
      ['BRVTAL_TICKET_MAIL_FROM','resend','from'],
      ['BRVTAL_TICKET_MAIL_FROM_NAME','resend','from_name'],
      ['BRVTAL_TICKET_MAIL_REPLY_TO','resend','reply_to'],
      ['BRVTAL_TICKET_MAIL_WEBHOOK_SECRET','resend','webhook_secret'],
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
    const json=(data,status=200)=>{
      const body=JSON.stringify(data);
      window.integrationFixture.responses.push(body);
      return new Response(body,{status,headers:{'Content-Type':'application/json'}});
    };
    window.fetch=async(path,options={})=>{
      const pathname=new URL(path,location.href).pathname;
      const method=options.method||'GET';
      if(pathname==='/api/index.php/auth')
        return json({ok:true,authenticated:true,csrf:'synthetic-csrf-token'});
      if(pathname==='/api/admin-integration-test-mail.php'){
        window.integrationFixture.testMailRequests.push({method});
        return json({ok:false,error:'MAIL_TEST_UNAVAILABLE'},503);
      }
      if(pathname==='/api/admin-integration-secrets.php'){
        const state=window.integrationFixture;
        if(method!=='GET'){
          const body=JSON.parse(options.body||'{}');
          state.writes.push({method,key:body.key,csrf:options.headers?.['X-CSRF-Token'],
            hasValue:Object.hasOwn(body,'value'),value:body.value});
          if(state.holdWrites)await new Promise(resolve=>state.pendingWrites.push(resolve));
          if(!state.writable)return json({ok:false,error:'SECRET_WRITE_DISABLED'},403);
          if(options.headers?.['X-CSRF-Token']!=='synthetic-csrf-token')
            return json({ok:false,error:'CSRF'},403);
          if(!fields.some(item=>item[0]===body.key))
            return json({ok:false,error:'SECRET_KEY_NOT_ALLOWED'},422);
          if(method==='POST'){
            if(!String(body.value||'').trim())return json({ok:false,error:'SECRET_FORMAT_INVALID'},422);
            state.values[body.key]=body.value;
          } else if(method==='DELETE')delete state.values[body.key];
        }
        return json({ok:true,write_enabled:state.writable,master_key_configured:state.writable,
          items:fields.map(([key,provider,field])=>({
            key,provider,field,stored:Object.hasOwn(state.values,key),
            updated_at:Object.hasOwn(state.values,key)?'2026-11-22 02:00:00':null
          }))});
      }
      if(pathname==='/discadmin/technical.php')
        return json({ok:true,health:{status:'unknown'},database:{counts:{}},repository:{github:{backlog_state:'unavailable'}},checks:[],issues:[]});
      if(pathname==='/api/content-health.php')return json({ok:true,data:{score:100}});
      if(pathname==='/api/admin-activity.php')return json({ok:true,data:{total:0,items:[]}});
      if(pathname==='/discadmin/free-quotas.php')return json({ok:true,data:{rows:[]}});
      if(pathname==='/api/admin-dashboard-preferences.php')return json({ok:true,data:{modules:[]}});
      if(pathname==='/api/admin-system-status.php')return json({ok:true,integrations:[
        {id:'resend',label:'Resend',status:Object.keys(window.integrationFixture.values).some(k=>k.startsWith('BRVTAL_TICKET_MAIL_'))?'configurado':'falta'},
        {id:'wompi',label:'Wompi sandbox',status:'modo sandbox'},
        {id:'smtp',label:'SMTP',status:'falta'},
        {id:'ga4',label:'Google Analytics 4',status:'falta'},
        {id:'sentry',label:'Sentry',status:'falta'}],workers:[]});
      throw Error('Unexpected fixture request '+method+' '+pathname);
    };
    window.go=section=>{
      if(section==='settings'){
        document.querySelector('.main .top h1').textContent='SETTINGS';
        document.getElementById('fixture').innerHTML=window.settingsHome();
      }
    };
  },{writable});
  await page.addStyleTag({content:settingsCss});
  await page.addScriptTag({content:settingsSource});
  await page.evaluate(()=>{document.getElementById('fixture').innerHTML=window.settingsHome();});
  await expect(page.locator('[data-integration-provider]')).toHaveCount(5);
  return page.locator('[data-settings-integrations]');
}

test('AC-01: Resend se guarda y elimina en Settings sin exponer el valor',async({page})=>{
  await setup(page);
  const resend=page.locator('[data-integration-provider="resend"]');
  const key=resend.locator('[data-secret-key="BRVTAL_TICKET_MAIL_API_KEY"]');
  const synthetic='re_synthetic_only_12345';
  await key.fill(synthetic);
  await resend.getByRole('button',{name:'Guardar',exact:true}).click();
  await expect(resend.locator('[data-secret-feedback]')).toContainText('Clave guardada');
  await expect(resend.locator('[data-provider-state]')).toHaveText('Configurado');
  await expect(key).toHaveValue('');
  const state=await page.evaluate(()=>window.integrationFixture);
  expect(state.writes).toHaveLength(1);
  expect(state.writes[0]).toMatchObject({method:'POST',key:'BRVTAL_TICKET_MAIL_API_KEY',csrf:'synthetic-csrf-token',hasValue:true});
  expect(state.responses.every(payload=>!payload.includes(synthetic))).toBe(true);
  expect(await page.locator('body').textContent()).not.toContain(synthetic);
  page.once('dialog',dialog=>dialog.accept());
  await resend.getByRole('button',{name:'Eliminar Clave de API'}).click();
  await expect(resend.locator('[data-provider-state]')).toHaveText('Falta');
  await expect(resend.locator('[data-secret-feedback]')).toContainText('eliminado');
  const after=await page.evaluate(()=>window.integrationFixture);
  expect(after.writes[1]).toMatchObject({method:'DELETE',key:'BRVTAL_TICKET_MAIL_API_KEY',hasValue:false});
  expect(Object.hasOwn(after.values,'BRVTAL_TICKET_MAIL_API_KEY')).toBe(false);
});

test('AC-02: cinco tarjetas, un Guardar por proveedor y etiquetas comprensibles',async({page})=>{
  await setup(page);
  await expect(page.locator('[data-integration-provider]')).toHaveCount(5);
  for(const id of ['resend','wompi','smtp','ga4','sentry']){
    const card=page.locator('[data-integration-provider="'+id+'"]');
    await expect(card.getByRole('button',{name:'Guardar',exact:true})).toHaveCount(1);
    await expect(card.locator('[data-secret-feedback][aria-live="polite"]')).toHaveCount(1);
  }
  await expect(page.getByText('Clave de API',{exact:true})).toBeVisible();
  await expect(page.getByText('Secreto del webhook',{exact:true})).toBeVisible();
  await expect(page.getByText('Dominio de envío',{exact:true})).toBeVisible();
  expect((await page.locator('[data-settings-integrations]').innerText()).toLowerCase())
    .not.toMatch(/\b(api key|webhook secret|from)\b/);
  await expect(page.locator('[data-secret-input][type="password"]')).not.toHaveCount(0);
  // #1927: Wompi now holds test and production key sets plus methods and return page.
  await expect(page.locator('[data-secret-input][autocomplete="off"]')).toHaveCount(24);
  await expect(page.locator('[data-secret-delete]:visible')).toHaveCount(0);
  await expect(page.locator('[data-integration-provider="wompi"] [data-provider-state]')).toHaveText('Falta');
});

test('AC-02: responsive 390/1440, foco accesible y capturas comparativas',async({page},testInfo)=>{
  // El workflow existente publica test-results/public-concept05-fidelity-*/*.
  // Guardamos allí capturas sin secretos y también como adjuntos Playwright.
  const evidence=resolve(process.cwd(),'test-results/public-concept05-fidelity-discadmin-integrations');
  mkdirSync(evidence,{recursive:true});
  for(const width of [390,1440]){
    await page.setViewportSize({width,height:900});
    await setup(page);
    const noOverflow=await page.evaluate(()=>({
      viewport:document.documentElement.clientWidth,
      document:document.documentElement.scrollWidth,
      body:document.body.scrollWidth
    }));
    expect(noOverflow.document).toBeLessThanOrEqual(noOverflow.viewport);
    expect(noOverflow.body).toBeLessThanOrEqual(noOverflow.viewport);
    const input=page.locator('[data-secret-key="BRVTAL_TICKET_MAIL_API_KEY"]');
    await input.focus();
    await expect(input).toBeFocused();
    const outline=await input.evaluate(el=>getComputedStyle(el).outlineStyle);
    expect(outline).not.toBe('none');
    const after=await page.screenshot({fullPage:true});
    await testInfo.attach('despues-settings-'+width+'px.png',{body:after,contentType:'image/png'});
    writeFileSync(join(evidence,'despues-settings-'+width+'px.png'),after);
    // Contraste de la estructura de #1840: un botón por cada CAMPO en System status.
    // Esta captura es una reconstrucción sintética, no una toma de producción.
    await page.evaluate(()=>{
      document.getElementById('fixture').innerHTML='<section style="border:1px solid #333;padding:20px;max-width:700px">'+
        '<h2>SYSTEM STATUS</h2><p>INTEGRACIONES / ESTADO SIN CLAVES</p>'+
        '<div style="display:grid;gap:6px"><strong>Resend</strong>'+
        '<label>api key <input aria-label="api key" type="password"></label>'+
        '<button>Guardar clave</button><button>Eliminar clave</button>'+
        '<label>webhook secret <input aria-label="webhook secret" type="password"></label>'+
        '<button>Guardar clave</button><button>Eliminar clave</button></div></section>';
    });
    const before=await page.screenshot({fullPage:true});
    await testInfo.attach('antes-estructura-heredada-'+width+'px.png',{body:before,contentType:'image/png'});
    writeFileSync(join(evidence,'antes-estructura-heredada-'+width+'px.png'),before);
  }
});

test('AC-01/02: borrado confirmado, valores vacíos intactos y borradores de otros campos',async({page})=>{
  await setup(page);
  const resend=page.locator('[data-integration-provider="resend"]');
  const smtp=page.locator('[data-integration-provider="smtp"]');
  await smtp.locator('[data-secret-key="BRVTAL_SMTP_HOST"]').fill('smtp.synthetic.invalid');
  await resend.getByRole('button',{name:'Guardar',exact:true}).click();
  await expect(resend.locator('[data-secret-feedback]')).toContainText('Escribe al menos un valor');
  expect((await page.evaluate(()=>window.integrationFixture.writes))).toHaveLength(0);
  await resend.locator('[data-secret-key="BRVTAL_TICKET_MAIL_API_KEY"]').fill('re_synthetic_only_12345');
  await resend.getByRole('button',{name:'Guardar',exact:true}).click();
  await expect(smtp.locator('[data-secret-key="BRVTAL_SMTP_HOST"]')).toHaveValue('smtp.synthetic.invalid');
  page.once('dialog',dialog=>dialog.dismiss());
  await resend.getByRole('button',{name:'Eliminar Clave de API'}).click();
  await expect(resend.locator('[data-provider-state]')).toHaveText('Configurado');
  expect((await page.evaluate(()=>window.integrationFixture.writes))).toHaveLength(1);
});

test('AC-02: la escritura bloqueada deshabilita controles sin realizar solicitudes',async({page})=>{
  await setup(page,{writable:false});
  const resend=page.locator('[data-integration-provider="resend"]');
  await expect(resend.locator('[data-secret-input]').first()).toBeDisabled();
  await expect(resend.getByRole('button',{name:'Guardar',exact:true})).toBeDisabled();
  await expect(page.locator('.sv2-secret-locked')).toContainText('bloqueada');
  expect((await page.evaluate(()=>window.integrationFixture.writes))).toHaveLength(0);
});

test('AC-01: GA4 conserva una clave RSA PEM de varias líneas y no la refleja',async({page})=>{
  await setup(page);
  const {privateKey}=generateKeyPairSync('rsa',{modulusLength:2048,
    privateKeyEncoding:{type:'pkcs8',format:'pem'},
    publicKeyEncoding:{type:'spki',format:'pem'}});
  const card=page.locator('[data-integration-provider="ga4"]');
  const field=card.locator('[data-secret-key="BRVTAL_GA4_PRIVATE_KEY"]');
  await field.fill(privateKey);
  await card.getByRole('button',{name:'Guardar',exact:true}).click();
  await expect(card.locator('[data-secret-feedback]')).toContainText('Clave guardada');
  const saved=await page.evaluate(()=>window.integrationFixture.values.BRVTAL_GA4_PRIVATE_KEY);
  expect(saved).toBe(privateKey);
  expect(createPrivateKey(saved).asymmetricKeyType).toBe('rsa');
  expect(await page.locator('body').textContent()).not.toContain(privateKey);
  await expect(field).toHaveValue('');
});

test('AC-01: System status es solo lectura y enlaza a Settings',async({page})=>{
  await setup(page);
  // Cambiar el shell sintético al módulo de diagnóstico y montar su script real.
  await page.evaluate(()=>{
    document.querySelector('.main .top h1').textContent='SYSTEM STATUS';
    document.getElementById('fixture').innerHTML='<div id="system-status-v2"></div>';
  });
  await page.addScriptTag({content:systemSource});
  await expect(page.locator('[data-testid="system-integrations"]')).toBeVisible();
  await expect(page.locator('[data-secret-input]')).toHaveCount(0);
  await expect(page.locator('[data-secret-action]')).toHaveCount(0);
  const link=page.getByRole('link',{name:'Configurar en Settings'});
  await expect(link).toHaveAttribute('href','/discadmin/?settings=integrations');
});

test('AC-01/03: un POST multiclave pendiente conserva el bloqueo tras remontar Settings',async({page},testInfo)=>{
  const dir=resolve(process.cwd(),'test-results/public-concept05-fidelity-discadmin-integrations');
  mkdirSync(dir,{recursive:true});
  for(const width of [390,1440]){
    await page.setViewportSize({width,height:900});
    await setup(page);
    const resend=page.locator('[data-integration-provider="resend"]');
    const before=await page.screenshot({fullPage:true});
    writeFileSync(join(dir,'antes-serializacion-'+width+'.png'),before);
    await testInfo.attach('antes-serializacion-'+width+'.png',{body:before,contentType:'image/png'});
    await page.evaluate(()=>{window.integrationFixture.holdWrites=true;});
    await resend.locator('[data-secret-key="BRVTAL_TICKET_MAIL_API_KEY"]').fill('re_old_synthetic_'+width);
    await resend.locator('[data-secret-key="BRVTAL_TICKET_MAIL_DOMAIN"]').fill('old'+width+'.synthetic.invalid');
    await resend.getByRole('button',{name:'Guardar',exact:true}).click();
    await expect.poll(()=>page.evaluate(()=>window.integrationFixture.writes.length)).toBe(1);
    await page.evaluate(()=>{document.getElementById('fixture').innerHTML=window.settingsHome();});
    await expect(resend).toHaveAttribute('aria-busy','true');
    await expect(resend.getByRole('button',{name:'Guardar',exact:true})).toBeDisabled();
    await expect(resend.locator('[data-secret-feedback]')).toContainText('Guardando');
    const after=await page.screenshot({fullPage:true});
    writeFileSync(join(dir,'despues-serializacion-'+width+'.png'),after);
    await testInfo.attach('despues-serializacion-'+width+'.png',{body:after,contentType:'image/png'});
    await page.evaluate(()=>{
      document.querySelector('[data-secret-provider-save="resend"]').dispatchEvent(new MouseEvent('click',{bubbles:true}));
    });
    expect(await page.evaluate(()=>window.integrationFixture.writes.length)).toBe(1);
    await page.evaluate(()=>{
      const f=window.integrationFixture;f.holdWrites=false;
      f.pendingWrites.splice(0).forEach(resolve=>resolve());
    });
    await expect(resend.locator('[data-secret-feedback]')).toContainText('2 claves guardadas');
    await expect(resend).toHaveAttribute('aria-busy','false');
    const old=await page.evaluate(()=>window.integrationFixture.writes);
    expect(old.map(w=>w.key)).toEqual(['BRVTAL_TICKET_MAIL_API_KEY','BRVTAL_TICKET_MAIL_DOMAIN']);
    expect(old.every(w=>w.csrf==='synthetic-csrf-token')).toBe(true);
    await resend.locator('[data-secret-key="BRVTAL_TICKET_MAIL_API_KEY"]').fill('re_new_synthetic_'+width);
    await resend.getByRole('button',{name:'Guardar',exact:true}).click();
    await expect(resend.locator('[data-secret-feedback]')).toContainText('Clave guardada');
    const final=await page.evaluate(()=>window.integrationFixture);
    expect(final.writes).toHaveLength(3);
    expect(final.values.BRVTAL_TICKET_MAIL_API_KEY).toBe('re_new_synthetic_'+width);
    expect(final.values.BRVTAL_TICKET_MAIL_DOMAIN).toBe('old'+width+'.synthetic.invalid');
    expect(final.responses.every(response=>!response.includes('re_new_synthetic_'+width))).toBe(true);
    expect(await page.locator('body').innerText()).not.toContain('re_new_synthetic_'+width);
  }
});

test('AC-01: una eliminación pendiente bloquea guardar y correo de prueba tras remount',async({page})=>{
  await setup(page);
  await page.evaluate(()=>{
    const f=window.integrationFixture;
    f.values.BRVTAL_TICKET_MAIL_API_KEY='re_existing_synthetic';
    f.holdWrites=true;
  });
  await page.evaluate(()=>window.BRVTALSettingsV2.reloadIntegrations());
  const resend=page.locator('[data-integration-provider="resend"]');
  await expect(resend.locator('[data-secret-delete]:visible')).toHaveCount(1);
  page.once('dialog',dialog=>dialog.accept());
  await resend.getByRole('button',{name:'Eliminar Clave de API'}).click();
  await expect.poll(()=>page.evaluate(()=>window.integrationFixture.writes.length)).toBe(1);
  await page.evaluate(()=>{document.getElementById('fixture').innerHTML=window.settingsHome();});
  await expect(resend).toHaveAttribute('aria-busy','true');
  await expect(resend.getByRole('button',{name:'Guardar',exact:true})).toBeDisabled();
  await expect(resend.locator('[data-mail-test-send]')).toBeDisabled();
  await page.evaluate(()=>{
    document.querySelector('[data-secret-provider-save="resend"]').dispatchEvent(new MouseEvent('click',{bubbles:true}));
    document.querySelector('[data-mail-test-send]').dispatchEvent(new MouseEvent('click',{bubbles:true}));
  });
  expect(await page.evaluate(()=>window.integrationFixture.writes.length)).toBe(1);
  // Drain pending microtasks: an accidental mail request would otherwise be caught silently.
  await page.evaluate(async()=>{await Promise.resolve();await Promise.resolve();});
  expect(await page.evaluate(()=>window.integrationFixture.testMailRequests.length)).toBe(0);
  await page.evaluate(()=>{
    const f=window.integrationFixture;f.holdWrites=false;
    f.pendingWrites.splice(0).forEach(resolve=>resolve());
  });
  await expect(resend.locator('[data-secret-feedback]')).toContainText('eliminado');
  await expect(resend).toHaveAttribute('aria-busy','false');
  const final=await page.evaluate(()=>({values:window.integrationFixture.values,writes:window.integrationFixture.writes}));
  expect(final.writes.map(write=>write.method)).toEqual(['DELETE']);
  expect(Object.hasOwn(final.values,'BRVTAL_TICKET_MAIL_API_KEY')).toBe(false);
});


test('AC-02 #1993: Nombre del remitente y Responder a se guardan; capturas 390/1440',async({page},testInfo)=>{
  const evidence=resolve(process.cwd(),'test-results/public-concept05-fidelity-mail-deliverability-1993');
  mkdirSync(evidence,{recursive:true});
  for(const width of [390,1440]){
    await page.setViewportSize({width,height:900});
    await setup(page);
    const resend=page.locator('[data-integration-provider="resend"]');
    const name=resend.locator('[data-secret-key="BRVTAL_TICKET_MAIL_FROM_NAME"]');
    const reply=resend.locator('[data-secret-key="BRVTAL_TICKET_MAIL_REPLY_TO"]');
    await expect(resend.getByText('Nombre del remitente',{exact:true})).toBeVisible();
    await expect(resend.getByText('Responder a',{exact:true})).toBeVisible();
    await expect(name).toHaveAttribute('placeholder','BRVTAL');
    const before=await page.screenshot({fullPage:true});
    await testInfo.attach('antes-guardar-remitente-'+width+'px.png',{body:before,contentType:'image/png'});
    writeFileSync(join(evidence,'antes-guardar-remitente-'+width+'px.png'),before);
    await name.fill('BRVTAL Música');
    await reply.fill('soporte@example.test');
    await resend.getByRole('button',{name:'Guardar',exact:true}).click();
    await expect(resend.locator('[data-secret-feedback]')).toContainText('2 claves guardadas');
    const stored=await page.evaluate(()=>window.integrationFixture);
    expect(stored.writes.map(write=>write.key))
      .toEqual(['BRVTAL_TICKET_MAIL_FROM_NAME','BRVTAL_TICKET_MAIL_REPLY_TO']);
    expect(stored.values.BRVTAL_TICKET_MAIL_FROM_NAME).toBe('BRVTAL Música');
    expect(stored.values.BRVTAL_TICKET_MAIL_REPLY_TO).toBe('soporte@example.test');
    await expect(name).toHaveValue('');
    await expect(reply).toHaveValue('');
    await expect(resend.locator('[data-secret-row="BRVTAL_TICKET_MAIL_FROM_NAME"] [data-secret-stored]')).toContainText('Guardada');
    await expect(resend.locator('[data-secret-row="BRVTAL_TICKET_MAIL_REPLY_TO"] [data-secret-stored]')).toContainText('Guardada');
    const overflow=await page.evaluate(()=>({
      viewport:document.documentElement.clientWidth,body:document.body.scrollWidth}));
    expect(overflow.body).toBeLessThanOrEqual(overflow.viewport);
    const after=await page.screenshot({fullPage:true});
    await testInfo.attach('despues-guardar-remitente-'+width+'px.png',{body:after,contentType:'image/png'});
    writeFileSync(join(evidence,'despues-guardar-remitente-'+width+'px.png'),after);
  }
});
