const defaultSleep = ms => new Promise(resolve => setTimeout(resolve, ms));

function normalizeDeployment(raw) {
  return {
    version: String(raw?.version || '').trim() || null,
    commit: String(raw?.commit || '').trim().toLowerCase() || null,
    source: raw?.source || null,
    exact: raw?.exact === true
  };
}

function responseStatus(response) {
  try {
    return typeof response?.status === 'function' ? response.status() : null;
  } catch (_) {
    return null;
  }
}

async function probeJson({ request, url, requestTimeoutMs, extract }) {
  let response = null;
  try {
    response = await request.get(url, {
      headers: {
        'Accept': 'application/json',
        'Cache-Control': 'no-cache'
      },
      timeout: requestTimeoutMs
    });
  } catch (error) {
    return {
      deployment: normalizeDeployment(null),
      responseOk: false,
      status: null,
      error: String(error?.message || error),
      parseError: null,
      usable: false
    };
  }

  let payload = null;
  let parseError = null;
  try {
    payload = await response.json();
  } catch (error) {
    parseError = String(error?.message || error);
  }

  const deployment = normalizeDeployment(extract(payload));
  const responseOk = response.ok();
  return {
    deployment,
    responseOk,
    status: responseStatus(response),
    error: null,
    parseError,
    usable: responseOk && Boolean(deployment.version)
  };
}

function fallbackReason(probe, label) {
  if (probe.error) return `${label} request failed: ${probe.error}`;
  if (probe.parseError) return `${label} returned invalid JSON: ${probe.parseError}`;
  if (!probe.responseOk) return `${label} returned HTTP ${probe.status ?? 'unknown'}`;
  return `${label} returned no deployment version`;
}

export async function observeRelease({
  request,
  baseUrl,
  expectedVersion,
  expectedSha = '',
  attempts = 36,
  requestTimeoutMs = 5_000,
  sleepMs = 10_000,
  sleep = defaultSleep,
  now = Date.now,
  onObservation = async () => {}
}) {
  if (!request || typeof request.get !== 'function') throw new TypeError('observeRelease requires a request context with get().');
  if (!baseUrl || !expectedVersion) throw new TypeError('observeRelease requires baseUrl and expectedVersion.');
  if (!Number.isInteger(attempts) || attempts < 1) throw new RangeError('observeRelease attempts must be a positive integer.');

  let versionMatched = false;
  let lastExactMismatch = null;
  let lastRequestError = null;

  for (let attempt = 1; attempt <= attempts; attempt += 1) {
    const token = encodeURIComponent(`${expectedVersion}-${attempt}-${now()}`);
    const primary = await probeJson({
      request,
      url: `${baseUrl}/api/deployment.php?__deploy_check=${token}`,
      requestTimeoutMs,
      extract: payload => payload?.data || null
    });

    let selected = primary;
    let probe = 'deployment';
    let usedFallback = false;
    let reason = null;

    if (!primary.usable) {
      usedFallback = true;
      reason = fallbackReason(primary, 'deployment probe');
      selected = await probeJson({
        request,
        url: `${baseUrl}/api/health.php?__deploy_health_check=${token}`,
        requestTimeoutMs,
        extract: payload => payload?.deployment || null
      });
      probe = 'health';
    }

    const observationError = selected.error
      || (!selected.usable && selected.parseError ? selected.parseError : null);
    if (observationError) lastRequestError = observationError;

    await onObservation({
      attempt,
      deployment: selected.deployment,
      error: observationError,
      responseOk: selected.responseOk,
      status: selected.status,
      probe,
      fallbackReason: reason,
      fallbackUsed: usedFallback
    });

    const deployment = selected.deployment;
    if (selected.responseOk && deployment.version === expectedVersion) {
      versionMatched = true;
      if (deployment.exact && expectedSha && deployment.commit !== expectedSha.toLowerCase()) {
        lastExactMismatch = deployment;
        if (attempt < attempts) {
          await sleep(sleepMs);
          continue;
        }
        break;
      }

      return {
        releaseObserved: true,
        deployment,
        attempt,
        probe,
        fallbackUsed: usedFallback
      };
    }

    if (attempt < attempts) await sleep(sleepMs);
  }

  if (versionMatched && lastExactMismatch) {
    throw new Error(`Production release v${expectedVersion} is visible, but exact source ${lastExactMismatch.commit || '(empty)'} does not match ${expectedSha}.`);
  }

  const requestDetail = lastRequestError ? ` Last request error: ${lastRequestError}` : '';
  throw new Error(`Production release v${expectedVersion} was not observed within the bounded deployment window.${requestDetail}`);
}

export async function observeBeforeAuthenticate(observe, authenticate) {
  await observe();
  return authenticate();
}
