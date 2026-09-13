import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const css = readFileSync(join(process.cwd(), 'css/home-banners.css'), 'utf8');
const script = readFileSync(join(process.cwd(), 'js/home-banners.js'), 'utf8');

test('banner slider supports manual navigation, focus safety and pause', async ({ page }) => {
  await page.setContent(`<!doctype html><html><head><style>${css}</style></head><body><section class="hero-banner">
    <div class="banner-slides"><article class="banner-slide is-active" aria-hidden="false"><h1>FIRST</h1><a href="/events/first">FIRST EVENT</a></article><article class="banner-slide" aria-hidden="true" inert><h2>SECOND</h2><a href="/events/second">SECOND EVENT</a></article></div>
    <div class="banner-controls"><button data-banner-prev>PREV</button><span class="banner-count" aria-live="polite">01 / 02</span><button data-banner-next>NEXT</button><button data-banner-pause>PAUSE</button></div>
  </section></body></html>`);
  await page.addScriptTag({ content: script });
  await page.getByRole('button', { name: 'NEXT' }).click();
  await expect(page.locator('.banner-slide').nth(1)).toHaveClass(/is-active/);
  await expect(page.locator('.banner-slide').first()).toHaveAttribute('aria-hidden', 'true');
  await expect(page.locator('.banner-slide').first()).toHaveAttribute('inert', '');
  await expect(page.locator('.banner-count')).toHaveText('02 / 02');
  await page.getByRole('button', { name: 'Pause banner rotation' }).click();
  await expect(page.getByRole('button', { name: 'Resume banner rotation' })).toBeVisible();
  await page.getByRole('button', { name: 'PREV' }).click();
  await expect(page.locator('.banner-slide').first()).toHaveClass(/is-active/);
});

test('reduced motion starts with automatic rotation paused', async ({ page }) => {
  await page.emulateMedia({ reducedMotion: 'reduce' });
  await page.setContent('<section class="hero-banner"><article class="banner-slide is-active"></article><article class="banner-slide" inert></article><span class="banner-count"></span><button data-banner-pause>PAUSE</button></section>');
  await page.addScriptTag({ content: script });
  await expect(page.getByRole('button', { name: 'Resume banner rotation' })).toHaveText('PLAY');
});
