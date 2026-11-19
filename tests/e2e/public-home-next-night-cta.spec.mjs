import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const script = readFileSync(join(process.cwd(), 'js/public-cromo-home.js'), 'utf8');
const url = 'http://127.0.0.1:4173/next-night-cta-e2e.html';
let sequence = 0;
async function mount(page, slug) {
  const event = { id: 1, title: 'Génesis', event_date: '2099-11-18 21:00:00', city: 'Pereira', venue: 'Bodega', ...(slug ? {slug} : {}) };
  const data = {events: [event], artists: [], sets: [], releases: [], memories: [], blog: [], archive:{events:[]},relations:{}};
  await page.route('**/api/public.php', route => route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,data})}));
  await page.route('**/api/hero-slider.php', route => route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,data:{enabled:false,slides:[]}})}));
  await page.route(url+'*', route => route.fulfill({contentType:'text/html',body:'<!doctype html><html><head></head><body><main>Legacy</main></body></html>'}));
  await page.goto(url+'?case='+(++sequence));
  await page.addScriptTag({content:script});
  await expect(page.locator('#cromo-home')).toBeAttached();
}

test('next night CTA and hero card open the same canonical event URL', async ({page}) => {
  await mount(page, 'genesis-noche');
  const target = '/events/genesis-noche';
  await expect(page.locator('.cr-hero .cr-next-link')).toHaveAttribute('href', target);
  await expect(page.getByRole('link',{name:'Ver la próxima noche'})).toHaveAttribute('href', target);
});

test('next night CTA retains #noches when the published event has no slug', async ({page}) => {
  await mount(page, '');
  await expect(page.getByRole('link',{name:'Ver la próxima noche'})).toHaveAttribute('href','#noches');
  await expect(page.locator('.cr-hero .cr-next-link')).toHaveCount(0);
});
