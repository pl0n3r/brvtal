import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const runtime = readFileSync(join(process.cwd(), 'js/public-quick-wins.js'), 'utf8');

async function mount(page) {
  await page.setContent(`<!doctype html><html><body>
    <div class="brvtal-hero-slider">
      <article class="brvtal-hero-slide active" data-hero-slide="0" aria-hidden="false">
        <a id="hero-active" href="/active">ACTIVE CTA</a>
      </article>
      <article class="brvtal-hero-slide" data-hero-slide="1" aria-hidden="true">
        <a id="hero-hidden" href="/hidden">HIDDEN CTA</a>
      </article>
    </div>

    <a id="fallback-artist" class="artist" href="#">PLACEHOLDER ARTIST</a>
    <article class="set-item" id="fallback-set">
      <div class="set-main"><span>SOUNDCLOUD</span><h4>STATIC SET</h4></div>
      <a id="fallback-set-action" href="https://soundcloud.com/" target="_blank" rel="noopener" class="set-action magnetic">↗</a>
    </article>
    <article class="set-item" id="dynamic-set">
      <div class="set-main"><span>SPOTIFY</span><h4>NIGHT SIGNAL</h4></div>
      <a id="dynamic-set-action" href="https://example.com/listen" target="_blank" rel="noopener" class="set-action magnetic">↗</a>
    </article>
  </body></html>`);
  await page.addScriptTag({content: runtime});
}

test('hidden Hero slides are inert and become interactive only when active', async ({ page }) => {
  await mount(page);

  await expect(page.locator('[data-hero-slide="0"]')).not.toHaveAttribute('inert', '');
  await expect(page.locator('[data-hero-slide="1"]')).toHaveAttribute('inert', '');

  await page.evaluate(() => {
    const first = document.querySelector('[data-hero-slide="0"]');
    const second = document.querySelector('[data-hero-slide="1"]');
    first.setAttribute('aria-hidden', 'true');
    second.setAttribute('aria-hidden', 'false');
  });

  await expect(page.locator('[data-hero-slide="0"]')).toHaveAttribute('inert', '');
  await expect(page.locator('[data-hero-slide="1"]')).not.toHaveAttribute('inert', '');
});

test('placeholder fallback actions are not keyboard-interactive', async ({ page }) => {
  await mount(page);

  await expect(page.locator('#fallback-artist')).not.toHaveAttribute('href', /.+/);
  await expect(page.locator('#fallback-artist')).toHaveAttribute('aria-disabled', 'true');

  await expect(page.locator('#fallback-set-action')).not.toHaveAttribute('href', /.+/);
  await expect(page.locator('#fallback-set-action')).toHaveAttribute('tabindex', '-1');
  await expect(page.locator('#fallback-set-action')).toHaveAttribute('aria-hidden', 'true');
});

test('real Set external actions receive a descriptive accessible name', async ({ page }) => {
  await mount(page);
  await expect(page.locator('#dynamic-set-action')).toHaveAttribute(
    'aria-label',
    'Listen to NIGHT SIGNAL on SPOTIFY'
  );
});
