#!/usr/bin/env python3
"""Browser-backed Concept 05 Hero acceptance (#968)."""
from __future__ import annotations

import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


class Concept05HeroTests(unittest.TestCase):
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
