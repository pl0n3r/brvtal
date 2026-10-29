"""Contratos Frecuencia del tema activo y sus límites de CSS."""
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CSS = (ROOT / "discadmin/admin-frecuencia.css").read_text(encoding="utf-8")
INDEX = (ROOT / "discadmin/index.php").read_text(encoding="utf-8")

class DiscadminFrecuenciaTests(unittest.TestCase):
    def test_sheet_loads_after_the_design_system_sheet(self):
        self.assertGreater(INDEX.index("admin-frecuencia.css"), INDEX.index("admin-design-system.css"))
        self.assertEqual(INDEX.count("admin-frecuencia.css"), 1)

    def test_it_only_redefines_tokens_and_the_active_states(self):
        self.assertNotIn(":root{", CSS)
        self.assertNotIn(".sv2-hero", CSS)
        self.assertNotIn(".ssv2-hero", CSS)
        for value in ("#brvtal-dashboard-v2", '[data-admin-nav="dashboard"].active',
                      "--admin-radius-control:", "--admin-radius-card:",
                      "--admin-radius-panel:", "--admin-elevation-card:"):
            with self.subTest(value=value): self.assertIn(value, CSS)

    def test_signal_follows_the_administrable_accent_and_stays_readable(self):
        for value in ("theme.active", "theme?.colors?.accent", '"#000000"',
                      '"#ffffff"', "BRVTALFrecuenciaAccent.sync()"):
            self.assertIn(value, INDEX)
        self.assertIn("var(--admin-signal-ink", CSS)
        self.assertIn("var(--admin-signal", CSS)
        self.assertNotIn("var(--acid", CSS)

    def test_settings_and_system_headers_carry_the_signal_rule(self):
        self.assertNotIn(".sv2-hero", CSS)
        self.assertNotIn(".ssv2-hero", CSS)
        self.assertIn("border-top:2px solid var(--admin-signal", CSS)

    def test_css_is_flat_and_does_not_force_priority(self):
        self.assertNotIn("!important", CSS)
        self.assertIn("box-shadow:none;", CSS)
        self.assertIn("background:var(--admin-signal", CSS)

if __name__ == "__main__":
    unittest.main()
