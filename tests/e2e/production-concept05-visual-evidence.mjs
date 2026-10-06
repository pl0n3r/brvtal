import fs from 'node:fs/promises';
import path from 'node:path';
import { createHash } from 'node:crypto';
import { chromium } from '@playwright/test';

const targetUrl = process.env.BRVTAL_PERF_URL || 'https://www.brvtal.com.co/';
const sourceSha = String(process.env.BRVTAL_VISUAL_SOURCE_SHA || '').trim().toLowerCase();
const outputDir = process.env.BRVTAL_VISUAL_OUTPUT_DIR || 'artifacts';

if (!/^[0-9a-f]{40}$/.test(sourceSha)) {
  throw new Error('BRVTAL_VISUAL_SOURCE_SHA must be an exact 40-character Git SHA.');
}

const target = new URL(targetUrl);
if (target.origin !== 'https://www.brvtal.com.co' || target.pathname !== '/' || target.username || target.password) {
  throw new Error('Visual evidence is restricted to the canonical public BRVTAL Home.');
}

const canonicalVisualRegions = [
  '.home-phase-a-hero',
  '#genesis',
  '#events',
  '#artists',
  '#sets',
  '#media',
  '#transmissions',
  '#connected',
  '.c5-footer',
];

const captures = [
  {
    name: 'mobile-390',
    filename: 'production-concept05-mobile-390.png',
    viewport: { width: 390, height: 844 },
    isMobile: true,
    hasTouch: true,
  },
  {
    name: 'desktop-1440',
    filename: 'production-concept05-desktop-1440.png',
    viewport: { width: 1440, height: 900 },
    isMobile: false,
    hasTouch: false,
  },
];

const browser = await chromium.launch({ headless: true });
const evidence = {
  schemaVersion: 1,
  sourceSha,
  requestedUrl: target.href,
  captures: [],
};

try {
  await fs.mkdir(outputDir, { recursive: true });

  for (const capture of captures) {
    const context = await browser.newContext({
      viewport: capture.viewport,
      deviceScaleFactor: 1,
      isMobile: capture.isMobile,
      hasTouch: capture.hasTouch,
    });

    try {
      const page = await context.newPage();
      const response = await page.goto(target.href, { waitUntil: 'domcontentloaded', timeout: 45000 });
      if (!response || !response.ok()) {
        throw new Error(`Production visual navigation failed: ${response?.status() ?? 'no response'}`);
      }

      await page.waitForLoadState('load', { timeout: 15000 }).catch(() => {});
      await page.evaluate(async () => {
        document.documentElement.classList.add('c5-visual-test');
        if (document.fonts?.ready) await document.fonts.ready;
      });
      await page.waitForTimeout(1000);

      for (const selector of canonicalVisualRegions) {
        const region = page.locator(selector).first();
        await region.waitFor({ state: 'visible', timeout: 10000 });
        await region.scrollIntoViewIfNeeded();
      }
      await page.evaluate(() => window.scrollTo(0, 0));
      await page.waitForTimeout(300);

      const finalUrl = new URL(page.url());
      if (
        finalUrl.origin !== 'https://www.brvtal.com.co'
        || finalUrl.pathname !== '/'
        || finalUrl.username
        || finalUrl.password
      ) {
        throw new Error('Production visual capture left the canonical public Home.');
      }

      const outputPath = path.join(outputDir, capture.filename);
      const png = await page.screenshot({
        path: outputPath,
        fullPage: true,
        animations: 'disabled',
        caret: 'hide',
        scale: 'css',
      });

      evidence.captures.push({
        name: capture.name,
        viewport: capture.viewport,
        filename: capture.filename,
        sha256: createHash('sha256').update(png).digest('hex'),
        finalUrl: finalUrl.href,
      });
    } finally {
      await context.close();
    }
  }

  await fs.writeFile(
    path.join(outputDir, 'production-concept05-visual.json'),
    `${JSON.stringify(evidence, null, 2)}\n`,
    'utf8',
  );
} finally {
  await browser.close();
}
