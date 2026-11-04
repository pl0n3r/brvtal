#!/usr/bin/env python3
"""Public Spanish-only release contract for BRVTAL #1441."""
from __future__ import annotations

import json
import re
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
ORIGIN = "https://www.brvtal.com.co"


def read(path: str) -> str:
    return (ROOT / path).read_text(encoding="utf-8")


def php(expression: str, filename: str):
    code = ("chdir($argv[1]); require '" + filename
            + "'; echo json_encode(" + expression
            + ",JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);")
    result = subprocess.run(["php", "-r", code, str(ROOT)],
                            cwd=ROOT, capture_output=True, text=True)
    if result.returncode:
        raise AssertionError(result.stderr or result.stdout)
    return json.loads(result.stdout)


class SpanishOnlyPublicTests(unittest.TestCase):
    def test_legacy_en_paths_redirect_301_to_equivalent_spanish_urls(self):
        router = read(".htaccess")
        self.assertIn("RewriteRule ^en/*$ / [R=301,L,NE,QSD,NC]", router)
        self.assertIn("RewriteRule ^en/+([^/].*)$ /$1 [R=301,L,NE,QSD,NC]", router)
        self.assertNotIn("index.php?locale=en", router)
        paths = {"en": "/", "en/": "/", "en/contact": "/contact",
                 "en/privacy": "/privacy", "en/events/genesis": "/events/genesis",
                 "en/blog/ritual-industrial": "/blog/ritual-industrial"}
        for value, expected in paths.items():
            if re.fullmatch(r"en/*", value, re.I):
                target = "/"
            else:
                match = re.fullmatch(r"en/+([^/].*)", value, re.I)
                self.assertIsNotNone(match, value)
                target = "/" + match.group(1)
            self.assertEqual(target, expected)
            self.assertFalse(target.startswith("//"))

    def test_runtime_rejects_english_even_if_legacy_settings_offer_it(self):
        locales = php("[brvtalPublicRouteLocale(null),brvtalPublicRouteLocale('es'),"
                      "brvtalPublicRouteLocale('en'),brvtalPublicRouteLocale('fr')]",
                      "config/public_i18n_routing.php")
        self.assertEqual(locales, ["es", "es", None, None])
        self.assertIsNone(php("brvtalPublicLocalizedRouteUrls('https://www.brvtal.com.co','en','events','genesis')",
                              "config/public_i18n_routing.php"))
        policy = php("brvtalPublicI18nPayload(['available_locales'=>['en','es']])",
                     "config/public_i18n.php")
        self.assertEqual(policy["available_locales"], ["es"])

    def test_public_pages_are_fixed_es_without_selector_or_translation_calls(self):
        index = read("index.php")
        nav = read("partials/home/nav.html")
        self.assertNotIn("brvtalDeepLOnePageTrialForRequest(", index)
        self.assertNotIn("public_translation_deepl_page_trial.php", index)
        self.assertNotIn("languageSelector", nav)
        self.assertIn('id="menuToggle"', nav)
        self.assertIn("document.documentElement.lang = 'es'", read("js/app.js"))
        self.assertIn("new Set(['es'])", read("js/public-contact.js"))
        self.assertIn("new Set(['es'])", read("js/public-runtime-loader.js"))

    def test_sitemap_and_seo_do_not_publish_english_or_hreflang(self):
        result = php("brvtal_public_sitemap_xml("
                     "brvtalPublicSitemapLocalizedEntityUrls('https://www.brvtal.com.co',"
                     "['id'=>1,'slug'=>'genesis','route_type'=>'blog','status'=>'published'],"
                     "null,['es','en']),"
                     "'https://www.brvtal.com.co')", "config/public_sitemap.php")
        self.assertIn("<loc>" + ORIGIN + "/blog/genesis</loc>", result)
        self.assertNotIn("/en/", result)
        self.assertNotIn("hreflang=", result)
        self.assertNotIn("xmlns:xhtml=", result)
        self.assertNotIn('hreflang=', read("config/public_seo.php").split("function brvtal_public_seo_tags", 1)[1])

    def test_version_and_test_entry_are_synced(self):
        package = json.loads(read("package.json"))
        self.assertEqual(package["version"], "0.1.185")
        self.assertIn("BRVTAL_APP_VERSION = '0.1.185'", read("config/version.php"))
        self.assertEqual(package["scripts"]["test:spanish-only"],
                         "python3 -m unittest tests/test_public_spanish_only.py")
        self.assertIn("npm run test:spanish-only", package["scripts"]["test:integration"])


if __name__ == "__main__":
    unittest.main()
