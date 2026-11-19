import {test, expect} from '@playwright/test';
import {readFileSync} from 'node:fs';
import {join} from 'node:path';

const core = readFileSync(join(process.cwd(), 'discadmin/index-core.php'), 'utf8');
const heroCss = readFileSync(join(process.cwd(), 'discadmin/artist-hero-preview.css'), 'utf8');
const heroJs = readFileSync(join(process.cwd(), 'discadmin/artist-hero-preview.js'), 'utf8');
const coreCss = core.split('<style>')[1]?.split('</style>')[0];
const start = core.indexOf('function artistHeroMarkup(');
const end = core.indexOf('function selectedSetArtistIds()');
if (!coreCss || start < 0 || end <= start) throw Error('Artist editor source missing');
const artistFns = core.slice(start, end);
const url = 'http://127.0.0.1:4173/discadmin/artist-scroll-fixture.html';

function html() {
  const helpers = [
    'function esc(s){return String(s ?? "");}',
    'function field(id,label,val){return "<div class=\\"field\\"><label>"+label+"</label><input id=\\"f_"+id+"\\" value=\\""+esc(val)+"\\"></div>";}',
    'function area(id,label,val){return "<div class=\\"field full\\"><label>"+label+"</label><textarea id=\\"f_"+id+"\\">"+esc(val)+"</textarea></div>";}',
    'function select(id,label,val,opts){return "<div class=\\"field\\"><label>"+label+"</label><select id=\\"f_"+id+"\\">"+opts.map(x=>"<option value=\\""+x[0]+"\\">"+x[1]+"</option>").join("")+"</select></div>";}',
    'function checkbox(id,label,checked,help){return "<div class=\\"field full\\"><label><input id=\\"f_"+id+"\\" type=\\"checkbox\\" "+(checked?"checked":"")+">"+label+"</label><p>"+help+"</p></div>";}',
    'function thumb(src){return "<img class=\\"thumb\\" src=\\""+(src||"")+"\\" alt=\\"Foto actual\\">";}'
  ].join('\n');
  return '<!doctype html><html lang="es"><head><meta name="viewport" content="width=device-width,initial-scale=1">'
    + '<style>' + coreCss + heroCss + '</style></head><body>'
    + '<div class="modal open artist-editor-modal" id="modal"><div class="modalbox">'
    + '<div class="modalhead"><h2 id="mtitle" tabindex="-1">EDIT ARTISTS</h2><button>ESC</button></div>'
    + '<div id="notice"></div><div id="mcontent"></div>'
    + '<div class="modalfoot"><span class="helper">Cambios guardados en MySQL.</span>'
    + '<button class="btn red" id="saveBtn">GUARDAR</button></div></div></div>'
    + '<script>' + helpers + artistFns
    + 'artistForm({id:4,name:"Artista de prueba",photo:"/uploads/old.jpg",instagram_url:"https://www.instagram.com/test",website_url:"https://example.test"});'
    + '</script><script>' + heroJs + '</script></body></html>';
}

for (const viewport of [{width:1440,height:900},{width:1280,height:720}]) {
  test('Edit Artist scroll reaches the last control with fixed footer at ' + viewport.width + 'x' + viewport.height, async ({page}) => {
    await page.setViewportSize(viewport);
    await page.route('**/discadmin/artist-scroll-fixture.html', route => route.fulfill({
      contentType:'text/html; charset=utf-8', body:html()
    }));
    await page.goto(url);
    const content = page.locator('#mcontent');
    const footer = page.locator('#modal .modalfoot');
    const finalInput = page.locator('#f_website_url');
    await expect(footer).toBeInViewport();
    await expect(content).toHaveCSS('overflow-y','auto');
    const metrics = await content.evaluate(el => ({
      client:el.clientHeight, scroll:el.scrollHeight
    }));
    expect(metrics.scroll).toBeGreaterThan(metrics.client);
    await content.hover();
    await page.mouse.wheel(0, 6000);
    await expect(finalInput).toBeInViewport();
    await expect(footer).toBeInViewport();
    await finalInput.focus();
    await expect(finalInput).toBeFocused();
    await content.evaluate(el => { el.scrollTop = 0; el.tabIndex = 0; el.focus(); });
    await page.keyboard.press('PageDown');
    await expect.poll(() => content.evaluate(el => el.scrollTop), {timeout:5000}).toBeGreaterThan(0);
    await page.locator('#f_hero_desktop_zoom').evaluate(el => {
      el.value = '1.25'; el.dispatchEvent(new Event('input',{bubbles:true}));
    });
    await expect(page.locator('[data-hero-output="f_hero_desktop_zoom"]')).toContainText('125%');
    await expect(footer).toBeInViewport();
  });
}
