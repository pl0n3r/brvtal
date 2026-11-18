import { test, expect } from '@playwright/test';
import { buildPerformanceWaterfall } from './production-performance-waterfall.mjs';

test('production waterfall keeps request order and summarizes cache/compression evidence', async () => {
  const finalUrl = 'https://www.brvtal.com.co/';
  const css = 'https://www.brvtal.com.co/css/style.css?v=abc1234';
  const js = 'https://www.brvtal.com.co/js/app.js?v=abc1234';
  const thirdParty = 'https://cdn.example.com/motion.js';

  const result = buildPerformanceWaterfall([
    {
      name: js,
      initiatorType: 'script',
      startTime: 120,
      requestStart: 125,
      responseStart: 170,
      responseEnd: 260,
      duration: 140,
      transferSize: 42000,
      encodedBodySize: 41000,
      decodedBodySize: 128000,
    },
    {
      name: css,
      initiatorType: 'link',
      startTime: 40,
      requestStart: 44,
      responseStart: 60,
      responseEnd: 100,
      duration: 60,
      transferSize: 12000,
      encodedBodySize: 11000,
      decodedBodySize: 44000,
    },
    {
      name: thirdParty,
      initiatorType: 'script',
      startTime: 80,
      requestStart: 82,
      responseStart: 100,
      responseEnd: 360,
      duration: 280,
      transferSize: 90000,
      encodedBodySize: 89000,
      decodedBodySize: 89000,
    },
  ], {
    [css]: {
      status: 200,
      cacheControl: 'public, max-age=31536000, immutable',
      contentEncoding: 'br',
      contentType: 'text/css; charset=utf-8',
    },
    [js]: {
      status: 200,
      cacheControl: 'public, max-age=31536000, immutable',
      contentEncoding: 'gzip',
      contentType: 'application/javascript',
    },
    [thirdParty]: {
      status: 200,
      cacheControl: 'public, max-age=60',
      contentEncoding: '',
      contentType: 'application/javascript',
    },
  }, finalUrl, 2);

  expect(result.resources.map((row) => row.url)).toEqual([css, thirdParty, js]);
  expect(result.slowest.map((row) => row.url)).toEqual([thirdParty, js]);
  expect(result.heaviest.map((row) => row.url)).toEqual([thirdParty, js]);
  expect(result.cache).toEqual({
    versionedStaticCount: 2,
    immutableVersionedStaticCount: 2,
    firstPartyTextCount: 2,
    compressedFirstPartyTextCount: 2,
  });
  expect(result.resources.find((row) => row.url === thirdParty)?.firstParty).toBe(false);
});
