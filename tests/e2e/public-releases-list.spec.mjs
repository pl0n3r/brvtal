import { test, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const root = process.cwd();
const routeBase = 'http://127.0.0.1:4173';
const homeSource = readFileSync(join(root, 'config/public_home.php'), 'utf8');
const css = Object.fromEntries(['public-entity.css','public-cromo.css','public-cromo-pages.css'].map(name =>
  ['/css/' + name, readFileSync(join(root, 'css', name), 'utf8')]));
const pixel = '<svg xmlns="http://www.w3.org/2000/svg" width="640" height="640"><rect width="640" height="640" fill="#222"/></svg>';
const source = "require 'config/public_seo.php'; require 'config/public_releases_listing.php';"
  + " $rows=json_decode(base64_decode($argv[1]),true);"
  + " echo brvtalPublicReleasesIndexPage($rows,brvtalPublicReleasesIndexSeo('https://www.brvtal.com.co'));";
function render(rows) {
  return execFileSync('php', ['-r', source, Buffer.from(JSON.stringify(rows)).toString('base64')],
    { cwd: root, encoding: 'utf8' });
}
const catalog = render([
  { title: 'GENESIS EP', slug: 'genesis-ep', artwork: '/uploads/genesis.webp', release_type: 'ep', catalog_number: 'BR-01', release_date: '2026-08-14' },
  { title: 'ARCHIVO BRVTAL', slug: 'archivo-brvtal', artwork: '', release_type: 'album', catalog_number: 'BR-02', release_date: '2026-09-01' },
]);
async function mockSite(page, catalogHtml) {
  await page.route('**/*', async route => {
    const path = new URL(route.request().url()).pathname;
    if (path === '/') return route.fulfill({ status: 200, contentType: 'text/html', body: '<!doctype html><html><body><a href="/releases">RECORDS</a></body></html>' });
    if (path === '/releases' || path === '/releases/') return route.fulfill({ status: 200, contentType: 'text/html', body: catalogHtml });
    if (css[path] !== undefined) return route.fulfill({ status: 200, contentType: 'text/css', body: css[path] });
    if (path.startsWith('/js/')) return route.fulfill({ status: 200, contentType: 'application/javascript', body: '' });
    if (path.endsWith('.jpeg') || path.endsWith('.webp')) return route.fulfill({ status: 200, contentType: 'image/svg+xml', body: pixel });
    return route.fulfill({ status: 404, contentType: 'text/plain', body: 'No encontrado' });
  });
}

for (const width of [1440, 390]) {
  test('RECORDS navega al catálogo real Cromo sin desbordar a ' + width + 'px', async ({ page }) => {
    expect(homeSource).toContain('href="/releases"');
    await page.setViewportSize({ width, height: 900 });
    await mockSite(page, catalog);
    await page.goto(routeBase + '/');
    await page.getByRole('link', { name: 'RECORDS' }).click();
    await expect(page).toHaveURL(routeBase + '/releases');
    await expect(page.getByRole('heading', { name: 'DISCOS', exact: true })).toBeVisible();
    await expect(page.locator('body')).toHaveClass(/cromo-page/);
    await expect(page.locator('link[rel="canonical"]')).toHaveAttribute('href', 'https://www.brvtal.com.co/releases');
    await expect(page.locator('.cp-nav a')).toHaveCount(6);
    await expect(page.locator('.entity-grid .entity-card')).toHaveCount(2);
    await expect(page.locator('a.entity-card[href="/releases/genesis-ep"]')).toContainText('GENESIS EP');
    await expect(page.locator('a.entity-card[href="/releases/archivo-brvtal"]')).toContainText('ARCHIVO BRVTAL');
    await expect(page.locator('.entity-card-mark')).toHaveCount(1);
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    expect(overflow).toBeLessThanOrEqual(1);
  });
}

test('catálogo sin publicaciones declara estado vacío veraz', async ({ page }) => {
  await mockSite(page, render([]));
  const response = await page.goto(routeBase + '/releases/');
  expect(response.status()).toBe(200);
  await expect(page.getByRole('status')).toContainText('Aún no hay discos publicados');
  await expect(page.locator('a.entity-card')).toHaveCount(0);
});
