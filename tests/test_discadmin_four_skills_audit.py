"""Executable source-audit index for the four-skills DISCADMIN initiative (#1652).

This validates reproducibility of the matrix, not production screenshots or
owner visual approval. A separate authenticated screenshot sign-off is required.
"""
from pathlib import Path
import re
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
            with self.subTest(name=name):
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
            self.assertIn(fragment.lower(), AUDIT.lower())

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

    def test_public_login_evidence_remains_unauthenticated(self):
        # This metadata is a real browser observation, not an admin sign-off.
        start = AUDIT.index("## Observación de navegador real")
        end = AUDIT.index("## Protocolo de evaluación visual pendiente", start)
        section = AUDIT[start:end]
        for size in ("360×844", "390×844", "430×844", "768×1024",
                     "1024×900", "1440×900"):
            with self.subTest(size=size):
                self.assertIn("| " + size + " |", section)
        screenshot_rows = [line for line in section.splitlines()
                           if re.match(r"^\\| (?:360×844|390×844|430×844|768×1024|1024×900|1440×900) \\|", line)]
        self.assertEqual(len(screenshot_rows), 6)
        for row in screenshot_rows:
            self.assertEqual(len(re.findall(r"`[0-9a-f]{64}`", row)), 1)
        self.assertIn("887188d7ae0f0549cc6fdc6d246e09324755be32c8ca73ea035089304e75c1b5", section)
        self.assertIn("**no son autenticadas**", section)
        self.assertIn("14 px", section)
        self.assertIn("PENDIENTES", section)
        self.assertIn("sin credenciales", section.lower())

    def test_matrix_section_has_scoped_results_per_skill(self):
        start = "## Matriz de evaluación de las cuatro skills"
        end = "## Hallazgos priorizados y no duplicados"
        self.assertEqual(AUDIT.count(start), 1)
        self.assertEqual(AUDIT.count(end), 1)
        section = AUDIT.split(start, 1)[1].split(end, 1)[0]
        self.assertIn("| Resultado y prueba de fuente |", section)
        for name in ("Shell/navegación", "Dashboard", "Eventos, Blog, Pages",
                     "Artistas", "Sets", "Releases / Media", "Memorias / Hero",
                     "Theme / SEO / Settings", "Seguridad / Backups / Logs"):
            with self.subTest(module=name):
                matching = [line for line in section.splitlines()
                            if line.startswith("| " + name + " |")]
                self.assertEqual(len(matching), 1)
                self.assertIn("**I:F · M:F · T:C · G:C · UI:P**", matching[0])
                self.assertIn("Código: `discadmin/", matching[0])
                self.assertIn("tests/test_discadmin_four_skills_audit.py", matching[0])
        self.assertIn("inspección estática documentada", section)
        self.assertIn("pendiente", section.lower())

    def test_source_metrics_have_reproducible_method(self):
        start = "## Medición reproducible de fuente"
        end = "## Matriz de evaluación de las cuatro skills"
        section = AUDIT.split(start, 1)[1].split(end, 1)[0]
        self.assertIn("4da5846ac7d2a81b3ceea35216109dfaa2886b6a", section)
        self.assertIn("len(source.splitlines())", section)
        self.assertIn("re.findall", section)
        self.assertIn("cualquier propiedad", section)
        self.assertIn("| `discadmin/index-core.php` | 1247 | 7 | 1 | 0 | 11 | 100 |", section)

    def test_issue_backlog(self):
        for issue in ("#1638", "#1651", "#1655", "#1656", "#1657", "#1660"):
            self.assertIn(issue, AUDIT)
        for priority in ("P0", "P1", "P2", "P3"):
            self.assertIn(priority, AUDIT)

if __name__ == "__main__":
    unittest.main()
