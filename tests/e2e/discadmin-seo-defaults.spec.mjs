import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const defaultsJs = readFileSync(join(process.cwd(), 'discadmin/seo-editorial-defaults.js'), 'utf8');
const harnessUrl = 'http://127.0.0.1:4173/discadmin/seo-defaults-e2e.html';

async function loadHarness(page) {
  await page.route(harnessUrl, route => route.fulfill({
    contentType:'text/html; charset=utf-8',
    body:`<!doctype html><html><head><meta charset="utf-8"></head><body><script>${defaultsJs}</script></body></html>`
  }));
  await page.goto(harnessUrl);
  await page.waitForFunction(() => Boolean(window.BRVTALSEODefaults));
}

test('Blog save derives SEO title and a clean capped description from editorial content', async ({ page }) => {
  let saved = null;
  await page.route('**/api/blog.php', async route => {
    saved = route.request().postDataJSON();
    await route.fulfill({ contentType:'application/json', body:JSON.stringify({ok:true,data:{id:1}}) });
  });
  await loadHarness(page);

  const longExcerpt = '<p>' + 'Underground techno from Pereira and the BRVTAL community. '.repeat(8) + '</p>';
  await page.evaluate(async ({ longExcerpt }) => {
    await fetch('/api/blog.php', {
      method:'POST',
      headers:{'Content-Type':'application/json'},
      body:JSON.stringify({
        title:'BRVTAL Journal 001',
        excerpt:longExcerpt,
        body:'Long body fallback',
        seo_title:'',
        seo_description:''
      })
    });
  }, { longExcerpt });

  await expect.poll(() => saved).not.toBeNull();
  expect(saved.seo_title).toBe('BRVTAL Journal 001');
  expect(saved.seo_description.length).toBeLessThanOrEqual(160);
  expect(saved.seo_description).not.toContain('<p>');
  expect(saved.seo_description).toContain('Underground techno from Pereira');
});

test('manual Blog SEO metadata always wins over automatic defaults', async ({ page }) => {
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

test('Page save derives SEO description from textual JSON blocks and ignores media paths', async ({ page }) => {
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
          sections:[
            {type:'text',body:'Underground electronic culture from Colombia.'},
            {type:'image',src:'/uploads/media/poster.jpg'}
          ]
        }),
        seo_title:'',
        seo_description:''
      })
    });
  });

  await expect.poll(() => saved).not.toBeNull();
  expect(saved.seo_title).toBe('About BRVTAL');
  expect(saved.seo_description).toContain('BRVTAL is music');
  expect(saved.seo_description).toContain('Underground electronic culture from Colombia.');
  expect(saved.seo_description).not.toContain('/uploads/media/poster.jpg');
  expect(saved.seo_description.length).toBeLessThanOrEqual(160);
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
  expect(' ,.;:-').not.toContain(values.punctuationTail.at(-1));
});
