// Executed by the canonical isolated T31 runner; no intercepted application APIs.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import https from 'node:https';
import http from 'node:http';
import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { readFileSync, writeFileSync, renameSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

const check = (condition, label) => assert.ok(Boolean(condition), label);
const sha = value => createHash('sha256').update(value).digest('hex');
const db = sql => execFileSync('mariadb', ['--no-defaults', '-h127.0.0.1', '-ufixture', '-pfixture',
  '--batch', '--skip-column-names', process.env.DB_NAME, '-e', sql], { encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] }).trim();
const worker = () => JSON.parse(execFileSync('php', ['-d', 'curl.cainfo=/fixture/ca.pem', '/fixture/worker.php'],
  { encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] }));

async function run() {
  check(process.env.BRVTAL_T31_ISOLATED === '1' && process.env.BRVTAL_INTEGRATION_TESTS === '1' &&
    /^brvtal_test_t31_[a-f0-9]{12}$/.test(process.env.DB_NAME), 'disposable promo fixture required');
  const ruleId = Number(execFileSync('php', ['-r', `
    require 'config/bootstrap.php';
    require 'config/ticketing_promos.php';
    echo brvtalPromoCreate(db(), ['code'=>'T31PROMO01','event_id'=>1,'ticket_type_id'=>1,
      'kind'=>'fixed','value_cop'=>56,'starts_at_utc'=>gmdate('Y-m-d H:i:s',time()-3600),
      'ends_at_utc'=>gmdate('Y-m-d H:i:s',time()+3600),'max_uses'=>2,'buyer_limit'=>1,'campaign_key'=>'t31_fixture']);
  `], { encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] }));
  check(Number.isSafeInteger(ruleId) && ruleId > 0, 'fixture coupon created');
  const ca = readFileSync('/fixture/ca.pem');
  const proxy = https.createServer({ key: readFileSync('/fixture/server.key'), cert: readFileSync('/fixture/server.pem') }, (req, res) => {
    const upstream = http.request({ host: '127.0.0.1', port: 8080, path: req.url, method: req.method,
      headers: { ...req.headers, host: 'localhost', 'x-fixture-tls': '1' } }, reply => {
      res.writeHead(reply.statusCode, reply.headers);
      reply.pipe(res);
    });
    upstream.on('error', () => { res.writeHead(502); res.end('{}'); });
    req.pipe(upstream);
  });
  await new Promise(resolve => proxy.listen(4444, '127.0.0.1', resolve));
  const request = (path, session = {}, body, headers = {}, port = 4444) => new Promise((resolve, reject) => {
    const data = body === undefined ? undefined : JSON.stringify(body);
    const req = https.request({ hostname: '127.0.0.1', port, servername: 'localhost', ca, path,
      method: data === undefined ? 'GET' : 'POST',
      headers: { ...headers, ...(session.cookie ? { Cookie: session.cookie } : {}),
        ...(data === undefined ? {} : { 'Content-Type': 'application/json', 'Content-Length': Buffer.byteLength(data) }) } }, res => {
      if (res.headers['set-cookie']) session.cookie = res.headers['set-cookie'][0].split(';')[0];
      let raw = '';
      res.setEncoding('utf8');
      res.on('data', chunk => { raw += chunk; });
      res.on('end', () => { try { resolve({ status: res.statusCode, body: JSON.parse(raw) }); } catch { reject(new Error('non-JSON HTTP response')); } });
    });
    req.setTimeout(8000, () => req.destroy());
    req.on('error', () => reject(new Error('local HTTPS request failed')));
    req.end(data);
  });
  const login = async email => {
    const session = {};
    const start = await request('/api/buyer/auth.php', session);
    check(start.status === 200 && typeof start.body.csrf === 'string', 'auth challenge');
    const result = await request('/api/buyer/auth.php', session, { action: 'login', email, password: 'T31 fixture password only!' },
      { 'X-CSRF-Token': start.body.csrf });
    check(result.status === 200 && result.body.ok && typeof result.body.csrf === 'string', 'verified buyer login');
    session.csrf = result.body.csrf;
    return session;
  };
  try {
    let ready = false;
    for (let n = 0; n < 40 && !ready; n++) {
      try { ready = (await request('/api/ticketing/webhook-wompi.php', {}, undefined, {}, 4443)).status === 405; } catch { /* bounded startup only */ }
      if (!ready) await new Promise(resolve => setTimeout(resolve, 250));
    }
    check(ready, 'provider and PHP readiness');
    const buyer = await login('buyer@example.test');
    const event = '/index.php?type=events&slug=t31-fixture';
    const input = { ticket_type_id: 1, quantity: 1, method: 'CARD', idempotency_key: 't31-promo-fixture-001', promo_code: 'T31PROMO01' };
    const prepare = body => request(event, buyer, body,
      { 'X-CSRF-Token': buyer.csrf, 'X-Brvtal-Ticket-Checkout': 'prepare' });
    const counts = () => db('SELECT CONCAT((SELECT COUNT(*) FROM ticket_orders),":",(SELECT COUNT(*) FROM ticket_promo_redemptions),":",(SELECT uses_count FROM ticket_promo_rules WHERE id=' + ruleId + '),":",(SELECT COUNT(*) FROM ticket_payment_attempts))');
    for (const field of ['total_cop', 'discount_cop']) {
      check((await prepare({ ...input, [field]: 1 })).status === 422 && counts() === '0:0:0:0', 'browser supplied amount rejected');
    }
    const order = await prepare(input);
    check(order.status === 200 && order.body.ok, 'public promo checkout');
    const { order_id: id, order_token: token, checkout, promo_breakdown: breakdown } = order.body;
    check(Number.isSafeInteger(id) && id > 0 && /^[a-f0-9]{32}$/.test(token), 'order identity');
    check(breakdown?.subtotal_cop === 456 && breakdown.discount_cop === 56 && breakdown.total_cop === 400 &&
      checkout.amount_in_cents === 40000 && checkout.reference.startsWith('BW7-' + id + '-CARD-') &&
      checkout.signature_integrity === sha(checkout.reference + '40000COPtest_integrity_fixture_only'), 'server net and widget integrity');
    const ledger = () => db('SELECT CONCAT(id,":",promo_id,":",subtotal_cop,":",discount_cop,":",total_cop,":",quantity) FROM ticket_promo_redemptions WHERE order_id=' + id);
    const redemption = ledger();
    check(/^[1-9][0-9]*:/.test(redemption) && redemption.endsWith(':' + ruleId + ':456:56:400:1') &&
      counts() === '1:1:1:1' && db('SELECT subtotal_cop FROM ticket_order_items WHERE order_id=' + id) === '456' &&
      db('SELECT CONCAT(status,":",total_cop,":",LOWER(HEX(public_id))) FROM ticket_orders WHERE id=' + id) === 'pending:400:' + token &&
      db('SELECT gross_amount_cop FROM ticket_payment_attempts WHERE order_id=' + id) === '400', 'immutable ledger and net attempt');
    const replay = await prepare(input);
    check(replay.status === 200 && replay.body.ok && replay.body.order_id === id && replay.body.order_token === token &&
      replay.body.checkout.reference === checkout.reference && replay.body.checkout.amount_in_cents === 40000 &&
      replay.body.promo_breakdown?.discount_cop === 56 && counts() === '1:1:1:1' && ledger() === redemption, 'prepare replay does not redeem again');
    check(worker().issue === 'idle' && db('SELECT COUNT(*) FROM ticket_entries') === '0', 'no unpaid issuance');
    const noticeFor = amount => {
      const tx = { id: 'tx-dynamic', reference: checkout.reference, status: 'APPROVED', currency: 'COP', amount_in_cents: amount };
      writeFileSync('/fixture/dynamic-record.tmp', JSON.stringify(tx), { mode: 0o600 });
      renameSync('/fixture/dynamic-record.tmp', '/fixture/dynamic-record.json');
      const timestamp = Math.floor(Date.now() / 1000);
      return { event: 'transaction.updated', environment: 'test', timestamp, data: { transaction: tx },
        signature: { properties: ['transaction.id', 'transaction.status', 'transaction.amount_in_cents'],
          checksum: sha(tx.id + tx.status + tx.amount_in_cents + timestamp + 'test_events_fixture_only') } };
    };
    const hook = payload => request('/api/ticketing/webhook-wompi.php', {}, payload, {}, 4443);
    const wrong = await hook(noticeFor(45600));
    check(wrong.status === 403 && wrong.body.error === 'WEBHOOK_ORDER_MISMATCH' &&
      db('SELECT status FROM ticket_orders') === 'pending' && db('SELECT COUNT(*) FROM ticket_payment_webhook_events') === '0' &&
      db('SELECT COUNT(*) FROM ticket_issue_outbox') === '0' && db('SELECT COUNT(*) FROM ticket_entries') === '0' &&
      db('SELECT COUNT(*) FROM ticket_payment_attempts WHERE provider_transaction_id IS NOT NULL') === '0' &&
      counts() === '1:1:1:1' && ledger() === redemption, 'signed gross amount rejected before journal or issuance');
    const notice = noticeFor(40000);
    check((await hook(notice)).status === 200 && (await hook(notice)).status === 200, 'verified net callback and replay');
    check(db('SELECT status FROM ticket_orders') === 'paid' && db('SELECT status FROM ticket_inventory_holds') === 'consumed' &&
      db('SELECT COUNT(*) FROM ticket_payment_webhook_events') === '1' && db('SELECT COUNT(*) FROM ticket_issue_outbox') === '1' &&
      db('SELECT COUNT(*) FROM ticket_entries') === '0', 'payment admits one durable issue job');
    check(worker().issue === 'issued', 'actual issuance worker');
    check((await hook(notice)).status === 200 && worker().issue === 'idle', 'callback replay after issuance');
    const account = await request('/api/buyer/account.php', buyer);
    const orders = account.body.data?.orders;
    const item = orders?.[0]?.items?.[0];
    check(account.status === 200 && orders?.length === 1 && orders[0].order_number === id && orders[0].public_id === token &&
      orders[0].status === 'paid' && orders[0].total_cop === 400 && orders[0].items.length === 1 && item?.qr_stored &&
      item.status === 'issued', 'same net order and ticket in account');
    check(counts() === '1:1:1:1' && ledger() === redemption && db('SELECT COUNT(*) FROM ticket_entries') === '1' &&
      db('SELECT COUNT(*) FROM ticket_entry_credentials') === '1' && db('SELECT LOWER(HEX(public_id)) FROM ticket_entries') === item.ticket_id &&
      db('SELECT state FROM ticket_issue_outbox') === 'done' && db('SELECT COUNT(*) FROM ticket_payment_webhook_events') === '1', 'one redemption and issuance after replay');
    check(readFileSync('/fixture/audit.jsonl', 'utf8').split('\n').filter(line => line.includes('"id":"tx-dynamic","status":200')).length >= 2,
      'independent TLS provider consulted for both amounts');
  } finally { proxy.close(); }
}

if (process.argv[1] === fileURLToPath(import.meta.url)) {
  test('T31 real promo checkout net payment and idempotent issuance', async () => {
    try { await run(); } catch (error) {
      throw new Error(error instanceof Error && /^[a-zA-Z0-9 ,:._-]{1,120}$/.test(error.message)
        ? error.message : 'fixture operation failed');
    }
  });
}
