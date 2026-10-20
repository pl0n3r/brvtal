#!/usr/bin/env python3
"""Concept 05 build-ahead contract for the real journal/connected home partials."""
from __future__ import annotations

import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def source(path: str) -> str:
    return (ROOT / path).read_text(encoding="utf-8")


class HomeC05JournalConnectedTests(unittest.TestCase):
    def test_journal_lists_published_transmissions_in_date_order(self) -> None:
        js = source("js/public-transmissions.js")
        self.assertIn("const publicationTime = value =>", js)
        self.assertIn("const publishedPosts = data =>", js)
        self.assertIn(".sort((a, b) => (a.timestamp === null) - (b.timestamp === null)", js)
        self.assertIn("(post.published_at == null || timestamp !== null)", js)
        self.assertIn("publicationTime(posts[0]?.published_at) === null", js)
        self.assertLess(js.index(".sort((a, b)"), js.index(".slice(0, 5)", js.index("const publishedPosts")))
        self.assertIn("post.status === 'published'", js)
        self.assertIn("post.is_published !== false", js)
        self.assertIn("routeUrl('blog', post.slug)", js)
        self.assertIn("const posts = publishedPosts(data);", js)
        self.assertIn("timeZone:'UTC'", js)
        self.assertIn("CMS publishes newest Journal entries", source(
            "tests/e2e/public-concept05-journal-connected.spec.mjs"))
        self.assertIn("Published CMS post without a publication date stays visible", source(
            "tests/e2e/public-concept05-journal-connected.spec.mjs"))

    def test_connected_renders_bounded_cms_node_map(self) -> None:
        section = source("partials/home/connected.html")
        js = source("js/public-concept05-connected.js")
        self.assertEqual(1, section.count('id="connected"'))
        self.assertEqual(1, section.count('data-index="07"'))
        self.assertEqual(5, section.count('data-connected-node="'))
        self.assertIn('data-connected-lines', section)
        self.assertIn('data-connected-ledger', section)
        self.assertIn('TODO CONECTADO.', section)
        self.assertIn("relations && typeof relations.counts === 'object'", js)
        self.assertIn("relations.counts !== null", js)
        self.assertIn("!Array.isArray(relations.counts)", js)
        self.assertIn("Number.isSafeInteger(value)", js)
        self.assertIn("Connected rejects malformed CMS counts", source(
            "tests/e2e/public-concept05-journal-connected.spec.mjs"))
        self.assertIn("Connected tolerates null CMS relationship counts", source(
            "tests/e2e/public-concept05-journal-connected.spec.mjs"))
        self.assertIn("if (value < 1) return;", js)
        self.assertIn("window.BRVTALPublicDataPromise", js)

    def test_desktop_mobile_reference_contract_and_capture_gate(self) -> None:
        journal = source("partials/home/journal.html")
        connected = source("partials/home/connected.html")
        php = source("index.php")
        shell = source("index.html")
        css = source("css/public-concept05-journal-connected.css")
        e2e = source("tests/e2e/public-concept05-journal-connected.spec.mjs")
        self.assertIn('class="scene transmissions c5-numbered"', journal)
        self.assertEqual(1, journal.count('data-index="06"'))
        self.assertNotIn('data-index="07"', journal)
        self.assertIn("BRVTAL JOURNAL / 06", journal)
        self.assertIn("'css/public-concept05-tokens.css',", php)
        self.assertIn("'css/public-concept05-journal-connected.css',", php)
        self.assertIn("'<body data-scene=\"CORE\" data-concept=\"05\">'", php)
        self.assertLess(php.index("'css/public-concept05-tokens.css'"),
                        php.index("'css/public-concept05-journal-connected.css'"))
        self.assertIn("'journal' => __DIR__ . '/partials/home/journal.html'", php)
        self.assertIn("'connected' => __DIR__ . '/partials/home/connected.html'", php)
        for name in ("journal", "connected"):
            self.assertEqual(1, shell.count(f"<!-- BRVTAL-HOME-PARTIAL:{name} -->"))
        self.assertIn("grid-template-columns:repeat(5,minmax(0,1fr));", css)
        self.assertIn("grid-template-columns:repeat(2,minmax(0,1fr));", css)
        for marker in ("[390,1440]", "page.screenshot({fullPage:true})",
                       "build-ahead-local-partials-", 'data-index="07"'):
            self.assertIn(marker, e2e)
        self.assertEqual(1, connected.count('data-index="07"'))

    def test_browser_scenarios_cover_journal_and_connected(self) -> None:
        loader = source("js/public-runtime-loader.js")
        js = source("js/public-concept05-connected.js")
        test = source("tests/e2e/public-concept05-journal-connected.spec.mjs")
        self.assertEqual(1, loader.count("'js/public-concept05-connected.js'"))
        self.assertIn("'js/public-transmissions.js', 'js/public-concept05-connected.js'", loader)
        self.assertIn("document.querySelector('#connected[data-scene], section#connected')", js)
        self.assertIn("Connected renders no fake lines", test)
        self.assertIn("CMS publishes newest Journal entries", test)
        self.assertIn("real home partials source mounts", test)
        self.assertIn("Python AC-01..04 bridge executes alongside Playwright acceptance", test)
        self.assertIn("'tests/test_home_c05_journal_connected.py'", test)
        self.assertEqual(1, source("js/public-transmissions.js").count("function render(data)"))


if __name__ == "__main__":
    unittest.main()
