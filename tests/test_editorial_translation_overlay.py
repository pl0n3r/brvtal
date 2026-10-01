from __future__ import annotations

import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SCENARIO = ROOT / "tests" / "editorial-translation-overlay-scenarios.php"


def run_scenario(name: str) -> str:
    result = subprocess.run(
        ["php", str(SCENARIO), name],
        cwd=ROOT,
        text=True,
        capture_output=True,
        timeout=20,
        check=False,
    )
    output = result.stdout + result.stderr
    if result.returncode != 0:
        raise AssertionError(output)
    return output


class EditorialTranslationOverlayTests(unittest.TestCase):
    def test_overlay_preserves_spanish_source_identity_and_invalidates_on_source_change(self):
        output = run_scenario("identity")
        self.assertIn("identity-ok", output)

    def test_untrusted_or_private_content_is_not_published_through_translation_overlay(self):
        output = run_scenario("visibility")
        self.assertIn("visibility-ok", output)


if __name__ == "__main__":
    unittest.main()
