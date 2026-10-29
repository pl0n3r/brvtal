"""Contrato de «Frecuencia» en DISCADMIN (BRVTAL v2, fase 3)."""
import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CSS = (ROOT / "discadmin" / "admin-frecuencia.css").read_text(encoding="utf-8")
INDEX = (ROOT / "discadmin" / "index.php").read_text(encoding="utf-8")


class DiscadminFrecuenciaTests(unittest.TestCase):
    def test_sheet_loads_after_the_design_system_sheet(self) -> None:
        self.assertGreater(INDEX.index("admin-frecuencia.css"), INDEX.index("admin-design-system.css"))
        self.assertEqual(INDEX.count("admin-frecuencia.css"), 1)

    def test_it_only_redefines_tokens_and_the_active_states(self) -> None:
        for token in ("--admin-radius-control", "--admin-radius-card", "--admin-radius-panel", "--admin-elevation-card"):
            with self.subTest(token=token):
                self.assertIn(f"{token}:", CSS)
        self.assertIn("button.active", CSS)

    def test_signal_follows_the_administrable_accent_and_stays_readable(self) -> None:
        self.assertIn("var(--acid,#b8ff00)", CSS)
        self.assertEqual(set(re.findall(r"#[0-9a-fA-F]{3,6}\b", CSS)), {"#b8ff00", "#050505"})

    def test_settings_and_system_headers_carry_the_signal_rule(self) -> None:
        for selector in (".sv2-hero", ".ssv2-hero"):
            with self.subTest(selector=selector):
                self.assertRegex(CSS, re.escape(selector) + r"[^{]*\{[^}]*border-top:2px solid var\(--admin-signal\)")

    def test_css_is_flat_and_does_not_force_priority(self) -> None:
        self.assertNotIn("!important", CSS)
        shadows = re.findall(r"box-shadow:([^;]+);", CSS)
        self.assertGreater(len(shadows), 0)
        self.assertTrue(all(shadow.strip() == "none" for shadow in shadows))
        self.assertIn("border-left:3px solid var(--admin-signal);", CSS)
        self.assertIn("background:var(--admin-signal);", CSS)


if __name__ == "__main__":
    unittest.main()
