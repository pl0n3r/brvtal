import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const gridJs = readFileSync(join(process.cwd(),'discadmin/admin-data-grid.js'),'utf8');
const gridCss = readFileSync(join(process.cwd(),'discadmin/admin-data-grid.css'),'utf8');

const defaults = {
  events:['primary','date','location','status'],
  artists:['primary','links','collective','status','position'],
  releases:['primary','artists','type','date','status','position'],
  sets:['primary','artist','event','status','position'],
  media:['primary','type','mime','size','status'],
  pages:['primary','locale','status'],
  blog:['primary','excerpt','published','status','position'],
};

async function harness(page) {
  await page.setContent(`<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><style>${gridCss}</style></head><body>
    <div id="events"></div><div id="artists"></div><div id="releases"></div><div id="blog"></div><div id="media"></div>
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

test('Artist membership filter uses the canonical checkbox without duplicating state', async ({page}) => {
  await harness(page);
  const artists=[
    {id:1,name:'PL0N3R',slug:'pl0n3r',is_collective_member:1,status:'published',sort_order:0},
    {id:2,name:'GUEST',slug:'guest',is_collective_member:0,status:'published',sort_order:1},
  ];
  await page.evaluate(rows => BRVTALDataGrid.render('artists',document.getElementById('artists'),rows,{allRows:rows}),artists);
  await expect(page.locator('#artists [data-grid-cell="collective"]')).toHaveText(['MEMBER','EXTERNAL']);
  const filter=page.locator('#artists [data-grid-membership-filter]');
  await filter.selectOption('member');
  await expect(page.locator('#artists [data-grid-row-id]')).toHaveCount(1);
  await expect(page.locator('#artists [data-grid-row-id="1"]')).toBeVisible();
  await filter.selectOption('external');
  await expect(page.locator('#artists [data-grid-row-id]')).toHaveCount(1);
  await expect(page.locator('#artists [data-grid-row-id="2"]')).toBeVisible();
  await filter.selectOption('all');
  await expect(page.locator('#artists [data-grid-row-id]')).toHaveCount(2);
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


test('selection and column redraws restore keyboard focus', async ({page}) => {
  await harness(page);
  await page.evaluate(rows => BRVTALDataGrid.render('events',document.getElementById('events'),rows,{allRows:rows}),events);

  const rowToggle=page.locator('#events [data-grid-select="9"]');
  await rowToggle.focus();
  await rowToggle.press('Space');
  await expect(page.locator('#events [data-grid-select="9"]')).toBeFocused();

  const chooser=page.locator('#events [data-grid-columns-toggle]');
  await chooser.click();
  const location=page.locator('#events [data-grid-column="location"]');
  await location.focus();
  await location.press('Space');
  await expect(page.locator('#events [data-grid-column="location"]')).toBeFocused();
});

test('non-image media uses the fallback instead of requesting the document path as an image', async ({page}) => {
  await harness(page);
  const media=[{id:12,title:'Press Kit',file_path:'/uploads/press-kit.pdf',type:'document',mime_type:'application/pdf',file_size:2048,status:'published'}];
  await page.evaluate(rows => BRVTALDataGrid.render('media',document.getElementById('media'),rows,{allRows:rows}),media);
  await expect(page.locator('#media .admin-grid-thumb-ph')).toBeVisible();
  await expect(page.locator('#media .admin-grid-primary small')).toContainText('DOCUMENT');
  await expect(page.locator('#media img[src*="press-kit.pdf"]')).toHaveCount(0);
});


test('server pagination exposes total/page state and delegates page changes', async ({page}) => {
  await harness(page);
  await page.evaluate(rows => {
    window.__requestedPage = null;
    BRVTALDataGrid.render('events',document.getElementById('events'),rows,{
      allRows:rows,
      pagination:{page:2,page_size:50,total:123,pages:3,has_previous:true,has_next:true},
      onPageChange:page => { window.__requestedPage = page; },
      orderingEnabled:false
    });
  },events);

  await expect(page.locator('#events .admin-grid-result-count')).toContainText('123 TOTAL');
  await expect(page.locator('#events .admin-grid-pagination')).toContainText('PAGE 2 / 3');
  await page.locator('#events [data-grid-page-next]').click();
  await expect.poll(() => page.evaluate(() => window.__requestedPage)).toBe(3);
  await page.evaluate(() => { window.__requestedPage = null; });
  await page.locator('#events [data-grid-page-prev]').click();
  await expect.poll(() => page.evaluate(() => window.__requestedPage)).toBe(1);
});
