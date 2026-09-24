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
const wholeSmokeTimeoutMs = Number.parseInt(String(process.env.BRVTAL_PROD_SMOKE_TIMEOUT_MS || '720000'), 10);
const operationTimeoutMs = Number.parseInt(String(process.env.BRVTAL_PROD_OPERATION_TIMEOUT_MS || '20000'), 10);
const versionSource = readFileSync(new URL('../../config/version.php', import.meta.url), 'utf8');
const versionMatch = versionSource.match(/BRVTAL_APP_VERSION\s*=\s*'([^']+)'/);
if (!versionMatch) throw new Error('Canonical BRVTAL_APP_VERSION could not be parsed.');
const expectedVersion = versionMatch[1];

if (!adminEmail || !adminPassword) {
  throw new Error('BRVTAL_PROD_ADMIN_EMAIL and BRVTAL_PROD_ADMIN_PASSWORD are required.');
}
if (!Number.isFinite(wholeSmokeTimeoutMs) || wholeSmokeTimeoutMs < 60_000) {
  throw new Error('BRVTAL_PROD_SMOKE_TIMEOUT_MS must be an integer >= 60000.');
}
if (!Number.isFinite(operationTimeoutMs) || operationTimeoutMs < 1_000) {
  throw new Error('BRVTAL_PROD_OPERATION_TIMEOUT_MS must be an integer >= 1000.');
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
  deploymentProbeFallbacks: [],
  observedDeploymentProbe: null,
  authentication: { totp: false },
  checks: {
    health: null,
    home: null,
    adminDocument: null,
    dashboardLoad: null,
    adminVersion: null,
    eventsWorkspace: null,
    eventDate: null,
    setsApiPage: null,
    setsBrowserNavigation: null,
    setRelations: null,
    heroSlider: []
  },
  localStubs: [],
  blockedMutations: [],
  execution: {
    startedAt: new Date().toISOString(),
    wholeTimeoutMs: wholeSmokeTimeoutMs,
    operationTimeoutMs,
    stage: 'bootstrap',
    operation: null,
    failureStage: null,
    failureOperation: null
  },
  status: 'running'
};

function writeEvidence() {
  mkdirSync(dirname(outputPath), { recursive: true });
  writeFileSync(outputPath, `${JSON.stringify(evidence, null, 2)}\n`, 'utf8');
}

function markStage(stage) {
  evidence.execution.stage = stage;
  evidence.execution.operation = null;
  writeEvidence();
}

async function runOperation(label, operation, timeoutMs = operationTimeoutMs) {
  evidence.execution.operation = label;
  writeEvidence();
  let timer = null;
  try {
    return await Promise.race([
      Promise.resolve().then(operation),
      new Promise((_, reject) => {
        timer = setTimeout(
          () => reject(new Error(`${label} timed out after ${timeoutMs} ms.`)),
          timeoutMs
        );
      })
    ]);
  } catch (error) {
    evidence.execution.failureStage ||= evidence.execution.stage;
    evidence.execution.failureOperation ||= label;
    writeEvidence();
    throw error;
  } finally {
    if (timer) clearTimeout(timer);
    evidence.execution.operation = null;
  }
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
    onObservation: ({ attempt, deployment, error, probe, fallbackReason, status }) => {
      if (deployment) {
        evidence.observedDeployment = deployment;
        evidence.deploymentExact = deployment.exact;
        evidence.observedDeploymentProbe = probe || null;
      }
      if (error) {
        evidence.deploymentProbeErrors.push({ attempt, probe: probe || null, status: status ?? null, error });
      }
      if (fallbackReason) {
        evidence.deploymentProbeFallbacks.push({
          attempt,
          probe: probe || null,
          status: status ?? null,
          reason: fallbackReason
        });
      }
      writeEvidence();
    }
  });

  evidence.releaseObserved = result.releaseObserved;
  evidence.observedDeployment = result.deployment;
  evidence.deploymentExact = result.deployment.exact;
  evidence.observedDeploymentProbe = result.probe || null;
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

async function navigate(page, section) {
  const button = page.locator(`[data-admin-nav="${section}"]`).first();
  await runOperation(
    `navigate:${section}`,
    async () => {
      await button.waitFor({ state: 'visible', timeout: 8_000 });
      await button.click();
      await page.waitForFunction(
        target => typeof state !== 'undefined' && state.section === target,
        section,
        { timeout: Math.max(1_000, operationTimeoutMs - 2_000) }
      );
    },
    operationTimeoutMs
  );
}

let browser = null;
const wholeSmokeTimer = setTimeout(() => {
  evidence.status = 'failed';
  evidence.error = `Whole production smoke timed out after ${wholeSmokeTimeoutMs} ms.`;
  evidence.execution.failureStage = evidence.execution.stage;
  evidence.execution.failureOperation = evidence.execution.operation;
  writeEvidence();
  console.error(evidence.error);
  process.exit(124);
}, wholeSmokeTimeoutMs);

browser = await chromium.launch({ headless: true });
const context = await browser.newContext({
  viewport: { width: 1440, height: 1000 },
  serviceWorkers: 'block'
});

try {
  markStage('release-authentication');
  await runOperation('release-authentication', async () => {
    await observeBeforeAuthenticate(
      () => observeProductionRelease(context.request),
      () => authenticate(context)
    );
  }, Math.min(wholeSmokeTimeoutMs - 30_000, 420_000));

  // Assert source identity and database health directly, not solely from the
  // deployment marker. Capture public home HTTP status without private data.
  markStage('health');
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

  markStage('home');
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
  page.setDefaultTimeout(operationTimeoutMs);
  page.setDefaultNavigationTimeout(Math.max(operationTimeoutMs, 20_000));
  const serverErrors = [];
  page.on('response', response => {
    if (response.status() >= 500) {
      const url = new URL(response.url());
      if (url.origin === baseUrl) serverErrors.push({ path: url.pathname, status: response.status() });
    }
  });
  markStage('dashboard');
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


  markStage('production-fixtures');
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

  // Events uses the canonical native grid plus a hidden Content Core host for
  // the guided editor. Wait for the asynchronous host/simplifier to settle:
  // two temporarily visible search inputs are not a completed workspace.
  markStage('events-workspace');
  await navigate(page, 'events');
  await page.locator('[data-admin-nav="events"].active').waitFor({ state: 'visible', timeout: 15_000 });
  await page.locator('#admin-module-host [data-admin-module="content-core"][data-ia-context="events"]')
    .waitFor({ state: 'attached', timeout: 15_000 });
  await page.locator('#admin-module-host [data-admin-module="content-core"] .wrap')
    .waitFor({ state: 'hidden', timeout: 15_000 });
  await page.locator('.main [data-admin-grid-search]:visible')
    .waitFor({ state: 'visible', timeout: 15_000 });
  const visibleSearchCount = await page.locator('.main .toolbar .search:visible').count();
  if (visibleSearchCount !== 1) {
    throw new Error(`Events workspace exposes ${visibleSearchCount} search inputs; expected exactly one canonical visible search.`);
  }
  const eventsHeadingLocator = page.locator('.main .top h1');
  await eventsHeadingLocator.waitFor({ state: 'visible', timeout: 15_000 });
  const eventsHeading = (await eventsHeadingLocator.innerText()).trim().toUpperCase();
  await page.locator('#rows').getByRole('button', { name: /^EDIT$/i }).first()
    .waitFor({ state: 'visible', timeout: 10_000 });
  evidence.checks.eventsWorkspace = {
    heading: eventsHeading,
    visibleSearch: true,
    visibleSearchCount,
    nativeEditVisible: true,
    pass: eventsHeading === 'EVENTS'
  };
  writeEvidence();
  if (eventsHeading !== 'EVENTS') {
    throw new Error('Canonical Events workspace is inconsistent.');
  }

  // Choose a dated record that was actually loaded into the native grid. This
  // also works when the next Admin release enables opt-in server pagination.
  markStage('event-editor');
  const visibleDatedEvent = await runOperation('events-grid-state', () => page.evaluate(() => {
    if (typeof state === 'undefined' || !Array.isArray(state.rows)) return null;
    const row = state.rows.find(item => String(item.event_date || '').trim());
    return row ? { id: Number(row.id), event_date: String(row.event_date) } : null;
  }));
  if (!visibleDatedEvent) {
    throw new Error('Native Events grid did not load a dated Event on its current page.');
  }
  const matchingEvent = events.find(event => Number(event.id) === visibleDatedEvent.id);
  if (!matchingEvent) throw new Error('Native Events grid record was not returned by the authenticated Events API.');
  // Exercise the actual user's EDIT button, not the implementation helper.
  await runOperation(
    'events-edit-click',
    () => page.locator(`#rows [data-grid-action="edit"][data-grid-id="${visibleDatedEvent.id}"]`).click()
  );
  await page.locator('#eventModal').waitFor({ state: 'visible', timeout: 10_000 });
  const editorHeading = (await page.locator('#eventHeading').innerText()).trim();
  if (editorHeading !== 'EDIT EVENT') throw new Error('Events EDIT opened a new record instead of the selected event.');
  const gridDate = normalizeDatetimeLocal(visibleDatedEvent.event_date);
  const expectedDate = normalizeDatetimeLocal(matchingEvent.event_date);
  if (!gridDate || !expectedDate || gridDate !== expectedDate) {
    throw new Error(`Event #${visibleDatedEvent.id} grid/API date mismatch (grid=${gridDate || '(empty)'}, API=${expectedDate || '(empty)'}).`);
  }
  const renderedDate = await page.locator('#e_event_date').inputValue();
  if (renderedDate !== expectedDate) {
    throw new Error(`Event date reopen failed: Event #${visibleDatedEvent.id} expected ${expectedDate}, rendered ${renderedDate || '(empty)'}.`);
  }
  evidence.checks.eventDate = {
    eventId: visibleDatedEvent.id,
    expected: expectedDate,
    grid: gridDate,
    rendered: renderedDate,
    pass: true
  };
  writeEvidence();
  await runOperation('events-close-editor', () => page.evaluate(() => {
    window.BRVTALContentCore.closeEvent();
    return true;
  }));
  await page.locator('#eventModal').waitFor({ state: 'hidden', timeout: 8_000 });

  // #124 — separate API latency from browser/navigation behavior before opening New Set.
  markStage('sets-relations');
  const setsApiStarted = performance.now();
  const setsApiResponse = await runOperation(
    'sets-api-page',
    () => context.request.get(`${baseUrl}/api/index.php/sets?page=1&page_size=50`, {
      headers: { 'Cache-Control': 'no-cache' },
      timeout: 15_000
    }),
    17_000
  );
  const setsApiPayload = await runOperation(
    'sets-api-json',
    () => jsonOrThrow(setsApiResponse, 'Sets paginated read')
  );
  evidence.checks.setsApiPage = {
    httpStatus: setsApiResponse.status(),
    elapsedMs: Math.round(performance.now() - setsApiStarted),
    rowCount: Array.isArray(setsApiPayload.data) ? setsApiPayload.data.length : null,
    total: Number(setsApiPayload.pagination?.total ?? 0),
    pageSize: Number(setsApiPayload.pagination?.page_size ?? 0),
    pass: setsApiResponse.ok()
      && Array.isArray(setsApiPayload.data)
      && Number(setsApiPayload.pagination?.page_size ?? 0) === 50
  };
  writeEvidence();
  if (!evidence.checks.setsApiPage.pass) {
    throw new Error(`Sets paginated API check failed (HTTP ${setsApiResponse.status()}).`);
  }

  const setsBrowserStarted = performance.now();
  const setsBrowserNavigation = {
    requestSeen: false,
    responseSeen: false,
    responseStatus: null,
    requestFailure: null,
    elapsedMs: null
  };
  evidence.checks.setsBrowserNavigation = setsBrowserNavigation;
  const isSetsListRequest = rawUrl => {
    const url = new URL(rawUrl);
    return url.origin === baseUrl
      && url.pathname === '/api/index.php/sets'
      && url.searchParams.get('page') === '1'
      && url.searchParams.get('page_size') === '50';
  };
  const onSetsRequest = request => {
    if (!isSetsListRequest(request.url())) return;
    setsBrowserNavigation.requestSeen = true;
    setsBrowserNavigation.elapsedMs = Math.round(performance.now() - setsBrowserStarted);
    writeEvidence();
  };
  const onSetsResponse = response => {
    if (!isSetsListRequest(response.url())) return;
    setsBrowserNavigation.responseSeen = true;
    setsBrowserNavigation.responseStatus = response.status();
    setsBrowserNavigation.elapsedMs = Math.round(performance.now() - setsBrowserStarted);
    writeEvidence();
  };
  const onSetsRequestFailed = request => {
    if (!isSetsListRequest(request.url())) return;
    setsBrowserNavigation.requestFailure = request.failure()?.errorText || 'REQUEST_FAILED';
    setsBrowserNavigation.elapsedMs = Math.round(performance.now() - setsBrowserStarted);
    writeEvidence();
  };
  page.on('request', onSetsRequest);
  page.on('response', onSetsResponse);
  page.on('requestfailed', onSetsRequestFailed);
  try {
    await navigate(page, 'sets');
  } finally {
    page.off('request', onSetsRequest);
    page.off('response', onSetsResponse);
    page.off('requestfailed', onSetsRequestFailed);
    writeEvidence();
  }
  await runOperation(
    'sets-workspace-ready',
    () => Promise.all([
      page.locator('[data-admin-nav="sets"].active').waitFor({ state: 'visible', timeout: 5_000 }),
      page.locator('[data-admin-grid-host="sets"]').waitFor({ state: 'attached', timeout: 5_000 })
    ]),
    7_000
  );
  await runOperation(
    'sets-open-modal',
    () => page.evaluate(() => window.openModal('sets')),
    15_000
  );
  await runOperation(
    'sets-modal-visible',
    () => page.locator('#modal').waitFor({ state: 'visible', timeout: 8_000 }),
    10_000
  );
  const relationState = await runOperation('sets-relation-state', () => page.evaluate(() => ({
    artistIds: Array.isArray(window.state?.artists) ? window.state.artists.map(item => Number(item.id)).filter(Number.isFinite) : [],
    eventIds: Array.isArray(window.state?.events) ? window.state.events.map(item => Number(item.id)).filter(Number.isFinite) : []
  })));
  const { artistOption, eventOption } = await runOperation('sets-relation-options', async () => ({
    artistOption: await page.locator(`#f_artist_id option[value="${Number(publishedArtist.id)}"]`).count(),
    eventOption: await page.locator(`#f_event_id option[value="${Number(publishedEvent.id)}"]`).count()
  }));
  evidence.checks.setRelations = {
    publishedArtistId: Number(publishedArtist.id),
    publishedEventId: Number(publishedEvent.id),
    artistStateCount: relationState.artistIds.length,
    eventStateCount: relationState.eventIds.length,
    artistStateContainsExpected: relationState.artistIds.includes(Number(publishedArtist.id)),
    eventStateContainsExpected: relationState.eventIds.includes(Number(publishedEvent.id)),
    artistOptionPresent: Boolean(artistOption),
    eventOptionPresent: Boolean(eventOption),
    pass: Boolean(artistOption && eventOption)
  };
  writeEvidence();
  if (!artistOption || !eventOption) {
    throw new Error(
      `#124 failed: published relation options missing (artist=${Boolean(artistOption)}, event=${Boolean(eventOption)}, artistState=${relationState.artistIds.length}, eventState=${relationState.eventIds.length}).`
    );
  }
  await runOperation('sets-close-modal', () => page.evaluate(() => {
    window.closeModal();
    return true;
  }));

  // #125 — repeatedly open the manager; each real async navigation must settle
  // before the next workspace assertion or transition begins.
  markStage('hero-slider');
  for (let attempt = 1; attempt <= 3; attempt += 1) {
    const opened = await runOperation(
      `hero-slider-open-${attempt}`,
      () => page.evaluate(() => window.go('hero-slider')),
      operationTimeoutMs
    );
    if (opened !== true) {
      const diagnostic = await runOperation(
        `hero-slider-diagnostic-${attempt}`,
        () => page.evaluate(() => window.BRVTALHeroSliderDiagnostics?.lastLoad?.() || null)
      );
      evidence.checks.heroSlider.push({ attempt, pass: false, diagnostic });
      writeEvidence();
      throw new Error(
        `#125 Hero Slider navigation ${attempt} did not commit (result=${String(opened)}, reason=${diagnostic?.reason || 'unknown'}).`
      );
    }
    await runOperation(
      `hero-slider-ready-${attempt}`,
      () => Promise.all([
        page.locator('#hero-slider-root').waitFor({ state: 'visible', timeout: 8_000 }),
        page.locator('.hero-manager').waitFor({ state: 'visible', timeout: 15_000 })
      ]),
      17_000
    );
    const { loading, errors } = await runOperation(`hero-slider-state-${attempt}`, async () => ({
      loading: await page.locator('.hero-slider-loading').count(),
      errors: await page.locator('.hero-slider-error').count()
    }));
    if (loading || errors) throw new Error(`#125 failed on Hero Slider attempt ${attempt}: loading=${loading}, errors=${errors}.`);
    evidence.checks.heroSlider.push({ attempt, pass: true });
    writeEvidence();
    if (attempt < 3) {
      const dashboardOpened = await runOperation(
        `hero-slider-dashboard-${attempt}`,
        () => page.evaluate(() => window.go('dashboard')),
        operationTimeoutMs
      );
      if (dashboardOpened !== true) {
        throw new Error(`#125 Dashboard transition ${attempt} did not commit (result=${String(dashboardOpened)}).`);
      }
      await runOperation(
        `hero-slider-dashboard-ready-${attempt}`,
        () => page.locator('[data-admin-nav="dashboard"].active').waitFor({ state: 'visible', timeout: 8_000 }),
        10_000
      );
    }
  }

  markStage('final-guards');
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
  evidence.execution.failureStage ||= evidence.execution.stage;
  evidence.execution.failureOperation ||= evidence.execution.operation;
  writeEvidence();
  throw error;
} finally {
  try {
    if (browser) {
      markStage('cleanup');
      await runOperation('browser-close', () => browser.close(), Math.min(operationTimeoutMs, 10_000));
    }
  } catch (cleanupError) {
    evidence.cleanupError = String(cleanupError?.message || cleanupError);
    if (evidence.status !== 'failed') {
      evidence.status = 'failed';
      evidence.error = evidence.cleanupError;
      evidence.execution.failureStage ||= 'cleanup';
      evidence.execution.failureOperation ||= 'browser-close';
    }
    writeEvidence();
    console.error(evidence.cleanupError);
    process.exit(124);
  } finally {
    clearTimeout(wholeSmokeTimer);
  }
}
