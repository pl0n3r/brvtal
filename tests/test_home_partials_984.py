"""Lossless public-home decomposition contract for BRVTAL #984."""
from __future__ import annotations

import shutil
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
NAMES = (
    "nav", "hero", "experience", "nights", "artists",
    "sound", "memories", "journal", "connected", "footer",
)
MARKER = "<!-- BRVTAL-HOME-PARTIAL:{} -->"


def render_static_composition() -> str:
    html = (ROOT / "index.html").read_text(encoding="utf-8")
    for name in NAMES:
        marker = MARKER.format(name)
        if html.count(marker) != 1:
            raise ValueError(f"Missing or repeated fixed partial marker: {name}")
        partial = ROOT / "partials" / "home" / f"{name}.html"
        html = html.replace(marker, partial.read_text(encoding="utf-8"))
    return html


class HomePartials984Tests(unittest.TestCase):
    def test_existing_php_public_home_contracts(self) -> None:
        php = (ROOT / "index.php").read_text(encoding="utf-8")
        self.assertIn("$homePartialPaths = [", php)
        self.assertIn("substr_count($html, $marker) !== 1", php)
        self.assertIn("http_response_code(503)", php)
        self.assertIn("brvtal_public_render_next_experience", php)
        self.assertIn("brvtal_public_optimize_home_images", php)
        self.assertIn("brvtalPublicApplyLocalizedDocument", php)
        self.assertNotIn("$_GET[", php[php.index("$homePartialPaths = ["):php.index("foreach ($homePartialPaths")])
        if shutil.which("php"):
            check = subprocess.run(
                ["php", "-l", "index.php"], cwd=ROOT,
                capture_output=True, text=True, check=False,
            )
            self.assertEqual(check.returncode, 0, check.stdout + check.stderr)

    def test_rendered_home_matches_pre_refactor_baseline(self) -> None:
        baseline = (ROOT / "tests" / "fixtures" / "home-partials-984-baseline.html").read_text(encoding="utf-8")
        self.assertEqual(render_static_composition(), baseline)
        self.assertIn('<main id="top">', baseline)
        self.assertIn('data-dynamic="artists"', baseline)
        self.assertIn('data-dynamic="events"', baseline)
        self.assertIn('data-dynamic="sets"', baseline)
        self.assertIn('data-dynamic="media"', baseline)

    def test_fixed_partial_composition_preserves_order(self) -> None:
        source = (ROOT / "index.html").read_text(encoding="utf-8")
        positions = [source.index(MARKER.format(name)) for name in NAMES]
        self.assertEqual(positions, sorted(positions))
        self.assertTrue(all(source.count(MARKER.format(name)) == 1 for name in NAMES))
        self.assertEqual(source.count("<!-- BRVTAL-HOME-PARTIAL:"), len(NAMES))
        self.assertEqual((ROOT / "partials" / "home" / "connected.html").read_text(encoding="utf-8"), "")

    def test_home_partial_ownership_map_is_disjoint(self) -> None:
        mapping = (ROOT / "docs" / "reference" / "home-partials-map.md").read_text(encoding="utf-8")
        for name in NAMES:
            self.assertEqual(mapping.count(f"\`{name}.html\`"), 1, name)
        self.assertIn("#969", mapping)
        self.assertIn("#968", mapping)
        self.assertIn("#970", mapping)
        self.assertIn("#971", mapping)
        self.assertIn("#972", mapping)
        self.assertIn("#973", mapping)
        self.assertIn("#974", mapping)


if __name__ == "__main__":
    unittest.main()
