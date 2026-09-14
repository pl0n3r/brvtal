import fs from 'node:fs/promises';
import path from 'node:path';
import { chromium, devices } from '@playwright/test';

const targetUrl = process.env.BRVTAL_PERF_URL || 'https://www.brvtal.com.co/';
const mode = process.env.BRVTAL_PERF_MODE || 'mobile';
const outputPath = process.env.BRVTAL_PERF_OUTPUT || `artifacts/production-performance-${mode}.json`;

if (!['mobile', 'desktop'].includes(mode)) {
  throw new Error(`Unsupported BRVTAL_PERF_MODE: ${mode}`);
}

const round = (value) => Number.isFinite(value) ? Math.round(value * 10) / 10 : null;
const formatMs = (value) => value == null ? '—' : `${Math.round(value)} ms`;
const formatKb = (value) => value == null ? '—' : `${Math.round(value / 1024)} KiB`;

const browser = await chromium.launch({ headless: true });

try {
  const contextOptions = mode === 'mobile'
    ? { ...devices['Pixel 5'] }
    : {
        viewport: { width: 1440, height: 900 },
        deviceScaleFactor: 1,
        isMobile: false,
        hasTouch: false,
      };

  const context = await browser.newContext(contextOptions);
  await context.addInitScript(() => {
    window.__brvtalPerf = { lcp: [], cls: 0 };

    const selectorFor = (element) => {
      if (!element) return null;
      if (element.id) return `#${CSS.escape(element.id)}`;
      let selector = String(element.tagName || '').toLowerCase();
      const classes = [...(element.classList || [])].slice(0, 3).map((name) => CSS.escape(name));
      if (classes.length) selector += `.${classes.join('.')}`;
      return selector || null;
    };

    try {
      new PerformanceObserver((list) => {
        for (const entry of list.getEntries()) {
          window.__brvtalPerf.lcp.push({
            startTime: entry.startTime,
            renderTime: entry.renderTime,
            loadTime: entry.loadTime,
            size: entry.size,
            url: entry.url || '',
            selector: selectorFor(entry.element),
            tagName: entry.element?.tagName?.toLowerCase() || null,
          });
        }
      }).observe({ type: 'largest-contentful-paint', buffered: true });
    } catch {}

    try {
      new PerformanceObserver((list) => {
        for (const entry of list.getEntries()) {
          if (!entry.hadRecentInput) window.__brvtalPerf.cls += entry.value;
        }
      }).observe({ type: 'layout-shift', buffered: true });
    } catch {}
  });

  const page = await context.newPage();
  const response = await page.goto(targetUrl, { waitUntil: 'domcontentloaded', timeout: 45000 });
  if (!response || !response.ok()) {
    throw new Error(`Production navigation failed: ${response?.status() ?? 'no response'}`);
  }

  await page.waitForLoadState('load', { timeout: 15000 }).catch(() => {});
  await page.waitForTimeout(3000);

  const result = await page.evaluate(() => {
    const nav = performance.getEntriesByType('navigation')[0];
    const paints = Object.fromEntries(
      performance.getEntriesByType('paint').map((entry) => [entry.name, entry.startTime]),
    );
    const resources = performance.getEntriesByType('resource');
    const lcpEntries = window.__brvtalPerf?.lcp || [];
    const lcp = lcpEntries.length ? lcpEntries[lcpEntries.length - 1] : null;

    let lcpResource = null;
    if (lcp?.url) {
      let target;
      try { target = new URL(lcp.url, location.href); } catch {}
      if (target) {
        for (let index = resources.length - 1; index >= 0; index -= 1) {
          const resource = resources[index];
          let candidate;
          try { candidate = new URL(resource.name, location.href); } catch { continue; }
          if (candidate.href === target.href || candidate.pathname === target.pathname) {
            lcpResource = resource;
            break;
          }
        }
      }
    }

    const transferByType = {};
    for (const resource of resources) {
      const type = resource.initiatorType || 'other';
      transferByType[type] = (transferByType[type] || 0) + (resource.transferSize || 0);
    }

    return {
      finalUrl: location.href,
      title: document.title,
      document: nav ? {
        responseStart: nav.responseStart,
        domContentLoaded: nav.domContentLoadedEventEnd,
        loadEventEnd: nav.loadEventEnd,
        transferSize: nav.transferSize,
        encodedBodySize: nav.encodedBodySize,
        decodedBodySize: nav.decodedBodySize,
      } : null,
      firstContentfulPaint: paints['first-contentful-paint'] ?? null,
      cls: window.__brvtalPerf?.cls ?? 0,
      lcp,
      lcpResource: lcpResource ? {
        name: lcpResource.name,
        initiatorType: lcpResource.initiatorType,
        requestStart: lcpResource.requestStart,
        responseStart: lcpResource.responseStart,
        responseEnd: lcpResource.responseEnd,
        transferSize: lcpResource.transferSize,
        encodedBodySize: lcpResource.encodedBodySize,
        decodedBodySize: lcpResource.decodedBodySize,
      } : null,
      resourceCount: resources.length,
      transferByType,
    };
  });

  if (!result.lcp) {
    throw new Error('No Largest Contentful Paint entry was observed');
  }

  const responseStart = result.document?.responseStart ?? null;
  const resource = result.lcpResource;
  const lcpStart = result.lcp.startTime;
  const breakdown = {
    timeToFirstByte: responseStart,
    resourceLoadDelay: resource && responseStart != null
      ? Math.max(0, resource.requestStart - responseStart)
      : null,
    resourceLoadDuration: resource
      ? Math.max(0, resource.responseEnd - resource.requestStart)
      : null,
    elementRenderDelay: resource
      ? Math.max(0, lcpStart - resource.responseEnd)
      : (responseStart != null ? Math.max(0, lcpStart - responseStart) : null),
  };

  const report = {
    measuredAt: new Date().toISOString(),
    mode,
    requestedUrl: targetUrl,
    finalUrl: result.finalUrl,
    metrics: {
      firstContentfulPaint: round(result.firstContentfulPaint),
      largestContentfulPaint: round(lcpStart),
      cumulativeLayoutShift: round(result.cls),
      domContentLoaded: round(result.document?.domContentLoaded),
      loadEventEnd: round(result.document?.loadEventEnd),
    },
    lcp: {
      selector: result.lcp.selector,
      tagName: result.lcp.tagName,
      url: result.lcp.url || null,
      size: round(result.lcp.size),
      resourceTransferSize: resource?.transferSize ?? null,
      resourceEncodedBodySize: resource?.encodedBodySize ?? null,
      resourceDecodedBodySize: resource?.decodedBodySize ?? null,
      breakdown: Object.fromEntries(Object.entries(breakdown).map(([key, value]) => [key, round(value)])),
    },
    page: {
      title: result.title,
      documentTransferSize: result.document?.transferSize ?? null,
      resourceCount: result.resourceCount,
      transferByType: result.transferByType,
    },
  };

  await fs.mkdir(path.dirname(outputPath), { recursive: true });
  await fs.writeFile(outputPath, `${JSON.stringify(report, null, 2)}\n`, 'utf8');

  const summary = [
    `## Production Performance · ${mode}`,
    '',
    `Target: \`${report.finalUrl}\``,
    '',
    '| Metric | Value |',
    '|---|---:|',
    `| FCP | ${formatMs(report.metrics.firstContentfulPaint)} |`,
    `| LCP | ${formatMs(report.metrics.largestContentfulPaint)} |`,
    `| CLS | ${report.metrics.cumulativeLayoutShift ?? '—'} |`,
    `| TTFB | ${formatMs(report.lcp.breakdown.timeToFirstByte)} |`,
    `| LCP resource load delay | ${formatMs(report.lcp.breakdown.resourceLoadDelay)} |`,
    `| LCP resource load duration | ${formatMs(report.lcp.breakdown.resourceLoadDuration)} |`,
    `| LCP element render delay | ${formatMs(report.lcp.breakdown.elementRenderDelay)} |`,
    `| LCP resource transfer | ${formatKb(report.lcp.resourceTransferSize)} |`,
    `| Requests | ${report.page.resourceCount} |`,
    '',
    `LCP element: \`${report.lcp.selector || report.lcp.tagName || 'unknown'}\``,
    report.lcp.url ? `LCP resource: \`${report.lcp.url}\`` : 'LCP resource: text / no external resource',
    '',
  ].join('\n');

  console.log(summary);
  if (process.env.GITHUB_STEP_SUMMARY) {
    await fs.appendFile(process.env.GITHUB_STEP_SUMMARY, `${summary}\n`, 'utf8');
  }
} finally {
  await browser.close();
}
