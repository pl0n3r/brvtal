// Executed by the mandatory real-stack shell, not by the intercepted Playwright suite.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import https from 'node:https';
import http from 'node:http';
import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { readFileSync, writeFileSync, renameSync, existsSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

const check = (condition, label) => assert.ok(Boolean(condition), label);
const sha = value => createHash('sha256').update(value).digest('hex');
const db = sql => execFileSync('mariadb', ['--no-defaults', '-h127.0.0.1', '-ufixture', '-pfixture',
  '--batch', '--skip-column-names', process.env.DB_NAME, '-e', sql], { encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] }).trim();
const worker = () => JSON.parse(execFileSync('php', ['-d', 'curl.cainfo=/fixture/ca.pem', '/fixture/worker.php'],
  { encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] }));

async function run() {
  check(process.env.BRVTAL_T31_ISOLATED === '1' && /^brvtal_test_t31_[a-f0-9]{12}$/.test(process.env.DB_NAME), 'disposable fixture required');
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
    // Both TLS listeners must be ready before the first application assertion.
    let ready = false;
    for (let n = 0; n < 40 && !ready; n++) {
      try { ready = (await request('/api/ticketing/webhook-wompi.php', {}, undefined, {}, 4443)).status === 405; } catch { /* bounded startup only */ }
      if (!ready) await new Promise(resolve => setTimeout(resolve, 250));
    }
    check(ready, 'provider and PHP readiness');
    const buyer = await login('buyer@example.test');
    const other = await login('other@example.test');
    const event = '/index.php?type=events&slug=t31-fixture';
    const prepare = (session, key) => request(event, session, { lines: [{ ticket_type_id: 1, quantity: 1 }], method: 'CARD', idempotency_key: key },
      { 'X-CSRF-Token': session.csrf, 'X-Brvtal-Ticket-Checkout': 'prepare' });
    const order = await prepare(buyer, 't31-checkout-fixture-001');
    check(order.status === 200 && order.body.ok, 'public HTTP checkout');
    const { order_id: id, order_token: token, checkout } = order.body;
    check(Number.isSafeInteger(id) && id > 0 && /^[a-f0-9]{32}$/.test(token) && checkout.amount_in_cents === 45600 &&
      checkout.reference.startsWith('BW7-' + id + '-CARD-'), 'checkout identity and price');
    check(db('SELECT COUNT(*) FROM ticket_orders') === '1' &&
      db('SELECT CONCAT(status,":",total_cop,":",LOWER(HEX(public_id))) FROM ticket_orders WHERE id=' + id) === 'pending:456:' + token &&
      db('SELECT CONCAT(status,":",quantity) FROM ticket_inventory_holds') === 'active:1', 'pending order and reserved capacity');
    check((await prepare(other, 't31-checkout-fixture-002')).status === 409, 'capacity cannot be double reserved');
    check(worker().issue === 'idle' && db('SELECT COUNT(*) FROM ticket_entries') === '0', 'no unpaid issuance');
    const tx = { id: 'tx-dynamic', reference: checkout.reference, status: 'APPROVED', currency: 'COP', amount_in_cents: checkout.amount_in_cents };
    // Independent fixture record established before notification; provider accepts no writes.
    writeFileSync('/fixture/dynamic-record.tmp', JSON.stringify(tx), { mode: 0o600 });
    renameSync('/fixture/dynamic-record.tmp', '/fixture/dynamic-record.json');
    const timestamp = Math.floor(Date.now() / 1000);
    const checksum = sha(tx.id + tx.status + tx.amount_in_cents + timestamp + 'test_events_fixture_only');
    const notice = { event: 'transaction.updated', environment: 'test', timestamp, data: { transaction: tx },
      signature: { properties: ['transaction.id', 'transaction.status', 'transaction.amount_in_cents'], checksum } };
    const hook = payload => request('/api/ticketing/webhook-wompi.php', {}, payload, {}, 4443);
    check((await hook({ ...notice, signature: { ...notice.signature, checksum: '0'.repeat(64) } })).status === 403, 'invalid signature rejected');
    check(db('SELECT COUNT(*) FROM ticket_payment_webhook_events') === '0' && db('SELECT COUNT(*) FROM ticket_issue_outbox') === '0' &&
      db('SELECT status FROM ticket_orders') === 'pending', 'invalid signature did not mutate payment or issuance');
    check((await hook(notice)).status === 200 && (await hook(notice)).status === 200, 'verified webhook and replay');
    check(db('SELECT status FROM ticket_orders') === 'paid' && db('SELECT status FROM ticket_inventory_holds') === 'consumed' &&
      db('SELECT COUNT(*) FROM ticket_payment_webhook_events') === '1' && db('SELECT COUNT(*) FROM ticket_issue_outbox') === '1' &&
      db('SELECT COUNT(*) FROM ticket_entries') === '0', 'payment admits exactly one durable issue job');
    check(worker().issue === 'issued', 'actual issuance worker');
    // Drain bounded queued test mail, never contact Resend.
    for (let n = 0; n < 5 && db("SELECT COUNT(*) FROM ticket_mail_outbox WHERE state='queued'") !== '0'; n++) worker();
    check((await hook(notice)).status === 200 && worker().issue === 'idle', 'replay after issuance is idempotent');
    const account = await request('/api/buyer/account.php', buyer);
    const orders = account.body.data?.orders;
    const item = orders?.[0]?.items?.[0];
    check(account.status === 200 && orders.length === 1 && orders[0].order_number === id &&
      orders[0].public_id === token && orders[0].status === 'paid' && orders[0].total_cop === 456 &&
      orders[0].items.length === 1 && item.qr_stored && item.status === 'issued', 'buyer account identity');
    check(db('SELECT COUNT(*) FROM ticket_entries') === '1' && db('SELECT COUNT(*) FROM ticket_entry_credentials') === '1' &&
      db('SELECT LOWER(HEX(public_id)) FROM ticket_entries') === item.ticket_id &&
      db('SELECT state FROM ticket_issue_outbox') === 'done', 'entry and credential persisted once');
    const qr = await request('/api/buyer/account.php', buyer, { action: 'qr_stored', ticket_id: item.ticket_id }, { 'X-CSRF-Token': buyer.csrf });
    check(qr.status === 200 && qr.body.qr?.startsWith('BRVTAL:T1:' + item.ticket_id + ':'), 'authorized QR');
    check(db('SELECT LOWER(HEX(token_hash)) FROM ticket_entry_credentials') === sha(Buffer.from(qr.body.qr.split(':').at(-1), 'base64url')), 'QR digest matches credential');
    const foreign = await request('/api/buyer/account.php', other);
    const denied = await request('/api/buyer/account.php', other, { action: 'qr_stored', ticket_id: item.ticket_id }, { 'X-CSRF-Token': other.csrf });
    check(foreign.status === 200 && foreign.body.data?.orders?.length === 0 && denied.status === 404 && !denied.body.qr, 'foreign buyer cannot read order or QR');
    check(existsSync('/fixture/mail.jsonl'), 'mail captured');
    const mail = readFileSync('/fixture/mail.jsonl', 'utf8').trim().split('\n').map(JSON.parse);
    const receipt = mail.filter(x => x.key === 'order:' + id + ':payment_approved');
    check(receipt.length === 1 && receipt[0].payload.to[0] === 'buyer@example.test' &&
      receipt[0].payload.text.includes(qr.body.qr), 'one captured receipt contains same credential');
    check(db("SELECT COUNT(*) FROM ticket_mail_outbox WHERE template_key='payment_approved' AND state='accepted'") === '1', 'mail receipt persisted');
    check(readFileSync('/fixture/audit.jsonl', 'utf8').includes('"id":"tx-dynamic","status":200'), 'independent TLS lookup observed');
  } finally { proxy.close(); }
}

if (process.argv[1] === fileURLToPath(import.meta.url)) {
  // Do not print assertion operands, raw responses, SQL errors, QR or cookies.
  test('T31 real checkout, payment, issuance, mail and buyer isolation', async () => {
    try { await run(); } catch (error) {
      throw new Error(error instanceof Error && /^[a-zA-Z0-9 ,:._-]{1,120}$/.test(error.message)
        ? error.message : 'fixture operation failed');
    }
  });
}
