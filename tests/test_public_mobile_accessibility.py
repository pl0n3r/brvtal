"""Regressions for mobile touch targets and the real PHP image-delivery pipeline.

The #984 home partials are byte-exact owner-approved templates. Dynamic image
attributes are added by the existing PHP optimizer, not duplicated in markup.
"""
from html.parser import HTMLParser
from pathlib import Path
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]


class Images(HTMLParser):
    def __init__(self):
        super().__init__()
        self.images = []

    def handle_starttag(self, tag, attrs):
        if tag == "img":
            self.images.append(dict(attrs))


def actual_optimized_home_images():
    php = (
        "require_once 'tests/home-partials-composition.php';"
        "require_once 'config/public_assets.php';"
        "echo brvtal_public_optimize_home_images(brvtal_test_composed_home());"
    )
    run = subprocess.run(
        ["php", "-r", php], cwd=ROOT, capture_output=True,
        text=True, timeout=18, check=False,
    )
    if run.returncode:
        raise AssertionError(f"PHP optimizer failed: {run.stderr[:500]}")
    parser = Images()
    parser.feed(run.stdout)
    return parser.images


class PublicMobileAccessibilityTests(unittest.TestCase):
    def test_hero_image_has_intrinsic_dimensions_and_usable_alt(self):
        original = Images()
        original.feed((ROOT / "partials/home/hero.html").read_text())
        self.assertEqual(len(original.images), 1)
        self.assertEqual(original.images[0]["alt"], "BRVTAL official logo")

        optimized = actual_optimized_home_images()
        logo = next((i for i in optimized if "hero-logo" in i.get("class", "")), None)
        self.assertIsNotNone(logo)
        self.assertEqual((logo["width"], logo["height"]), ("886", "886"))
        self.assertEqual(logo["loading"], "eager")
        self.assertEqual(logo["fetchpriority"], "high")
        self.assertEqual(logo["decoding"], "async")

    def test_flyers_are_described_lazy_and_dimensioned_from_assets(self):
        original = Images()
        original.feed((ROOT / "partials/home/memories.html").read_text())
        expected = [(665,1182),(602,1306),(360,450),(360,450)]
        self.assertEqual(len(original.images),len(expected))
        rendered = actual_optimized_home_images()
        for source,(w,h) in zip(original.images,expected):
            self.assertTrue(source.get("alt","").strip())
            image = next((i for i in rendered if i.get("src") == source["src"]), None)
            self.assertIsNotNone(image,source["src"])
            self.assertEqual((int(image["width"]),int(image["height"])),(w,h))
            self.assertEqual(image["loading"],"lazy")
            self.assertEqual(image["decoding"],"async")
            self.assertTrue((ROOT / source["src"]).is_file())

    def test_cms_images_reserve_slots_and_non_decorative_cover_has_alt(self):
        js=(ROOT / "js/public-concept05-nights-artists.js").read_text()
        self.assertIn("image.alt = '';",js)
        self.assertIn("media.setAttribute('aria-hidden', 'true');",js)
        self.assertIn("image.width = 640;",js)
        self.assertIn("image.height = 640;",js)
        self.assertIn("image.alt = 'Portada de '",js)
        self.assertIn("image.width = 800;",js)
        self.assertIn("image.height = 1000;",js)
        self.assertGreaterEqual(js.count("image.loading = 'lazy';"),2)

    def test_mobile_touch_targets_and_cursor_are_scoped(self):
        css=(ROOT / "css/public-controls.css").read_text()
        self.assertIn("#cromo-home .cr-snd{",css)
        self.assertIn('footer :is(a[href="/privacy"],button)',css)
        self.assertGreaterEqual(css.count("min-height:44px;"),2)
        self.assertIn("html.cromo-home:root body,html.cromo-home:root body.cursor-enhanced",css)
        self.assertIn("cursor:auto}",css)
        self.assertNotIn("cursor:auto!important",css)
        self.assertIn("@media (pointer:coarse)",css)
        self.assertNotIn("body{cursor:none}",css)

    def test_mobile_legibility_minimum_preserves_display_artwork(self):
        css=(ROOT / "css/public-legibility.css").read_text()
        self.assertIn("--brvtal-functional-text:12px;",css)
        self.assertIn("font-size:max(12px,1em)",css)
        self.assertIn(".hero-title{font-size:clamp",css)


if __name__ == "__main__":
    unittest.main()
