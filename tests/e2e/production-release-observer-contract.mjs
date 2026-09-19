import assert from 'node:assert/strict';
import { observeBeforeAuthenticate, observeRelease } from './production-release-observer.mjs';

const expectedVersion = '0.1.13';
const expectedSha = '0123456789abcdef0123456789abcdef01234567';
const baseUrl = 'https://www.brvtal.com.co';
const noSleep = async () => {};
const fixedNow = () => 1_700_000_000_000;

function response(data, ok = true) {
  return { ok: () => ok, json: async () => ({ data }) };
}

function requestSequence(steps) {
  let index = 0;
  return {
    async get() {
      const step = steps[Math.min(index, steps.length - 1)];
      index += 1;
      if (step instanceof Error) throw step;
      return step;
    }
  };
}

const fallback = await observeRelease({
  request: requestSequence([response({ version: expectedVersion, commit: null, source: 'release_fallback', exact: false })]),
  baseUrl, expectedVersion, expectedSha, attempts: 1, sleep: noSleep, now: fixedNow
});
assert.equal(fallback.releaseObserved, true);
assert.equal(fallback.deployment.exact, false);
assert.equal(fallback.deployment.source, 'release_fallback');

const exact = await observeRelease({
  request: requestSequence([response({ version: expectedVersion, commit: expectedSha, source: 'environment', exact: true })]),
  baseUrl, expectedVersion, expectedSha, attempts: 1, sleep: noSleep, now: fixedNow
});
assert.equal(exact.deployment.commit, expectedSha);
assert.equal(exact.deployment.exact, true);

const transientErrors = [];
const recovered = await observeRelease({
  request: requestSequence([new Error('ETIMEDOUT'), response({ version: expectedVersion, commit: null, source: 'release_fallback', exact: false })]),
  baseUrl, expectedVersion, expectedSha, attempts: 2, sleep: noSleep, now: fixedNow,
  onObservation: ({ error }) => { if (error) transientErrors.push(error); }
});
assert.equal(recovered.attempt, 2);
assert.deepEqual(transientErrors, ['ETIMEDOUT']);

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
    request: requestSequence([response({ version: '0.1.12', commit: null, source: 'release_fallback', exact: false })]),
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
