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

class PublicI18nIndexingTests(unittest.TestCase):
    def test_only_spanish_entity_url_even_if_legacy_locales_requested(self):
        expr = """brvtalPublicSitemapLocalizedEntityUrls('https://www.brvtal.com.co',['id'=>4,'route_type'=>'blog','slug'=>'ritual-industrial','status'=>'published'],null,['es','en'])"""
        rows=php(expr,"config/public_sitemap.php")
        self.assertEqual([row[0] for row in rows], ["https://www.brvtal.com.co/blog/ritual-industrial"])
        self.assertEqual(rows[0][2], [])

    def test_private_draft_untrusted_not_indexed(self):
        expr = """[brvtalPublicSitemapLocalizedEntityUrls('https://www.brvtal.com.co',['route_type'=>'blog','slug'=>'draft','status'=>'draft']),brvtalPublicSitemapLocalizedEntityUrls('https://www.brvtal.com.co',['route_type'=>'blog','slug'=>'private','status'=>'published','visibility'=>'private']),brvtalPublicSitemapLocalizedEntityUrls('https://www.brvtal.com.co',['route_type'=>'blog','slug'=>'untrusted','status'=>'published','trusted'=>false])]"""
        self.assertEqual(php(expr,"config/public_sitemap.php"), [[],[],[]])

    def test_sitemap_xml_contains_no_alternate_urls_or_hreflang(self):
        expr = """brvtal_public_sitemap_xml(brvtalPublicSitemapLocalizedStaticUrls('https://www.brvtal.com.co',['/','/contact'],['es','en']),'https://www.brvtal.com.co')"""
        xml=php(expr,"config/public_sitemap.php")
        self.assertIn("<loc>https://www.brvtal.com.co/contact</loc>",xml)
        self.assertNotIn("/en",xml)
        self.assertNotIn("hreflang",xml)
        self.assertNotIn("xmlns:xhtml",xml)

if __name__ == "__main__":
    unittest.main()
