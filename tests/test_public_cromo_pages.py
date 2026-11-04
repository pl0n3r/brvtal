#!/usr/bin/env python3
"""Cromo theme for the interior public pages (entity, privacy, 404, unavailable)."""
from __future__ import annotations

import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CSS = (ROOT / "css/public-cromo-pages.css").read_text(encoding="utf-8")
TEMPLATES = ("config/public_page.php", "config/public_not_found.php",
             "config/public_unavailable.php", "config/public_privacy_rights.php")


def selectors(css: str) -> list[str]:
    css = re.sub(r"/\*.*?\*/", "", css, flags=re.S)
    out = []
    for block in re.finditer(r"([^{}]+)\{[^{}]*\}", css):
        head = block.group(1).strip()
        if head.startswith("@"):
            continue
        out.extend(s.strip() for s in head.split(",")
                   if s.strip() and not re.fullmatch(r"from|to|\d+%", s.strip()))  # keyframe steps
    return out


class CromoPagesTests(unittest.TestCase):
    def test_every_rule_is_scoped_to_the_cromo_page_body(self) -> None:
        for sel in selectors(CSS):
            self.assertRegex(sel, r"^(html\.cromo )?body\.cromo-page", sel)

    def test_templates_load_theme_script_and_mark_the_body(self) -> None:
        for path in TEMPLATES:
            source = (ROOT / path).read_text(encoding="utf-8")
            self.assertIn("/css/public-cromo.css", source, path)
            self.assertIn("/css/public-cromo-pages.css", source, path)
            self.assertIn("/js/public-cromo.js", source, path)
            self.assertIn("cromo-page", source, path)

    def test_palette_is_red_bone_black_with_installed_fonts_only(self) -> None:
        self.assertIn("#e12e34", CSS)
        # No green anywhere: the title glitch is the site's red (same idea as the home titles).
        self.assertNotRegex(CSS, r"(?i)#b6ff00|#c8ff|lime")
        self.assertRegex(CSS, r"h1\[data-text\]::before\{color:var\(--cromo-red\)")
        for family in re.findall(r'font-family:([^;}]+)', CSS):
            for name in re.findall(r'"([^"]+)"', family):
                self.assertIn(name, {"Broken Glass", "Barlow Condensed", "Space Mono"}, family)
        self.assertNotRegex(CSS, r"Rubik Mono One|Archivo")

    def test_content_stays_above_the_canvas_and_text_is_never_hidden(self) -> None:
        self.assertRegex(CSS, r"body\.cromo-page > main[^{]*\{[^}]*z-index:2")
        self.assertNotRegex(CSS, r"display:\s*none\s*[;}]\s*(?!\s*/\*)(?=[^{]*h1)")
        self.assertIn("prefers-reduced-motion", CSS)

    def test_interactive_targets_keep_a_44px_hit_area_and_focus_ring(self) -> None:
        self.assertIn("min-height:44px", CSS)
        self.assertIn(":focus-visible", CSS)


if __name__ == "__main__":
    unittest.main()
