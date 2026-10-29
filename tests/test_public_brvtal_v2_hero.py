"""Contrato de «Frecuencia» (BRVTAL v2, fase 1): hero de la home sobre los tokens administrables de Concept 05."""
import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CSS = (ROOT / "css" / "public-brvtal-v2.css").read_text(encoding="utf-8")
HOME = (ROOT / "config" / "public_home.php").read_text(encoding="utf-8")


def luminance(hex_color: str) -> float:
    value = hex_color.lstrip("#")
    channels = [int(value[i:i + 2], 16) / 255 for i in (0, 2, 4)]
    linear = [c / 12.92 if c <= 0.03928 else ((c + 0.055) / 1.055) ** 2.4 for c in channels]
    return 0.2126 * linear[0] + 0.7152 * linear[1] + 0.0722 * linear[2]


def contrast(a: str, b: str) -> float:
    la, lb = luminance(a), luminance(b)
    return (max(la, lb) + 0.05) / (min(la, lb) + 0.05)


class BrvtalV2HeroTests(unittest.TestCase):
    def test_stylesheet_is_injected_after_the_home_phase_a_sheet(self) -> None:
        phase_a = HOME.index("css/public-home-phase-a.css")
        v2 = HOME.index("css/public-brvtal-v2.css")
        self.assertGreater(v2, phase_a)
        self.assertEqual(HOME.count("href=\\\"css/public-brvtal-v2.css\\\""), 1)

    def test_decorative_noise_is_removed_and_the_wordmark_has_one_line(self) -> None:
        for noise in (".hero-grid", ".hero-scan", ".hero-glitch-lines"):
            with self.subTest(noise=noise):
                self.assertIn(noise, CSS.split("{display:none}")[0])
        self.assertIn("white-space:nowrap", CSS)
        self.assertIn("display:none", CSS)

    def test_colors_come_from_administrable_tokens_not_fixed_values(self) -> None:
        fixed = set(re.findall(r"#[0-9a-fA-F]{3,6}\b", CSS))
        self.assertEqual(fixed, {"#050505"}, "Solo el texto del botón sobre el acento puede ser fijo")
        for token in ("--c5-signal-green", "--c5-deep-black", "--c5-paper-dirty", "--c5-rule-color"):
            with self.subTest(token=token):
                self.assertIn(f"var({token})", CSS)

    def test_button_text_meets_wcag_aa_on_the_default_accent(self) -> None:
        self.assertGreaterEqual(contrast("#050505", "#b8ff00"), 4.5)

    def test_css_is_flat_and_respects_reduced_motion(self) -> None:
        self.assertNotIn("!important", CSS)
        self.assertNotRegex(CSS, r"box-shadow:\s*\d")
        self.assertNotRegex(CSS, r"transition:\s*all\b")
        self.assertIn("@media (prefers-reduced-motion:reduce)", CSS)
        self.assertIn("@media (max-width:900px)", CSS)


if __name__ == "__main__":
    unittest.main()
