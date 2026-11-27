import {test,expect} from '@playwright/test';
import {execFileSync} from 'node:child_process';
import {readFileSync,mkdirSync} from 'node:fs';
import {resolve} from 'node:path';
const origin='http://127.0.0.1:4173', path='/pages/form-fixture', id='a'.repeat(32);
const schema=version=>({id,version,title:'Formulario sintético',notice:'Aviso ficticio de privacidad',fields:[
  {id:'message',label:'Mensaje sintético',type:'text',required:true,max_length:100},
  {id:'email',label:'Correo sintético',type:'email',required:false,max_length:254},
  {id:'consent_ok',label:'Consentimiento sintético',type:'consent',required:true,purpose:'privacy',notice_version:'v'+version}
]});
function html(root,state) {
  const php=`require_once 'config/public_seo.php'; require_once 'config/public_page.php';
    $spec=json_decode($argv[1],true,flags:JSON_THROW_ON_ERROR);
    $raw=json_encode(['blocks'=>[['type'=>'heading','content'=>'Antes'],['type'=>'form_ref','public_id'=>$spec['id']]]]);
    $form=['title'=>$spec['title'],'fields'=>array_map(static fn($f)=>['label'=>$f['label']],$spec['fields']),'submit'=>$spec];
    $page=['entity'=>['id'=>1,'route_type'=>'pages','title'=>'Fixture CMS','description'=>'Antes','status'=>'published'],'facts'=>[],'links'=>[],'related'=>[],'record'=>[],'degraded'=>false];
    $page['record']['body_html']=brvtal_page_content_html($raw,static fn(string $id):?array=>$argv[2]==='off'?null:$form);
    $seo=['title'=>'Fixture CMS','description'=>'Antes','image'=>'','canonical'=>'https://www.brvtal.com.co/pages/form-fixture','schema'=>[]];
    echo brvtal_public_entity_page($page,$seo);`;
  const spec=schema(state.version);if(state.xss)spec.fields[1].label='<img src=x onerror=alert(1)>';
  return execFileSync('php',['-r',php,JSON.stringify(spec),state.off?'off':'on'],{cwd:root,encoding:'utf8'});
}
async function mount(page,root,state) {
  await page.route('**/*',async route=>{
    const req=route.request(),u=new URL(req.url());
    if(u.origin!==origin)return route.abort();
    if(u.pathname===path)return route.fulfill({contentType:'text/html',body:html(root,state)});
    if(u.pathname==='/api/forms.php'){
      const key=req.headers()['idempotency-key'];expect(key).toMatch(/^[a-f0-9]{32}$/);
      if(state.off||Number(u.searchParams.get('version'))!==state.version)return route.fulfill({status:409,json:{ok:false,error:'FORM_VERSION_CONFLICT'}});
      if(req.method()==='GET')return route.fulfill({json:{ok:true,data:{question:'2 + 3 = ?',captcha_token:'fixture',expires_in:120}}});
      state.posts.push({key,body:req.postDataJSON(),answer:req.headers()['x-form-captcha-answer']});
      if(state.network){state.network=false;return route.abort();}
      if(state.invalid){state.invalid=false;return route.fulfill({status:422,json:{ok:false,error:'FORM_PAYLOAD_INVALID'}});}
      return route.fulfill({json:{ok:true,message:'FORM_RECEIVED'}});
    }
    if(/^\/(?:css|js)\/[a-z0-9-]+\.(?:css|js)$/.test(u.pathname)){
      try{return route.fulfill({contentType:u.pathname.endsWith('.css')?'text/css':'application/javascript',body:readFileSync(resolve(root,'.'+u.pathname))});}catch{return route.abort();}
    }
    return route.abort();
  });
  await page.goto(origin+path);
}
async function capture(page,stage,width){
  if(!process.env.BRVTAL_PUBLIC_FORMS_BASE_ROOT)return;
  mkdirSync('artifacts/forms-2079',{recursive:true});
  for(const ext of ['png','jpg'])await page.screenshot({path:'artifacts/forms-2079/'+stage+'-'+width+'.'+ext,type:ext==='jpg'?'jpeg':'png',
    ...(ext==='jpg'?{quality:65}:{}),fullPage:true,animations:'disabled'});
}
for(const width of [390,1440])test('public form durable retry and version '+width,async({page})=>{
  await page.setViewportSize({width,height:900});const state={version:1,posts:[]};
  if(process.env.BRVTAL_PUBLIC_FORMS_BASE_ROOT){const before=await page.context().newPage();await before.setViewportSize({width,height:900});
    await mount(before,process.env.BRVTAL_PUBLIC_FORMS_BASE_ROOT,state);await expect(before.locator('[data-form-ref="preview"]')).toBeVisible();await capture(before,'before',width);await before.close();}
  await mount(page,process.cwd(),state);const form=page.locator('.public-form');
  await expect(form).toBeVisible();await expect(form.getByRole('button',{name:'Enviar formulario'})).toBeDisabled();
  await form.getByRole('button',{name:'Cargar desafío'}).click();await form.getByLabel('2 + 3 = ?').fill('5');
  await form.getByRole('button',{name:'Enviar formulario'}).click();await expect(form.getByLabel('Mensaje sintético')).toHaveAttribute('aria-invalid','true');
  await expect(form.getByLabel('Mensaje sintético')).toBeFocused();
  await form.getByLabel('Mensaje sintético').fill('<script>fixture</script>');await form.getByLabel('Consentimiento sintético').check();
  await form.getByRole('button',{name:'Enviar formulario'}).click();expect(state.posts).toHaveLength(0);
  await form.getByLabel('Mensaje sintético').fill('Respuesta ficticia');await capture(page,'after',width);
  expect(await form.evaluate(n=>n.scrollWidth<=n.clientWidth)).toBeTruthy();
  state.invalid=true;await form.getByRole('button',{name:'Enviar formulario'}).click();await expect(form.getByRole('status')).toContainText('rechazó');
  state.network=true;await form.evaluate(n=>{n.requestSubmit();n.requestSubmit();});await expect(form.getByRole('status')).toContainText('No se confirmó');
  expect(state.posts).toHaveLength(2);await expect(form.getByLabel('Mensaje sintético')).toBeDisabled();
  await form.getByRole('button',{name:'Reintentar envío'}).click();await expect(form.getByRole('status')).toHaveText('Formulario recibido. Gracias.');
  expect(state.posts).toHaveLength(3);expect(state.posts[2]).toEqual(state.posts[1]);
  await page.reload();await form.getByLabel('Mensaje sintético').fill('Valor compatible');await form.getByLabel('Consentimiento sintético').check();
  await form.getByRole('button',{name:'Cargar desafío'}).click();await form.getByLabel('2 + 3 = ?').fill('5');
  state.version=2;await form.getByRole('button',{name:'Enviar formulario'}).click();
  await expect(form.getByRole('status')).toContainText('El formulario cambió');await expect(form.getByLabel('Mensaje sintético')).toHaveValue('Valor compatible');
  await expect(form.getByLabel('Consentimiento sintético')).not.toBeChecked();await expect(form.getByRole('button',{name:'Enviar formulario'})).toBeDisabled();
  state.off=true;await form.getByRole('button',{name:'Cargar desafío'}).click();await expect(form.getByRole('status')).toContainText('Formulario no disponible');
  await expect(form.getByRole('button',{name:'Enviar formulario'})).toBeDisabled();expect(state.posts).toHaveLength(3);
});
test('untrusted label stays text and disabled fixture has no form',async({page})=>{
  const state={version:1,posts:[],xss:true};await mount(page,process.cwd(),state);
  await expect(page.getByLabel('<img src=x onerror=alert(1)>')).toBeVisible();await expect(page.locator('img[src="x"]')).toHaveCount(0);
  state.off=true;await page.reload();await expect(page.locator('[data-form-ref="unavailable"]')).toBeVisible();
  await expect(page.locator('.public-form')).toHaveCount(0);expect(state.posts).toHaveLength(0);
});
