import { test, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const source = [
  "require_once 'config/public_seo.php'; require_once 'config/public_page.php';",
  "$raw=json_encode(['blocks'=>[['type'=>'heading','content'=>'Antes del formulario'],['type'=>'form_ref','public_id'=>str_repeat('a',32)],['type'=>'text','content'=>'Después del formulario <img src=x onerror=alert(1)>']]]);",
  "$description=brvtal_page_content_plain_text($raw);",
  "$page=['entity'=>['id'=>1,'route_type'=>'pages','title'=>'Fixture CMS','description'=>$description,'status'=>'published'],'facts'=>[],'links'=>[],'related'=>[],'record'=>[],'degraded'=>false];",
  "if(function_exists('brvtal_page_content_html')) $page['record']['body_html']=brvtal_page_content_html($raw,static fn(string $id):?array=>$argv[1]==='active'?['title'=>'Formulario sintético','fields'=>[['label'=>'Mensaje sintético'],['label'=>'Consentimiento sintético']]]:null);",
  "$seo=['title'=>'Fixture CMS','description'=>$description,'image'=>'','canonical'=>'https://www.brvtal.com.co/pages/cms-fixture','schema'=>['@context'=>'https://schema.org','@type'=>'WebPage','description'=>$description]];",
  "echo brvtal_public_entity_page($page,$seo);"
].join('\n');

async function mount(page, root, state) {
  const html=execFileSync('php',['-r',source,state],{cwd:root,encoding:'utf8'});
  await page.route('**/*',async route=>{
    const url=new URL(route.request().url());
    if(url.origin!=='http://127.0.0.1:4173') return route.abort();
    if(url.pathname==='/pages/cms-fixture')return route.fulfill({contentType:'text/html',body:html});
    if(/^\/css\/[a-z0-9-]+\.css$/.test(url.pathname)) {
      try{return route.fulfill({contentType:'text/css',body:readFileSync(resolve(root,'.'+url.pathname))});}catch{return route.abort();}
    }
    if(/^\/assets\/fonts\/[a-zA-Z0-9_.-]+\.(?:woff2?|ttf|otf)$/.test(url.pathname)){
      try{return route.fulfill({body:readFileSync(resolve(root,'.'+url.pathname))});}catch{return route.abort();}
    }
    return route.abort();
  });
  await page.goto('http://127.0.0.1:4173/pages/cms-fixture');
}

for(const width of [390,1440]){
  test('2072 inert CMS form, exact public renderer '+width,async({page})=>{
    await page.setViewportSize({width,height:900});
    const capture=async(p,stage)=>{
      const png=await p.screenshot({path:'artifacts/forms-2072/'+stage+'-'+width+'.png',fullPage:true,animations:'disabled'});
      expect(png.readUInt32BE(16)).toBe(width);
    };
    const baseline=process.env.BRVTAL_FORM_VISUAL_BASE_ROOT;
    if(baseline){
      const before=await page.context().newPage();await before.setViewportSize({width,height:900});
      await mount(before,baseline,'active');await expect(before.locator('[data-form-ref]')).toHaveCount(0);
      await capture(before,'before');await before.close();
    }
    await mount(page,process.cwd(),'active');
    const block=page.locator('[data-form-ref="preview"]');
    await expect(block.getByRole('heading',{name:'Formulario sintético'})).toBeVisible();
    await expect(block).toContainText('Recepción no disponible.');
    await expect(block).toContainText('Mensaje sintético');
    await expect(block.locator('form,input,textarea,select,button')).toHaveCount(0);
    await expect(page.locator('img[src="x"]')).toHaveCount(0);
    expect(await page.locator('.entity-statement').innerText()).toMatch(/Antes del formulario[\s\S]*Formulario sintético[\s\S]*Después del formulario/);
    expect(await page.locator('meta[name=description]').getAttribute('content')).not.toMatch(/aaaaaaaa|Formulario sintético/);
    expect(await block.evaluate(el=>el.scrollWidth<=el.clientWidth)).toBeTruthy();
    await capture(page,'after');
    await page.unrouteAll();await mount(page,process.cwd(),'missing');
    await expect(page.locator('[data-form-ref="unavailable"]')).toHaveText('Formulario no disponible.');
    await expect(page.getByText('Formulario sintético',{exact:true})).toHaveCount(0);
    await capture(page,'unavailable');
  });
}
