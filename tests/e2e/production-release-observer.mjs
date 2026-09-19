const defaultSleep = ms => new Promise(resolve => setTimeout(resolve, ms));

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
    let response = null;
    try {
      response = await request.get(
        `${baseUrl}/api/deployment.php?__deploy_check=${encodeURIComponent(`${expectedVersion}-${attempt}-${now()}`)}`,
        {
          headers: { 'Cache-Control': 'no-cache' },
          timeout: requestTimeoutMs
        }
      );
    } catch (error) {
      lastRequestError = String(error?.message || error);
      await onObservation({ attempt, deployment: null, error: lastRequestError, responseOk: false });
      if (attempt < attempts) await sleep(sleepMs);
      continue;
    }

    let payload = null;
    try { payload = await response.json(); } catch (_) {}
    const raw = payload?.data || null;
    const deployment = {
      version: String(raw?.version || '').trim() || null,
      commit: String(raw?.commit || '').trim().toLowerCase() || null,
      source: raw?.source || null,
      exact: raw?.exact === true
    };
    const responseOk = response.ok();
    await onObservation({ attempt, deployment, error: null, responseOk });

    if (responseOk && deployment.version === expectedVersion) {
      versionMatched = true;
      if (deployment.exact && expectedSha && deployment.commit !== expectedSha.toLowerCase()) {
        lastExactMismatch = deployment;
        if (attempt < attempts) {
          await sleep(sleepMs);
          continue;
        }
        break;
      }

      return { releaseObserved: true, deployment, attempt };
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
