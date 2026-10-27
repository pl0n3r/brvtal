#!/usr/bin/env python3
"""HOME C05 v2 acceptance against the actual PHP partial composition.

This tests the server-side composer used by index.php, not synthetic markup()
from Playwright fixtures. A visual sign-off remains a separate PR review gate.
"""
from __future__ import annotations

import json
import re
import subprocess
import unittest
from html.parser import HTMLParser
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
REFERENCE = ROOT / "docs/reference/home-concept05-owner-reference-v2.png"
E2E = ROOT / "tests/e2e/public-concept05-shell.spec.mjs"
STYLE = ROOT / "css/public-concept05-shell.css"
LOADER = ROOT / "js/public-runtime-loader.js"

PHP_RENDER = r"""
require 'config/public_home.php';
$html = file_get_contents('index.html');
if (!is_string($html)) { throw new RuntimeException('index unavailable'); }
foreach (['nav','hero','experience','nights','artists','sound','memories','journal','connected','footer'] as $part) {
    $marker = '<!-- BRVTAL-HOME-PARTIAL:' . $part . ' -->';
    $file = 'partials/home/' . $part . '.html';
    if (substr_count($html, $marker) !== 1 || !is_file($file)) {
        throw new RuntimeException('missing home partial');
    }
    $value = file_get_contents($file);
    if (!is_string($value)) { throw new RuntimeException('bad home partial'); }
    $html = str_replace($marker, $value, $html);
}
echo brvtal_public_home_identity($html);
"""


class NavParser(HTMLParser):
    def __init__(self) -> None:
        super().__init__()
        self.stack: list[str] = []
        self.links: dict[str, list[dict[str, str]]] = {}
        self.nav_counts: dict[str, int] = {}

    def handle_starttag(self, tag: str, attrs: list[tuple[str, str | None]]) -> None:
        attr = dict(attrs)
        classes = (attr.get("class") or "").split()
        if tag == "nav":
            for kind in ("c5-header-nav", "c5-bottom-nav", "c5-footer-nav"):
                if kind in classes:
                    self.nav_counts[kind] = self.nav_counts.get(kind, 0) + 1
                    self.stack.append(kind)
        elif tag == "a" and self.stack:
            item = {key: value or "" for key, value in attrs}
            self.links.setdefault(self.stack[-1], []).append(item)

    def handle_endtag(self, tag: str) -> None:
        if tag == "nav" and self.stack:
            self.stack.pop()


class Concept05ShellV2Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        result = subprocess.run(
            ["php", "-r", PHP_RENDER], cwd=ROOT, capture_output=True,
            text=True, timeout=30, check=False,
        )
        if result.returncode:
            raise AssertionError("Real PHP shell composition failed: " + result.stderr[-2000:])
        cls.html = result.stdout
        cls.nav = NavParser()
        cls.nav.feed(cls.html)

    def test_desktop_mobile_navigation_and_footer_use_canonical_routes(self) -> None:
        destinations = {
            "c5-header-nav": ["#events", "#artists", "#sets", "/releases", "#transmissions", "#connected"],
            "c5-bottom-nav": ["#events", "#artists", "#sets", "/releases", "#transmissions"],
            "c5-footer-nav": ["#events", "#artists", "#sets", "/releases", "#transmissions", "#connected"],
        }
        for kind, expected in destinations.items():
            with self.subTest(nav=kind):
                self.assertEqual(self.nav.nav_counts.get(kind), 1)
                self.assertEqual([a.get("href") for a in self.nav.links[kind]], expected)
        self.assertEqual(self.html.count('class="footer scene c5-footer"'), 1)
        self.assertIn('data-footer-privacy hidden', self.html)
        self.assertIn('href="/contact">CONTACT', self.html)
        # The search affordance must land on the existing local archive filters.
        runtime = (ROOT / "js/public-concept05-shell.js").read_text(encoding="utf-8")
        self.assertIn("function attachArchiveSearch()", runtime)
        self.assertIn("if (!bottomNav || !document.querySelector('.c5-footer')) return false;", runtime)
        self.assertIn("anchor.href = '#media';", runtime)
        self.assertNotIn('class="c5-header-search magnetic"', self.html)

    def test_mobile_touch_targets_and_keyboard_accessibility(self) -> None:
        css = STYLE.read_text(encoding="utf-8")
        shell = (ROOT / "js/public-concept05-shell.js").read_text(encoding="utf-8")
        self.assertIn('min-height:48px;', css)
        self.assertIn('min-width:44px;min-height:44px', css)
        self.assertIn('c5-header-search:focus-visible', css)
        self.assertIn('bottomNav.inert = locked;', shell)
        self.assertIn("bottomNav.setAttribute('aria-hidden'", shell)
        self.assertIn('aria-label="Primary mobile"', self.html)
        self.assertEqual(self.html.count('class="c5-bottom-nav"'), 1)
        # The composer declares this stylesheet once; index.php must not
        # inject the same stylesheet a second time.
        self.assertNotIn("'css/public-concept05-shell.css',", (ROOT / "index.php").read_text(encoding="utf-8"))
        self.assertIn("css/public-concept05-shell.css", (ROOT / "config/public_home.php").read_text(encoding="utf-8"))
        self.assertEqual(self.html.count('href="css/public-concept05-shell.css"'), 1)
        loader = LOADER.read_text(encoding="utf-8")
        index = (ROOT / "index.php").read_text(encoding="utf-8")
        self.assertEqual(loader.count("'js/public-concept05-shell.js'"), 1)
        # Production index.php sets data-concept on <body>, so the loader
        # must inspect body rather than <html>; browser E2E exercises boot().
        self.assertIn("document.body?.dataset.concept === '05'", loader)
        self.assertIn("'<body data-scene=\"CORE\" data-concept=\"05\">'", index)
        self.assertNotIn("document.documentElement.dataset.concept === '05'", loader)
        self.assertIn("!document.querySelector('script[src^=\"js/public-concept05-shell.js\"]')", loader)

    def test_reference_capture_contract_retains_review_gate(self) -> None:
        self.assertTrue(REFERENCE.is_file(), "The owner's visual reference must remain available")
        self.assertTrue(REFERENCE.read_bytes().startswith(b"\x89PNG\r\n\x1a\n"))
        fixture = json.loads((ROOT / "tests/fixtures/public-concept05-reference-v1.json").read_text(encoding="utf-8"))
        self.assertEqual(fixture["owner_reference_v2"]["viewports"]["desktop"]["width"], 1440)
        self.assertEqual(fixture["owner_reference_v2"]["viewports"]["mobile"]["width"], 390)
        e2e = E2E.read_text(encoding="utf-8")
        for marker in ("real PHP partial composition", "c05-php-desktop-1440.png",
                       "c05-php-mobile-390.png", "page.screenshot("):
            self.assertIn(marker, e2e)
        # This test validates capture wiring, NOT aesthetic approval.
        # Screenshots from a real Chromium run still require PR comparison.

    def test_playwright_shell_scenarios(self) -> None:
        # Database-only CI jobs do not install browser dependencies. The
        # Chromium job runs the real Playwright suite, including PHP markup.
        # Do not attempt a network npm install from this database test.
        if not (ROOT / "node_modules" / "@playwright" / "test").exists():
            self.skipTest("Browser bridge executes in Chromium job with @playwright/test")
        result = subprocess.run(
            ["npx", "playwright", "test", "tests/e2e/public-concept05-shell.spec.mjs",
             "--project=chromium"], cwd=ROOT, capture_output=True, text=True,
            timeout=240, check=False,
        )
        self.assertEqual(result.returncode, 0, result.stdout[-3500:] + result.stderr[-3500:])


if __name__ == "__main__":
    unittest.main()
