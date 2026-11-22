import { test, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { createServer } from 'node:http';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const root = process.cwd();
const directives = readFileSync(join(root, '.htaccess'), 'utf8');
const php = readFileSync(join(root, 'errors/404.php'), 'utf8');
const redirects = [
  { route: '/events', section: 'noches' },
  { route: '/artists', section: 'artistas' },
];

/**
 * The browser tests a real HTTP response and the actual PHP-rendered 404.
 * The isolated Node listener interprets only the two explicit Apache redirects
 * so it does not depend on a production CMS or a privileged Apache daemon.
 * Hostinger/LiteSpeed rewrite parity remains a post-deploy smoke concern.
 */
function redirectFromHtaccess(path) {
  for (const { route, section } of redirects) {
    const directive = 'RewriteRule ^' + route.slice(1) + '/?$ /#' + section + ' [R=301,L,NE,QSD,NC]';
    if (directives.includes(directive) && (path === route || path === route + '/')) {
      return '/#' + section;
    }
  }
  return null;
}

test('BRVTAL declares an ErrorDocument that leaves missing URLs at HTTP 404', () => {
  expect(directives).toMatch(/^ErrorDocument 404 \/errors\/404\.php$/m);
  expect(php).toContain('http_response_code(404)');
  expect(php).toContain("header('X-Robots-Tag: noindex, follow')");
  expect(redirectFromHtaccess('/events')).toBe('/#noches');
  expect(redirectFromHtaccess('/events/')).toBe('/#noches');
  expect(redirectFromHtaccess('/artists')).toBe('/#artistas');
  expect(redirectFromHtaccess('/artists/')).toBe('/#artistas');
  expect(redirectFromHtaccess('/events/example')).toBeNull();
});

test('HTTP 404 Cromo page and 301 shortcuts retain working navigation at 390/1440', async ({ browser }, testInfo) => {
  const html = execFileSync('php', ['errors/404.php'], {cwd:root,encoding:'utf8'});
  const server = createServer((req, res) => {
    const path = new URL(req.url, 'http://localhost').pathname;
    const target = redirectFromHtaccess(path);
    if (target) {
      res.writeHead(301, {'Location':target,'Cache-Control':'no-store'});
      return res.end();
    }
    if (path === '/') {
      res.writeHead(200, {'Content-Type':'text/html; charset=utf-8'});
      return res.end('<!doctype html><html lang="es"><title>BRVTAL</title><body><main id="noches">NOCHES</main><section id="artistas">ARTISTAS</section></body></html>');
    }
    if (path === '/css/public-cromo.css') {
      res.writeHead(200, {'Content-Type':'text/css; charset=utf-8'});
      return res.end(readFileSync(join(root, 'css/public-cromo.css')));
    }
    if (path === '/assets/fonts/BrokenGlass.ttf') {
      res.writeHead(200, {'Content-Type':'font/ttf'});
      return res.end(readFileSync(join(root, 'assets/fonts/BrokenGlass.ttf')));
    }
    res.writeHead(404, {'Content-Type':'text/html; charset=utf-8','X-Robots-Tag':'noindex, follow'});
    return res.end(html);
  });
  await new Promise(resolve => server.listen(0,'127.0.0.1',resolve));
  const port = server.address().port;
  const origin = 'http://127.0.0.1:' + port;
  try {
    const context = await browser.newContext({viewport:{width:390,height:844},reducedMotion:'reduce'});
    try {
      const page = await context.newPage();
      for (const width of [390,1440]) {
        await page.setViewportSize({width,height:width===390?844:900});
        // Read-only baseline from the incumbent production route; failures do
        // not invent a baseline or make offline product tests network-dependent.
        try {
          const current = await page.goto('https://www.brvtal.com.co/__brvtal_missing_1858', {waitUntil:'domcontentloaded',timeout:7000});
          if (current?.status() === 404) {
            await page.screenshot({path:testInfo.outputPath('before-live-'+width+'.png'),fullPage:true});
          }
        } catch {}
        const response = await page.goto(origin + '/__brvtal_missing_1858',{waitUntil:'domcontentloaded'});
        expect(response.status()).toBe(404);
        expect(response.headers()['x-robots-tag']).toBe('noindex, follow');
        await expect(page.locator('html')).toHaveAttribute('lang','es');
        await expect(page.getByRole('heading',{name:'Esta ruta no existe.'})).toBeVisible();
        await expect(page.getByRole('link',{name:'BRVTAL — inicio'})).toHaveAttribute('href','/');
        await expect(page.getByRole('navigation',{name:'Volver a BRVTAL'}).getByRole('link')).toHaveCount(3);
        await expect(page.getByRole('link',{name:'Ir al inicio'})).toHaveAttribute('href','/');
        await expect(page.getByRole('link',{name:'Ver noches'})).toHaveAttribute('href','/#noches');
        await expect(page.getByRole('link',{name:'Conocer artistas'})).toHaveAttribute('href','/#artistas');
        const geometry = await page.evaluate(() => ({
          viewport:document.documentElement.clientWidth,
          scroll:document.documentElement.scrollWidth,
          focus:getComputedStyle(document.querySelector('.error-links a'),':focus-visible').outlineWidth,
        }));
        expect(geometry.scroll).toBeLessThanOrEqual(geometry.viewport + 1);
        await page.screenshot({path:testInfo.outputPath('after-cromo-'+width+'.png'),fullPage:true});
      }
      for (const {route,section} of redirects) {
        for (const suffix of ['','/']) {
          const redirected = await context.request.get(origin+route+suffix,{maxRedirects:0});
          expect(redirected.status()).toBe(301);
          expect(redirected.headers().location).toBe('/#'+section);
        }
        await page.goto(origin+route);
        expect(new URL(page.url()).hash).toBe('#'+section);
      }
      // Real missing entity slugs still use their individual canonical routes.
      const nested = await page.goto(origin+'/events/no-such-night');
      expect(nested.status()).toBe(404);
    } finally {await context.close();}
  } finally {await new Promise(resolve => server.close(resolve));}
});
