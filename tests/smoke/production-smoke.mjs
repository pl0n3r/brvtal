const baseUrl = (process.env.SMOKE_BASE_URL || 'https://brvtal.com.co').replace(/\/$/, '');
const retries = Math.max(1, Number(process.env.SMOKE_RETRIES || 1));
const retryDelayMs = Math.max(0, Number(process.env.SMOKE_RETRY_DELAY_MS || 15000));
const requestTimeoutMs = Math.max(1000, Number(process.env.SMOKE_TIMEOUT_MS || 15000));

function assert(condition, message) {
  if (!condition) throw new Error(message);
}

function wait(ms) {
  return new Promise(resolve => setTimeout(resolve, ms));
}

async function request(path, options = {}) {
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), requestTimeoutMs);
  try {
    return await fetch(`${baseUrl}${path}`, {
      redirect: 'manual',
      ...options,
      signal: controller.signal,
      headers: {
        'User-Agent': 'BRVTAL-Production-Smoke/1.0',
        ...(options.headers || {}),
      },
    });
  } finally {
    clearTimeout(timer);
  }
}

function collectKeys(value, out = []) {
  if (Array.isArray(value)) {
    for (const item of value) collectKeys(item, out);
    return out;
  }
  if (value && typeof value === 'object') {
    for (const [key, nested] of Object.entries(value)) {
      out.push(key.toLowerCase());
      collectKeys(nested, out);
    }
  }
  return out;
}

async function runChecks() {
  const results = [];

  const home = await request('/');
  assert(home.status === 200, `homepage expected 200, got ${home.status}`);
  results.push('homepage:200');

  const publicApi = await request('/api/public.php', {
    headers: { Accept: 'application/json' },
  });
  assert(publicApi.status === 200, `public API expected 200, got ${publicApi.status}`);
  const publicJson = await publicApi.json();
  assert(publicJson?.ok === true, 'public API must return ok=true');
  assert(publicJson?.data && typeof publicJson.data === 'object', 'public API must return data object');
  assert(publicJson.data.settings && typeof publicJson.data.settings === 'object', 'public API must include settings object');

  const forbiddenPublicKeys = new Set([
    'password',
    'password_hash',
    'secret',
    'csrf',
    'csrf_key',
    'encryption_key',
    'token',
    'api_key',
    'customcode',
    'analytics',
  ]);
  const publicSettingKeys = collectKeys(publicJson.data.settings);
  const leaked = publicSettingKeys.filter(key => forbiddenPublicKeys.has(key));
  assert(leaked.length === 0, `public settings expose forbidden keys: ${[...new Set(leaked)].join(', ')}`);
  results.push('public-api:ok');
  results.push('public-settings:no-private-keys');

  const admin = await request('/discadmin/');
  assert([200, 301, 302, 303, 307, 308].includes(admin.status), `DISCADMIN expected login/redirect response, got ${admin.status}`);
  results.push(`discadmin:${admin.status}`);

  const adminAsset = await request('/discadmin/admin-modules.js');
  assert(adminAsset.status === 200, `DISCADMIN JS expected 200, got ${adminAsset.status}`);
  const adminAssetType = (adminAsset.headers.get('content-type') || '').toLowerCase();
  assert(adminAssetType.includes('javascript') || adminAssetType.includes('text/plain') || adminAssetType === '', `unexpected DISCADMIN JS content-type: ${adminAssetType}`);
  results.push('discadmin-js:200');

  const mediaApi = await request('/api/media-library.php?action=list', {
    headers: { Accept: 'application/json' },
  });
  assert(mediaApi.status === 401, `unauthenticated Media Library must return 401, got ${mediaApi.status}`);
  const mediaJson = await mediaApi.json();
  assert(mediaJson?.ok === false && mediaJson?.error === 'AUTH_REQUIRED', 'Media Library must reject anonymous access with AUTH_REQUIRED');
  results.push('media-library:protected');

  const uploads = await request('/uploads/');
  if (uploads.status === 200) {
    const body = (await uploads.text()).toLowerCase();
    assert(!body.includes('index of /uploads'), 'uploads directory listing is enabled');
  }
  results.push(`uploads:no-directory-listing:${uploads.status}`);

  return results;
}

let lastError;
for (let attempt = 1; attempt <= retries; attempt += 1) {
  try {
    const results = await runChecks();
    console.log(`BRVTAL production smoke passed on attempt ${attempt}/${retries}.`);
    for (const result of results) console.log(`  PASS ${result}`);
    process.exit(0);
  } catch (error) {
    lastError = error;
    console.error(`Smoke attempt ${attempt}/${retries} failed: ${error instanceof Error ? error.message : String(error)}`);
    if (attempt < retries) await wait(retryDelayMs);
  }
}

console.error(`BRVTAL production smoke failed: ${lastError instanceof Error ? lastError.message : String(lastError)}`);
process.exit(1);
