import { test, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const root = process.cwd();
const css = ['css/public-entity.css', 'css/public-event-record.css', 'css/public-cromo.css', 'css/public-cromo-pages.css']
  .map(path => readFileSync(join(root, path), 'utf8')).join('\n');
const php = String.raw`
require_once 'config/public_seo.php';
require_once 'config/public_page.php';
$accent = $argv[1] ?? '';
$page = ['entity' => ['id'=>1,'route_type'=>'events','title'=>'Génesis','description'=>'# GENESIS — EL REGRESO','accent'=>$accent,'status'=>'published'],
 'facts'=>['DATE'=>'18.11.2026','LOCATION'=>'Pereira'], 'links'=>['TICKETS'=>'https://tickets.example.org'],
 'record'=>['state'=>'active','status'=>'UPCOMING','year'=>'2026'], 'related'=>[], 'degraded'=>false];
$seo = ['title'=>'Génesis — BRVTAL','description'=>'# GENESIS — EL REGRESO','image'=>'',
 'canonical'=>'https://www.brvtal.com.co/events/genesis',
 'schema'=>['@context'=>'https://schema.org','@type'=>'Event','description'=>'# GENESIS — EL REGRESO']];
echo brvtal_public_entity_page($page, $seo);
`;
const render = accent => execFileSync('php', ['-r', php, accent], { cwd: root, encoding: 'utf8' })
  .replace(/<link[^>]*rel="stylesheet"[^>]*>/g, '')
  .replace(/<script[^>]*src="[^"]+"[^>]*><\/script>/g, '')
  .replace('</head>', '<style>' + css + '</style></head>');
const rgb = value => (value.match(/\d+(?:\.\d+)?/g) || []).slice(0, 3).map(Number);
const luminance = channels => channels.reduce((sum, channel, i) => {
  const s = channel / 255;
  return sum + [0.2126, 0.7152, 0.0722][i] * (s <= 0.04045 ? s / 12.92 : ((s + 0.055) / 1.055) ** 2.4);
}, 0);
const contrast = (a, b) => {
  const values = [luminance(rgb(a)), luminance(rgb(b))].sort((x, y) => y - x);
  return (values[0] + 0.05) / (values[1] + 0.05);
};

test('event accent controls signal, poster tint, band, hero and AA button on 390px Safari-sized layout', async ({ page }) => {
  for (const accent of ['#96d35f', '#101020', '']) {
    await page.setViewportSize({ width: 390, height: 844 });
    await page.setContent(render(accent));
    const expected = accent || '#e12e34';
    const styles = await page.evaluate(() => {
      const body = document.body;
      const button = document.querySelector('.entity-actions a');
      return {
        signal: getComputedStyle(body).getPropertyValue('--event-signal').trim().toLowerCase(),
        poster: getComputedStyle(document.querySelector('.entity-image'), '::after').backgroundColor,
        button: getComputedStyle(button).backgroundColor,
        text: getComputedStyle(button).color,
        band: getComputedStyle(document.querySelector('.event-record-band'), '::before').backgroundColor,
        heroBorder: getComputedStyle(document.querySelector('.entity-hero')).borderBottomColor,
        width: document.documentElement.scrollWidth, viewport: window.innerWidth,
      };
    });
    const hex = expected.match(/\w\w/g).map(h => parseInt(h, 16));
    const expectedRgb = 'rgb(' + hex.join(', ') + ')';
    expect(styles.signal).toBe(expected);
    expect(styles.poster).toBe(expectedRgb);
    expect(styles.button).toBe(expectedRgb);
    expect(styles.band).toBe(expectedRgb);
    expect(styles.heroBorder).toBe(expectedRgb);
    expect(contrast(styles.button, styles.text)).toBeGreaterThanOrEqual(4.5);
    expect(styles.width).toBeLessThanOrEqual(styles.viewport + 1);
    await expect(page.locator('.entity-actions a').first()).toHaveAttribute('href', 'https://tickets.example.org');
  }
});
