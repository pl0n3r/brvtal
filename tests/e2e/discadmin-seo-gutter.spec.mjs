import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const seoJs = readFileSync(join(process.cwd(), 'discadmin/seo-metadata.js'), 'utf8');
const contentCoreCss = readFileSync(join(process.cwd(), 'discadmin/content-core.css'), 'utf8');

async function mountContentCoreSeo(page, viewport) {
  await page.setViewportSize(viewport);
  await page.setContent(`<!doctype html><html><head><meta charset="utf-8"></head><body>
    <section data-admin-module="content-core">
      <div id="eventModal" class="modal open" data-event-id="new">
        <div class="wizard-main">
          <div class="step-content active" data-content="1">
            <div id="identity-section" class="section">
              <div class="form">
                <div class="field"><label for="e_title">Name</label><input id="e_title" value="GENESIS"></div>
                <div class="field"><label for="e_slug">Slug</label><input id="e_slug" value="genesis"></div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>
  </body></html>`);
  await page.addStyleTag({ content: contentCoreCss });
  await page.addScriptTag({ content: seoJs });
  await expect(page.locator('.brvtal-seo-section[data-seo-editor="content-core"]')).toBeVisible();
}

test('SEO metadata uses the canonical editor gutter on desktop and mobile', async ({ page }) => {
  await mountContentCoreSeo(page, { width: 1180, height: 800 });

  const desktop = await page.evaluate(() => {
    const identity = document.getElementById('identity-section');
    const seo = document.querySelector('.brvtal-seo-section[data-seo-editor="content-core"]');
    const identityStyle = getComputedStyle(identity);
    const seoStyle = getComputedStyle(seo);
    const identityBox = identity.getBoundingClientRect();
    const seoBox = seo.getBoundingClientRect();
    return {
      identityPaddingLeft: identityStyle.paddingLeft,
      identityPaddingRight: identityStyle.paddingRight,
      seoPaddingLeft: seoStyle.paddingLeft,
      seoPaddingRight: seoStyle.paddingRight,
      leftDelta: Math.abs(identityBox.left - seoBox.left),
      widthDelta: Math.abs(identityBox.width - seoBox.width),
    };
  });

  expect(desktop.seoPaddingLeft).toBe(desktop.identityPaddingLeft);
  expect(desktop.seoPaddingRight).toBe(desktop.identityPaddingRight);
  expect(desktop.leftDelta).toBeLessThan(1);
  expect(desktop.widthDelta).toBeLessThan(1);

  await page.setViewportSize({ width: 390, height: 844 });
  const mobile = await page.evaluate(() => {
    const seo = document.querySelector('.brvtal-seo-section[data-seo-editor="content-core"]');
    const box = seo.getBoundingClientRect();
    return {
      boxLeft: box.left,
      boxRight: box.right,
      viewportWidth: document.documentElement.clientWidth,
      scrollWidth: document.documentElement.scrollWidth,
      paddingLeft: getComputedStyle(seo).paddingLeft,
      paddingRight: getComputedStyle(seo).paddingRight,
    };
  });

  expect(mobile.paddingLeft).toBe('17px');
  expect(mobile.paddingRight).toBe('17px');
  expect(mobile.boxLeft).toBeGreaterThanOrEqual(0);
  expect(mobile.boxRight).toBeLessThanOrEqual(mobile.viewportWidth);
  expect(mobile.scrollWidth).toBeLessThanOrEqual(mobile.viewportWidth);
});


test('SEO metadata spans the full width of grid-based editor forms', async ({ page }) => {
  await page.setViewportSize({ width: 1180, height: 800 });
  await page.setContent(`<!doctype html><html><head><meta charset="utf-8"></head><body>
    <div id="mcontent">
      <div class="form" style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px;width:760px">
        <input id="release_title" value="">
        <input id="release_slug" value="">
      </div>
    </div>
  </body></html>`);
  await page.addScriptTag({ content: seoJs });

  const seo = page.locator('.brvtal-seo-section[data-seo-editor="release"]');
  await expect(seo).toBeVisible();

  const geometry = await page.evaluate(() => {
    const form = document.querySelector('#mcontent .form');
    const section = document.querySelector('.brvtal-seo-section[data-seo-editor="release"]');
    const formBox = form.getBoundingClientRect();
    const sectionBox = section.getBoundingClientRect();
    return {
      leftDelta: Math.abs(formBox.left - sectionBox.left),
      widthDelta: Math.abs(formBox.width - sectionBox.width),
    };
  });

  expect(geometry.leftDelta).toBeLessThan(1);
  expect(geometry.widthDelta).toBeLessThan(1);
});
