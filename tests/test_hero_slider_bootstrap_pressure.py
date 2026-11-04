"""Contrato estático para la barrera de lecturas de Banners; complementa Playwright."""
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]


class HeroSliderBootstrapPressureTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.runtime = (ROOT / "discadmin/hero-slider.js").read_text(encoding="utf-8")
        cls.browser = (ROOT / "tests/e2e/hero-slider-v2.spec.mjs").read_text(encoding="utf-8")
        cls.load = cls.runtime.split("  async function load() {", 1)[1].split("\n  function slideList()", 1)[0]

    def test_sequential_settings_then_media(self):
        settings = "const settingsResponse = await readSettingsWithRetry(diagnostics);"
        picker = "mediaPromise = request(MEDIA_PICKER_PATH,{cache:'no-store'})"
        self.assertEqual(self.load.count(settings), 1)
        self.assertEqual(self.load.count(picker), 1)
        self.assertLess(self.load.index(settings), self.load.index("renderManager();"))
        self.assertLess(self.load.index("renderManager();"), self.load.index(picker))
        self.assertIn("let mediaPromise = null;", self.load)
        self.assertIn("void mediaPromise", self.load)
        self.assertIn("defers Media until Settings resolves", self.browser)
        self.assertIn("settings:resolved','media:start", self.browser)

    def test_revisions_and_errors_stay_fail_closed(self):
        picker = "mediaPromise = request(MEDIA_PICKER_PATH,{cache:'no-store'})"
        self.assertLess(self.load.index("if (revision !== loadRevision)"), self.load.index(picker))
        self.assertIn("window.state?.section !== 'hero-slider'", self.load)
        self.assertLess(self.load.index("if (revision !== loadRevision || window.state?.section"), self.load.index(picker))
        self.assertIn("if (mediaPromise) void mediaPromise.then(() => {});", self.load)
        self.assertIn("never requests Media when the Settings bootstrap fails", self.browser)
        self.assertIn("drops stale Settings completion without starting Media", self.browser)
        self.assertIn("syncMediaControls(root());", self.load)


if __name__ == "__main__":
    unittest.main()
