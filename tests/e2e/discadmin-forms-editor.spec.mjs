import {test,expect} from '@playwright/test';
import {readFileSync,mkdirSync} from 'node:fs';
import {execFileSync} from 'node:child_process';
const base=process.env.BRVTAL_FORMS_EDITOR_BASE||'';
const origin='http://127.0.0.1:4173';
const read=(path,old=false)=>old?execFileSync('git',['show',base+':'+path],{encoding:'utf8',maxBuffer:4*1024*1024}):readFileSync(path,'utf8');
async function setup(page,{old=false,denied=false}={}){
  const core=read('discadmin/index-core.php',old);
  const skin=core.match(/<style>([\s\S]*?)<\/style>/)[1];
  const runtime=core.match(/<script>\s*const API=([\s\S]*?)<\/script>/)[0].replace(/<\?=[\s\S]*?\?>/g,'fixture')
    .replace("let csrf='';let state=","window.csrf='';window.state=");
  const styles=['admin-modules','admin-appearance','admin-shell','admin-information-architecture','admin-design-system'].map(n=>read('discadmin/'+n+'.css',old)).join('\n');
  const control={row:null,posts:[],published:[],error:0,denied};
  await page.route('**/*',async route=>{
    const u=new URL(route.request().url());if(u.origin!==origin)return route.abort();
    if(u.pathname==='/discadmin/')return route.fulfill({contentType:'text/html',body:
      '<!doctype html><html lang="es" data-discadmin-appearance="dark"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>'+skin+styles+'</style><div id="app"></div><div id="modal"></div>'+runtime+
      '<script src="/discadmin/admin-unsaved-changes.js"></script><script src="/discadmin/admin-modules.js"></script><script src="/discadmin/admin-shell.js"></script><script src="/discadmin/admin-information-architecture.js"></script><script>window.csrf="fixture-csrf";state.authed=true;state.section="pages";render();</script></html>'});
    if(u.pathname==='/discadmin/forms.php')return route.fulfill({contentType:'text/html',body:read('discadmin/forms.php',old).split('?>').at(-1)});
    if(u.pathname==='/api/admin-forms.php')return route.fulfill({json:{ok:true,data:{items:[],management_enabled:false}}});
    if(u.pathname==='/api/admin-form-definitions.php'){
      if(control.denied)return route.fulfill({status:403,json:{ok:false}});
      const req=route.request();
      if(req.method()==='GET')return route.fulfill({json:{ok:true,data:u.searchParams.has('form_key')?control.row:{items:control.row?[control.row]:[],next_cursor:null}}});
      const b=req.postDataJSON();control.posts.push({body:b,csrf:req.headers()['x-csrf-token']});
      if(control.error===-1)return route.abort();
      if(control.error)return route.fulfill({status:control.error,json:{ok:false}});
      if(b.action==='create')control.row={form_key:'f_fixture',slug:b.slug,revision:1,draft:b.draft,state:'draft',published_version_number:null};
      else {expect(b.revision).toBe(control.row.revision);control.row.revision++;
        if(b.action==='save')Object.assign(control.row,{slug:b.slug,draft:b.draft});
        if(b.action==='publish'){control.published.push(structuredClone(control.row.draft));control.row.state='active';control.row.published_version_number=control.published.length;}
        if(b.action==='disable')control.row.state='disabled';
        if(b.action==='duplicate')Object.assign(control.row,{form_key:'f_copy',slug:b.slug,revision:1,state:'draft',published_version_number:null});}
      return route.fulfill({json:{ok:true,data:control.row}});
    }
    if(u.pathname.startsWith('/api/'))return route.fulfill({json:{ok:true,data:[]}});
    if(/^\/discadmin\/[a-z0-9-]+\.(js|css)$/.test(u.pathname)){try{return route.fulfill({contentType:u.pathname.endsWith('.css')?'text/css':'application/javascript',body:read(u.pathname.slice(1),old)});}catch{return route.fulfill({status:404,body:''});}}
    return route.fulfill({status:404,body:''});
  });
  await page.goto(origin+'/discadmin/?module=forms');
  await expect(page.getByRole('heading',{name:'Formularios disponibles'})).toBeVisible();
  return control;
}
async function capture(page,stage,width){
  if(!base)return;
  mkdirSync('artifacts/forms-2071',{recursive:true});await page.evaluate(()=>window.scrollTo(0,0));
  for(const ext of ['png','jpg'])await page.screenshot({path:'artifacts/forms-2071/'+stage+'-'+width+'.'+ext,
    type:ext==='jpg'?'jpeg':'png',...(ext==='jpg'?{quality:65}:{}),animations:'disabled',fullPage:true});
}
for(const width of [390,1440])test('Editor visual y publicación '+width,async({page})=>{
  await page.setViewportSize({width,height:900});
  if(base){const before=await page.context().newPage();await before.setViewportSize({width,height:900});await setup(before,{old:true});await capture(before,'before',width);await before.close();}
  const c=await setup(page);await page.getByRole('button',{name:'Editor de formularios'}).click();
  await page.getByRole('button',{name:'Crear formulario'}).click();
  await page.getByLabel('Nombre del formulario').fill('Formulario ficticio');await page.getByLabel('Slug',{exact:true}).fill('fixture');
  await page.getByRole('button',{name:'Agregar campo'}).click();await page.getByLabel('Etiqueta',{exact:true}).fill('Nombre');
  await page.getByLabel('Tipo de campo').selectOption('select');await page.getByRole('button',{name:'Agregar campo'}).click();
  await page.getByLabel('Etiqueta',{exact:true}).nth(1).fill('Color');await page.getByLabel('Opción 1',{exact:true}).fill('Azul');
  await page.getByRole('button',{name:'Subir',exact:true}).nth(1).focus();await page.keyboard.press('Enter');
  await expect(page.getByLabel('Etiqueta',{exact:true}).first()).toHaveValue('Color');
  await page.getByRole('button',{name:'Agregar campo'}).click();await page.getByRole('button',{name:'Eliminar campo'}).last().click();
  await page.getByLabel('Versión del aviso',{exact:true}).fill('fixture-v1');await page.getByLabel('Texto del aviso').fill('Aviso ficticio');
  await page.getByLabel('Clave de finalidad').fill('fixture');await page.getByLabel('Clave de conservación').fill('fixture');
  await page.getByLabel('Tipo de campo').selectOption('consent');await page.getByRole('button',{name:'Agregar campo'}).click();
  await page.getByRole('button',{name:'Guardar borrador'}).click();await expect(page.getByText('Revisión 1',{exact:false})).toBeVisible();
  expect(c.posts[0]).toMatchObject({csrf:'fixture-csrf',body:{action:'create',slug:'fixture',draft:{fields:[{label:'Color',order:0},{label:'Nombre',order:1},{type:'consent',notice_version:'fixture-v1',order:2}]}}});
  await page.getByRole('button',{name:'Vista previa ficticia'}).click();await expect(page.getByText('Solo datos ficticios.',{exact:false})).toBeVisible();expect(c.posts).toHaveLength(1);
  await page.getByRole('button',{name:'Vista previa ficticia'}).click();await page.getByRole('button',{name:'Publicar versión'}).click();
  await expect(page.getByText('Versión publicada 1',{exact:false})).toBeVisible();const first=structuredClone(c.published[0]);
  await page.getByLabel('Nombre del formulario').fill('Nuevo título');
  await expect(page.getByRole('button',{name:'Publicar versión'})).toBeDisabled();
  page.once('dialog',d=>d.dismiss());await page.getByRole('button',{name:'Volver a definiciones'}).click();
  await expect(page.getByLabel('Nombre del formulario')).toHaveValue('Nuevo título');
  c.error=-1;await page.getByRole('button',{name:'Guardar borrador'}).click();await expect(page.getByText('Se conservan tus cambios.',{exact:false})).toBeVisible();
  c.error=0;await page.getByRole('button',{name:'Guardar borrador'}).click();await expect(page.getByText('Revisión 3',{exact:false})).toBeVisible();
  await page.getByRole('button',{name:'Publicar versión'}).click();await expect(page.getByText('Versión publicada 2',{exact:false})).toBeVisible();expect(c.published[0]).toEqual(first);
  expect(await page.evaluate(()=>document.documentElement.scrollWidth)).toBeLessThanOrEqual(width);
  if(base){await expect(page.locator('.brvtal-feedback')).toHaveCount(0,{timeout:10000});await capture(page,'after',width);}
  await page.getByLabel('Nombre del formulario').fill('Borrador local');c.error=409;
  await page.getByRole('button',{name:'Guardar borrador'}).click();await expect(page.getByText('Conflicto de revisión.',{exact:false})).toBeVisible();
  await expect(page.getByLabel('Nombre del formulario')).toHaveValue('Borrador local');
  await expect(page.getByRole('button',{name:'Publicar versión'})).toBeDisabled();
});
test('Duplicar, desactivar y revocación de permiso durante escritura',async({page})=>{
  const c=await setup(page);
  await page.getByRole('button',{name:'Editor de formularios'}).click();
  await page.getByRole('button',{name:'Crear formulario'}).click();
  await page.getByLabel('Nombre del formulario').fill('Original ficticio');
  await page.getByLabel('Slug',{exact:true}).fill('original');
  await page.getByRole('button',{name:'Guardar borrador'}).click();
  await expect(page.getByText('Revisión 1',{exact:false})).toBeVisible();
  page.once('dialog',d=>d.accept('copia'));
  await page.getByRole('button',{name:'Duplicar definición'}).click();
  await expect(page.getByLabel('Slug',{exact:true})).toHaveValue('copia');
  expect(c.posts.at(-1).body).toMatchObject({action:'duplicate',form_key:'f_fixture',revision:1,slug:'copia'});
  page.once('dialog',d=>d.accept());
  await page.getByRole('button',{name:'Desactivar',exact:true}).click();
  await expect(page.getByRole('button',{name:'Desactivar',exact:true})).toHaveCount(0);
  await page.getByLabel('Nombre del formulario').fill('Conservar borrador');
  c.error=403;
  await page.getByRole('button',{name:'Guardar borrador'}).click();
  await expect(page.getByText('Permiso no válido.',{exact:false})).toBeVisible();
  await expect(page.getByLabel('Nombre del formulario')).toHaveValue('Conservar borrador');
  await expect(page.getByRole('button',{name:'Guardar borrador'})).toBeDisabled();
});
test('Permiso denegado no expone editor ni afecta bandeja',async({page})=>{
  const c=await setup(page,{denied:true});await expect(page.getByRole('button',{name:'Editor de formularios'})).toHaveCount(0);expect(c.posts).toHaveLength(0);
});
