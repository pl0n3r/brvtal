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

class PublicI18nRoutingTests(unittest.TestCase):
    def test_spanish_routes_keep_original_paths(self):
        routes = php("[brvtalPublicLocalizedRouteUrls('https://www.brvtal.com.co','es'),brvtalPublicLocalizedRouteUrls('https://www.brvtal.com.co','es','events','genesis'),brvtalPublicLocalizedRouteUrls('https://www.brvtal.com.co','es','','','contact')]")
        self.assertEqual([r["canonical"] for r in routes], ["https://www.brvtal.com.co/","https://www.brvtal.com.co/events/genesis","https://www.brvtal.com.co/contact"])
        self.assertTrue(all(r["alternates"] == [] for r in routes))

    def test_en_and_invalid_locale_are_not_public_routes(self):
        values = php("[brvtalPublicRouteLocale(null),brvtalPublicRouteLocale('es'),brvtalPublicRouteLocale('en'),brvtalPublicRouteLocale('fr'),brvtalPublicLocalizedRoute('en','events','genesis'),brvtalPublicLocalizedRoute('es','events','invalid/slug')]")
        self.assertEqual(values, ["es", "es", None, None, None, None])

    def test_seo_does_not_emit_language_alternates(self):
        expression = """brvtalPublicApplyLocalizedSeo(['title'=>'BRVTAL','schema'=>['url'=>'old']],brvtalPublicLocalizedRouteUrls('https://www.brvtal.com.co','es','events','genesis'))"""
        result=php(expression)
        self.assertEqual(result["canonical"], "https://www.brvtal.com.co/events/genesis")
        self.assertNotIn("alternates", result)
        self.assertEqual(result["schema"]["url"], result["canonical"])

    def test_server_redirects_english_routes_before_php(self):
        rules=read(".htaccess")
        self.assertIn("RewriteRule ^en/*$ / [R=301,L,NE,QSD,NC]", rules)
        self.assertIn("RewriteRule ^en/+([^/].*)$ /$1 [R=301,L,NE,QSD,NC]",rules)
        self.assertNotIn("index.php?locale=en",rules)

if __name__ == "__main__":
    unittest.main()
