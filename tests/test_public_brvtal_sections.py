"""Contrato de «Frecuencia» (BRVTAL v2, fase 2): secciones numeradas, próxima experiencia y pie."""
import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CSS = (ROOT / "css" / "public-brvtal-sections.css").read_text(encoding="utf-8")
HOME = (ROOT / "config" / "public_home.php").read_text(encoding="utf-8")


class BrvtalSectionsTests(unittest.TestCase):
    def test_sheet_is_injected_after_the_hero_sheet(self) -> None:
        self.assertGreater(HOME.index("css/public-brvtal-sections.css"), HOME.index("css/public-brvtal-impact.css"))
        self.assertEqual(HOME.count("'css/public-brvtal-sections.css'"), 1)

    def test_glitch_layers_are_removed_from_the_next_experience_and_the_footer(self) -> None:
        self.assertIn(".genesis-copy h2::after{content:none}", CSS)
        self.assertIn("&::before{display:none}", CSS)

    def test_colors_come_from_administrable_tokens(self) -> None:
        self.assertEqual(set(re.findall(r"#[0-9a-fA-F]{3,6}\b", CSS)), {"#050505"})
        self.assertIn("var(--c5-signal-green)", CSS)

    def test_css_is_flat_and_does_not_force_priority(self) -> None:
        self.assertNotIn("!important", CSS)
        self.assertNotRegex(CSS, r"box-shadow:\s*\d")

    def test_nights_and_artists_stack_full_width_with_responsive_grids_and_keep_the_transmissions_pair(self) -> None:
        block = CSS[CSS.index("/* Noches y Artistas dejan de compartir"):]
        self.assertIn("main#top>.c5-board-region--pair:has(> #events)", block)
        self.assertNotIn("#transmissions", block, "El par Journal/Conectado conserva su composición")
        self.assertIn("grid-template-columns: minmax(0, 1fr);", block)
        self.assertEqual(block.count("repeat(auto-fill,minmax("), 2)


if __name__ == "__main__":
    unittest.main()
