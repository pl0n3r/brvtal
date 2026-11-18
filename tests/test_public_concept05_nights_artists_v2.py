#!/usr/bin/env python3
"""BRVTAL #971 C05 02/03 acceptance: real CMS source, browser and visual evidence."""
from __future__ import annotations

import shutil
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def source(path: str) -> str:
    return (ROOT / path).read_text(encoding="utf-8")


class Concept05NightsArtistsV2Tests(unittest.TestCase):
    def test_02_and_03_share_reference_row_at_1440(self) -> None:
        home = source("index.html")
        nights = source("partials/home/nights.html")
        artists = source("partials/home/artists.html")
        css = source("css/public-concept05-nights-artists.css")
        self.assertIn('data-c5-board="02-03"', home)
        self.assertLess(home.index("BRVTAL-HOME-PARTIAL:nights"),
                        home.index("BRVTAL-HOME-PARTIAL:artists"))
        self.assertEqual(1, nights.count('id="events"'))
        self.assertEqual(1, artists.count('id="artists"'))
        self.assertIn('data-index="02"', nights)
        self.assertIn('data-index="03"', artists)
        self.assertIn("grid-template-columns:repeat(2,minmax(0,1fr))", css)
        self.assertIn("grid-template-columns:minmax(0,1fr)", css)
        self.assertIn("C05 real partials 1440 row", source(
            "tests/e2e/public-concept05-nights-artists.spec.mjs"))

    def test_published_nights_and_artists_come_from_cms_with_empty_states(self) -> None:
        nights = source("partials/home/nights.html")
        artists = source("partials/home/artists.html")
        enhancement = source("js/public-concept05-nights-artists.js")
        self.assertIn("data-c5-nights-empty", nights)
        self.assertIn("data-c5-artists-empty", artists)
        self.assertIn('class="events-track"', nights)
        self.assertIn('class="artist-list"', artists)
        self.assertNotIn('data-public-event-id="', nights)
        self.assertNotIn('class="artist" href="', artists)
        self.assertIn("function renderPastNights(payload)", enhancement)
        self.assertIn("data-c5-past-nights", enhancement)
        self.assertIn("if (artistEmpty) artistEmpty.hidden", enhancement)
        self.assertIn("canonicalEventList(activeItems, archiveItems)",
                      source("js/app.js"))
        self.assertIn("window.BRVTALPublicRoster", source("js/public-roster.js"))
        self.assertIn("brvtal:public-data", enhancement)
        self.assertIn("published !== undefined", enhancement)
        self.assertIn("localEventDay < todayMidnight", enhancement)
        self.assertIn("TODAY IS NOT HISTORY", source(
            "tests/e2e/public-concept05-nights-artists.spec.mjs"))
        self.assertIn("Allow only known public lifecycle states", enhancement)
        self.assertIn("latest published past nights are sorted newest-first", source(
            "tests/e2e/public-concept05-nights-artists.spec.mjs"))
        self.assertIn("data-c5-legacy-track", nights)
        self.assertIn("owner v2 roster has six columns", source(
            "tests/e2e/public-concept05-nights-artists.spec.mjs"))
        self.assertIn("CMS archive and published roster", source(
            "tests/e2e/public-concept05-nights-artists.spec.mjs"))

    def test_1440_and_390_visual_evidence_comparison_is_required(self) -> None:
        screenshot = ROOT / "docs/reference/home-concept05-owner-reference-v2.png"
        self.assertTrue(screenshot.is_file(), "Owner v2 image is mandatory")
        self.assertGreater(screenshot.stat().st_size, 1000)
        test = source("tests/e2e/public-concept05-nights-artists.spec.mjs")
        for marker in ("1440", "390", "page.screenshot({ fullPage: true })",
                       "testInfo.attach", "home-concept05-owner-reference-v2.png"):
            with self.subTest(marker=marker):
                self.assertIn(marker, test)

    def test_playwright_nights_artists_suite_runs_with_python_wrapper(self) -> None:
        self.assertIsNotNone(shutil.which("npx"), "Node/npx required, do not fake browser PASS")
        result = subprocess.run(
            ["npx", "playwright", "test",
             "tests/e2e/public-concept05-nights-artists.spec.mjs",
             "--project=chromium", "--workers=1"],
            cwd=ROOT, capture_output=True, text=True, check=False, timeout=240,
        )
        self.assertEqual(0, result.returncode,
                         "Actual Playwright suite failed:\n"
                         + result.stdout[-4000:] + result.stderr[-4000:])


if __name__ == "__main__":
    unittest.main()
