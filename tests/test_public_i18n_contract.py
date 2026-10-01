from __future__ import annotations

import json
import subprocess
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]


class PublicI18nContractTests(unittest.TestCase):
    def php_payload(self, site_expression: str) -> dict:
        script = (
            "chdir($argv[1]); "
            "require 'config/public_i18n.php'; "
            f"echo json_encode(brvtal_public_i18n_payload({site_expression}), "
            "JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);"
        )
        result = subprocess.run(
            ["php", "-r", script, str(ROOT)],
            cwd=ROOT,
            check=True,
            capture_output=True,
            text=True,
        )
        return json.loads(result.stdout)

    def test_spanish_is_canonical_default_and_available_locales_are_effective(self) -> None:
        payload = self.php_payload(
            "['default_locale'=>'es','available_locales'=>['es','en','fr','en']]"
        )
        self.assertEqual(payload["canonical_locale"], "es")
        self.assertEqual(payload["default_locale"], "es")
        self.assertEqual(payload["available_locales"], ["es", "en"])

        fallback = self.php_payload(
            "['default_locale'=>'fr','available_locales'=>['fr']]"
        )
        self.assertEqual(fallback["canonical_locale"], "es")
        self.assertEqual(fallback["default_locale"], "es")
        self.assertEqual(fallback["available_locales"], ["es", "en"])

        index = (ROOT / "index.html").read_text(encoding="utf-8")
        api = (ROOT / "api/public.php").read_text(encoding="utf-8")
        runtime = (ROOT / "js/app.js").read_text(encoding="utf-8")
        self.assertIn('<html lang="es">', index)
        self.assertIn("brvtal_public_i18n_payload", api)
        self.assertIn("document.documentElement.lang = defaultLocale;", runtime)

    def test_critical_microcopy_and_protected_terms_are_versioned_in_code(self) -> None:
        payload = self.php_payload("[]")
        self.assertEqual(payload["version"], 1)
        self.assertEqual(set(payload["catalog"]), {"es", "en"})
        self.assertEqual(
            set(payload["catalog"]["es"]),
            set(payload["catalog"]["en"]),
        )
        self.assertEqual(payload["catalog"]["es"]["nav.events"], "EVENTOS")
        self.assertEqual(payload["catalog"]["en"]["nav.events"], "EVENTS")

        protected = payload["protected_terms"]
        self.assertIn("BRVTAL", protected["exact"])
        self.assertIn("RAVE TILL GRAVE", protected["exact"])
        self.assertIn("HARD TECHNO", protected["music_taxonomy"])
        self.assertIn("artists[].name", protected["entity_fields"])


if __name__ == "__main__":
    unittest.main()
