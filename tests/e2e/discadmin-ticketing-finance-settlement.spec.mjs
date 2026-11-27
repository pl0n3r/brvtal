import {test,expect} from '@playwright/test';
import {readFileSync} from 'node:fs';
import {execFileSync} from 'node:child_process';
import {createHash} from 'node:crypto';

const baseline='73b7a302e847db05578e563d62a1e7ac66367a2b';
const origin='http://127.0.0.1:4173';
const endpoint='/api/ticketing/admin-finances.php';
const header='payout_reference,payout_date,line_reference,transaction_reference,gross_cop,fee_cop,fee_vat_cop,withheld_cop,adjustment_cop,credited_cop\n';
const files=[header+'SYNTH-1,2026-11-27,L1,TX-1,10000,100,19,0,0,9881',
  header+'SYNTH-2,2026-11-27,L2,TX-2,10000,100,19,0,0,9800'];
const line={line_reference:'L1',transaction_reference:'TX-1',status:'reconciled',gross_cop:'10000',fee_cop:'100',
  fee_vat_cop:'19',withheld_cop:'0',adjustment_cop:'0',credited_cop:'9881',difference_cop:'0'};
const payouts=[{reference:'SYNTH-1',lines:[line]},
  {reference:'SYNTH-2',lines:[{...line,line_reference:'L2',transaction_reference:'TX-2',status:'discrepancy',credited_cop:'9800',difference_cop:'-81'}]}];
async function mount(page,old,handler) {
  const source=path=>old?execFileSync('git',['show',baseline+':'+path],{encoding:'utf8'}):readFileSync(path,'utf8');
  const php=source('discadmin/ticketing-finance.php');
  const fragment=php.slice(php.indexOf('<section data-finance-module'))
    .replace(/<script type="application\/json" data-finance-catalog>[\s\S]*?<\/script>/,
      '<script type="application/json" data-finance-catalog>{"ok":true,"events":[{"id":1,"title":"Evento sintético"}]}</script>')
    .replace(/data-(ledger|settlement)-csrf="[^"]*"/g,'data-$1-csrf="fixture"');
  await page.unrouteAll({behavior:'wait'});
  await page.route('**/*',route=>route.abort());
  await page.route('**/finance-2199-test.html',route=>route.fulfill({contentType:'text/html',body:
    '<!doctype html><html lang="es"><meta name="viewport" content="width=device-width,initial-scale=1">'
    +'<body style="margin:0;background:#050505;color:#f4f5f6;font:14px system-ui;padding:16px">'
    +'<section data-admin-module="ticketing"><button data-ticketing-tab="finance" aria-selected="true">Finanzas</button><div id="panel"></div></section></body></html>'}));
  await page.route('**/discadmin/ticketing-finance.php',route=>route.fulfill({contentType:'text/html',body:fragment}));
  await page.route('**/discadmin/ticketing-finance.js',route=>route.fulfill({contentType:'text/javascript',body:source('discadmin/ticketing-finance.js')}));
  await page.route('**'+endpoint+'*',handler);
  await page.goto(origin+'/finance-2199-test.html');
  await page.addStyleTag({content:source('discadmin/ticketing.css')});
  await page.evaluate(async()=>{const m=await import('/discadmin/ticketing-finance.js');await m.mount(document.querySelector('#panel'));});
}
async function capture(page,info,stage,width) {
  const png=await page.screenshot({fullPage:true});
  expect(png.readUInt32BE(16)).toBe(width);
  await info.attach(stage+'-'+width,{body:png,contentType:'image/png'});
  // Public synthetic fixture only; compact, recoverable evidence when the canonical
  // workflow does not export arbitrary successful test attachments.
  if(process.env.GITHUB_ACTIONS==='true'&&process.env.GITHUB_HEAD_REF==='work/issue-2199'){
    const sha=execFileSync('git',['rev-parse','HEAD'],{encoding:'utf8'}).trim();
    const chunks=png.toString('base64').match(/.{1,960}/g);
    console.log('SETTLE2199-PNG '+sha+' '+stage+' '+width+' '+createHash('sha256').update(png).digest('hex')+' '+chunks.length);
    chunks.forEach((part,i)=>console.log('SETTLE2199-DATA '+stage+' '+width+' '+i+' '+part));
  }
}
for(const width of [390,1440]){
  test('2199 settlement upload, details and error states '+width,async({page},info)=>{
    await page.setViewportSize({width,height:900});
    let posts=0,mode='ok';
    const handler=async route=>{
      const req=route.request();
      if(!req.url().includes('settlements=1'))return route.fulfill({json:{ok:true,rows:[]}});
      if(req.method()==='POST'){
        expect(req.headers()['x-csrf-token']).toBe('fixture');
        expect(req.headers()['content-type']).toBe('text/csv');
        expect(req.postData()).toBe(files[posts]);
        posts++;
        return route.fulfill({status:201,json:{ok:true,id:String(posts)}});
      }
      if(mode==='error')return route.fulfill({status:503,json:{ok:false}});
      if(mode==='disabled')return route.fulfill({status:423,json:{ok:false}});
      return route.fulfill({json:{ok:true,payouts:payouts.slice(0,posts)}});
    };
    await mount(page,true,handler);
    await expect(page.getByRole('button',{name:'Conciliación',exact:true})).toHaveCount(0);
    await capture(page,info,'before',width);
    await mount(page,false,handler);
    await page.getByRole('button',{name:'Conciliación',exact:true}).click();
    await expect(page.getByText('No hay abonos importados.',{exact:false})).toBeVisible();
    const input=page.getByLabel('Archivo CSV');
    await input.setInputFiles({name:'oversize.csv',mimeType:'text/csv',buffer:Buffer.alloc(1048577,65)});
    await page.getByRole('button',{name:'Importar archivo'}).click();
    await expect(page.getByText('El archivo supera 1 MB.',{exact:true})).toBeVisible();
    expect(posts).toBe(0);
    for (const [index,csv] of files.entries()) {
      await input.setInputFiles({name:'synthetic.csv',mimeType:'text/csv',buffer:Buffer.from(csv)});
      await page.getByRole('button',{name:'Importar archivo'}).click();
      await expect(page.getByText('Reporte importado.',{exact:true})).toBeVisible();
      expect(posts).toBe(index+1);
    }
    await page.getByText('Abono SYNTH-1 · 1 línea · Conciliado',{exact:true}).click();
    await page.getByText('Abono SYNTH-2 · 1 línea · Discrepancia',{exact:true}).click();
    await expect(page.locator('td[data-label="Estado de referencia"]').filter({hasText:/^Conciliado$/})).toBeVisible();
    await expect(page.locator('td[data-label="Estado de referencia"]').filter({hasText:/^Discrepancia$/})).toBeVisible();
    await expect(page.locator('td[data-label="Diferencia global"]')).toHaveText([
      new Intl.NumberFormat('es-CO',{style:'currency',currency:'COP',maximumFractionDigits:0}).format(0),
      new Intl.NumberFormat('es-CO',{style:'currency',currency:'COP',maximumFractionDigits:0}).format(-81)]);
    expect(await page.evaluate(()=>document.documentElement.scrollWidth)).toBeLessThanOrEqual(width);
    await capture(page,info,'after',width);
    for(const [next,message] of [['error','No se pudo completar la consulta.'],['disabled','La conciliación sintética no está habilitada.']]){
      mode=next;
      await page.getByRole('button',{name:'Actualizar abonos'}).click();
      await expect(page.getByText(message,{exact:true})).toBeVisible();
      await expect(page.getByRole('button',{name:'Importar archivo'})).toBeDisabled();
      await expect(page.getByText('Abono SYNTH-1',{exact:false})).toHaveCount(0);
    }
    await page.getByRole('button',{name:'Resumen',exact:true}).click();
    await expect(page.getByRole('button',{name:'Consultar',exact:true})).toBeVisible();
  });
}
