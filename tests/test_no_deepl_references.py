#!/usr/bin/env python3
"""#1443: no active DeepL/automatic translation code; preserve historical data."""
from __future__ import annotations

import json
import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
RETIRED = (
    "config/public_translation.php",
    "config/public_translation_deepl.php",
    "config/public_translation_deepl_page_trial.php",
    "config/public_translation_deepl_transport.php",
    "config/public_translation_overlay.php",
    "tests/deepl_one_page_trial_scenarios.php",
    "tests/deepl_real_transport_scenarios.php",
    "tests/deepl_translation_adapter_scenarios.php",
    "tests/editorial-translation-overlay-scenarios.php",
    "tests/test_deepl_adapter.py",
    "tests/test_deepl_one_page_trial.py",
    "tests/test_deepl_real_transport.py",
    "tests/test_editorial_translation_overlay.py",
    "tests/test_translation_cache.py",
    "tests/test_translation_provider_decision.py",
    "tests/test_translation_provider_evaluation.py",
    "tests/integration/public-translation-cache.php",
)
RUNTIME_DIRS = ("config", "api", "discadmin", "js")
FORBIDDEN = ("deepl", "brvtalpublictranslationadapter",
             "brvtalpubliceditorialoverlay")


class NoDeepLReferencesTests(unittest.TestCase):
    def test_retired_modules_and_fixtures_absent(self) -> None:
        for relative in RETIRED:
            with self.subTest(path=relative):
                self.assertFalse((ROOT / relative).exists())

    def test_public_runtime_cannot_invoke_or_simulate_translator(self) -> None:
        for folder in RUNTIME_DIRS:
            for path in (ROOT / folder).rglob("*"):
                if path.suffix.lower() not in {".php", ".js", ".mjs", ".ts", ".tsx"}:
                    continue
                content = path.read_text(encoding="utf-8", errors="replace").lower()
                for forbidden in FORBIDDEN:
                    with self.subTest(path=str(path.relative_to(ROOT)), token=forbidden):
                        self.assertNotIn(forbidden, content)

    def test_package_has_only_live_spanish_regressions(self) -> None:
        package = json.loads((ROOT / "package.json").read_text(encoding="utf-8"))
        self.assertIn("npm run test:no-deepl-references", package["scripts"]["test:integration"])
        for name, command in package["scripts"].items():
            if name == "test:no-deepl-references":
                continue
            sanitized = command.replace("npm run test:no-deepl-references", "")
            self.assertNotIn("deepl", (name + " " + sanitized).lower())
            self.assertNotIn("translation-cache", (name + " " + sanitized).lower())
            self.assertNotIn("translation-overlay", (name + " " + sanitized).lower())
        php_version = (ROOT / "config" / "version.php").read_text(encoding="utf-8")
        actual = re.search(r"BRVTAL_APP_VERSION = '([^']+)'", php_version)
        self.assertIsNotNone(actual)
        self.assertEqual(package["version"], actual.group(1))

    def test_historical_cache_schema_is_preserved(self) -> None:
        migration = ROOT / "database" / "migration_public_translation_cache_01.sql"
        self.assertTrue(migration.is_file())
        self.assertIn("public_translation_cache", migration.read_text(encoding="utf-8"))


if __name__ == "__main__":
    unittest.main()
