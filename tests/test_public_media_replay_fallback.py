"""Real Playwright regressions for failed WebP replay, BRVTAL #985."""
from pathlib import Path
import re
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
PLAYWRIGHT = ROOT / "node_modules" / ".bin" / "playwright"
SUITE = "tests/e2e/public-media.spec.mjs"


class PublicMediaReplayFallbackTests(unittest.TestCase):
    """Execute actual Chromium tests; no textual substitutes or mocked test runner."""

    def run_case(self, case):
        self.assertTrue(PLAYWRIGHT.is_file(), "Playwright must be installed by npm ci")
        command = [
            str(PLAYWRIGHT), "test", SUITE, "--project=chromium",
            "--grep", "^public image replay fallback: " + re.escape(case) + "$",
            "--reporter=line",
        ]
        result = subprocess.run(
            command, cwd=ROOT, capture_output=True, text=True,
            timeout=150, check=False,
        )
        self.assertEqual(
            result.returncode, 0,
            f"Playwright Chromium case {case!r} failed:\n"
            f"{result.stdout[-5000:]}\n{result.stderr[-5000:]}",
        )
        self.assertIn("passed", result.stdout + result.stderr)

    def test_same_asset_replay_does_not_reselect_failed_webp(self):
        self.run_case("same asset replay does not reselect failed WebP")

    def test_original_404_replay_remains_fail_closed(self):
        self.run_case("original 404 replay remains fail closed")

    def test_new_asset_can_retry_without_stale_fallback(self):
        self.run_case("new asset can retry without stale fallback")

    def test_real_playwright_suite_runs(self):
        for case in (
            "same asset replay does not reselect failed WebP",
            "original 404 replay remains fail closed",
            "new asset can retry without stale fallback",
        ):
            with self.subTest(case=case):
                self.run_case(case)


if __name__ == "__main__":
    unittest.main()
