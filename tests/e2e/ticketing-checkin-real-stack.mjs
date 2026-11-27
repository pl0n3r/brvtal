import assert from 'node:assert/strict';
import { test } from 'node:test';
import { createRequire } from 'node:module';
import { execFileSync } from 'node:child_process';
import { randomBytes } from 'node:crypto';

// Called only inside the existing checkout scenario, after account QR retrieval.
export async function checkinIssued(args) {
  // A genuine awaited subtest: failure propagates to the mandatory parent checkout test.
  await test('T31 real issued QR at door: mobile, concurrent audit and negatives', async () => {
    assert.equal(await runCheckin(args), '1', 'exactly one durable admission after browser cleanup');
    console.log('T31_CHECKIN_STACK_PASS: issued QR, real door, concurrent audit, mobile negatives');
  });
}

async function runCheckin({ check, db, request, qr, ticketId, armDoorRace }) {
  check(process.env.BRVTAL_T31_ISOLATED === '1' && /^brvtal_test_t31_[a-f0-9]{12}$/.test(process.env.DB_NAME), 'door fixture isolation');
  check(/^[a-f0-9]{32}$/.test(ticketId), 'issued entry identity');
  const start = execFileSync('php', ['-r', 'echo (new DateTimeImmutable("now",new DateTimeZone("America/Bogota")))->modify("-5 minutes")->format("Y-m-d H:i:s");'], { encoding: 'utf8' }).trim();
  check(/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(start), 'fixture event clock');
  // Advance the synthetic event into its admission window only after real payment/issuance.
  db("UPDATE events SET event_date='" + start + "' WHERE id=1; INSERT INTO events(id,title,slug,event_date,status,published_at) VALUES(2,'T31 other event','t31-other-event','" + start + "','published','" + start + "')");
  const accepted = () => db("SELECT COUNT(*) FROM ticket_checkins WHERE outcome='accepted'");
  const unchanged = () => check(accepted() === '0' && db('SELECT status FROM ticket_entries') === 'issued', 'no admission before valid scan');
  const login = async name => {
    const session = {};
    const begin = await request('/api/index.php/auth', session);
    check(begin.status === 200 && begin.body.authenticated === false, 'staff session start');
    const result = await request('/api/index.php/auth', session, { email: name + '@example.test', password: 'T31 staff fixture only!' });
    check(result.status === 200 && result.body.ok && /^[a-f0-9]{64}$/.test(result.body.csrf) && Number.isSafeInteger(result.body.admin?.id) && session.cookie?.startsWith('BRVTAL_ADMIN='), 'real staff login');
    session.csrf = result.body.csrf;
    session.id = result.body.admin.id;
    return session;
  };
  const first = await login('door-a'), second = await login('door-b'), denied = await login('no-door');
  check(first.id !== second.id && first.cookie !== second.cookie, 'separate door sessions');
  const api = '/api/ticketing/checkin.php';
  const scan = (session, eventId, token, csrf = session.csrf) => request(api, session, {
    event_id: eventId, device_id: 't31-negative', request_id: randomBytes(16).toString('hex'), qr: token
  }, { 'X-CSRF-Token': csrf || '' });
  const rejected = (r, status, code, key = 'error') => check(r.status === status && r.body.ok === false && r.body[key] === code && Object.keys(r.body).sort().join(',') === [key,'ok'].sort().join(','), 'closed rejection ' + code);
  rejected(await scan({}, 1, qr), 401, 'AUTH_REQUIRED');
  rejected(await scan(first, 1, qr, 'invalid'), 419, 'CSRF');
  rejected(await scan(denied, 1, qr), 403, 'FORBIDDEN');
  check(db('SELECT COUNT(*) FROM ticket_checkins') === '0', 'unauthorized attempts have no access audit');
  rejected(await scan(first, 2, qr), 409, 'wrong_event', 'reason');
  rejected(await scan(second, 1, 'invalid'), 409, 'malformed_qr', 'reason');
  unchanged();

  const require = createRequire(import.meta.url);
  const { chromium } = require('/opt/t31-browser/node_modules/playwright-core');
  const browser = await chromium.launch({ executablePath: '/usr/bin/chromium', headless: true, args: ['--disable-dev-shm-usage','--disable-background-networking'] });
  try {
    const pageFor = async session => {
      const context = await browser.newContext({ viewport: { width: 390, height: 844 } });
      await context.addCookies([{ name: 'BRVTAL_ADMIN', value: session.cookie.slice('BRVTAL_ADMIN='.length), domain: '127.0.0.1', path: '/', secure: true, httpOnly: true, sameSite: 'Strict' }]);
      const page = await context.newPage();
      const response = await page.goto('https://127.0.0.1:4444/t31-door-fixture');
      check(response?.status() === 200, 'trusted browser HTTPS');
      await page.locator('[data-door-mounted="true"]').waitFor();
      await page.locator('[data-door-event]').selectOption('1');
      await page.locator('[name="qr"]').fill(qr);
      check(await page.evaluate(() => innerWidth === 390), 'mobile 390px viewport');
      return { context, page };
    };
    const waitResult = async page => {
      await page.waitForFunction(() => ['allow','deny'].includes(document.querySelector('[data-door-result]')?.dataset.state));
      return page.locator('[data-door-result]').getAttribute('data-state');
    };
    // The browser really loses transport before sending; no API response is fabricated.
    const offline = await pageFor(first);
    const auditBefore = db('SELECT COUNT(*) FROM ticket_checkins');
    await offline.context.setOffline(true);
    await offline.page.locator('[data-door-submit]').click();
    check(await waitResult(offline.page) === 'deny' && (await offline.page.locator('[data-door-result]').innerText()).includes('NO permitir acceso'), 'offline UI denies access');
    check(db('SELECT COUNT(*) FROM ticket_checkins') === auditBefore, 'transport loss reaches no scanner');
    unchanged();
    await offline.context.close();
    const a = await pageFor(first), b = await pageFor(second);
    const released = armDoorRace();
    const responses = [a,b].map(({ page }) => page.waitForResponse(r => r.url().endsWith(api) && r.request().method() === 'POST'));
    await Promise.all([a.page.locator('[data-door-submit]').click(), b.page.locator('[data-door-submit]').click()]);
    const results = await Promise.all([waitResult(a.page), waitResult(b.page)]);
    const http = await Promise.all(responses);
    check(released() && results.slice().sort().join(',') === 'allow,deny' && http.map(r => r.status()).sort().join(',') === '200,409', 'two concurrent real scans, one acceptance');
    const winner = results[0] === 'allow' ? first : second;
    const loserPage = results[0] === 'deny' ? a.page : b.page;
    check((await loserPage.locator('[data-door-result]').innerText()).includes('ya utilizada'), 'rejected UI identifies replay');
    const payloads = await Promise.all(http.map(r => r.json()));
    check(payloads.every((p,i) => Object.keys(p).sort().join(',') === 'ok,reason' && p.ok === (results[i] === 'allow') && p.reason === (p.ok ? 'accepted' : 'already_used')), 'real scanner response without buyer details');
    check(accepted() === '1' && db('SELECT status FROM ticket_entries') === 'used', 'one durable admission');
    check(db("SELECT COUNT(*) FROM ticket_checkins WHERE outcome='rejected' AND reason_code='already_used'") === '1', 'one concurrent replay audited');
    check(db("SELECT COUNT(*) FROM ticket_checkins c JOIN ticket_entries e ON e.id=c.entry_id JOIN ticket_entry_credentials k ON k.entry_id=e.id AND k.version=c.credential_version WHERE c.outcome='accepted' AND c.event_id=1 AND c.operator_admin_id=" + winner.id + " AND e.public_id=UNHEX('" + ticketId + "') AND k.status='active' AND e.used_at_utc IS NOT NULL") === '1', 'accepted audit links exact issued entry and operator');
  } finally { await browser.close(); }
  return accepted();
}
