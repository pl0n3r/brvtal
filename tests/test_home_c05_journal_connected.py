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
        # Behavioral assertions run in Chromium, not against implementation text.
        e2e = source("tests/e2e/public-concept05-journal-connected.spec.mjs")
        for scenario in (
            "CMS publishes newest Journal entries",
            "CMS database flags never expose unpublished Journal records",
            "Published CMS post without a publication date stays visible",
            "Journal waits for CMS public data to be published after the loader",
        ):
            self.assertIn(f"test('{scenario}", e2e)

    def test_connected_renders_bounded_cms_node_map(self) -> None:
        section = source("partials/home/connected.html")
        self.assertEqual(1, section.count('id="connected"'))
        self.assertEqual(1, section.count('data-index="07"'))
        self.assertEqual(5, section.count('data-connected-node="'))
        self.assertIn("data-connected-lines", section)
        self.assertIn("data-connected-ledger", section)
        self.assertIn("TODO CONECTADO.", section)

        # Chromium exercises malformed, inherited, null and imprecise data.
        e2e = source("tests/e2e/public-concept05-journal-connected.spec.mjs")
        for scenario in (
            "Connected renders no fake lines",
            "Connected ignores inherited relation counters",
            "Connected rejects malformed CMS counts",
            "Connected tolerates null CMS relationship counts",
            "Connected declines an imprecise aggregate total",
        ):
            self.assertIn(f"test('{scenario}", e2e)

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
        # A/B evidence must render a real local editorial fixture, not a missing cover.
        visual = source("tests/e2e/public-concept05-fidelity-matrix.spec.mjs")
        self.assertIn("document.querySelector('#media img[src^=", visual)
        self.assertIn("image.complete && image.naturalWidth > 0", visual)
        self.assertIn("transmission-cover.is-media-missing", visual)
        self.assertIn("NOCHE / SEÑAL", visual)
        self.assertIn("FRECUENCIAS DEL ARCHIVO", visual)
        self.assertIn("MEMORIAS VIVAS", visual)
        self.assertIn(".toHaveText(['NOCHE / SEÑAL'", visual)

    def test_browser_scenarios_cover_journal_and_connected(self) -> None:
        loader = source("js/public-runtime-loader.js")
        spec = source("tests/e2e/public-concept05-journal-connected.spec.mjs")
        self.assertEqual(1, loader.count("'js/public-concept05-connected.js'"))
        self.assertIn("'js/public-transmissions.js', 'js/public-concept05-connected.js'", loader)
        for scenario in (
            "Connected renders no fake lines",
            "CMS publishes newest Journal entries",
            "real home partials source mounts",
            "Python AC-01..04 bridge executes alongside Playwright acceptance",
        ):
            self.assertIn(f"test('{scenario}", spec)
        self.assertIn("'tests/test_home_c05_journal_connected.py'", spec)


if __name__ == "__main__":
    unittest.main()
