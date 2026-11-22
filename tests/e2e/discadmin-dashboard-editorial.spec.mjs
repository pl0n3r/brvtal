import {test, expect} from '@playwright/test';
import {readFileSync, mkdirSync} from 'node:fs';
import {join} from 'node:path';

const currentJs=readFileSync(join(process.cwd(),'discadmin/dashboard-v2.js'),'utf8');
const currentCss=readFileSync(join(process.cwd(),'discadmin/dashboard-v2.css'),'utf8');
const baseline='9b3a0421f247d8e3eb9038a1721be44d7165dbdf';
const harness='http://127.0.0.1:4173/discadmin/editorial-dashboard-e2e.html';
const proofDir='test-results/public-concept05-fidelity-dashboard-1863';

const fixtures={
  '/api/dashboard-overview.php':{ok:true,data:{summary:{public_records:3,draft_records:2,active_events:1,media_assets:4},next_event:{id:42,title:'Noche de prueba',event_date:'2099-01-01',status:'published',venue:'Sala sintética',city:'Pereira',cover_image:'',ticket_url:''}}},
  '/api/content-health.php':{ok:true,data:{public:{score:74,items:[]},drafts:{total:2,ready:1,needs_attention:1,items:[]}}},
  '/api/index.php/health':{ok:true,database:'connected',php:'8.5',driver:'mysql'},
  '/discadmin/storage-metrics.php':{ok:true,data:{used:'1 MB',quota:'1 GB',managed_files:2,used_percent:1}},
  '/api/admin-activity.php':{ok:true,data:{items:[],total:0,has_more:false}},
  '/api/admin-dashboard-preferences.php':{ok:true,data:{modules:[]}},
  '/api/ticketing/admin-finances.php':{ok:true,rows:[{event_id:42,paid_orders:2,tickets:3,approved_cop:65000}],currency:'COP'},
  '/api/index.php/events/42/lineup':{ok:true,data:[{artist_id:1,name:'Artista sintético'}]},
  '/api/admin-development-signals.php':{ok:true,data:{open_issues:4,open_prs:2,latest_ci:{conclusion:'failure'},freshness:'fresh',read_only:true}},
  '/api/admin-sonar-signals.php':{ok:true,data:{quality_gate:'ERROR',new_issues:2332}},
  '/api/admin-coderabbit-signals.php':{ok:true,data:{review_state:'PENDING'}}
};

async function mount(page,js=currentJs,css=currentCss) {
  let developmentRequests=0;
  await page.route(harness,route=>route.fulfill({
    contentType:'text/html; charset=utf-8',
    body:'<!doctype html><html lang="es"><head><meta name="viewport" content="width=device-width,initial-scale=1"><style>body{margin:0;background:#050505;color:#fff;font-family:Arial}.main{max-width:1250px;margin:auto;padding:22px}.top{border-bottom:1px solid #333;padding-bottom:10px}</style><style>'+css+'</style></head><body><main class="main"><div class="top"><h1>Dashboard</h1><span class="status"></span></div></main>'
      +'<script>var state={authed:true,section:"dashboard",role:"editor"};window.go=function(){};window.tech=function(){window.__technicalOpened=true};</script>'
      +'<script>'+js+'</script></body></html>'
  }));
  await page.route('**/api/**',route=>{
    const path=new URL(route.request().url()).pathname;
    if (path.includes('development-signals')||path.includes('sonar-signals')||path.includes('coderabbit-signals')) developmentRequests++;
    const data=fixtures[path]||{ok:false,error:'UNAVAILABLE'};
    return route.fulfill({status:data.ok===false?503:200,contentType:'application/json',body:JSON.stringify(data)});
  });
  await page.route('**/discadmin/storage-metrics.php*',route=>route.fulfill({contentType:'application/json',body:JSON.stringify(fixtures['/discadmin/storage-metrics.php'])}));
  await page.goto(harness);
  await expect(page.locator('#brvtal-dashboard-v2 .dashboard-v2-hero')).toBeVisible();
  return ()=>developmentRequests;
}

test('El dashboard editorial oculta telemetría y los controles hasta Personalizar',async({page})=>{
  const count=await mount(page);
  const root=page.locator('#brvtal-dashboard-v2');
  await expect(root).not.toContainText(/SONAR NEW ISSUES|CODERABBIT|LATEST CI|PROD SHA/);
  await expect(root.locator('[data-dashboard-module="development"]')).toContainText('Sistema');
  await expect(root.locator('.dashboard-v2-module-controls').first()).toBeHidden();
  await expect(root.getByRole('button',{name:'Personalizar'})).toBeVisible();
  await expect(root.locator('[data-dashboard-lineup]')).toContainText('1 artista asignado');
  await expect(root.locator('[data-dashboard-sales]')).toContainText('65.000');
  await expect(root.locator('[data-dashboard-sales]')).toContainText('3');
  expect(count()).toBe(0);
  await root.getByRole('button',{name:'Personalizar'}).click();
  await expect(root.locator('.dashboard-v2-module-controls').first()).toBeVisible();
  await expect(root.getByRole('button',{name:'Terminar personalización'})).toHaveAttribute('aria-expanded','true');
  // AC-01: test real geometry while edit handles are VISIBLE, not after hiding.
  for(const width of [390,1440]){
    await page.setViewportSize({width,height:900});
    await expect(root.locator('.dashboard-v2-module-controls').first()).toBeVisible();
    const overlaps=await root.locator('.dashboard-v2-module').evaluateAll(nodes=>nodes.some(module=>{
      const title=module.querySelector('h2');
      const controls=module.querySelector('.dashboard-v2-module-controls');
      if(!title||!controls)return false;
      const t=title.getBoundingClientRect(),c=controls.getBoundingClientRect();
      return c.width>0&&c.height>0&&t.left<c.right&&t.right>c.left&&t.top<c.bottom&&t.bottom>c.top;
    }));
    expect(overlaps, 'no module title overlap with editing controls at '+width+'px').toBe(false);
  }
  await root.getByRole('button',{name:'Terminar personalización'}).click();
  await expect(root.locator('.dashboard-v2-module-controls').first()).toBeHidden();
});

test('La fuente de ventas restringida no fabrica ceros ni datos personales',async({page})=>{
  const original=fixtures['/api/ticketing/admin-finances.php'];
  const originalLineup=fixtures['/api/index.php/events/42/lineup'];
  fixtures['/api/ticketing/admin-finances.php']={ok:false,error:'FORBIDDEN'};
  fixtures['/api/index.php/events/42/lineup']={ok:true,data:[]};
  try{
    await mount(page);
    await expect(page.locator('[data-dashboard-sales]')).toContainText('requiere permiso de finanzas');
    await expect(page.locator('[data-dashboard-sales]')).not.toContainText('0 COP');
    await expect(page.locator('[data-dashboard-lineup]')).toContainText('Falta lineup');
  }finally{
    fixtures['/api/ticketing/admin-finances.php']=original;
    fixtures['/api/index.php/events/42/lineup']=originalLineup;
  }
});

test('Evidencia visual sintética antes/después a 390 y 1440 px',async({page,request})=>{
  const source=async path=>{
    const url='https://raw.githubusercontent.com/pl0n3r/brvtal/'+baseline+'/'+path;
    const response=await request.get(url,{timeout:20000});
    expect(response.ok(),url).toBe(true);
    return response.text();
  };
  const [oldJs,oldCss]=await Promise.all([
    source('discadmin/dashboard-v2.js'),source('discadmin/dashboard-v2.css')
  ]);
  mkdirSync(proofDir,{recursive:true});
  for(const phase of ['before','after']){
    for(const width of [390,1440]){
      await page.setViewportSize({width,height:900});
      await mount(page,phase==='before'?oldJs:currentJs,phase==='before'?oldCss:currentCss);
      await page.screenshot({path:join(proofDir,phase+'-'+width+'.png'),fullPage:true,animations:'disabled'});
      if(phase==='after'){
        await expect(page.locator('.dashboard-v2-module-controls').first()).toBeHidden();
        const clipped=await page.locator('#brvtal-dashboard-v2').evaluate(root=>root.scrollWidth>root.clientWidth+1);
        expect(clipped).toBe(false);
      }
    }
  }
});
