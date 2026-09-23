import { chromium } from '@playwright/test';
import { observeBeforeAuthenticate, observeRelease } from './production-release-observer.mjs';
import { createHmac } from 'node:crypto';
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname } from 'node:path';

const baseUrl = String(process.env.BRVTAL_PROD_URL || 'https://www.brvtal.com.co').replace(/\/$/, '');
const adminEmail = String(process.env.BRVTAL_PROD_ADMIN_EMAIL || '').trim();
const adminPassword = String(process.env.BRVTAL_PROD_ADMIN_PASSWORD || '');
const totpSecret = String(process.env.BRVTAL_PROD_TOTP_SECRET || '').trim();
const expectedSha = String(process.env.BRVTAL_EXPECTED_SHA || '').trim();
const outputPath = String(process.env.BRVTAL_PROD_SMOKE_OUTPUT || 'artifacts/production-authenticated-smoke.json');
const versionSource = readFileSync(new URL('../../config/version.php', import.meta.url), 'utf8');
const versionMatch = versionSource.match(/BRVTAL_APP_VERSION\s*=\s*'([^']+)'/);
if (!versionMatch) throw new Error('Canonical BRVTAL_APP_VERSION could not be parsed.');
const expectedVersion = versionMatch[1];

if (!adminEmail || !adminPassword) {
  throw new Error('BRVTAL_PROD_ADMIN_EMAIL and BRVTAL_PROD_ADMIN_PASSWORD are required.');
}

if (baseUrl !== 'https://www.brvtal.com.co') {
  throw new Error(`Authenticated production smoke only accepts the canonical origin; received ${baseUrl}`);
}

const evidence = {
  checkedAt: new Date().toISOString(),
  baseUrl,
  expectedSha: expectedSha || null,
  expectedVersion,
  releaseObserved: false,
  deploymentExact: null,
  observedDeployment: null,
  deploymentProbeErrors: [],
  authentication: { totp: false },
  checks: {
    health: null,
    home: null,
    adminDocument: null,
    dashboardLoad: null,
    adminVersion: null,
    eventsWorkspace: null,
    eventDate: null,
    setRelations: null,
    heroSlider: []
  },
  localStubs: [],
  blockedMutations: [],
  status: 'running'
};

function writeEvidence() {
  mkdirSync(dirname(outputPath), { recursive: true });
  writeFileSync(outputPath, `${JSON.stringify(evidence, null, 2)}\n`, 'utf8');
}

function decodeBase32(value) {
  const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
  const clean = String(value || '').toUpperCase().replace(/[^A-Z2-7]/g, '');
  if (!clean) throw new Error('BRVTAL_PROD_TOTP_SECRET is empty or invalid.');
  let bits = '';
  for (const char of clean) {
    const index = alphabet.indexOf(char);
    if (index < 0) throw new Error('BRVTAL_PROD_TOTP_SECRET contains invalid Base32 data.');
    bits += index.toString(2).padStart(5, '0');
  }
  const bytes = [];
  for (let offset = 0; offset + 8 <= bits.length; offset += 8) {
    bytes.push(Number.parseInt(bits.slice(offset, offset + 8), 2));
  }
  return Buffer.from(bytes);
}

function currentTotp(secret, atMs = Date.now()) {
  const counter = Math.floor(atMs / 1000 / 30);
  const buffer = Buffer.alloc(8);
  buffer.writeBigUInt64BE(BigInt(counter));
  const digest = createHmac('sha1', decodeBase32(secret)).update(buffer).digest();
  const offset = digest[digest.length - 1] & 0x0f;
  const binary = ((digest[offset] & 0x7f) << 24)
    | ((digest[offset + 1] & 0xff) << 16)
    | ((digest[offset + 2] & 0xff) << 8)
    | (digest[offset + 3] & 0xff);
  return String(binary % 1_000_000).padStart(6, '0');
}

function normalizeDatetimeLocal(value) {
  return String(value || '').trim().replace(' ', 'T').slice(0, 16);
}

async function jsonOrThrow(response, label) {
  const body = await response.text();
  let payload = null;
  try { payload = JSON.parse(body); } catch (_) {}
  if (!response.ok() || !payload) {
    throw new Error(`${label} failed with HTTP ${response.status()}.`);
  }
  return payload;
}

async function observeProductionRelease(request) {
  const result = await observeRelease({
    request,
    baseUrl,
    expectedVersion,
    expectedSha,
    attempts: 36,
    requestTimeoutMs: 5_000,
    sleepMs: 10_000,
    onObservation: ({ attempt, deployment, error }) => {
      if (deployment) {
        evidence.observedDeployment = deployment;
        evidence.deploymentExact = deployment.exact;
      }
      if (error) {
        evidence.deploymentProbeErrors.push({ attempt, error });
      }
      writeEvidence();
    }
  });

  evidence.releaseObserved = result.releaseObserved;
  evidence.observedDeployment = result.deployment;
  evidence.deploymentExact = result.deployment.exact;
  writeEvidence();
}
async function authenticate(context) {
  const loginResponse = await context.request.post(`${baseUrl}/api/index.php/auth`, {
    headers: { 'Content-Type': 'application/json' },
    data: { email: adminEmail, password: adminPassword },
    timeout: 12_000
  });
  const login = await jsonOrThrow(loginResponse, 'Admin login');
  if (login.ok !== true) throw new Error(`Admin login rejected: ${login.error || 'UNKNOWN_ERROR'}`);

  if (login.requires_totp) {
    if (!totpSecret) throw new Error('Production admin requires 2FA; configure BRVTAL_PROD_TOTP_SECRET.');
    evidence.authentication.totp = true;
    const verifyResponse = await context.request.post(`${baseUrl}/api/index.php/auth`, {
      headers: { 'Content-Type': 'application/json' },
      data: { action: 'totp_verify', code: currentTotp(totpSecret) },
      timeout: 12_000
    });
    const verified = await jsonOrThrow(verifyResponse, 'TOTP verification');
    if (verified.ok !== true) throw new Error(`TOTP verification rejected: ${verified.error || 'UNKNOWN_ERROR'}`);
  }

  const sessionResponse = await context.request.get(`${baseUrl}/api/index.php/auth`, { timeout: 12_000 });
  const session = await jsonOrThrow(sessionResponse, 'Authenticated session check');
  if (session.authenticated !== true || !session.csrf) throw new Error('Authenticated production session was not established.');
}

async function getAdminCollection(context, resource) {
  const response = await context.request.get(`${baseUrl}/api/index.php/${resource}`, {
    headers: { 'Cache-Control': 'no-cache' },
    timeout: 12_000
  });
  const payload = await jsonOrThrow(response, `${resource} read`);
  if (payload.ok !== true || !Array.isArray(payload.data)) throw new Error(`${resource} returned an invalid collection.`);
  return payload.data;
}

async function navigate(page, label, section) {
  const button = page.getByRole('button', { name: new RegExp(`^${label}$`, 'i') }).first();
  if (await button.count()) {
    await button.click();
    return;
  }
  await page.evaluate(target => window.go(target), section);
}

const browser = await chromium.launch({ headless: true });
const context = await browser.newContext({
  viewport: { width: 1440, height: 1000 },
  serviceWorkers: 'block'
});

try {
  await observeBeforeAuthenticate(
    () => observeProductionRelease(context.request),
    () => authenticate(context)
  );

  // Assert source identity and database health directly, not solely from the
  // deployment marker. Capture public home HTTP status without private data.
  const healthResponse = await context.request.get(`${baseUrl}/api/health.php`, {
    timeout: 12_000,
    headers: { 'Cache-Control': 'no-cache' }
  });
  const health = await jsonOrThrow(healthResponse, 'Production health');
  const deployment = health.deployment || {};
  evidence.checks.health = {
    httpStatus: healthResponse.status(),
    database: health.database,
    version: deployment.version,
    commit: deployment.commit,
    exact: deployment.exact === true,
    pass: healthResponse.status() === 200 && health.ok === true
      && health.database === 'connected' && deployment.exact === true
      && deployment.version === expectedVersion && deployment.commit === expectedSha
  };
  writeEvidence();
  if (!evidence.checks.health.pass) throw new Error('Production health/version/SHA/database does not match exact main.');

  const homeResponse = await context.request.get(`${baseUrl}/`, { timeout: 12_000 });
  evidence.checks.home = { httpStatus: homeResponse.status(), pass: homeResponse.status() === 200 };
  writeEvidence();
  if (!evidence.checks.home.pass) throw new Error(`Production home returned HTTP ${homeResponse.status()}.`);

  // From this point forward the browser is content-read-only. DISCADMIN performs
  // a media-permission repair POST during session bootstrap; fulfill that request
  // locally so the smoke never sends it to production. Any other browser mutation
  // is blocked and makes the run fail.
  await context.route('**/*', async route => {
    const request = route.request();
    const method = request.method().toUpperCase();
    if (['GET', 'HEAD', 'OPTIONS'].includes(method)) {
      await route.continue();
      return;
    }
    const url = new URL(request.url());
    if (method === 'POST' && url.origin === baseUrl && url.pathname === '/api/media-permissions.php') {
      evidence.localStubs.push(`${method} ${url.pathname}`);
      await route.fulfill({
        status: 200,
        contentType: 'application/json; charset=utf-8',
        body: JSON.stringify({ ok: true, smoke_stub: true })
      });
      return;
    }
    evidence.blockedMutations.push(`${method} ${url.origin}${url.pathname}`);
    await route.abort('blockedbyclient');
  });

  const page = await context.newPage();
  const serverErrors = [];
  page.on('response', response => {
    if (response.status() >= 500) {
      const url = new URL(response.url());
      if (url.origin === baseUrl) serverErrors.push({ path: url.pathname, status: response.status() });
    }
  });
  const dashboardStarted = performance.now();
  const adminResponse = await page.goto(`${baseUrl}/discadmin/`, {
    waitUntil: 'domcontentloaded',
    timeout: 20_000
  });
  evidence.checks.adminDocument = {
    httpStatus: adminResponse?.status() ?? null,
    pass: adminResponse?.status() === 200
  };
  writeEvidence();
  if (!evidence.checks.adminDocument.pass) {
    throw new Error(`Production DISCADMIN document returned HTTP ${adminResponse?.status() ?? 'none'}.`);
  }
  await page.waitForFunction(() => typeof window.go === 'function' && document.querySelector('.shell'), null, { timeout: 15_000 });
  await page.locator('#brvtal-dashboard-v2 .dashboard-v2-hero').waitFor({
    state: 'visible',
    timeout: 20_000
  });
  evidence.checks.dashboardLoad = {
    elapsedMs: Math.round(performance.now() - dashboardStarted),
    visibleHero: true,
    serverErrors,
    pass: serverErrors.length === 0
  };
  writeEvidence();
  if (serverErrors.length) {
    throw new Error('Production dashboard returned HTTP 5xx on ' + serverErrors.map(item => item.path).join(', '));
  }

  const expectedVersionText = `BRVTAL v${expectedVersion}`;
  const renderedVersion = (await page.getByTestId('admin-product-version').innerText()).trim();
  evidence.checks.adminVersion = {
    expected: expectedVersionText,
    rendered: renderedVersion,
    pass: renderedVersion === expectedVersionText
  };
  writeEvidence();
  if (renderedVersion !== expectedVersionText) {
    throw new Error(`Admin product version mismatch: expected ${expectedVersionText}, rendered ${renderedVersion || '(empty)'}.`);
  }


  const [events, artists] = await Promise.all([
    getAdminCollection(context, 'events'),
    getAdminCollection(context, 'artists')
  ]);
  const datedEvent = events.find(event => String(event.event_date || '').trim());
  if (!datedEvent) throw new Error('Production has no dated Event available for the #123 read-only verification.');
  const publishedArtist = artists.find(artist => artist.status === 'published');
  const publishedEvent = events.find(event => event.status === 'published');
  if (!publishedArtist || !publishedEvent) {
    throw new Error('Production needs at least one published Artist and one published Event to verify #124 without creating data.');
  }

  // The canonical EVENTS sidebar opens the native Events grid. Content Core
  // is a separate module; requiring its hidden host here is an obsolete smoke
  // assumption and does not reflect the user's Events workspace.
  await navigate(page, 'EVENTS', 'events');
  await page.locator('[data-admin-nav="events"].active').waitFor({ state: 'visible', timeout: 15_000 });
  await page.locator('.main .toolbar .search:visible').waitFor({ state: 'visible', timeout: 15_000 });
  const eventsHeadingLocator = page.locator('.main .top h1');
  await eventsHeadingLocator.waitFor({ state: 'visible', timeout: 15_000 });
  const eventsHeading = (await eventsHeadingLocator.innerText()).trim().toUpperCase();
  await page.locator('#rows').getByRole('button', { name: /^EDIT$/i }).first()
    .waitFor({ state: 'visible', timeout: 10_000 });
  evidence.checks.eventsWorkspace = {
    heading: eventsHeading,
    visibleSearch: true,
    nativeEditVisible: true,
    pass: eventsHeading === 'EVENTS'
  };
  writeEvidence();
  if (eventsHeading !== 'EVENTS') {
    throw new Error('Canonical Events workspace is inconsistent.');
  }

  // Choose a dated record that was actually loaded into the native grid. This
  // also works when the next Admin release enables opt-in server pagination.
  const visibleDatedEvent = await page.evaluate(() => {
    if (typeof state === 'undefined' || !Array.isArray(state.rows)) return null;
    const row = state.rows.find(item => String(item.event_date || '').trim());
    return row ? { id: Number(row.id), event_date: String(row.event_date) } : null;
  });
  if (!visibleDatedEvent) {
    throw new Error('Native Events grid did not load a dated Event on its current page.');
  }
  const matchingEvent = events.find(event => Number(event.id) === visibleDatedEvent.id);
  if (!matchingEvent) throw new Error('Native Events grid record was not returned by the authenticated Events API.');
  await page.evaluate(id => window.openModal('events', id), visibleDatedEvent.id);
  await page.locator('#modal').waitFor({ state: 'visible', timeout: 10_000 });
  const expectedDate = normalizeDatetimeLocal(matchingEvent.event_date);
  const renderedDate = await page.locator('#f_event_date').inputValue();
  if (renderedDate !== expectedDate) {
    throw new Error(`Event date reopen failed: Event #${visibleDatedEvent.id} expected ${expectedDate}, rendered ${renderedDate || '(empty)'}.`);
  }
  evidence.checks.eventDate = {
    eventId: visibleDatedEvent.id,
    expected: expectedDate,
    rendered: renderedDate,
    pass: true
  };
  await page.evaluate(() => window.closeModal());

  // #124 — navigating to Sets must hydrate real Artist/Event relations before New Set opens.
  await navigate(page, 'SETS', 'sets');
  await page.waitForFunction(() => document.querySelector('.main')?.textContent?.toUpperCase().includes('SETS'), null, { timeout: 10_000 });
  await page.evaluate(() => window.openModal('sets'));
  await page.locator('#modal').waitFor({ state: 'visible', timeout: 8_000 });
  const artistOption = await page.locator(`#f_artist_id option[value="${Number(publishedArtist.id)}"]`).count();
  const eventOption = await page.locator(`#f_event_id option[value="${Number(publishedEvent.id)}"]`).count();
  if (!artistOption || !eventOption) {
    throw new Error(`#124 failed: published relation options missing (artist=${Boolean(artistOption)}, event=${Boolean(eventOption)}).`);
  }
  evidence.checks.setRelations = {
    publishedArtistId: Number(publishedArtist.id),
    publishedEventId: Number(publishedEvent.id),
    artistOptionPresent: true,
    eventOptionPresent: true,
    pass: true
  };
  await page.evaluate(() => window.closeModal());

  // #125 — repeatedly open the manager; loading must settle and no error may remain.
  for (let attempt = 1; attempt <= 3; attempt += 1) {
    await page.evaluate(() => window.go('hero-slider'));
    await page.locator('#hero-slider-root').waitFor({ state: 'visible', timeout: 8_000 });
    await page.locator('.hero-manager').waitFor({ state: 'visible', timeout: 15_000 });
    const loading = await page.locator('.hero-slider-loading').count();
    const errors = await page.locator('.hero-slider-error').count();
    if (loading || errors) throw new Error(`#125 failed on Hero Slider attempt ${attempt}: loading=${loading}, errors=${errors}.`);
    evidence.checks.heroSlider.push({ attempt, pass: true });
    if (attempt < 3) {
      await page.evaluate(() => window.go('dashboard'));
      await page.waitForTimeout(250);
    }
  }

  if (serverErrors.length) {
    throw new Error('Authenticated DISCADMIN emitted HTTP 5xx on ' + serverErrors.map(item => item.path).join(', '));
  }
  if (evidence.blockedMutations.length) {
    throw new Error(`Read-only guard blocked unexpected production mutations: ${evidence.blockedMutations.join(', ')}`);
  }

  evidence.status = 'passed';
  writeEvidence();
  console.log('Authenticated production smoke passed for admin version and issues #123, #124 and #125.');
} catch (error) {
  evidence.status = 'failed';
  evidence.error = String(error?.message || error);
  writeEvidence();
  throw error;
} finally {
  await browser.close();
}
