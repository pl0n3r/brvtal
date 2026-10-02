from __future__ import annotations

import json
import subprocess
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]


class PublicI18nIndexingTests(unittest.TestCase):
    def php_json(self, expression: str) -> list | dict | str | None:
        script = (
            "chdir($argv[1]); "
            "require 'config/public_sitemap.php'; "
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

    def test_localized_sitemap_contains_only_published_canonical_variants(self) -> None:
        source = (
            "['id'=>41,'slug'=>'ritual-industrial','route_type'=>'blog',"
            "'status'=>'published','title'=>'Ritual industrial']"
        )
        rows = self.php_json(
            "brvtalPublicSitemapLocalizedEntityUrls("
            "'https://www.brvtal.com.co',"
            f"{source},'2026-10-01 12:30:00',['es','en'])"
        )
        self.assertEqual(
            [row[0] for row in rows],
            [
                "https://www.brvtal.com.co/blog/ritual-industrial",
                "https://www.brvtal.com.co/en/blog/ritual-industrial",
            ],
        )
        for row in rows:
            self.assertEqual(row[1], "2026-10-01 12:30:00")
            self.assertEqual(
                row[2],
                {
                    "es": "https://www.brvtal.com.co/blog/ritual-industrial",
                    "en": "https://www.brvtal.com.co/en/blog/ritual-industrial",
                    "x-default": "https://www.brvtal.com.co/blog/ritual-industrial",
                },
            )

        endpoint_rows = self.php_json(
            "brvtalPublicSitemapLocalizedContentUrls("
            "'https://www.brvtal.com.co','blog',["
            "['id'=>41,'slug'=>'ritual-industrial','status'=>'published',"
            "'updated_at'=>'2026-10-01 12:30:00']"
            "],false)"
        )
        self.assertEqual(
            [row[0] for row in endpoint_rows],
            [
                "https://www.brvtal.com.co/blog/ritual-industrial",
                "https://www.brvtal.com.co/en/blog/ritual-industrial",
            ],
        )
        self.assertEqual(
            [row[1] for row in endpoint_rows],
            ["2026-10-01 12:30:00", "2026-10-01 12:30:00"],
        )

    def test_drafts_private_content_and_invalid_locales_are_never_indexed(self) -> None:
        draft = self.php_json(
            "brvtalPublicSitemapLocalizedEntityUrls("
            "'https://www.brvtal.com.co',"
            "['id'=>42,'slug'=>'draft-note','route_type'=>'blog','status'=>'draft'],"
            "null,['es','en'])"
        )
        private = self.php_json(
            "brvtalPublicSitemapLocalizedEntityUrls("
            "'https://www.brvtal.com.co',"
            "['id'=>43,'slug'=>'private-note','route_type'=>'blog',"
            "'status'=>'published','visibility'=>'private'],"
            "null,['es','en'])"
        )
        untrusted = self.php_json(
            "brvtalPublicSitemapLocalizedEntityUrls("
            "'https://www.brvtal.com.co',"
            "['id'=>44,'slug'=>'untrusted-note','route_type'=>'blog',"
            "'status'=>'published','trusted'=>false],"
            "null,['es','en'])"
        )
        invalid_locales = self.php_json(
            "brvtalPublicSitemapLocalizedEntityUrls("
            "'https://www.brvtal.com.co',"
            "['id'=>45,'slug'=>'public-note','route_type'=>'blog','status'=>'published'],"
            "null,['fr','../../en'])"
        )
        self.assertEqual(draft, [])
        self.assertEqual(private, [])
        self.assertEqual(untrusted, [])
        self.assertEqual(invalid_locales, [])

        xml = self.php_json(
            "brvtal_public_sitemap_xml("
            "brvtalPublicSitemapLocalizedEntityUrls("
            "'https://www.brvtal.com.co',"
            "['id'=>46,'slug'=>'public-note','route_type'=>'blog','status'=>'published'],"
            "null,['es','en']),"
            "'https://www.brvtal.com.co')"
        )
        self.assertIn('hreflang="es"', xml)
        self.assertIn('hreflang="en"', xml)
        self.assertIn('hreflang="x-default"', xml)
        self.assertNotIn("/fr/", xml)


if __name__ == "__main__":
    unittest.main()
