"""Executable source-audit index for the four-skills DISCADMIN initiative (#1652).

This validates reproducibility of the matrix, not production screenshots or
owner visual approval. A separate authenticated screenshot sign-off is required.
"""
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
AUDIT = (ROOT / "docs/DISCADMIN-FOUR-SKILLS-AUDIT.md").read_text(encoding="utf-8")

class DiscadminFourSkillsAuditTests(unittest.TestCase):
    def test_module_inventory(self):
        modules = ("Shell y acceso", "Dashboard", "Eventos", "Artistas", "Sets",
                   "Releases", "Media", "Pages", "Blog", "Memorias", "Hero / Banners",
                   "Theme Studio", "SEO", "Settings / Security", "System Status / Logs",
                   "Backups", "Búsqueda global")
        for name in modules:
            self.subTest(name=name)
            self.assertIn("| " + name + " |", AUDIT)
        for path in ("discadmin/index-core.php", "discadmin/admin-data-grid.js",
                     "discadmin/admin-shell.css", "discadmin/hero-slider-v2.css"):
            self.assertTrue((ROOT / path).is_file(), path)

    def test_impeccable_matrix(self):
        for phrase in ("Impeccable", "reference/adapt.md", "reference/craft-floor.md",
                       "Jerarquía", "contrast", "360 px", "1440 px"):
            self.assertIn(phrase.lower(), AUDIT.lower())
        self.assertIn("lanzador", AUDIT)

    def test_interface_feel_matrix(self):
        for fragment in ("Make Interfaces Feel Better", "44", "Tab", "Escape",
                         "foco", "guardar", "error"):
            self.assertIn(fragment.lower(), AUDIT.lower())

    def test_transitions_provenance(self):
        for fragment in ("transitions.dev", "instalación local autorizada",
                         "05-menu-dropdown.md", "07-panel-reveal.md",
                         "No se vendorizó", "reversibilidad"):
            self.assertIn(fragment, AUDIT)

    def test_gsap_matrix(self):
        for fragment in ("GSAP", "gsap-core/SKILL.md", "gsap-performance/SKILL.md",
                         "cleanup", "prefers-reduced-motion"):
            self.assertIn(fragment, AUDIT)

    def test_viewport_evidence(self):
        # Explicitly mark unverified screenshot evidence; never certify it.
        for size in (360, 390, 430, 768, 1024, 1440):
            self.assertIn(f"| {size} px |", AUDIT)
        self.assertIn("PENDIENTE", AUDIT)
        self.assertIn("no se ha ejecutado una prueba visual autenticada", AUDIT)

    def test_issue_backlog(self):
        for issue in ("#1638", "#1651", "#1655", "#1656", "#1657"):
            self.assertIn(issue, AUDIT)
        for priority in ("P0", "P1", "P2", "P3"):
            self.assertIn(priority, AUDIT)

if __name__ == "__main__":
    unittest.main()
