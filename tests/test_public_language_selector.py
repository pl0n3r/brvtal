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

class PublicLanguageSelectorTests(unittest.TestCase):
    def test_navigation_has_no_language_selector(self):
        nav=read("partials/home/nav.html")
        styles=read("css/style.css")
        self.assertNotIn("languageSelector",nav)
        self.assertNotIn("language-selector",styles)
        self.assertNotIn("language-option",styles)
        self.assertIn('id="menuToggle"',nav)

    def test_browser_keeps_spanish_only_with_legacy_en_preference(self):
        runtime=read("js/app.js")
        self.assertIn("document.documentElement.lang = 'es'",runtime)
        self.assertIn("window.localStorage.removeItem('brvtal.public.locale')",runtime)
        self.assertIn("if (locale !== 'es') return false",runtime)
        self.assertNotIn("document.documentElement.lang = locale",runtime)

    def test_contact_and_dynamic_loader_reject_english(self):
        for path in ("js/public-contact.js","js/public-runtime-loader.js"):
            self.assertIn("new Set(['es'])",read(path))

    def test_no_synthetic_audio_toggle_reintroduced(self):
        self.assertNotIn('id="soundToggle"',read("partials/home/nav.html"))
        self.assertNotIn("new AudioContext",read("js/app.js"))

if __name__=="__main__":
    unittest.main()
