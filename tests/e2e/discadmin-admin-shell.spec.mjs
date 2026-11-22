import { test, expect } from '@playwright/test';
import { readFileSync, writeFileSync, mkdirSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { join } from 'node:path';

const css = readFileSync(join(process.cwd(), 'discadmin/admin-shell.css'), 'utf8');
const js = readFileSync(join(process.cwd(), 'discadmin/admin-shell.js'), 'utf8');
const indexPhp = readFileSync(join(process.cwd(), 'discadmin/index.php'), 'utf8');
const indexCore = readFileSync(join(process.cwd(), 'discadmin/index-core.php'), 'utf8');
const iaCss = readFileSync(join(process.cwd(), 'discadmin/admin-information-architecture.css'), 'utf8');

async function mount(page) {
  await page.setContent(`<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><style>${css}</style></head><body>
    <div id="app"><div class="shell">
      <button class="admin-nav-scrim" aria-label="Cerrar navegación" onclick="closeAdminNav()"></button>
      <aside class="side" id="admin-primary-nav" aria-label="Navegación principal">
        <div class="nav"><button class="active" onclick="window.selected='dashboard'">INICIO</button><button onclick="window.selected='security'">SEGURIDAD / 2FA</button></div>
      </aside>
      <main class="main"><div class="top"><button class="admin-menu-toggle" aria-controls="admin-primary-nav" aria-expanded="false" onclick="toggleAdminNav()">MENÚ</button><h1>DASHBOARD</h1></div></main>
    </div></div><script>${js}</script></body></html>`);
}

test('mobile navigation uses the existing sidebar and closes after choosing a module', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await mount(page);
  const menu = page.getByRole('button', { name: 'MENÚ' });
  const nav = page.getByRole('complementary', { name: 'Navegación principal' });
  await expect(menu).toBeVisible();
  await expect(nav).toBeHidden();
  await menu.click();
  await expect(menu).toHaveAttribute('aria-expanded', 'true');
  await expect(nav).toBeVisible();
  await nav.getByRole('button', { name: 'SEGURIDAD / 2FA' }).click();
  await expect(nav).toBeHidden();
  await expect(menu).toHaveAttribute('aria-expanded', 'false');
  expect(await page.evaluate(() => window.selected)).toBe('security');
});

test('Escape closes the mobile menu and returns focus; desktop sidebar stays visible', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await mount(page);
  const menu = page.getByRole('button', { name: 'MENÚ' });
  const nav = page.getByRole('complementary', { name: 'Navegación principal' });
  await menu.click();
  await page.keyboard.press('Escape');
  await expect(nav).toBeHidden();
  await expect(menu).toBeFocused();
  await page.setViewportSize({ width: 1280, height: 800 });
  await expect(nav).toBeVisible();
  await expect(menu).toBeHidden();
});

test('dashboard PHP runtime label comes from the server runtime instead of a fixed version', async () => {
  expect(indexPhp).toContain('PHP_VERSION');
  expect(indexPhp).toContain('data-runtime-card="php"');
  expect(indexPhp).toContain('$phpRuntime');
  expect(indexPhp).toMatch(/<h3>PHP<\/h3><div class=\\?"techvalue\\?">\[\^<\]\*<\/div><\/div>/);
  expect(indexPhp).not.toContain("$phpRuntime = '8.3+'");
});

test('admin release metadata preserves diagnostics without showing internal jargon', async () => {
  expect(indexCore).toContain('data-testid="admin-product-version"');
  expect(indexCore).toContain('data-release="<?= htmlspecialchars(BRVTAL_APP_VERSION');
  expect(indexCore).not.toContain('BRVTAL v<?= htmlspecialchars(BRVTAL_APP_VERSION');
  expect(indexCore).toContain('data-testid="admin-product-environment"');
  expect(indexCore).toContain('data-testid="admin-deploy-source"');
  expect(indexCore).toContain('SOURCE UNAVAILABLE');
  expect(indexCore).toContain('<span hidden style="display:none"');
  expect(indexCore).not.toContain('v<?= htmlspecialchars(BRVTAL_APP_VERSION, ENT_QUOTES, "UTF-8") ?> · <?= htmlspecialchars(BRVTAL_APP_ENV');
});



/**
 * #1864: real Playwright screenshots of SOURCE-DERIVED local fixtures.
 * No production session or personal data; the original screen comes from
 * exact pre-change Git history. This is not a production smoke test.
 */
test('visual evidence #1864 source-driven shell and banners at 390 and 1440',async({page},testInfo)=>{
  const baseline='9b3a0421f247d8e3eb9038a1721be44d7165dbdf';
  // CI's canonical Chromium checkout is shallow. Fetch only the pinned original
  // commit when necessary; never replace it with an unverified local snapshot.
  try{
    execFileSync('git',['cat-file','-e',baseline+':discadmin/index-core.php'],
      {cwd:process.cwd(),stdio:'ignore'});
  }catch{
    execFileSync('git',['-c','protocol.version=2','fetch','--no-tags','--depth=1','origin',baseline],
      {cwd:process.cwd(),stdio:'pipe',timeout:30000,maxBuffer:4*1024*1024});
    const fetched=execFileSync('git',['rev-parse','FETCH_HEAD'],
      {cwd:process.cwd(),encoding:'utf8'}).trim();
    if(fetched!==baseline)throw Error('Visual baseline SHA did not match pinned commit');
  }
  const loadOriginal=path=>execFileSync('git',['show',baseline+':'+path],
    {cwd:process.cwd(),encoding:'utf8',maxBuffer:4*1024*1024});
  const originalCore=loadOriginal('discadmin/index-core.php');
  const originalBanners=loadOriginal('discadmin/hero-slider.js');
  const finalCore=readFileSync('discadmin/index-core.php','utf8');
  const finalBanners=readFileSync('discadmin/hero-slider.js','utf8');
  const shellCss=readFileSync('discadmin/admin-shell.css','utf8');
  const bannerCss=readFileSync('discadmin/hero-slider-v2.css','utf8');
  const skin=(/<style>([\s\S]*?)<\/style>/.exec(finalCore)||[])[1];
  if(!skin)throw Error('Canonical inline DISCADMIN styles unavailable');
  const safe=value=>String(value).replace(/[&<>"']/g,char=>({
    '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
  })[char]);
  const sourcePair=[
    {key:'before',core:originalCore,banners:originalBanners},
    {key:'after',core:finalCore,banners:finalBanners}
  ];
  function renderFixture(source,width){
    // The #1864 baseline uses editorialSections; approved V3 uses 8 roots.
    const legacyNav=(/const editorialSections=\[([\s\S]*?)\];/.exec(source.core)||null);
    const v3Nav=(/const adminMenuRoots=\[([\s\S]*?)\];/.exec(source.core)||null);
    const navText=(v3Nav||legacyNav||[])[1];
    const nav=[...(navText||'').matchAll(/\['([^']+)','([^']+)'\]/g)]
      .map(match=>({key:match[1],label:match[2]}));
    if(!navText || (v3Nav ? nav.length!==8 : nav.length<9))
      throw Error('Canonical navigation labels unavailable or wrong root count');
    const bannerTitle=(/<h2>(SLIDER MANAGER V2|Banners de portada)<\/h2>/.exec(source.banners)||[])[1];
    if(!bannerTitle)throw Error('Canonical banner heading unavailable');
    const spanish=source.key==='after';
    const firstLabel=nav[0].label;
    const appearance=spanish?'APARIENCIA':'APPEARANCE';
    const headline=spanish?'BANNERS DE PORTADA':'BANNERS';
    const add=spanish?'+ NUEVO BANNER':'+ ADD SLIDE';
    const media=spanish?'ELEGIR ARCHIVO':'SELECT MEDIA';
    const dest=spanish?'ESCRITORIO':'DESKTOP';
    const mobile=spanish?'MÓVIL':'MOBILE';
    const intro=spanish?'Organiza banners y capas con ajustes independientes para móvil.'
      :'LayerSlider-inspired visual layers with safe mobile overrides.';
    const navHtml=nav.map(item => v3Nav
      ? '<div class="ia-root" data-ia-root="'+safe(item.key)+'"><div class="ia-root-row">'
        +'<button type="button" class="ia-root-button" data-admin-nav="'+safe(item.key)+'">'
        +safe(item.label)+'</button></div></div>'
      : '<button type="button" data-admin-nav="'+safe(item.key)+'">'+safe(item.label)+'</button>'
    ).join('');
    // Source-driven labels with real repository CSS, synthetic zero-data editor state.
    // The fixture is explicitly marked, so no one can mistake it for production.
    return '<!doctype html><html lang="es"><head><meta name="viewport" content="width=device-width,initial-scale=1">'
      +'<style>'+skin+'\n'+shellCss+'\n'+bannerCss+(v3Nav?'\n'+iaCss:'')+'</style>'
      +'<style>.fixture-label{font-size:12px;letter-spacing:0.05em;color:#e4e4e4;margin-bottom:16px}'
      +'.fixture-editor{display:grid;gap:14px;margin-top:24px;padding:18px;border:1px solid #35383d;background:#080909}'
      +'.fixture-editor button{min-height:44px;cursor:pointer;padding:9px 14px}'
      +'.fixture-editor input{min-width:0;width:100%;padding:10px;background:#090a0b;color:#eee;border:1px solid #35383d}'
      +'@media(max-width:650px){.shell{display:block}.side{display:none}.main{padding:18px;min-width:0}.hero-manager{padding:16px}}'
      +'</style></head><body><div class="shell"><aside class="side"><div class="logo">BRVTAL</div>'
      +'<div class="sub">DISCADMIN</div><nav class="nav" aria-label="Navegación de prueba">'+navHtml
      +(v3Nav?'':'<button>'+appearance+'</button>')+'</nav></aside><main class="main">'
      +'<p class="fixture-label">PRUEBA VISUAL LOCAL · DATOS SINTÉTICOS · '+source.key+' · '+width+' PX</p>'
      +'<div class="top"><h1>'+safe(firstLabel)+'</h1></div><section class="hero-manager">'
      +'<div class="hero-manager-toolbar"><div><h2>'+safe(bannerTitle)+'</h2><p>'+safe(intro)+'</p></div>'
      +'<div class="hero-manager-actions"><button class="btn red">'+safe(add)+'</button></div></div>'
      +'<div class="fixture-editor"><h3>'+safe(headline)+'</h3>'
      +'<label>'+safe(spanish?'Archivo para escritorio':'Desktop media')+'<input value="" readonly aria-label="Archivo sintético"></label>'
      +'<button class="btn ghost">'+safe(media)+'</button>'
      +'<div class="hero-preview-head"><strong>'+safe(spanish?'VISTA PREVIA':'LIVE PREVIEW')+'</strong>'
      +'<button>'+safe(dest)+'</button><button>'+safe(mobile)+'</button></div>'
      +'</div></section></main></div></body></html>';
  }
  const folder=join(process.cwd(),'artifacts/discadmin-spanish-visual');
  mkdirSync(folder,{recursive:true});
  const evidence=[];
  for(const width of [390,1440]){
    await page.setViewportSize({width,height:900});
    for(const source of sourcePair){
      await page.setContent(renderFixture(source,width),{waitUntil:'domcontentloaded'});
      await expect(page.locator('.hero-manager h2')).toBeVisible();
      await expect(page.locator('[data-admin-nav="dashboard"]')).toHaveCount(1);
      const png=await page.screenshot({fullPage:false,animations:'disabled'});
      const pngWidth=png.readUInt32BE(16),pngHeight=png.readUInt32BE(20);
      expect(pngWidth).toBe(width);
      const encoded=await page.evaluate(async raw=>{
        const image=new Image();image.src='data:image/png;base64,'+raw;await image.decode();
        const canvas=document.createElement('canvas');
        canvas.width=image.naturalWidth;canvas.height=image.naturalHeight;
        canvas.getContext('2d').drawImage(image,0,0);
        return canvas.toDataURL('image/webp',0.77).split(',')[1];
      },png.toString('base64'));
      const webp=Buffer.from(encoded,'base64');
      expect(webp.subarray(0,4).toString()).toBe('RIFF');
      expect(webp.subarray(8,12).toString()).toBe('WEBP');
      const name=source.key+'-'+width+'.webp';
      writeFileSync(join(folder,name),webp);
      await testInfo.attach(name,{body:webp,contentType:'image/webp'});
      evidence.push({
        name,width,height:pngHeight,sha256:createHash('sha256').update(webp).digest('hex')
      });
    }
  }
  const revision=execFileSync('git',['rev-parse','HEAD'],{encoding:'utf8'}).trim();
  writeFileSync(join(folder,'manifest.json'),JSON.stringify({
    version:1,baseline,head:revision,
    provenance:'Real Playwright Chromium screenshot, synthetic source-derived fixture (not production)',
    captures:evidence
  },null,2));
});
