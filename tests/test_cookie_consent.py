"""BRVTAL #1351: real first-party consent and fail-closed tracking contracts."""
from __future__ import annotations

import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def run(command: list[str]) -> str:
    proc = subprocess.run(command, cwd=ROOT, text=True, capture_output=True, timeout=30)
    if proc.returncode:
        raise AssertionError(
            f"{' '.join(command[:2])} failed ({proc.returncode}):\n{proc.stdout}\n{proc.stderr}"
        )
    return proc.stdout


NODE_HARNESS = r"""
const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const source = fs.readFileSync('js/cookie-consent.js', 'utf8');

function setup(previous = '', rejectStorage = false) {
  const store = new Map();
  if (previous) store.set('brvtal_cookie_choice', previous);
  const elements = [];
  const reloads = [];
  function element(tag) {
    const e = {
      tagName: tag.toUpperCase(), children: [], dataset: {}, hidden: false,
      className: '', textContent: '', attributes: {}, handlers: {},
      classList: { add(name) { e.className += ' ' + name; } },
      setAttribute(name, value) { this.attributes[name] = value; },
      addEventListener(name, callback) { this.handlers[name] = callback; },
      append(...items) { this.children.push(...items); },
      appendChild(item) { this.children.push(item); },
      querySelector(selector) {
        const property = selector === '[data-cookie-reject]' ? 'cookieReject' : 'cookieAccept';
        return this.children.flatMap(x => x.children || []).find(x => Object.hasOwn(x.dataset || {}, property)) || null;
      },
      focus() { this.focused = true; },
    };
    elements.push(e);
    return e;
  }
  const head = element('head');
  const body = element('body');
  const footer = element('footer');
  const document = {
    currentScript: { dataset: { gtmId: 'GTM-ABCD1234', version: 'hash123' } },
    readyState: 'complete',
    head, body, createElement: element,
    querySelector(selector) { return selector === '.c5-footer-legal, footer' ? footer : null; },
    get cookie() { return [...store.entries()].map(([k, v]) => k + '=' + v).join('; '); },
    set cookie(line) {
      if (rejectStorage) return;
      const [name, value] = line.split(';')[0].split('=');
      store.set(name, value);
      this.lastSetCookie = line;
    },
  };
  const window = {};
  vm.runInNewContext(source, { document, window, location: {
    protocol: 'https:', reload() { reloads.push(true); }
  } });
  const panel = elements.find(e => e.id === 'brvtal-cookie-banner');
  const scripts = () => body.children.filter(e => e.tagName === 'SCRIPT');
  return { store, document, window, elements, panel, scripts, reloads, footer };
}

let state = setup();
assert.equal(state.window.BRVTALCookieConsent.get(), 'rejected');
assert.equal(state.window.BRVTALCookieConsent.isAccepted(), false);
assert.equal(state.scripts().length, 0, 'no optional scripts on undecided visit');
assert.equal(state.panel.hidden, false);
assert.equal(state.panel.attributes.role, 'dialog');
assert.equal(state.panel.attributes['aria-modal'], 'false');
const actions = state.elements.find(e => e.className === 'brvtal-cookie-actions');
assert.deepEqual(actions.children.map(e => e.textContent), ['Rechazar', 'Aceptar']);
const reject = actions.children[0], accept = actions.children[1];
assert.equal(reject.dataset.cookieReject, '');
assert.equal(accept.dataset.cookieAccept, '');
assert.equal(state.window.BRVTALCookieConsent.set('accepted'), true);
assert.equal(state.window.BRVTALCookieConsent.isAccepted(), true);
assert.equal(state.store.get('brvtal_cookie_choice'), 'accepted');
assert.match(state.document.lastSetCookie, /Max-Age=15552000; Path=\/; SameSite=Lax; Secure/);
assert.equal(state.scripts().length, 2);
assert.ok(state.scripts()[0].src.startsWith('/js/public-analytics.js'));
assert.ok(state.scripts()[1].src.startsWith('/js/public-measurement.js'));
assert.equal(state.scripts()[0].dataset.gtmId, 'GTM-ABCD1234');
assert.equal(state.window.BRVTALCookieConsent.set('rejected'), true);
assert.equal(state.reloads.length, 1, 'revocation reloads already running analytics');
assert.equal(state.store.get('brvtal_cookie_choice'), 'rejected');

state = setup('rejected');
assert.equal(state.scripts().length, 0);
assert.equal(state.panel.hidden, true);
assert.ok(state.footer.children.length);
state.window.BRVTALCookieConsent.open();
assert.equal(state.panel.hidden, false);
const closing = state.elements.find(e => e.className === 'brvtal-cookie-close');
closing.handlers.click();
assert.equal(state.store.get('brvtal_cookie_choice'), 'rejected');
assert.equal(state.scripts().length, 0);

state = setup('accepted');
assert.equal(state.panel.hidden, true);
assert.equal(state.scripts().length, 2, 'persisted acceptance enables optional scripts');

state = setup('', true);
assert.equal(state.window.BRVTALCookieConsent.set('accepted'), false);
assert.equal(state.scripts().length, 0, 'blocked cookie storage fails closed');
assert.equal(state.panel.hidden, false);
state = setup('unexpected');
assert.equal(state.window.BRVTALCookieConsent.isAccepted(), false);
assert.equal(state.scripts().length, 0);
console.log('consent state / analytics gate PASS');
"""


class CookieConsentTests(unittest.TestCase):
    def test_criterio_principal(self) -> None:
        """AC-01: server never emits optional loaders, JS unlocks on stored opt-in."""
        php = r"""require 'config/public_analytics.php';
echo json_encode([
  'configured' => brvtal_public_analytics_markup('GTM-ABCD1234', 'hash123'),
  'unconfigured' => brvtal_public_analytics_markup('', 'hash123'),
  'untrusted' => brvtal_public_analytics_markup('"><script>alert(1)</script>', 'hash123'),
], JSON_THROW_ON_ERROR);"""
        obj = json.loads(run(['php', '-r', php]))
        for value in obj.values():
            self.assertIn('/js/cookie-consent.js', value)
            self.assertNotIn('src="/js/public-analytics.js', value)
            self.assertNotIn('src="/js/public-measurement.js', value)
            self.assertNotIn('googletagmanager.com', value)
        self.assertIn('data-gtm-id="GTM-ABCD1234"', obj['configured'])
        self.assertIn('data-gtm-id=""', obj['unconfigured'])
        self.assertNotIn('<script>alert(1)', obj['untrusted'])
        self.assertIn('consent state / analytics gate PASS', run(['node', '-e', NODE_HARNESS]))

    def test_legal_and_accessibility(self) -> None:
        js = (ROOT / 'js/cookie-consent.js').read_text(encoding='utf-8')
        css = (ROOT / 'css/cookie-consent.css').read_text(encoding='utf-8')
        self.assertIn('Texto legal pendiente de aprobación profesional', js)
        self.assertIn('prefers-reduced-motion:reduce', css)
        self.assertIn('button:focus-visible', css)
        self.assertIn('min-height:44px', css)
        self.assertIn('aria-describedby', js)
        self.assertIn('Escape', js)
        self.assertNotIn('localStorage', js)
        run(['node', '--check', 'js/cookie-consent.js'])

    def test_shared_public_routes(self) -> None:
        """All public branches already use the shared server-side analytics slot."""
        entry = (ROOT / 'index.php').read_text(encoding='utf-8')
        self.assertGreaterEqual(entry.count('brvtal_public_analytics_markup('), 3)
        self.assertIn('brvtal_public_entity_page(', (ROOT / 'config/public_page.php').read_text(encoding='utf-8'))
        self.assertIn('brvtal_public_home_identity(', (ROOT / 'config/public_home.php').read_text(encoding='utf-8'))


if __name__ == '__main__':
    unittest.main()
