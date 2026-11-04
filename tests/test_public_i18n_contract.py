from __future__ import annotations
import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]

def read(path: str) -> str:
    return (ROOT / path).read_text(encoding="utf-8")

def php(expression: str, imports: str = "config/public_i18n_routing.php"):
    command = "chdir($argv[1]); require '" + imports + "'; echo json_encode(" + expression + ", JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);"
    process = subprocess.run(["php", "-r", command, str(ROOT)], cwd=ROOT, text=True, capture_output=True)
    if process.returncode:
        raise AssertionError(process.stderr)
    return json.loads(process.stdout)

class PublicI18nContractTests(unittest.TestCase):
    def test_public_policy_ignores_legacy_english_settings(self):
        for settings in ("[]", "['default_locale'=>'en','available_locales'=>['en','es']]",
                         "['available_locales'=>['en']]", "['available_locales'=>['fr']]"):
            value = php("brvtalPublicI18nPayload(" + settings + ")", "config/public_i18n.php")
            self.assertEqual(value["canonical_locale"], "es")
            self.assertEqual(value["default_locale"], "es")
            self.assertEqual(value["available_locales"], ["es"])
            self.assertIn("es", value["catalog"])

    def test_legacy_offline_adapter_and_caches_are_not_deleted(self):
        source = read("config/public_i18n.php")
        self.assertIn("function brvtalPublicI18nCatalog()", source)
        self.assertIn("function brvtalPublicI18nEditorialSurfaceFields()", source)
        self.assertNotIn("DELETE FROM", source)

    def test_home_is_spanish_with_no_selector(self):
        self.assertIn('<html lang="es">', read("index.html"))
        nav = read("partials/home/nav.html")
        self.assertNotIn('id="languageSelector"', nav)
        self.assertNotIn('data-locale="en"', nav)
        self.assertIn('id="menuToggle"', nav)
        self.assertIn("window.BRVTALPublicLocale", read("js/app.js"))

    def test_ci_keeps_spanish_regression_suite(self):
        package = json.loads(read("package.json"))
        self.assertEqual(package["scripts"]["test:i18n"], "python3 -m unittest tests/test_public_i18n_contract.py")
        self.assertIn("npm run test:spanish-only", package["scripts"]["test:integration"])

if __name__ == "__main__":
    unittest.main()
