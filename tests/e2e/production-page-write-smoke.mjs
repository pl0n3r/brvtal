import { chromium } from '@playwright/test';
import { createHmac } from 'node:crypto';
import { mkdirSync, writeFileSync } from 'node:fs';
import { dirname } from 'node:path';

const baseUrl = String(process.env.BRVTAL_PROD_URL || 'https://www.brvtal.com.co').replace(/\/$/, '');
const adminEmail = String(process.env.BRVTAL_PROD_ADMIN_EMAIL || '').trim();
const adminPassword = String(process.env.BRVTAL_PROD_ADMIN_PASSWORD || '');
const totpSecret = String(process.env.BRVTAL_PROD_TOTP_SECRET || '').trim();
const expectedSha = String(process.env.BRVTAL_EXPECTED_SHA || '').trim();
const confirmation = String(process.env.BRVTAL_PROD_PAGE_WRITE_CONFIRM || '').trim();
const outputPath = String(process.env.BRVTAL_PROD_PAGE_WRITE_OUTPUT || 'artifacts/production-page-write-smoke.json');
const requiredConfirmation = 'WRITE_AND_DELETE_TEMP_PAGE';

if (!adminEmail || !adminPassword) {
  throw new Error('BRVTAL_PROD_ADMIN_EMAIL and BRVTAL_PROD_ADMIN_PASSWORD are required.');
}

if (baseUrl !== 'https://www.brvtal.com.co') {
  throw new Error(`Controlled production Page smoke only accepts the canonical origin; received ${baseUrl}`);
}

if (confirmation !== requiredConfirmation) {
  throw new Error(`Refusing production content mutation without BRVTAL_PROD_PAGE_WRITE_CONFIRM=${requiredConfirmation}.`);
}

const shortSha = (expectedSha || 'unknown').slice(0, 7);
const uniqueSuffix = `${Date.now().toString(36)}-${process.pid.toString(36)}`;
const pageSlug = `production-smoke-122-${shortSha}-${uniqueSuffix}`;
const pageContent = { text: 'Manifiesto' };

const evidence = {
  checkedAt: new Date().toISOString(),
  baseUrl,
  expectedSha: expectedSha || null,
  deploymentObserved: false,
  authentication: { totp: false },
  page: {
    id: null,
    slug: pageSlug,
    title: 'BRVTAL PROD SMOKE #122',
    locale: 'es',
    status: 'published',
    content: pageContent,
    created: false,
    persisted: false
  },
  cleanup: {
    attempted: false,
    deleted: false,
    verifiedAbsent: false
  },
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

async function jsonPayload(response, label, allowNon2xx = false) {
  const body = await response.text();
  let payload = null;
  try { payload = JSON.parse(body); } catch (_) {}
  if ((!allowNon2xx && !response.ok()) || !payload) {
    throw new Error(`${label} failed with HTTP ${response.status()}.`);
  }
  return payload;
}

async function observeExactDeploy(request) {
  if (!expectedSha) {
    throw new Error('BRVTAL_EXPECTED_SHA is required for controlled production writes.');
  }
  for (let attempt = 1; attempt <= 8; attempt += 1) {
    const response = await request.get(`${baseUrl}/?__deploy_check=${encodeURIComponent(`${shortSha}-${attempt}-${Date.now()}`)}`, {
      headers: { 'Cache-Control': 'no-cache' },
      timeout: 10_000
    });
    const html = await response.text();
    if (response.ok() && html.includes(`?v=${shortSha}`)) {
      evidence.deploymentObserved = true;
      return;
    }
    if (attempt < 8) await new Promise(resolve => setTimeout(resolve, 5_000));
  }
  throw new Error(`Hostinger deploy marker ?v=${shortSha} was not observed.`);
}

async function authenticate(context) {
  const loginResponse = await context.request.post(`${baseUrl}/api/index.php/auth`, {
    headers: { 'Content-Type': 'application/json' },
    data: { email: adminEmail, password: adminPassword },
    timeout: 12_000
  });
  const login = await jsonPayload(loginResponse, 'Admin login');
  if (login.ok !== true) throw new Error(`Admin login rejected: ${login.error || 'UNKNOWN_ERROR'}`);

  if (login.requires_totp) {
    if (!totpSecret) throw new Error('Production admin requires 2FA; configure BRVTAL_PROD_TOTP_SECRET.');
    evidence.authentication.totp = true;
    const verifyResponse = await context.request.post(`${baseUrl}/api/index.php/auth`, {
      headers: { 'Content-Type': 'application/json' },
      data: { action: 'totp_verify', code: currentTotp(totpSecret) },
      timeout: 12_000
    });
    const verified = await jsonPayload(verifyResponse, 'TOTP verification');
    if (verified.ok !== true) throw new Error(`TOTP verification rejected: ${verified.error || 'UNKNOWN_ERROR'}`);
  }

  const sessionResponse = await context.request.get(`${baseUrl}/api/index.php/auth`, { timeout: 12_000 });
  const session = await jsonPayload(sessionResponse, 'Authenticated session check');
  if (session.authenticated !== true || !session.csrf) {
    throw new Error('Authenticated production session was not established.');
  }
  return String(session.csrf);
}

async function findPageBySlug(context, slug) {
  const response = await context.request.get(`${baseUrl}/api/index.php/pages`, {
    headers: { 'Cache-Control': 'no-cache' },
    timeout: 12_000
  });
  const payload = await jsonPayload(response, 'Pages recovery read');
  if (payload.ok !== true || !Array.isArray(payload.data)) {
    throw new Error('Pages recovery read returned an invalid collection.');
  }
  return payload.data.find(row => String(row.slug || '') === slug) || null;
}

async function deleteCreatedPage(context, csrf, pageId) {
  evidence.cleanup.attempted = true;
  const deleteResponse = await context.request.delete(`${baseUrl}/api/index.php/pages/${Number(pageId)}`, {
    headers: { 'X-CSRF-Token': csrf },
    timeout: 12_000
  });
  const deleted = await jsonPayload(deleteResponse, 'Temporary Page cleanup');
  if (deleted.ok !== true || Number(deleted.deleted) !== 1) {
    throw new Error(`Temporary Page cleanup did not delete Page #${Number(pageId)}.`);
  }
  evidence.cleanup.deleted = true;

  const verifyResponse = await context.request.get(`${baseUrl}/api/index.php/pages/${Number(pageId)}`, {
    headers: { 'Cache-Control': 'no-cache' },
    timeout: 12_000
  });
  const verifyBody = await verifyResponse.text();
  let verifyPayload = null;
  try { verifyPayload = JSON.parse(verifyBody); } catch (_) {}
  if (verifyResponse.status() !== 404 || verifyPayload?.error !== 'NOT_FOUND') {
    throw new Error(`Temporary Page cleanup verification failed for Page #${Number(pageId)}.`);
  }
  evidence.cleanup.verifiedAbsent = true;
}

const browser = await chromium.launch({ headless: true });
const context = await browser.newContext({ serviceWorkers: 'block' });
let csrf = '';
let createdPageId = null;
let runError = null;

try {
  await observeExactDeploy(context.request);
  csrf = await authenticate(context);

  const createResponse = await context.request.post(`${baseUrl}/api/index.php/pages`, {
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-Token': csrf
    },
    data: {
      title: evidence.page.title,
      slug: pageSlug,
      locale: 'es',
      status: 'published',
      content_json: JSON.stringify(pageContent),
      seo_title: 'BRVTAL production smoke #122',
      seo_description: 'Temporary authenticated production regression for valid CMS Page JSON.'
    },
    timeout: 12_000
  });

  const createBody = await createResponse.text();
  let createPayload = null;
  try { createPayload = JSON.parse(createBody); } catch (_) {}
  if (createPayload && Number(createPayload.id || createPayload.data?.id) > 0) {
    createdPageId = Number(createPayload.id || createPayload.data?.id);
    evidence.page.id = createdPageId;
  }
  if (!createResponse.ok() || createPayload?.ok !== true || !createdPageId) {
    throw new Error(`#122 production Page create failed with HTTP ${createResponse.status()} (${createPayload?.error || 'INVALID_RESPONSE'}).`);
  }
  evidence.page.created = true;

  const readResponse = await context.request.get(`${baseUrl}/api/index.php/pages/${createdPageId}`, {
    headers: { 'Cache-Control': 'no-cache' },
    timeout: 12_000
  });
  const persisted = await jsonPayload(readResponse, 'Temporary Page verification');
  const row = persisted.data;
  if (persisted.ok !== true || !row) throw new Error('Temporary Page verification returned no record.');
  if (String(row.slug) !== pageSlug) throw new Error('#122 verification failed: persisted slug differs.');
  if (String(row.title) !== evidence.page.title) throw new Error('#122 verification failed: persisted title differs.');
  if (String(row.locale) !== 'es') throw new Error('#122 verification failed: persisted locale differs.');
  if (String(row.status) !== 'published') throw new Error('#122 verification failed: persisted status differs.');
  let parsedContent = null;
  try { parsedContent = JSON.parse(String(row.content_json || '')); } catch (_) {}
  if (parsedContent?.text !== 'Manifiesto') {
    throw new Error('#122 verification failed: persisted content_json differs.');
  }
  evidence.page.persisted = true;
} catch (error) {
  runError = error;
  evidence.status = 'failed';
  evidence.error = String(error?.message || error);
} finally {
  if (!createdPageId && csrf) {
    try {
      const recovered = await findPageBySlug(context, pageSlug);
      if (recovered && Number(recovered.id) > 0) {
        createdPageId = Number(recovered.id);
        evidence.page.id = createdPageId;
        evidence.page.created = true;
      }
    } catch (recoveryError) {
      evidence.cleanup.recoveryError = String(recoveryError?.message || recoveryError);
    }
  }

  if (createdPageId && csrf) {
    try {
      await deleteCreatedPage(context, csrf, createdPageId);
    } catch (cleanupError) {
      evidence.status = 'failed';
      evidence.cleanup.error = String(cleanupError?.message || cleanupError);
      if (!runError) runError = cleanupError;
    }
  }

  if (!runError && evidence.page.persisted && evidence.cleanup.verifiedAbsent) {
    evidence.status = 'passed';
  } else if (!runError) {
    runError = new Error('Controlled production Page smoke did not complete persistence and cleanup verification.');
    evidence.status = 'failed';
    evidence.error = runError.message;
  }

  writeEvidence();
  await browser.close();
}

if (runError) throw runError;
console.log('Controlled production Page write smoke passed for issue #122 and removed its temporary record.');
