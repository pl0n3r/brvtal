"""Contract for the DISCADMIN console layer (visual hierarchy only).

The layer re-skins the shared shell through the existing --admin-* appearance
tokens. It must load after the design-system and Frecuencia sheets, must not
create a second shell or palette, and must keep the Frecuencia signal contract.
"""
import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CSS = (ROOT / "discadmin/admin-console.css").read_text(encoding="utf-8")
INDEX = (ROOT / "discadmin/index.php").read_text(encoding="utf-8")


class DiscadminConsoleTests(unittest.TestCase):
    def test_sheet_loads_once_after_design_system_and_frecuencia(self):
        self.assertEqual(INDEX.count("admin-console.css"), 1)
        self.assertGreater(INDEX.index("admin-console.css"), INDEX.index("admin-design-system.css"))
        self.assertGreater(INDEX.index("admin-console.css"), INDEX.index("admin-frecuencia.css"))

    def test_every_rule_is_scoped_to_the_appearance_root(self):
        stripped = re.sub(r"/\*.*?\*/", "", CSS, flags=re.S)
        for match in re.finditer(r"([^{}@]+)\{", stripped):
            prelude = match.group(1).strip()
            if not prelude or re.match(r"(media|keyframes)\b", prelude):
                continue
            for selector in (part.strip() for part in re.split(r",(?![^(]*\))", prelude)):
                self.assertTrue(
                    selector.startswith("html[data-discadmin-appearance]"),
                    f"unscoped selector: {selector}",
                )

    def test_uses_tokens_not_a_parallel_palette(self):
        self.assertNotRegex(CSS, r":root\s*\{")
        self.assertIn("var(--admin-signal", CSS)
        self.assertIn("var(--admin-surface", CSS)
        self.assertIn("var(--admin-border-subtle", CSS)
        self.assertNotRegex(CSS, r"background:#[0-9a-fA-F]{3,6}\b")

    def test_does_not_force_priority_or_redefine_frecuencia(self):
        code = re.sub(r"/\*.*?\*/", "", CSS, flags=re.S)
        for line in code.splitlines():
            if "!important" in line:
                self.assertIn("var(--admin-", line)
        # Only the backups override, which fights backups.css's own !important.
        self.assertLessEqual(code.count("!important"), 3)
        self.assertNotIn("border-top:2px", CSS)
        self.assertIn(':not([data-admin-nav="dashboard"])', CSS)

    def test_module_tools_stay_keyboard_reachable(self):
        self.assertNotIn("display:none", CSS.split(".dashboard-v2-module-controls", 1)[1].split("}", 1)[0])
        self.assertIn(":focus-within", CSS)
        self.assertIn("@media(hover:none)", CSS)

    def test_reduced_motion_is_respected(self):
        self.assertIn("prefers-reduced-motion:reduce", CSS)


if __name__ == "__main__":
    unittest.main()
