"""Contrato de la capa de impacto sobre Concept 05: aditiva, accesible y con el movimiento apagable."""
import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CSS = (ROOT / "css" / "public-brvtal-impact.css").read_text(encoding="utf-8")
JS = (ROOT / "js" / "public-brvtal-impact.js").read_text(encoding="utf-8")
HOME = (ROOT / "config" / "public_home.php").read_text(encoding="utf-8")


class BrvtalImpactLayerTests(unittest.TestCase):
    def test_layer_is_injected_without_touching_the_concept05_sheets(self) -> None:
        self.assertEqual(HOME.count("'css/public-brvtal-impact.css'"), 1)
        self.assertEqual(HOME.count("js/public-brvtal-impact.js"), 2)
        self.assertGreater(HOME.index("css/public-brvtal-impact.css"), HOME.index("css/public-home-phase-a.css"))

    def test_hero_keeps_its_glitch_layer_and_the_documentary_photo_stays_visible(self) -> None:
        self.assertNotIn("hero-title::after{display:none", CSS)
        self.assertNotIn("hero-title::before{display:none", CSS)
        figure = CSS.split(".c5-hero-documentary{", 1)[1].split("img{", 1)[0]
        self.assertNotIn("display:", figure, "No debe anular el atributo hidden cuando no hay medio")
        self.assertIn("inset:0;", figure)
        self.assertNotIn("visibility:hidden", CSS)
        self.assertNotIn("!important", CSS)

    def test_colors_come_from_administrable_tokens(self) -> None:
        self.assertEqual(set(re.findall(r"#[0-9a-fA-F]{3,6}\b", CSS)), set())
        for token in ("--c5-signal-green", "--c5-deep-black"):
            with self.subTest(token=token):
                self.assertIn(f"var({token})", CSS)

    def test_ticker_is_decorative_and_its_loop_only_runs_without_reduced_motion(self) -> None:
        self.assertIn("setAttribute('aria-hidden', 'true')", JS)
        block = CSS.split("@media (prefers-reduced-motion:no-preference){", 1)[1]
        self.assertIn("animation:c5-ticker", block)
        self.assertNotIn("animation:c5-ticker", CSS.split("@media (prefers-reduced-motion:no-preference){", 1)[0])

    def test_motion_respects_reduced_motion_theme_studio_and_the_visual_test_hook(self) -> None:
        for needle in ("prefers-reduced-motion: reduce", "c5-visual-test", "data-theme-motion", "data-theme-glitch"):
            with self.subTest(needle=needle):
                self.assertIn(needle, JS)

    def test_title_animation_clears_its_inline_styles_so_it_never_stays_hidden(self) -> None:
        self.assertIn("clearProps: 'clipPath'", JS)
        self.assertNotIn("innerHTML", JS)


if __name__ == "__main__":
    unittest.main()
