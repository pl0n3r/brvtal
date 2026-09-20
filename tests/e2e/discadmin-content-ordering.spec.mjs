import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const orderingJs = readFileSync(join(process.cwd(),'discadmin/content-ordering.js'),'utf8');
const orderingCss = readFileSync(join(process.cwd(),'discadmin/content-ordering.css'),'utf8');

async function harness(page) {
  await page.setContent('<!doctype html><html><head><style>' + orderingCss + '</style></head><body>' +
    '<div class="table"><div id="rows" data-order-resource="artists" data-order-enabled="1">' +
    '<div class="tr" data-order-id="1"><div class="title">One</div><div data-order-position></div></div>' +
    '<div class="tr" data-order-id="2"><div class="title">Two</div><div data-order-position></div></div>' +
    '<div class="tr" data-order-id="3"><div class="title">Three</div><div data-order-position></div></div>' +
    '</div></div></body></html>');
  await page.evaluate(() => {
    window.__orders=[]; window.__orderEvents=[]; window.__failOrder=false;
    window.BRVTALFeedback={success(){},error(){}};
    window.addEventListener('brvtal:content-order-changed',event=>window.__orderEvents.push(event.detail));
    window.fetch=async(url,options={})=>{
      if(String(url).includes('/auth')) return new Response(JSON.stringify({authenticated:true,csrf:'csrf-test'}),{status:200,headers:{'Content-Type':'application/json'}});
      if(String(url).includes('/api/reorder.php')) {
        const body=JSON.parse(options.body||'{}'); window.__orders.push({body,headers:options.headers});
        if(window.__failOrder) return new Response(JSON.stringify({ok:false,error:'ORDER_SAVE_FAILED'}),{status:500,headers:{'Content-Type':'application/json'}});
        return new Response(JSON.stringify({ok:true,resource:body.resource,ids:body.ids}),{status:200,headers:{'Content-Type':'application/json'}});
      }
      return new Response('{}',{status:404});
    };
  });
  await page.evaluate(source => {
    const script = document.createElement('script');
    script.textContent = source;
    document.head.appendChild(script);
  }, orderingJs);
  await expect(page.locator('.content-order-handle')).toHaveCount(3);
}

test('keyboard reorder saves canonical IDs and updates positions', async ({page}) => {
  await harness(page);
  const first=page.locator('[data-order-id="1"] .content-order-handle');
  await first.focus(); await first.press('ArrowDown');
  await expect.poll(()=>page.evaluate(()=>window.__orders.length)).toBe(1);
  expect(await page.locator('#rows > [data-order-id]').evaluateAll(nodes=>nodes.map(node=>Number(node.dataset.orderId)))).toEqual([2,1,3]);
  expect(await page.locator('[data-order-position]').allTextContents()).toEqual(['01','02','03']);
  expect(await page.evaluate(()=>window.__orders[0].body)).toEqual({resource:'artists',ids:[2,1,3],previous_ids:[1,2,3]});
  expect(await page.evaluate(()=>window.__orderEvents[0])).toEqual({resource:'artists',ids:[2,1,3]});
});

test('touch-style Pointer Events reorder through the shared drag handle', async ({page}) => {
  await harness(page);
  await page.evaluate(async () => {
    const handle = document.querySelector('[data-order-id="1"] .content-order-handle');
    const target = document.querySelector('[data-order-id="3"]');
    const original = document.elementFromPoint.bind(document);
    document.elementFromPoint = () => target;
    handle.dispatchEvent(new PointerEvent('pointerdown', {
      bubbles:true, pointerId:17, pointerType:'touch', button:0, clientX:10, clientY:10
    }));
    document.dispatchEvent(new PointerEvent('pointermove', {
      bubbles:true, pointerId:17, pointerType:'touch', button:0, clientX:10, clientY:999
    }));
    document.dispatchEvent(new PointerEvent('pointerup', {
      bubbles:true, pointerId:17, pointerType:'touch', button:0, clientX:10, clientY:999
    }));
    document.elementFromPoint = original;
  });

  await expect.poll(() => page.evaluate(() => window.__orders.length)).toBe(1);
  expect(await page.locator('#rows > [data-order-id]').evaluateAll(nodes => nodes.map(node => Number(node.dataset.orderId)))).toEqual([2,3,1]);
  expect(await page.evaluate(() => window.__orders[0].body)).toEqual({resource:'artists',ids:[2,3,1],previous_ids:[1,2,3]});
});

test('failed reorder restores the exact previous DOM order', async ({page}) => {
  await harness(page); await page.evaluate(()=>{window.__failOrder=true});
  const second=page.locator('[data-order-id="2"] .content-order-handle');
  await second.focus(); await second.press('ArrowDown');
  await expect.poll(()=>page.evaluate(()=>window.__orders.length)).toBe(1);
  await expect.poll(()=>page.locator('#rows > [data-order-id]').evaluateAll(nodes=>nodes.map(node=>Number(node.dataset.orderId)))).toEqual([1,2,3]);
  await expect(page.locator('.content-order-status')).toContainText('Could not save order');
  expect(await page.evaluate(()=>window.__orderEvents.length)).toBe(0);
});

test('filters disable ordering rather than persisting a partial collection', async ({page}) => {
  await harness(page);
  await page.evaluate(()=>{const rows=document.getElementById('rows');rows.dataset.orderEnabled='0';window.BRVTALContentOrdering.refresh(rows)});
  await expect(page.locator('.content-order-handle')).toBeHidden();
  await expect(page.locator('.content-order-help')).toContainText('Clear search/filter');
  expect(await page.evaluate(()=>window.__orders.length)).toBe(0);
});

test('applyOrder keeps module stores aligned with the persisted order', async ({page}) => {
  await harness(page);
  const ordered=await page.evaluate(()=>window.BRVTALContentOrdering.applyOrder([{id:1,sort_order:9},{id:2,sort_order:8},{id:3,sort_order:7}],[3,1,2]));
  expect(ordered.map(record=>[record.id,record.sort_order])).toEqual([[3,0],[1,1],[2,2]]);
});
