import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const defaultsJs = readFileSync(join(process.cwd(), 'discadmin/seo-editorial-defaults.js'), 'utf8');
const eventWorkflowSeoJs = readFileSync(join(process.cwd(), 'discadmin/event-workflow-seo.js'), 'utf8');
const harnessUrl = 'http://127.0.0.1:4173/discadmin/seo-defaults-e2e.html';

async function loadHarness(page, body = '', scripts = defaultsJs) {
  await page.route(harnessUrl, route => route.fulfill({
    contentType:'text/html; charset=utf-8',
    body:`<!doctype html><html><head><meta charset="utf-8"></head><body>${body}<script>${scripts}</script></body></html>`
  }));
  await page.goto(harnessUrl);
  await page.waitForFunction(() => Boolean(window.BRVTALSEODefaults));
}

test('Blog save keeps empty SEO overrides empty so public fallbacks remain dynamic', async ({ page }) => {
  let saved = null;
  await page.route('**/api/blog.php', async route => {
    saved = route.request().postDataJSON();
    await route.fulfill({ contentType:'application/json', body:JSON.stringify({ok:true,data:{id:1}}) });
  });
  await loadHarness(page);

  await page.evaluate(async () => {
    await fetch('/api/blog.php', {
      method:'POST',
      headers:{'Content-Type':'application/json'},
      body:JSON.stringify({
        title:'BRVTAL Journal 001',
        excerpt:'Underground techno from Pereira.',
        seo_title:'',
        seo_description:''
      })
    });
  });

  await expect.poll(() => saved).not.toBeNull();
  expect(saved.seo_title).toBe('');
  expect(saved.seo_description).toBe('');
});

test('manual Blog SEO metadata passes through untouched', async ({ page }) => {
  let saved = null;
  await page.route('**/api/blog.php?id=4', async route => {
    saved = route.request().postDataJSON();
    await route.fulfill({ contentType:'application/json', body:JSON.stringify({ok:true,data:{id:4}}) });
  });
  await loadHarness(page);

  await page.evaluate(async () => {
    await fetch('/api/blog.php?id=4', {
      method:'PUT',
      headers:{'Content-Type':'application/json'},
      body:JSON.stringify({
        title:'Editorial title',
        excerpt:'Editorial excerpt',
        seo_title:'Custom search title',
        seo_description:'Custom search description'
      })
    });
  });

  await expect.poll(() => saved).not.toBeNull();
  expect(saved.seo_title).toBe('Custom search title');
  expect(saved.seo_description).toBe('Custom search description');
});

test('Page save keeps empty SEO overrides empty instead of materializing JSON-derived fallbacks', async ({ page }) => {
  let saved = null;
  await page.route('**/api/index.php/pages', async route => {
    saved = route.request().postDataJSON();
    await route.fulfill({ contentType:'application/json', body:JSON.stringify({ok:true,id:9}) });
  });
  await loadHarness(page);

  await page.evaluate(async () => {
    await fetch('/api/index.php/pages', {
      method:'POST',
      headers:{'Content-Type':'application/json'},
      body:JSON.stringify({
        title:'About BRVTAL',
        content_json:JSON.stringify({
          type:'page',
          heading:'BRVTAL is music',
          sections:[{type:'text',body:'Underground electronic culture from Colombia.'}]
        }),
        seo_title:'',
        seo_description:''
      })
    });
  });

  await expect.poll(() => saved).not.toBeNull();
  expect(saved.seo_title).toBe('');
  expect(saved.seo_description).toBe('');
});

test('automatic editor metadata stays blank while its preview fallback tracks editorial changes', async ({ page }) => {
  await loadHarness(page, `
    <input id="e_title" value="Genesis">
    <textarea id="e_description">Underground techno in Pereira.</textarea>
    <section data-seo-editor="content-core">
      <input id="e_seo_title" value="">
      <textarea id="e_seo_description"></textarea>
    </section>
  `);

  await expect(page.locator('#e_seo_title')).toHaveValue('');
  await expect(page.locator('#e_seo_title')).toHaveAttribute('data-seo-mode','auto');
  await expect(page.locator('#e_seo_title')).toHaveAttribute('placeholder','Genesis');
  await expect(page.locator('#e_seo_description')).toHaveValue('');
  await expect(page.locator('#e_seo_description')).toHaveAttribute('placeholder','Underground techno in Pereira.');

  expect(await page.evaluate(() => BRVTALSEODefaults.persistableValue(document.getElementById('e_seo_title')))).toBe('');

  await page.locator('#e_title').fill('Genesis II');
  await expect(page.locator('#e_seo_title')).toHaveValue('');
  await expect(page.locator('#e_seo_title')).toHaveAttribute('placeholder','Genesis II');

  await page.locator('#e_seo_title').fill('Manual Genesis search title');
  await expect(page.locator('#e_seo_title')).toHaveAttribute('data-seo-mode','manual');
  expect(await page.evaluate(() => BRVTALSEODefaults.persistableValue(document.getElementById('e_seo_title')))).toBe('Manual Genesis search title');

  await page.locator('#e_seo_title').fill('');
  await expect(page.locator('#e_seo_title')).toHaveAttribute('data-seo-mode','auto');
  await expect(page.locator('#e_seo_title')).toHaveValue('');
  await expect(page.locator('#e_seo_title')).toHaveAttribute('placeholder','Genesis II');
  expect(await page.evaluate(() => BRVTALSEODefaults.persistableValue(document.getElementById('e_seo_title')))).toBe('');
});

test('Content Core Event workflow persists absence for automatic SEO and explicit manual overrides only', async ({ page }) => {
  const requests = [];
  await page.route('**/api/event-workflow.php', async route => {
    requests.push(route.request().postDataJSON());
    await route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,data:{event:{id:42}}})});
  });

  await loadHarness(page, `
    <input id="e_title" value="Genesis">
    <textarea id="e_description">Underground techno in Pereira.</textarea>
    <section data-seo-editor="content-core">
      <input id="e_seo_title" value="">
      <textarea id="e_seo_description"></textarea>
    </section>
  `, defaultsJs + '\n' + eventWorkflowSeoJs);

  await expect(page.locator('#e_seo_title')).toHaveAttribute('data-seo-mode','auto');
  await page.evaluate(() => fetch('/api/event-workflow.php',{
    method:'POST',
    headers:{'Content-Type':'application/json'},
    body:JSON.stringify({event:{title:'Genesis'},ticket_types:[],lineup:[]})
  }));
  expect(requests[0].event.seo_title).toBe('');
  expect(requests[0].event.seo_description).toBe('');

  await page.locator('#e_seo_title').fill('Genesis manual SEO');
  await page.evaluate(() => fetch('/api/event-workflow.php',{
    method:'POST',
    headers:{'Content-Type':'application/json'},
    body:JSON.stringify({event:{title:'Genesis'},ticket_types:[],lineup:[]})
  }));
  expect(requests[1].event.seo_title).toBe('Genesis manual SEO');
  expect(requests[1].event.seo_description).toBe('');
});

test('SEO truncation stays bounded on long words and strips a trailing punctuation run', async ({ page }) => {
  await loadHarness(page);

  const values = await page.evaluate(() => {
    const longWordBoundary = BRVTALSEODefaults.truncate(`${'A'.repeat(110)} ${'B'.repeat(120)}`, 160);
    const punctuationTail = BRVTALSEODefaults.truncate(`${'alpha '.repeat(20)}signal----- ${'omega '.repeat(20)}`, 80);
    return { longWordBoundary, punctuationTail };
  });

  expect(values.longWordBoundary).toBe('A'.repeat(110));
  expect(values.punctuationTail.length).toBeLessThanOrEqual(80);
  expect(' ,.;:-'.includes(values.punctuationTail.at(-1) || '')).toBe(false);
});
