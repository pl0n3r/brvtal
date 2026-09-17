import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const script = readFileSync(join(process.cwd(), 'js/public-memory-relations.js'), 'utf8');
const css = [
  'css/public-media.css',
  'css/related-content.css',
  'css/public-memory-relations.css',
].map(path => readFileSync(join(process.cwd(), path), 'utf8')).join('\n');

const markup = `<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><style>${css}</style></head><body>
<section id="media"><div class="media-grid public-media-grid">
  <figure class="public-media-item" data-public-media-item data-public-media-search-value="genesis memory">
    <button type="button" data-public-media-open="5"><img src="/uploads/memory.jpg" alt="Genesis floor"></button>
    <figcaption><strong>GENESIS FLOOR</strong><span class="mono">IMAGE</span></figcaption>
  </figure>
</div></section>
<section id="network" class="related-network">
  <button type="button" class="related-network-entity active" data-related-type="events" data-related-id="10">GENESIS</button>
  <div data-related-detail><div class="related-groups"></div></div>
</section>
</body></html>`;

const data = {
  media:[{
    id:5,
    type:'image',
    title:'GENESIS FLOOR',
    file_path:'/uploads/memory.jpg',
    relations:[
      {related_type:'event',related_id:10,route_type:'events',slug:'genesis',label:'GENESIS'},
      {related_type:'artist',related_id:20,route_type:'artists',slug:'pl0n3r',label:'PL0N3R <SCRIPT>'},
    ],
  }],
  relations:{
    events:{'10':{artists:[],sets:[],memories:[5]}},
    artists:{'20':{events:[],sets:[],releases:[],memories:[5]}},
    sets:{},
    releases:{},
    media:{'5':{events:[10],artists:[20],sets:[],releases:[]}},
    counts:{event_memory:1,artist_memory:1},
  },
};

async function boot(page) {
  await page.setContent(markup);
  await page.addScriptTag({content:script});
  await page.evaluate(payload => window.dispatchEvent(new CustomEvent('brvtal:public-data',{detail:payload})), data);
}

test('Memories expose canonical cultural context and enrich public search text', async ({ page }) => {
  await boot(page);
  const context = page.locator('.public-memory-context');
  await expect(context).toBeVisible();
  await expect(context.locator('a[href="/events/genesis"]')).toContainText('EVENT / GENESIS');
  await expect(context.locator('a[href="/artists/pl0n3r"]')).toContainText('ARTIST / PL0N3R <SCRIPT>');
  await expect(context.locator('script')).toHaveCount(0);
  await expect(page.locator('[data-public-media-item]')).toHaveAttribute('data-public-media-search-value', /pl0n3r <script>/i);
});

test('CONNECTED keeps four-layer selection but shows explicit Memories in entity detail', async ({ page }) => {
  await boot(page);
  const group = page.locator('[data-related-memories]');
  await expect(group).toBeVisible();
  await expect(group).toContainText('MEMORIES');
  await expect(group).toContainText('GENESIS FLOOR');
  await expect(group.locator('a')).toHaveAttribute('href', '/#media');
  await expect(page.locator('#network [data-related-mode]')).toHaveCount(0);
});

test('Memory context remains touch-safe without horizontal overflow on mobile', async ({ page }) => {
  await page.setViewportSize({width:390,height:844});
  await boot(page);
  const chip = page.locator('.public-memory-context a').first();
  await expect(chip).toBeVisible();
  expect(await chip.evaluate(node => node.getBoundingClientRect().height)).toBeGreaterThanOrEqual(44);
  const metrics = await page.evaluate(() => ({viewport:document.documentElement.clientWidth,scrollWidth:document.documentElement.scrollWidth}));
  expect(metrics.scrollWidth).toBeLessThanOrEqual(metrics.viewport);
});
