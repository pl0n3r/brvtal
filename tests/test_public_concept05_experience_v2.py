#!/usr/bin/env python3
"""Executable, release-independent acceptance for Concept 05 Next Experience.

Issue #970, AC-01 .. AC-04. The canonical PHP renderer and actual Chromium
suite are executed; an HTML fixture is never mistaken for published CMS data.
Visual owner-v2 parity still requires a reviewed A/B PR attachment.
"""
from __future__ import annotations

import shutil
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SPEC = "tests/e2e/public-concept05-experience.spec.mjs"
PHP_CONTRACT = "tests/public-home-contract.php"


class Concept05ExperienceV2Tests(unittest.TestCase):
    def _run_php(self, expression: str | None = None) -> str:
        php = shutil.which("php")
        self.assertIsNotNone(php, "PHP executable required for real Home renderer acceptance")
        command = [php, PHP_CONTRACT] if expression is None else [php, "-r", expression]
        result = subprocess.run(
            command, cwd=ROOT, capture_output=True, text=True,
            timeout=45, check=False,
        )
        self.assertEqual(
            result.returncode, 0,
            "Canonical PHP Home contract failed:\n"
            + (result.stdout + "\n" + result.stderr)[-10000:],
        )
        return result.stdout

    def _run_playwright(self, *extra: str) -> None:
        binary = ROOT / "node_modules/.bin/playwright"
        self.assertTrue(binary.is_file(), "Project Playwright must be installed; no remote npx fallback")
        self.assertIsNotNone(shutil.which("node"), "Node runtime required")
        command = [str(binary), "test", SPEC, "--project=chromium", "--workers=1", *extra]
        result = subprocess.run(
            command, cwd=ROOT, capture_output=True, text=True,
            timeout=240, check=False,
        )
        self.assertEqual(
            result.returncode, 0,
            "Actual browser acceptance failed:\n"
            + (result.stdout + "\n" + result.stderr)[-12000:],
        )

    def test_next_published_event_renders_lineup_prices_and_tickets(self) -> None:
        """AC-01: the real renderer selects published CMS data and escapes URLs."""
        output = self._run_php()
        self.assertIn("BRVTAL Public Home contract tests passed.", output)

    def test_no_published_event_has_explicit_empty_state(self) -> None:
        """AC-02: active lifecycle gate and live PHP renderer must fail closed."""
        expression = r'''
require "config/public_home.php";
$now = new DateTimeImmutable("2026-10-10T12:00:00+00:00");
$events = [
  ["id"=>101,"title"=>"Old event","status"=>"published","event_date"=>"2026-10-09T21:00:00+00:00"],
  ["id"=>102,"title"=>"Unpublished","status"=>"draft","event_date"=>"2026-10-25T21:00:00+00:00"],
];
if (brvtal_public_select_next_experience($events, $now) !== null) {
  fwrite(STDERR, "Past/private CMS Event leaked through active filter\n"); exit(2);
}
$html = brvtal_public_render_next_experience(file_get_contents("index.html"), null);
$start = strpos($html, "<section class=\"genesis scene");
$end = $start === false ? false : strpos($html, "</section>", $start);
if ($start === false || $end === false) { fwrite(STDERR, "Missing empty experience\n"); exit(3); }
$section = substr($html, $start, $end - $start);
$required = [">NEXT SIGNAL</h2>", "NEW DATE TO BE ANNOUNCED.",
  "DATE TBA", "TIME TBA", "LOCATION TBA", "VIEW EVENTS"];
foreach ($required as $expected) {
  if (!str_contains($section, $expected)) {
    fwrite(STDERR, "Missing neutral fallback: " . $expected . "\n"); exit(4);
  }
}
if (str_contains($section, "ticket-cta") || str_contains($section, "c5-experience-ticket-signal")
    || str_contains($section, "<img")) {
  fwrite(STDERR, "Stale ticket, price or artwork in empty state\n"); exit(5);
}
echo "NEUTRAL_NEXT_EXPERIENCE_OK\n";
'''
        self.assertIn("NEUTRAL_NEXT_EXPERIENCE_OK", self._run_php(expression))

    def test_1440_and_390_visual_evidence_comparison_is_required(self) -> None:
        """AC-03: capture both browser viewports; PR still needs human A/B review."""
        ref = ROOT / "docs/reference/home-concept05-owner-reference-v2.png"
        self.assertTrue(ref.is_file(), "Owner-approved visual reference missing")
        with ref.open("rb") as fh:
            self.assertEqual(fh.read(8), b"\x89PNG\r\n\x1a\n")
        # This scenario records real PNG attachments in CI, not a fabricated
        # assertion of visual parity against a differently sized contact sheet.
        self._run_playwright("--grep", "Next Experience attaches owner-review captures at 390 and 1440")

    def test_playwright_experience_suite_runs_from_unittest_wrapper(self) -> None:
        """AC-04: execute genuine Chromium tests, including adverse media states."""
        self._run_playwright()


if __name__ == "__main__":
    unittest.main()
