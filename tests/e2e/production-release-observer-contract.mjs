import assert from 'node:assert/strict';
import { observeBeforeAuthenticate, observeRelease } from './production-release-observer.mjs';

const expectedVersion = '0.1.13';
const expectedSha = '0123456789abcdef0123456789abcdef01234567';
const baseUrl = 'https://www.brvtal.com.co';
const noSleep = async () => {};
const fixedNow = () => 1_700_000_000_000;

function response(data, ok = true, shape = 'data', status = ok ? 200 : 503) {
  return {
    ok: () => ok,
    status: () => status,
    json: async () => shape === 'health' ? { deployment: data } : { data }
  };
}

function invalidResponse(ok = true, status = 200) {
  return {
    ok: () => ok,
    status: () => status,
    json: async () => { throw new Error('INVALID_JSON'); }
  };
}

function requestSequence(steps, urls = []) {
  let index = 0;
  return {
    async get(url) {
      urls.push(url);
      const step = steps[Math.min(index, steps.length - 1)];
      index += 1;
      if (step instanceof Error) throw step;
      return step;
    }
  };
}

const releaseFallback = await observeRelease({
  request: requestSequence([response({ version: expectedVersion, commit: null, source: 'release_fallback', exact: false })]),
  baseUrl, expectedVersion, expectedSha, attempts: 1, sleep: noSleep, now: fixedNow
});
assert.equal(releaseFallback.releaseObserved, true);
assert.equal(releaseFallback.deployment.exact, false);
assert.equal(releaseFallback.deployment.source, 'release_fallback');
assert.equal(releaseFallback.probe, 'deployment');

const exact = await observeRelease({
  request: requestSequence([response({ version: expectedVersion, commit: expectedSha, source: 'environment', exact: true })]),
  baseUrl, expectedVersion, expectedSha, attempts: 1, sleep: noSleep, now: fixedNow
});
assert.equal(exact.deployment.commit, expectedSha);
assert.equal(exact.deployment.exact, true);
assert.equal(exact.fallbackUsed, false);

const fallbackUrls = [];
const fallbackObservations = [];
const healthFallback = await observeRelease({
  request: requestSequence([
    invalidResponse(),
    response({ version: expectedVersion, commit: expectedSha, source: 'git_checkout', exact: true }, true, 'health')
  ], fallbackUrls),
  baseUrl, expectedVersion, expectedSha, attempts: 1, sleep: noSleep, now: fixedNow,
  onObservation: observation => fallbackObservations.push(observation)
});
assert.equal(healthFallback.releaseObserved, true);
assert.equal(healthFallback.probe, 'health');
assert.equal(healthFallback.fallbackUsed, true);
assert.equal(healthFallback.deployment.commit, expectedSha);
assert.match(fallbackObservations[0].fallbackReason, /deployment probe returned invalid JSON/);
assert.match(fallbackUrls[0], /\/api\/deployment\.php\?__deploy_check=/);
assert.match(fallbackUrls[1], /\/api\/health\.php\?__deploy_health_check=/);

const transientErrors = [];
const transientFallbacks = [];
const recovered = await observeRelease({
  request: requestSequence([
    new Error('ETIMEDOUT'),
    new Error('HEALTH_TIMEOUT'),
    response({ version: expectedVersion, commit: null, source: 'release_fallback', exact: false })
  ]),
  baseUrl, expectedVersion, expectedSha, attempts: 2, sleep: noSleep, now: fixedNow,
  onObservation: ({ error, fallbackReason }) => {
    if (error) transientErrors.push(error);
    if (fallbackReason) transientFallbacks.push(fallbackReason);
  }
});
assert.equal(recovered.attempt, 2);
assert.deepEqual(transientErrors, ['HEALTH_TIMEOUT']);
assert.match(transientFallbacks[0], /ETIMEDOUT/);

await assert.rejects(
  observeRelease({
    request: requestSequence([
      response({ version: expectedVersion, commit: 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', source: 'git_checkout', exact: true }),
      response({ version: expectedVersion, commit: 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', source: 'git_checkout', exact: true })
    ]),
    baseUrl, expectedVersion, expectedSha, attempts: 2, sleep: noSleep, now: fixedNow
  }),
  /exact source .* does not match/
);

await assert.rejects(
  observeRelease({
    request: requestSequence([
      response({ version: '0.1.12', commit: null, source: 'release_fallback', exact: false }),
      response({ version: '0.1.12', commit: null, source: 'release_fallback', exact: false })
    ]),
    baseUrl, expectedVersion, expectedSha, attempts: 2, sleep: noSleep, now: fixedNow
  }),
  /was not observed within the bounded deployment window/
);

const order = [];
await observeBeforeAuthenticate(
  async () => { order.push('observe:start'); await Promise.resolve(); order.push('observe:end'); },
  async () => { order.push('authenticate'); }
);
assert.deepEqual(order, ['observe:start', 'observe:end', 'authenticate']);

let authenticatedAfterFailure = false;
await assert.rejects(
  observeBeforeAuthenticate(
    async () => { throw new Error('release missing'); },
    async () => { authenticatedAfterFailure = true; }
  ),
  /release missing/
);
assert.equal(authenticatedAfterFailure, false);

console.log('Production release observer behavior contract passed.');
