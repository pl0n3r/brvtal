import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const appearance = readFileSync(join(process.cwd(), 'discadmin/admin-appearance.css'), 'utf8');
const design = readFileSync(join(process.cwd(), 'discadmin/admin-design-system.css'), 'utf8');
const wrapper = readFileSync(join(process.cwd(), 'discadmin/index.php'), 'utf8');

async function mount(page) {
  await page.setContent(`<!doctype html><html data-discadmin-appearance="dark"><head>
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <style>:root{--red:#ff2038}*{box-sizing:border-box}body{margin:0}.shell{display:grid;grid-template-columns:252px 1fr}.side,.main{padding:24px}.nav button{display:block;width:100%}</style>
    <style>${appearance}</style><style>${design}</style></head><body>
    <div class="shell">
      <aside class="side"><div class="navgroup">SITE / EDITORIAL</div><nav class="nav"><button>DASHBOARD</button></nav></aside>
      <main class="main">
        <div class="top"><div><div class="eyebrow">CONTROL ROOM</div><h1>DASHBOARD</h1></div></div>
        <p class="helper">Readable helper text for long sessions.</p>
        <div class="field"><label>Title</label><input placeholder="Event title"></div>
        <div class="table"><div class="thead">TITLE</div><div class="tr">GENESIS <span class="pill">PUBLISHED</span></div></div>
        <section class="dashboard-v2-panel"><div class="dashboard-v2-kicker">WHAT NEEDS ATTENTION NOW</div><p class="dashboard-v2-sub">Operational overview.</p><div class="dashboard-v2-row-title">Admin activity</div><div class="dashboard-v2-row-meta">Recent record</div><button class="dashboard-v2-button">VIEW MORE</button></section>
        <section class="sv2-pane active"><div class="sv2-section-head"><div><span>SETTINGS</span><h3>GENERAL</h3><p>Configuration helper copy.</p></div></div><div class="sv2-field"><span>Site name</span><input value="BRVTAL"><small>Visible public identity.</small></div></section>
        <section class="ssv2-panel"><div class="ssv2-panel-head"><span>SYSTEM STATUS</span><b>READY</b></div><div class="ssv2-service-label">DATABASE</div><div class="ssv2-backlog-item"><span>ISSUE</span><strong>Readable backlog title</strong><small>Metadata</small></div></section>
        <section class="media-inspector"><div class="media-inspector-body"><h3>Media asset</h3><label>ALT TEXT</label><div class="media-fact"><span>SIZE</span><b>1280×720</b></div></div></section>
        <section class="blog-row"><div><div class="blog-title">Transmission</div><div class="blog-meta">Published today</div><div class="blog-excerpt">Editorial excerpt.</div><span class="blog-status-pill">PUBLISHED</span></div></section>
        <section class="release-row"><div><div class="release-title">Release</div><div class="release-meta">Catalog metadata</div><div class="release-artists">PL0N3R</div><span class="release-status-pill">PUBLISHED</span></div></section>
      </main>
    </div></body></html>`);
}

async function fontSize(page, selector) {
  return page.locator(selector).first().evaluate(el => parseFloat(getComputedStyle(el).fontSize));
}

test('premium design system establishes a readable shared type floor', async ({ page }) => {
  await mount(page);
  expect(await fontSize(page, 'body')).toBeGreaterThanOrEqual(14);
  expect(await fontSize(page, '.nav button')).toBeGreaterThanOrEqual(13);
  expect(await fontSize(page, '.helper')).toBeGreaterThanOrEqual(13);
  expect(await fontSize(page, '.field input')).toBeGreaterThanOrEqual(14);
  expect(await fontSize(page, '.tr')).toBeGreaterThanOrEqual(14);
  expect(await fontSize(page, '.dashboard-v2-button')).toBeGreaterThanOrEqual(11);
});

test('previously tiny modern-module metadata remains legible', async ({ page }) => {
  await mount(page);
  for (const selector of [
    '.dashboard-v2-kicker',
    '.dashboard-v2-row-meta',
    '.sv2-section-head span',
    '.sv2-field>span',
    '.sv2-field small',
    '.ssv2-service-label',
    '.ssv2-backlog-item>small',
    '.media-inspector label',
    '.media-fact span',
    '.blog-meta',
    '.blog-status-pill',
    '.release-meta',
    '.release-status-pill',
  ]) {
    expect(await fontSize(page, selector), selector).toBeGreaterThanOrEqual(11);
  }
});

test('premium surfaces and controls use shared semantic geometry', async ({ page }) => {
  await mount(page);
  const values = await page.locator('.sv2-pane').evaluate(el => {
    const root = getComputedStyle(document.documentElement);
    const style = getComputedStyle(el);
    return {
      panelRadius: parseFloat(style.borderRadius),
      tokenRadius: root.getPropertyValue('--admin-radius-card').trim(),
      baseFont: root.getPropertyValue('--admin-font-base').trim(),
      uiFont: root.getPropertyValue('--admin-font-ui').trim(),
    };
  });
  expect(values.panelRadius).toBeGreaterThanOrEqual(12);
  expect(values.tokenRadius).toBe('12px');
  expect(values.baseFont).toBe('14px');
  expect(values.uiFont).toContain('-apple-system');
});

test('mobile keeps readable text and native-sized form controls', async ({ page }) => {
  await page.setViewportSize({width:390,height:844});
  await mount(page);
  expect(await fontSize(page, 'body')).toBeGreaterThanOrEqual(14);
  expect(await fontSize(page, '.nav button')).toBeGreaterThanOrEqual(13);
  expect(await fontSize(page, '.field input')).toBeGreaterThanOrEqual(16);
  expect(await fontSize(page, '.dashboard-v2-row-meta')).toBeGreaterThanOrEqual(12);
});

test('wrapper loads the premium layer after module CSS so it owns final semantics', async () => {
  expect(wrapper).toContain('/discadmin/admin-design-system.css');
  expect(wrapper.indexOf('/discadmin/admin-design-system.css')).toBeGreaterThan(wrapper.indexOf('/discadmin/settings-v2.css'));
  expect(wrapper.indexOf('/discadmin/admin-design-system.css')).toBeGreaterThan(wrapper.indexOf('/discadmin/memories.css'));
  expect(design).toContain('--admin-font-base:14px');
  expect(design).toContain('--admin-radius-panel:14px');
  expect(design).toContain('prefers-reduced-motion:reduce');
});
