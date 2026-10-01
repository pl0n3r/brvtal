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
            f"echo json_encode(brvtalPublicI18nPayload({site_expression}), "
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

        stale_english_default = self.php_payload(
            "['default_locale'=>'en','available_locales'=>['es','en']]"
        )
        self.assertEqual(stale_english_default["default_locale"], "es")

        fallback = self.php_payload(
            "['default_locale'=>'fr','available_locales'=>['fr']]"
        )
        self.assertEqual(fallback["canonical_locale"], "es")
        self.assertEqual(fallback["default_locale"], "es")
        self.assertEqual(fallback["available_locales"], ["es", "en"])

        index = (ROOT / "index.html").read_text(encoding="utf-8")
        api = (ROOT / "api/public.php").read_text(encoding="utf-8")
        runtime = (ROOT / "js/app.js").read_text(encoding="utf-8")
        self.assertIn('<html lang="en">', index)
        self.assertIn("brvtalPublicI18nPayload", api)
        self.assertIn("window.BRVTALI18N", runtime)
        lint = subprocess.run(
            ["php", "-l", "api/public.php"],
            cwd=ROOT,
            capture_output=True,
            text=True,
        )
        self.assertEqual(lint.returncode, 0, lint.stderr)

    def test_browser_delegates_locale_policy_to_server_payload(self) -> None:
        runtime = (ROOT / "js" / "app.js").read_text(encoding="utf-8")
        self.assertNotIn("const supportedLocales", runtime)
        self.assertNotIn("configuredLocales", runtime)
        self.assertNotIn("canonicalLocale: 'es'", runtime)
        self.assertIn(
            "i18n.available_locales.every(locale => typeof locale === 'string')",
            runtime,
        )
        self.assertIn("typeof i18n.default_locale === 'string'", runtime)
        self.assertIn("typeof i18n.canonical_locale === 'string'", runtime)
        self.assertIn("canonicalLocale,", runtime)

    def test_selector_owned_document_locale_is_not_applied_in_foundation_leaf(self) -> None:
        index = (ROOT / "index.html").read_text(encoding="utf-8")
        runtime = (ROOT / "js" / "app.js").read_text(encoding="utf-8")
        self.assertIn('<html lang="en">', index)
        self.assertIn('<span>EN</span>', index)
        self.assertNotIn("document.documentElement.lang = defaultLocale;", runtime)
        self.assertNotIn("document.documentElement.dataset.locale = defaultLocale;", runtime)
        self.assertIn("window.BRVTALI18N", runtime)

    def test_legacy_pages_remain_unchanged_until_atomic_locale_migration(self) -> None:
        api = (ROOT / "api" / "public.php").read_text(encoding="utf-8")
        self.assertIn("WHERE status='published' AND locale='en'", api)
        self.assertNotIn("$pagesStatement->execute([$pageLocale]);", api)
        self.assertIn("migration must be atomic", api)

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
        self.assertIn("events[].title", protected["entity_fields"])

    def test_required_ci_command_executes_acceptance_suite(self) -> None:
        package = json.loads((ROOT / "package.json").read_text(encoding="utf-8"))
        self.assertEqual(
            package["scripts"]["test:i18n"],
            "python3 -m unittest tests/test_public_i18n_contract.py",
        )
        self.assertIn("npm run test:i18n", package["scripts"]["test:integration"])


if __name__ == "__main__":
    unittest.main()
