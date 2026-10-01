from __future__ import annotations

import json
import subprocess
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]


class PublicI18nRoutingTests(unittest.TestCase):
    def php_json(self, expression: str) -> dict | list | str | None:
        script = (
            "chdir($argv[1]); "
            "require 'config/public_i18n_routing.php'; "
            f"echo json_encode({expression}, "
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

    def test_localized_routes_emit_canonical_and_reciprocal_hreflang(self) -> None:
        base = "https://www.brvtal.com.co"
        spanish = self.php_json(
            f"brvtalPublicLocalizedRouteUrls('{base}', 'es', 'events', 'genesis')"
        )
        english = self.php_json(
            f"brvtalPublicLocalizedRouteUrls('{base}', 'en', 'events', 'genesis')"
        )

        self.assertEqual(spanish["canonical"], f"{base}/events/genesis")
        self.assertEqual(english["canonical"], f"{base}/en/events/genesis")
        self.assertEqual(
            spanish["alternates"],
            {
                "es": f"{base}/events/genesis",
                "en": f"{base}/en/events/genesis",
                "x-default": f"{base}/events/genesis",
            },
        )
        self.assertEqual(english["alternates"], spanish["alternates"])

        seo_script = (
            "chdir($argv[1]); "
            "require 'config/public_i18n_routing.php'; "
            "require 'config/public_seo.php'; "
            "$route=brvtalPublicLocalizedRouteUrls('https://www.brvtal.com.co','en','events','genesis'); "
            "$seo=brvtalPublicApplyLocalizedSeo(["
            "'title'=>'GENESIS — BRVTAL','description'=>'x',"
            "'canonical'=>'https://www.brvtal.com.co/events/genesis',"
            "'image'=>'https://www.brvtal.com.co/assets/brvtal-logo.jpeg',"
            "'schema'=>['@type'=>'MusicEvent','url'=>'https://www.brvtal.com.co/events/genesis']"
            "],$route); echo brvtal_public_seo_tags($seo);"
        )
        rendered = subprocess.run(
            ["php", "-r", seo_script, str(ROOT)],
            cwd=ROOT,
            check=True,
            capture_output=True,
            text=True,
        ).stdout
        self.assertIn(
            '<link rel="canonical" href="https://www.brvtal.com.co/en/events/genesis">',
            rendered,
        )
        self.assertIn(
            '<link rel="alternate" hreflang="es" href="https://www.brvtal.com.co/events/genesis">',
            rendered,
        )
        self.assertIn(
            '<link rel="alternate" hreflang="en" href="https://www.brvtal.com.co/en/events/genesis">',
            rendered,
        )
        self.assertIn(
            '<link rel="alternate" hreflang="x-default" href="https://www.brvtal.com.co/events/genesis">',
            rendered,
        )

        htaccess = (ROOT / ".htaccess").read_text(encoding="utf-8")
        self.assertIn("RewriteRule ^en$ index.php?locale=en", htaccess)
        self.assertIn(
            "RewriteRule ^en/(events|artists|sets|releases|blog|pages)/([a-z0-9-]+)/?$",
            htaccess,
        )
        self.assertIn("RewriteRule ^es/?$ / [R=301,L,NE]", htaccess)

    def test_unknown_locale_and_unpublished_content_fail_closed(self) -> None:
        locales = self.php_json(
            "["
            "brvtalPublicRouteLocale(null),"
            "brvtalPublicRouteLocale('es'),"
            "brvtalPublicRouteLocale('en'),"
            "brvtalPublicRouteLocale('fr'),"
            "brvtalPublicRouteLocale('../../en')"
            "]"
        )
        self.assertEqual(locales, ["es", "es", "en", None, None])
        self.assertIsNone(
            self.php_json(
                "brvtalPublicLocalizedRouteUrls("
                "'https://www.brvtal.com.co','en','events','bad slug')"
            )
        )
        self.assertIsNone(
            self.php_json(
                "brvtalPublicLocalizedRouteUrls("
                "'https://www.brvtal.com.co','fr','events','genesis')"
            )
        )

        index = (ROOT / "index.php").read_text(encoding="utf-8")
        self.assertIn("$routeLocale = brvtalPublicRouteLocale($routeLocaleInput);", index)
        self.assertIn("if ($routeLocale === null)", index)
        self.assertLess(
            index.index("if ($routeLocale === null)"),
            index.index("brvtal_public_seo_entity(db(), $type, $slug)"),
        )

        routes = (ROOT / "config" / "public_routes.php").read_text(encoding="utf-8")
        seo = (ROOT / "config" / "public_seo.php").read_text(encoding="utf-8")
        self.assertIn('string $where = "status=\'published\'"', routes)
        self.assertIn("status='published' AND locale='en'", routes)
        self.assertIn("WHERE slug=? AND {$where} LIMIT 1", seo)
        self.assertIn("brvtal_public_event_is_visible($row)", seo)

        runtime = (ROOT / "js" / "app.js").read_text(encoding="utf-8")
        self.assertIn("document.documentElement.dataset.routeLocale", runtime)
        self.assertIn("window.history.replaceState", runtime)


if __name__ == "__main__":
    unittest.main()
