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

class PublicI18nE2ETests(unittest.TestCase):
    def test_browser_contracts_now_target_spanish_only(self):
        selector = read("tests/e2e/public-language-selector.spec.mjs")
        contact = read("tests/e2e/public-contact-page.spec.mjs")
        self.assertIn("Spanish-only",selector)
        self.assertIn("Spanish-only",contact)
        self.assertNotIn("ES/EN switches without navigation",selector)
        self.assertNotIn("Contact follows persisted locale",contact)
        for file in ("tests/e2e/public-language-selector.spec.mjs","tests/e2e/public-contact-page.spec.mjs"):
            result=subprocess.run(["node","--check",file],cwd=ROOT,capture_output=True,text=True)
            self.assertEqual(result.returncode,0,result.stderr)

    def test_canonical_public_route_and_metadata(self):
        route = php("brvtalPublicLocalizedRouteUrls('https://www.brvtal.com.co','es','blog','ritual-industrial')")
        self.assertEqual(route["canonical"],"https://www.brvtal.com.co/blog/ritual-industrial")
        self.assertEqual(route["alternates"],{})

    def test_version_bump_and_ci_commands(self):
        package=json.loads(read("package.json"))
        self.assertEqual(package["version"],"0.1.185")
        self.assertIn("BRVTAL_APP_VERSION = '0.1.185'",read("config/version.php"))
        self.assertIn("npm run test:i18n-e2e",package["scripts"]["test:integration"])

if __name__=="__main__":
    unittest.main()
