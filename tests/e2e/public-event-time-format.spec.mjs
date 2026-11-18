import { test, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';

// Exercise the real PHP Event Record renderer with a deterministic GENESIS fixture.
// The browser never needs production data or a live API.
const php = [
  "require_once 'config/public_seo.php';",
  "require_once 'config/public_page.php';",
  "$eventAt = new DateTimeImmutable('2026-12-04 21:00:00', new DateTimeZone('America/Bogota'));",
  "$page = [",
  "  'entity'=>['id'=>1,'route_type'=>'events','title'=>'GENESIS','description'=>'Evento de prueba','status'=>'tickets_available'],",
  "  'facts'=>['DATE'=>brvtalPublicDateTimeFormat($eventAt),'LOCATION'=>'PEREIRA / COLOMBIA'],",
  "  'links'=>[],",
  "  'record'=>['state'=>'active','year'=>'2026','status'=>'TICKETS AVAILABLE'],",
  "  'related'=>[],",
  "  'degraded'=>false",
  "];",
  "$seo = ['title'=>'GENESIS / BRVTAL','description'=>'GENESIS event','canonical'=>'https://www.brvtal.com.co/events/genesis','image'=>'/images/genesis.jpg','schema'=>['@type'=>'MusicEvent']];",
  "echo brvtal_public_entity_page($page, $seo);"
].join('\n');

test('GENESIS Event Record displays 9:00 pm, not 21:00, on desktop and mobile', async ({ page }) => {
  const html = execFileSync('php', ['-r', php], { cwd: process.cwd(), encoding: 'utf8', timeout: 15000 });
  await page.route('**/*', async (route) => {
    if (new URL(route.request().url()).pathname === '/events/genesis') {
      await route.fulfill({ status: 200, contentType: 'text/html; charset=utf-8', body: html });
    } else {
      await route.fulfill({ status: 200, contentType: 'text/css', body: '' });
    }
  });
  for (const width of [1440, 390]) {
    await page.setViewportSize({ width, height: 900 });
    await page.goto('http://127.0.0.1:4173/events/genesis', { waitUntil: 'domcontentloaded' });
    await expect(page.locator('.entity-facts')).toContainText('04.12.2026 / 9:00 pm');
    await expect(page.locator('.entity-facts')).not.toContainText('21:00');
    await expect(page.locator('h1')).toContainText('GENESIS');
  }
});
