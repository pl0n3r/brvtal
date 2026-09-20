import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const gridJs = readFileSync(join(process.cwd(),'discadmin/admin-data-grid.js'),'utf8');
const gridCss = readFileSync(join(process.cwd(),'discadmin/admin-data-grid.css'),'utf8');

const defaults = {
  events:['primary','date','location','status'],
  artists:['primary','links','status','position'],
  releases:['primary','artists','type','date','status','position'],
  sets:['primary','artist','event','status','position'],
  media:['primary','type','mime','size','status'],
  pages:['primary','locale','status'],
  blog:['primary','excerpt','published','status','position'],
};

async function harness(page) {
  await page.setContent(`<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><style>${gridCss}</style></head><body>
    <div id="events"></div><div id="releases"></div><div id="blog"></div>
  </body></html>`);
  await page.evaluate(values => {
    window.csrf='grid-csrf';
    window.__gridPreferenceWrites=[];
    window.__bulkOpen=null;
    window.BRVTALFeedback={error(){},success(){}};
    window.BRVTALBulkActions={
      supports:module=>module!=='media',
      open:(module,ids)=>{window.__bulkOpen={module,ids};}
    };
    window.__gridDefaults=values;
    window.fetch=async(url,options={})=>{
      const target=new URL(String(url),'https://brvtal.test');
      if(target.pathname.endsWith('/api/admin-grid-preferences.php')) {
        const module=target.searchParams.get('module');
        if(String(options.method||'GET').toUpperCase()==='POST') {
          const body=JSON.parse(options.body||'{}');
          window.__gridPreferenceWrites.push({module,body,csrf:options.headers?.['X-CSRF-Token']||''});
          return new Response(JSON.stringify({ok:true,data:body}),{status:200,headers:{'Content-Type':'application/json'}});
        }
        return new Response(JSON.stringify({ok:true,data:{columns:values[module]}}),{status:200,headers:{'Content-Type':'application/json'}});
      }
      if(target.pathname.endsWith('/api/index.php/auth')) {
        return new Response(JSON.stringify({authenticated:true,csrf:'grid-csrf'}),{status:200,headers:{'Content-Type':'application/json'}});
      }
      return new Response('{}',{status:404});
    };
  }, defaults);
  await page.evaluate(source => window.eval(source),gridJs);
}

const events = [
  {id:9,title:'Zulu',slug:'zulu',event_date:'2026-09-20 22:00:00',city:'Pereira',status:'draft'},
  {id:3,title:'Alpha',slug:'alpha-3',event_date:'2026-09-19 22:00:00',city:'Bogota',status:'published'},
  {id:1,title:'Alpha',slug:'alpha-1',event_date:'2026-09-18 22:00:00',city:'Cali',status:'published'},
];

test('tri-state sort is deterministic and returns to module default', async ({page}) => {
  await harness(page);
  await page.evaluate(rows => BRVTALDataGrid.render('events',document.getElementById('events'),rows,{allRows:rows}),events);
  const ids=()=>page.locator('#events [data-grid-row-id]').evaluateAll(nodes=>nodes.map(n=>Number(n.dataset.gridRowId)));
  await expect.poll(ids).toEqual([9,3,1]);

  const header=page.locator('#events [data-grid-sort="primary"]');
  await header.press('Enter');
  await expect.poll(ids).toEqual([1,3,9]);
  await header.press('Enter');
  await expect.poll(ids).toEqual([9,1,3]);
  await header.press('Enter');
  await expect.poll(ids).toEqual([9,3,1]);
  await expect(header).toBeFocused();
});

test('column chooser persists only the current module and restores defaults', async ({page}) => {
  await harness(page);
  await page.evaluate(rows => BRVTALDataGrid.render('events',document.getElementById('events'),rows,{allRows:rows}),events);
  const chooser = page.locator('#events [data-grid-columns-toggle]');
  await chooser.click();
  await expect(chooser).toHaveAttribute('aria-expanded','true');
  await page.locator('#events [data-grid-column="location"]').uncheck();
  await expect.poll(()=>page.evaluate(()=>window.__gridPreferenceWrites.length)).toBe(1);
  const write=await page.evaluate(()=>window.__gridPreferenceWrites[0]);
  expect(write.module).toBe('events');
  expect(write.body.columns).not.toContain('location');
  expect(write.csrf).toBe('grid-csrf');
  await expect(page.locator('#events [data-grid-cell="location"]')).toHaveCount(0);

  await page.locator('#events [data-grid-columns-reset]').click();
  await expect.poll(()=>page.evaluate(()=>window.__gridPreferenceWrites.length)).toBe(2);
  expect((await page.evaluate(()=>window.__gridPreferenceWrites[1].body.columns))).toEqual(defaults.events);
});

test('row selection supports select-all, clear and safe bulk-action handoff', async ({page}) => {
  await harness(page);
  await page.evaluate(rows => BRVTALDataGrid.render('events',document.getElementById('events'),rows,{allRows:rows}),events);
  await page.locator('#events [data-grid-select-all]').check();
  await expect(page.locator('#events .admin-grid-selection-bar')).toContainText('3 SELECTED');
  await page.locator('#events [data-grid-bulk]').click();
  expect(await page.evaluate(()=>window.__bulkOpen)).toEqual({module:'events',ids:[9,3,1]});
  await page.locator('#events [data-grid-clear]').click();
  await expect(page.locator('#events .admin-grid-selection-bar')).toBeHidden();
});

test('Releases and Blog use the same canonical table structure', async ({page}) => {
  await harness(page);
  const release=[{id:4,title:'Industrial Signal',catalog_number:'BRVTAL001',release_type:'single',release_date:'2026-09-11',status:'published',sort_order:0,artists:[{name:'PL0N3R'}]}];
  const blog=[{id:7,title:'Journal 001',slug:'journal-001',excerpt:'Editorial',published_at:'2026-09-20 12:00:00',status:'published',sort_order:0}];
  await page.evaluate(({release,blog})=>{
    BRVTALDataGrid.render('releases',document.getElementById('releases'),release,{allRows:release,orderingEnabled:true});
    BRVTALDataGrid.render('blog',document.getElementById('blog'),blog,{allRows:blog,orderingEnabled:true});
  },{release,blog});
  await expect(page.locator('#releases [role="table"]')).toBeVisible();
  await expect(page.locator('#blog [role="table"]')).toBeVisible();
  await expect(page.locator('#releases [data-grid-cell="artists"]')).toContainText('PL0N3R');
  await expect(page.locator('#blog [data-grid-cell="excerpt"]')).toContainText('Editorial');
});

test('mobile keeps the canonical grid usable through horizontal scrolling', async ({page}) => {
  await page.setViewportSize({width:390,height:844});
  await harness(page);
  await page.evaluate(rows => BRVTALDataGrid.render('events',document.getElementById('events'),rows,{allRows:rows}),events);
  const dimensions=await page.locator('#events .admin-data-grid-scroll').evaluate(el=>({
    client:el.clientWidth,scroll:el.scrollWidth
  }));
  expect(dimensions.scroll).toBeGreaterThan(dimensions.client);
  await expect(page.locator('#events [data-grid-columns-toggle]')).toBeVisible();
});
