import {test, expect} from '@playwright/test';
import {readFileSync,mkdirSync,writeFileSync} from 'node:fs';

const shellScript = readFileSync('discadmin/ticketing.js','utf8');
const moduleScript = readFileSync('discadmin/ticketing-mail-templates.js','utf8');
const moduleCss = readFileSync('discadmin/ticketing-mail-templates.css','utf8');
const defaultTheme = {
  background:'#111111',foreground:'#f7f7f7',accent:'#ffffff',
  logo_media_id:null,hero_media_id:null
};
const original = {
  subject:'Compra aprobada',preheader:'Boletas disponibles',
  title:'Tu compra está confirmada',message:'Nos vemos en {{event_name}}.',
  cta:'Ver boletas',footer:'BRVTAL · Mensaje transaccional',
  theme:{...defaultTheme}
};
const entries = [
  {key:'payment_approved',scope:'ticketing',allowed_variables:['event_name'],defaults:original},
  {key:'verify_account',scope:'ticketing',allowed_variables:[],defaults:{
    subject:'Confirma tu cuenta',preheader:'',title:'Bienvenido',
    message:'Confirma tu cuenta con el enlace seguro.',cta:'Confirmar',
    footer:'BRVTAL',theme:{...defaultTheme}
  }},
  {key:'brand_theme',scope:'global',allowed_variables:[],defaults:{theme:{...defaultTheme}}}
];
const clone = value => JSON.parse(JSON.stringify(value));
const json = (value,status=200) => ({status,contentType:'application/json; charset=utf-8',body:JSON.stringify(value)});

async function setup(page,{width=1440,role='superadmin'}={}) {
  await page.setViewportSize({width,height:900});
  const state = {
    requests:[],active:clone(original),revision:0,versionId:null,draft:null,
    history:[],nextStatus:0,sent:0,html:0
  };
  await page.route('**/discadmin/mail-editor-fixture.html',route=>route.fulfill({
    contentType:'text/html; charset=utf-8',body:
      '<!doctype html><html lang="es"><head><meta name="viewport" content="width=device-width,initial-scale=1">'
      + '</head><body style="background:#080b0e;color:#f6f6f6;margin:0;padding:14px;font-family:Arial,sans-serif">'
      + '<div id="app"><div class="shell" style="max-width:1440px;margin:auto">'
      + '<aside class="side" style="height:30px">DISCADMIN</aside><main class="main">'
      + '<div id="admin-module-host"><section data-admin-module="ticketing" data-ticketing-role="'+role+'">'
      + '<div role="tablist" aria-label="Secciones de Boletería">'
      + '<button id="ticketing-tab-dashboard" data-ticketing-tab="dashboard" role="tab" aria-selected="true">Dashboard</button>'
      + '<button id="ticketing-tab-mail" data-ticketing-tab="mail" role="tab" aria-selected="false">Correos</button>'
      + '</div><div id="ticketing-panel" data-ticketing-panel role="tabpanel"></div>'
      + '</section></div></main></div></div>'
      + '<script>window.csrf="csrf-sintetico-1888";window.state={authed:true,section:"ticketing"};</script></body></html>'
  }));
  await page.route('**/discadmin/ticketing-mail-templates.js',route=>route.fulfill({
    contentType:'text/javascript; charset=utf-8',body:moduleScript
  }));
  await page.route('**/discadmin/ticketing-mail-templates.css',route=>route.fulfill({
    contentType:'text/css; charset=utf-8',body:moduleCss
  }));
  await page.route('**/api/media-library.php?action=list',route=>route.fulfill(json({
    ok:true,data:[{id:21,type:'image',status:'published',title:'Logo de prueba',
      file_path:'/uploads/brand/logo-test.png'},
      {id:22,type:'image',status:'draft',title:'No publicado',
        file_path:'/uploads/brand/draft.png'}]
  })));
  await page.route('**/api/admin-mail-templates.php*',route=>{
    const request = route.request();
    const parsed=new URL(request.url());
    const view=parsed.searchParams.get('view');
    if(request.method()==='GET'){
      if(view==='catalog')return route.fulfill(json({ok:true,data:{items:clone(entries)}}));
      if(view==='active')return route.fulfill(json({ok:true,data:{
        source:state.versionId?'published':'catalog',
        document:clone(state.active),revision:state.revision,version_id:state.versionId
      }}));
      if(view==='history')return route.fulfill(json({ok:true,data:{items:clone(state.history)}}));
      if(view==='draft'&&state.draft)return route.fulfill(json({ok:true,data:{
        version_id:state.draft.id,version_number:state.draft.revision,document:clone(state.draft.copy)
      }}));
      return route.fulfill(json({ok:false,error:'MAIL_TEMPLATE_NOT_FOUND'},404));
    }
    if(request.method()!=='POST')throw Error('Unknown method '+request.method());
    expect(request.headers()['x-csrf-token']).toBe('csrf-sintetico-1888');
    const body=request.postDataJSON();state.requests.push(body);
    expect(body).not.toHaveProperty('recipient');
    expect(body).not.toHaveProperty('token');
    if(state.nextStatus && body.action!=='preview'){
      const status=state.nextStatus;state.nextStatus=0;
      return route.fulfill(json({ok:false,error:status===403?'FORBIDDEN':
        status===409?'MAIL_TEMPLATE_CONFLICT':'MAIL_TEMPLATES_UNAVAILABLE'},status));
    }
    if(body.action==='preview'){
      const copy=clone(body.template_copy);
      for(const field of ['subject','preheader','title','message','cta','footer'])
        if(typeof copy[field]==='string')copy[field]=copy[field].replaceAll('{{event_name}}','Evento de prueba');
      return route.fulfill(json({ok:true,mail_sent:false,data:{
        template_copy:copy,synthetic:true,mail_sent:false
      }}));
    }
    if(body.revision!==state.revision)return route.fulfill(json({ok:false,error:'MAIL_TEMPLATE_CONFLICT'},409));
    if(body.action==='save_draft'){
      state.revision+=1;state.draft={id:78,revision:state.revision,copy:clone(body.template_copy)};
      state.history.unshift({version_id:78,revision:state.revision,kind:'draft',
        created_at:'2026-11-23 UTC',first_published_at:null,discarded_at:null});
      return route.fulfill(json({ok:true,mail_sent:false,data:{
        state:'draft',version_id:78,revision:state.revision,mail_sent:false
      }}));
    }
    if(body.action==='publish'){
      if(!state.draft||body.version_id!==state.draft.id)
        return route.fulfill(json({ok:false,error:'MAIL_TEMPLATE_VERSION_UNKNOWN'},404));
      state.active=clone(state.draft.copy);state.versionId=body.version_id;state.revision+=1;
      state.history[0].first_published_at='2026-11-23 UTC';state.draft=null;
      return route.fulfill(json({ok:true,mail_sent:false,data:{
        state:'published',version_id:78,revision:state.revision,mail_sent:false
      }}));
    }
    if(body.action==='restore'){
      state.active=clone(original);state.versionId=90;state.revision+=1;
      state.history.unshift({version_id:90,revision:state.revision,kind:'rollback',
        created_at:'2026-11-23 UTC',first_published_at:'2026-11-23 UTC',discarded_at:null});
      return route.fulfill(json({ok:true,mail_sent:false,data:{
        state:'published',version_id:90,revision:state.revision,mail_sent:false
      }}));
    }
    throw Error('Unexpected action '+body.action);
  });
  await page.goto('http://127.0.0.1:4173/discadmin/mail-editor-fixture.html');
  await page.addStyleTag({content:moduleCss});
  await page.addScriptTag({content:shellScript});
  await page.evaluate(()=>window.BRVTALTicketing.selectTab('mail'));
  await expect(page.locator('[data-mail-workspace]')).toBeVisible();
  await expect(page.getByRole('textbox',{name:'Asunto'})).toHaveValue('Compra aprobada');
  return state;
}

test('AC-01/AC-04: editor real en el shell a 390/1440, preview HTML/texto y foco sin oclusión',async({page},info)=>{
  for(const width of [390,1440]){
    const state=await setup(page,{width});
    const editor=page.locator('[data-mail-workspace]');
    await expect(editor.getByText('Correos transaccionales')).toBeVisible();
    await expect(editor.locator('[data-mail-catalog] button')).toHaveCount(3);
    await expect(editor.locator('[data-mail-catalog] button[data-mail-key="payment_approved"]')).toBeVisible();
    await expect(editor.getByRole('button',{name:'Enviar prueba'})).toBeDisabled();
    await expect(editor.getByText(/enlaces de verificación y recuperación/)).toBeVisible();
    await expect(editor.locator('[data-mail-preview-frame]').contentFrame().locator('h1'))
      .toContainText('Tu compra está confirmada');
    // The existing CI visual-artifact uploader collects this ephemeral test-results prefix.
    const visualDir='test-results/public-concept05-fidelity-mail-1888';
    mkdirSync(visualDir,{recursive:true});
    const before=await page.screenshot({fullPage:true});
    writeFileSync(visualDir+'/brvtal-correo-1888-'+width+'-antes.png',before);
    await info.attach('1888-antes-'+width+'.png',{body:before,contentType:'image/png'});
    await editor.getByRole('textbox',{name:'Título'}).fill('Bienvenidos al concierto');
    await editor.getByRole('button',{name:'{{event_name}}'}).click();
    await editor.getByRole('button',{name:'Móvil'}).click();
    await expect(editor.locator('[data-mail-preview-frame]')).toHaveAttribute('data-preview-mode','mobile');
    await expect(editor.locator('[data-mail-preview-frame]').contentFrame().locator('h1'))
      .toContainText('Bienvenidos al concierto');
    await editor.getByRole('button',{name:'Texto plano'}).click();
    await expect(editor.locator('[data-mail-preview-plain]')).toContainText('Evento de prueba');
    await expect(editor.locator('[data-mail-preview-frame]')).toBeHidden();
    await editor.getByRole('button',{name:'Escritorio'}).click();
    await expect(editor.locator('[data-mail-preview-frame]')).toBeVisible();
    await editor.getByRole('button',{name:'Elegir de Media'}).first().click();
    await expect(editor.getByRole('dialog').getByText('Logo de prueba')).toBeVisible();
    await expect(editor.getByRole('dialog').getByText('No publicado')).toHaveCount(0);
    await editor.getByRole('dialog').getByRole('button',{name:/Logo de prueba/}).click();
    await expect(editor.locator('[data-mail-media-value="logo_media_id"]')).toContainText('#21');
    const overflow=await page.evaluate(()=>document.documentElement.scrollWidth-innerWidth);
    expect(overflow).toBeLessThanOrEqual(1);
    await editor.getByRole('tab',{name:'Plantillas'}).focus();
    await page.keyboard.press('ArrowRight');
    await expect(editor.getByRole('tab',{name:'Historial'})).toBeFocused();
    await editor.getByRole('tab',{name:'Plantillas'}).click();
    await expect(editor.getByRole('textbox',{name:'Título'})).toHaveValue('Bienvenidos al concierto');
    const after=await page.screenshot({fullPage:true});
    writeFileSync(visualDir+'/brvtal-correo-1888-'+width+'-despues.png',after);
    await info.attach('1888-despues-'+width+'.png',{body:after,contentType:'image/png'});
    expect(state.requests.some(x=>x.action==='preview')).toBe(true);
  }
});

test('AC-02: guardar borrador no publica; publicar con confirmación, rol y CAS',async({page})=>{
  const state=await setup(page);
  const editor=page.locator('[data-mail-workspace]');
  await editor.getByRole('textbox',{name:'Asunto'}).fill('Noche BRVTAL');
  await expect(editor.getByRole('button',{name:'Publicar',exact:true})).toBeDisabled();
  await editor.getByRole('button',{name:'Guardar borrador'}).click();
  await expect(editor.locator('[data-mail-status]')).toContainText('Borrador guardado');
  expect(state.active.subject).toBe('Compra aprobada');
  expect(state.draft.copy.subject).toBe('Noche BRVTAL');
  await page.evaluate(()=>window.confirm=()=>false);
  await editor.getByRole('button',{name:'Publicar',exact:true}).click();
  expect(state.active.subject).toBe('Compra aprobada');
  await page.evaluate(()=>window.confirm=()=>true);
  await editor.getByRole('button',{name:'Publicar',exact:true}).click();
  await expect(editor.locator('[data-mail-status]')).toContainText('Plantilla publicada');
  expect(state.active.subject).toBe('Noche BRVTAL');
  expect(state.requests.filter(x=>x.action==='save_draft')).toHaveLength(1);
  expect(state.requests.filter(x=>x.action==='publish')).toHaveLength(1);
  expect(state.requests.every(x=>!('cta_url' in x||'password' in x))).toBe(true);
  await expect(editor.getByRole('button',{name:'Enviar prueba'})).toBeDisabled();
});

test('AC-02/AC-03: 403/409/503 preservan ediciones; historial/restauración y permisos de lectura',async({page})=>{
  const state=await setup(page);
  const editor=page.locator('[data-mail-workspace]');
  const subject=editor.getByRole('textbox',{name:'Asunto'});
  await subject.fill('Cambio que debe sobrevivir');
  for(const status of [403,409,503]){
    state.nextStatus=status;
    await editor.getByRole('button',{name:'Guardar borrador'}).click();
    await expect(editor.locator('[data-mail-status]')).toContainText(status===403?'permiso':
      status===409?'Otra edición':'no está disponible');
    await expect(subject).toHaveValue('Cambio que debe sobrevivir');
    expect(state.active.subject).toBe('Compra aprobada');
  }
  await editor.getByRole('button',{name:'Guardar borrador'}).click();
  await expect(editor.locator('[data-mail-status]')).toContainText('Borrador guardado');
  await page.evaluate(()=>window.confirm=()=>true);
  await editor.getByRole('button',{name:'Publicar',exact:true}).click();
  await expect(editor.locator('[data-mail-status]')).toContainText('publicada');
  await editor.getByRole('tab',{name:'Historial'}).click();
  await expect(editor.getByText('versión 1')).toBeVisible();
  await editor.getByRole('button',{name:'Restablecer versión'}).click();
  await expect(editor.locator('[data-mail-status]')).toContainText('Versión restaurada');
  expect(state.requests.some(x=>x.action==='restore')).toBe(true);
  await setup(page,{role:'mail_staff'});
  await expect(page.getByRole('button',{name:'Guardar borrador'})).toBeDisabled();
  await expect(page.getByRole('button',{name:'Publicar',exact:true})).toBeDisabled();
  await expect(page.locator('[data-mail-preview-frame]').contentFrame().locator('a')).toHaveCount(0);
});
