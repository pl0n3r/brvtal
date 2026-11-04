"""Static regressions for mobile accessibility of public home assets.

Pixel dimensions come from actual bundled image files; browser geometry is
verified separately on 390px and 1440px after deploying the claimed CSS.
"""
from html.parser import HTMLParser
from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[1]


class Images(HTMLParser):
    def __init__(self):
        super().__init__()
        self.images = []

    def handle_starttag(self, tag, attrs):
        if tag == "img":
            self.images.append(dict(attrs))


class PublicMobileAccessibilityTests(unittest.TestCase):
    def test_hero_image_has_intrinsic_dimensions_and_usable_alt(self):
        doc = Images()
        doc.feed((ROOT / "partials/home/hero.html").read_text())
        self.assertEqual(len(doc.images), 1)
        img = doc.images[0]
        self.assertEqual((img["width"], img["height"]), ("886", "886"))
        self.assertEqual(img["loading"], "eager")
        self.assertTrue(img["alt"].strip())

    def test_flyers_are_described_lazy_and_dimensioned_from_assets(self):
        doc = Images()
        doc.feed((ROOT / "partials/home/memories.html").read_text())
        expected = [(665,1182),(602,1306),(360,450),(360,450)]
        self.assertEqual(len(doc.images), len(expected))
        for image,(w,h) in zip(doc.images,expected):
            self.assertEqual((int(image["width"]),int(image["height"])),(w,h))
            self.assertTrue(image["alt"].startswith("Flyer del archivo visual de BRVTAL"))
            self.assertEqual(image["loading"],"lazy")
            self.assertEqual(image["decoding"],"async")
            self.assertTrue((ROOT / image["src"]).is_file())

    def test_cms_images_reserve_slots_and_non_decorative_cover_has_alt(self):
        js = (ROOT / "js/public-concept05-nights-artists.js").read_text()
        self.assertIn("image.alt = '';", js)
        self.assertIn("media.setAttribute('aria-hidden', 'true');", js)
        self.assertIn("image.width = 640;", js)
        self.assertIn("image.height = 640;", js)
        self.assertIn("image.alt = 'Portada de '", js)
        self.assertIn("image.width = 800;", js)
        self.assertIn("image.height = 1000;", js)
        self.assertGreaterEqual(js.count("image.loading = 'lazy';"), 2)

    def test_mobile_touch_targets_and_cursor_are_scoped(self):
        css = (ROOT / "css/public-controls.css").read_text()
        self.assertIn("#cromo-home .cr-snd{", css)
        self.assertIn('footer :is(a[href="/privacy"],button)', css)
        self.assertGreaterEqual(css.count("min-height:44px;"),2)
        self.assertIn("html.cromo-home body,html.cromo-home body.cursor-enhanced", css)
        self.assertIn("cursor:auto!important", css)
        self.assertIn("@media (pointer:coarse)",css)
        self.assertNotIn("body{cursor:none}",css)

    def test_mobile_legibility_minimum_preserves_display_artwork(self):
        css=(ROOT / "css/public-legibility.css").read_text()
        self.assertIn("--brvtal-functional-text:12px;", css)
        self.assertIn("font-size:max(12px,1em)",css)
        self.assertIn(".hero-title{font-size:clamp", css)


if __name__ == "__main__":
    unittest.main()
