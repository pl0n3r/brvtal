#!/usr/bin/env python3
"""Browser-backed Concept 05 Hero acceptance (#968)."""
from __future__ import annotations

import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


class Concept05HeroTests(unittest.TestCase):

    def test_slider_enabled_cannot_hide_stacked_brand_hero(self) -> None:
        self._run_browser("Concept 05 authored hero stays visible with a published CMS slider at 390 and 1440")

    def test_admin_hero_copy_roundtrip_and_safe_fallback(self) -> None:
        self._run_browser("Concept 05 editable CTA and eyebrow hydrate safely and restore per locale")
        self._run_browser("Concept 05 hydrates editable CMS hero fields on boot and locale change at 390/1440")
        self._run_browser("Concept 05 CTA and eyebrow settings roundtrip and reject unsafe copy",
                          "tests/e2e/discadmin-settings-v2.spec.mjs")

    def _run_browser(self, test_name: str, spec: str = "tests/e2e/public-concept05-hero.spec.mjs") -> None:
        completed = subprocess.run(
            ["npx", "playwright", "test", spec, "--project=chromium",
             "--grep", test_name, "--workers=1"],
            cwd=ROOT, capture_output=True, text=True, timeout=180, check=False,
        )
        self.assertEqual(0, completed.returncode,
                         f"{test_name} failed:\n{completed.stdout[-5000:]}\n{completed.stderr[-5000:]}")

    def test_hero_playwright_desktop_mobile(self) -> None:
        """Execute the browser contract; never accept source-only assertions."""
        result = subprocess.run(
            [
                "npx", "playwright", "test",
                "tests/e2e/public-concept05-hero.spec.mjs",
                "--project=chromium",
                "--grep", "Concept 05 authored hero stays visible with a published CMS slider at 390 and 1440",
                "--workers=1",
            ],
            cwd=ROOT,
            capture_output=True,
            text=True,
            timeout=180,
            check=False,
        )
        self.assertEqual(
            0, result.returncode,
            f"Browser acceptance failed:\n{result.stdout[-5000:]}\n{result.stderr[-5000:]}",
        )


if __name__ == "__main__":
    unittest.main()
