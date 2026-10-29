import {test,expect} from '@playwright/test';
import {readFileSync} from 'node:fs';
import {join} from 'node:path';
const source=readFileSync(join(process.cwd(),'discadmin/index.php'),'utf8');
const css=readFileSync(join(process.cwd(),'discadmin/admin-frecuencia.css'),'utf8');
const appearance=readFileSync(join(process.cwd(),'discadmin/admin-appearance.css'),'utf8');
const dashboard=readFileSync(join(process.cwd(),'discadmin/dashboard-v2.css'),'utf8');
const match=source.match(/<script data-admin-frecuencia-accent="1">([\s\S]*?)<\/script>/);
if(!match)throw new Error('Missing runtime from actual wrapper');
const runtime=match[1];
const url='http://127.0.0.1:4173/discadmin/';
function rgb(value){return (value.match(/[\d.]+/g)||[]).slice(0,3).map(Number)}
function hexRGB(color){const value=color.replace("#","");return [0,2,4].map(n=>parseInt(value.slice(n,n+2),16))}
function luminance(value){const c=rgb(value).map(x=>x/255).map(x=>x<=.04045?x/12.92:((x+.055)/1.055)**2.4);return .2126*c[0]+.7152*c[1]+.0722*c[2]}
function contrast(a,b){const x=luminance(a),y=luminance(b);return (Math.max(x,y)+.05)/(Math.min(x,y)+.05)}
for(const mode of ['dark','light','glass'])for(const [label,accent,ink] of [['bright','#b8ff00','rgb(0, 0, 0)'],['dark','#161616','rgb(255, 255, 255)']]){
  test('DISCADMIN wrapper '+mode+' with saved '+label+' accent',async({page})=>{
    const rows=[{setting_key:'theme.active',setting_value:'live'},
      {setting_key:'theme.live',setting_value:JSON.stringify({colors:{accent}})},
      {setting_key:'theme.draft',setting_value:JSON.stringify({colors:{accent:accent==='#161616'?'#b8ff00':'#161616'}})}];
    const html='<!doctype html><html data-discadmin-appearance="'+mode+'"><head><style>body{margin:0}.side{padding:12px}.side button{padding:12px}</style><style>'+appearance+'</style><style>'+dashboard+'</style><style>'+css+'</style></head><body><aside class="side"><button data-admin-nav="dashboard" class="active">DASHBOARD</button></aside><main><div id="brvtal-dashboard-v2"><div class="dashboard-v2-hero">OVERVIEW</div><div class="dashboard-v2-summary-card">SUMMARY</div><div class="sv2-hero" id="neighbor">SETTINGS</div></div></main><script>window.state={authed:true,section:"dashboard"};window.req=async function(path){if(!path.startsWith("/settings?key="))throw Error("Unexpected API");const key=decodeURIComponent(path.slice("/settings?key=".length));return {data:'+JSON.stringify(rows)+'.filter(x=>x.setting_key===key)}};</script><script>'+runtime+'</script><script>window.BRVTALFrecuenciaAccent.sync();</script></body></html>';
    await page.route(url,route=>route.fulfill({contentType:'text/html',body:html}));
    await page.goto(url);
    const target=page.locator('[data-admin-nav="dashboard"]');
    await expect.poll(()=>target.evaluate(x=>getComputedStyle(x).backgroundColor)).toBe('rgb('+hexRGB(accent).join(', ')+')');
    const computed=await target.evaluate(x=>({bg:getComputedStyle(x).backgroundColor,ink:getComputedStyle(x).color}));
    expect(computed.ink).toBe(ink);
    expect(contrast(computed.bg,computed.ink)).toBeGreaterThanOrEqual(4.5);
    await expect(page.locator('.dashboard-v2-summary-card')).toHaveCSS('box-shadow','none');
    await expect(page.locator('.dashboard-v2-summary-card')).toHaveCSS('border-top-width','2px');
    await expect(page.locator('#neighbor')).not.toHaveCSS('border-top-width','2px');
  });
}
test('wrapper consumes saved active instead of unsaved Theme Studio draft',async()=>{
  expect(source).toContain('theme.active');
  expect(source).toContain('theme?.colors?.accent');
  expect(source).toContain('restoreSession().then(');
});

const publicCss=readFileSync(join(process.cwd(),'css/public-brvtal-sections.css'),'utf8');
for(const accent of ['#b8ff00','#161616']){
  test('public experience label chooses readable ink for '+accent,async({page})=>{
    const address='http://127.0.0.1:4173/public-frecuencia-contrast.html';
    const html='<!doctype html><html><head><style>:root{--c5-signal-green:'+accent+'}</style><style>'+publicCss+'</style></head><body data-concept="05"><section class="c5-experience-authored"><span class="genesis-tag" id="public-label">EXPERIENCE</span></section></body></html>';
    await page.route(address,route=>route.fulfill({contentType:'text/html',body:html}));
    await page.goto(address);
    const {ink,bg}=await page.locator('#public-label').evaluate(el=>({ink:getComputedStyle(el).color,bg:getComputedStyle(el).backgroundColor}));
    expect(contrast(bg,ink)).toBeGreaterThanOrEqual(4.5);
  });
}
