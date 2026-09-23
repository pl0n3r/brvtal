import { test, expect } from '@playwright/test';

const baseUrl = process.env.BRVTAL_REAL_STACK_URL || '';
const adminEmail = process.env.BRVTAL_REAL_STACK_ADMIN_EMAIL || 'ci-admin@brvtal.test';
const adminPassword = process.env.BRVTAL_REAL_STACK_ADMIN_PASSWORD || '';

test.skip(!baseUrl || !adminPassword, 'BRVTAL real-stack admin credentials are required');

async function login(page) {
  const response = await page.request.post(`${baseUrl}/api/index.php/auth`, {
    data:{email:adminEmail,password:adminPassword},
  });
  expect(response.ok(), `Admin login failed with HTTP ${response.status()}`).toBeTruthy();
}

test('authenticated Dashboard V2 removes redundant auth and legacy preload overhead', async ({page}) => {
  await login(page);

  const observed = [];
  page.on('request', request => {
    try {
      const url = new URL(request.url());
      if (url.origin === new URL(baseUrl).origin) {
        observed.push({method:request.method(),path:url.pathname,search:url.search});
      }
    } catch {}
  });

  const loadStarted = performance.now();
  await page.goto(`${baseUrl}/discadmin/`, {waitUntil:'domcontentloaded'});
  await page.waitForFunction(() => Boolean(window.state?.authed), null, {timeout:10_000});
  await expect(page.locator('#brvtal-dashboard-v2 .dashboard-v2-hero')).toBeVisible({timeout:10_000});
  const optimizedInitialLoadMs = performance.now() - loadStarted;

  const authGets = observed.filter(item => item.method === 'GET' && item.path === '/api/index.php/auth');
  const legacyDashboardGets = observed.filter(item => item.method === 'GET' && item.path === '/api/index.php/dashboard');
  expect(authGets).toHaveLength(1);
  expect(legacyDashboardGets).toHaveLength(0);

  const controlled = await page.evaluate(async () => {
    const json = async url => {
      const response = await fetch(url,{credentials:'same-origin',cache:'no-store'});
      if (!response.ok) throw new Error(`HTTP_${response.status}_${url}`);
      await response.text();
    };
    const dashboardSources = [
      '/api/dashboard-overview.php',
      '/api/content-health.php',
      '/api/index.php/health',
      '/discadmin/storage-metrics.php',
      '/api/admin-activity.php?limit=4',
    ];

    const legacyStarted = performance.now();
    for (let index = 0; index < 9; index += 1) {
      await json('/api/index.php/auth');
    }
    await json('/api/index.php/dashboard');
    await Promise.all(dashboardSources.map(json));
    const legacyControlMs = performance.now() - legacyStarted;

    const optimizedStarted = performance.now();
    await window.BRVTALDashboardV2.mount();
    const optimizedRefreshMs = performance.now() - optimizedStarted;

    return {legacyControlMs,optimizedRefreshMs};
  });

  console.log(
    '[admin-performance] ' +
    JSON.stringify({
      optimizedInitialLoadMs:Math.round(optimizedInitialLoadMs * 10) / 10,
      legacyControlMs:Math.round(controlled.legacyControlMs * 10) / 10,
      optimizedRefreshMs:Math.round(controlled.optimizedRefreshMs * 10) / 10,
      initialAuthGets:authGets.length,
      legacyDashboardGets:legacyDashboardGets.length,
    })
  );

  expect(controlled.optimizedRefreshMs).toBeLessThan(controlled.legacyControlMs);
});


test('paginated list search treats percent and underscore as literal MariaDB characters', async ({page}) => {
  await login(page);
  for (const query of ['%', '_']) {
    const response=await page.request.get(`${baseUrl}/api/index.php/artists?page=1&q=${encodeURIComponent(query)}`);
    expect(response.ok()).toBeTruthy();
    const result=await response.json();
    expect(result.pagination.total, `Search ${query} must not match a wildcard`).toBe(0);
    expect(result.data).toEqual([]);
  }
  const control=await page.request.get(`${baseUrl}/api/index.php/artists?page=1&q=SMOKE`);
  expect(control.ok()).toBeTruthy();
  expect((await control.json()).pagination.total).toBeGreaterThan(0);
});
