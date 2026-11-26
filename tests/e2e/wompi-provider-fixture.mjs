import https from 'node:https';
import http from 'node:http';
import { appendFileSync, readFileSync } from 'node:fs';

const root = '/fixture';
const tls = {
  key: readFileSync(`${root}/server.key`),
  cert: readFileSync(`${root}/server.pem`),
};
const ref = `BW7-1-CARD-${'a'.repeat(24)}`;
const record = Object.freeze({
  id: 'tx-valid', reference: ref, status: 'APPROVED',
  currency: 'COP', amount_in_cents: 12300,
});
const writeAudit = (method, id, status) => appendFileSync(
  `${root}/audit.jsonl`, `${JSON.stringify({ method, id, status })}\n`,
);
const reply = (response, status, data) => {
  response.writeHead(status, { 'Content-Type': 'application/json', 'Cache-Control': 'no-store' });
  response.end(data);
};

https.createServer(tls, (request, response) => {
  const url = new URL(request.url, 'https://sandbox.wompi.co');
  const id = url.pathname.startsWith('/v1/transactions/')
    ? url.pathname.slice('/v1/transactions/'.length) : '';
  if (request.method !== 'GET' || !/^tx-[a-z-]+$/.test(id)) {
    writeAudit(request.method, 'invalid-route', 404);
    return reply(response, 404, '{"error":"not-found"}');
  }
  if (request.headers.authorization !== 'Bearer prv_test_fixture_only_123456') {
    writeAudit(request.method, id, 401);
    return reply(response, 401, '{"error":"unauthorized"}');
  }
  switch (id) {
    case 'tx-valid':
      writeAudit(request.method, id, 200);
      return reply(response, 200, JSON.stringify({ data: record }));
    case 'tx-conflict':
      writeAudit(request.method, id, 200);
      return reply(response, 200, JSON.stringify({ data: record }));
    case 'tx-malformed':
      writeAudit(request.method, id, 200);
      return reply(response, 200, '{');
    case 'tx-unavailable':
      writeAudit(request.method, id, 502);
      return reply(response, 502, '{"error":"unavailable"}');
    case 'tx-timeout':
      writeAudit(request.method, id, 504);
      return setTimeout(() => reply(response, 504, '{"error":"timeout"}'), 5500);
    default:
      writeAudit(request.method, id, 404);
      return reply(response, 404, '{"error":"not-found"}');
  }
}).listen(443, '127.0.0.1');

// Real local HTTPS termination; the forwarded scheme is asserted by the
// throwaway PHP router only after this TLS listener accepted the request.
https.createServer(tls, (request, response) => {
  if (request.url !== '/api/ticketing/webhook-wompi.php') {
    return reply(response, 404, '{"error":"not-found"}');
  }
  const upstream = http.request({
    hostname: '127.0.0.1', port: 8080, method: request.method, path: request.url,
    headers: { ...request.headers, host: 'localhost', 'x-fixture-tls': '1' },
  }, result => {
    response.writeHead(result.statusCode ?? 502, result.headers);
    result.pipe(response);
  });
  upstream.on('error', () => reply(response, 502, '{"error":"upstream"}'));
  request.pipe(upstream);
}).listen(4443, '127.0.0.1');
