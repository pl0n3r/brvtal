#!/usr/bin/env python3
"""Regression for BRVTAL #1349: explicit CSS font sizes and shorthand >= 11px.

Only the 43 coordinated CSS surfaces in the Issue contract are checked.
This static test is not a substitute for browser accessibility or production QA.
"""
from __future__ import annotations

import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CSS_PATHS = [
    "css/archive.css",
    "css/contact-social.css",
    "css/hero-slider.css",
    "css/input-accessibility.css",
    "css/public-brvtal-impact.css",
    "css/public-concept05-experience.css",
    "css/public-concept05-hero.css",
    "css/public-concept05-journal-connected.css",
    "css/public-concept05-nights-artists.css",
    "css/public-concept05-shell.css",
    "css/public-concept05-sound-memories.css",
    "css/public-entity.css",
    "css/public-event-record.css",
    "css/public-home-phase-a.css",
    "css/public-legibility.css",
    "css/public-media.css",
    "css/public-memories.css",
    "css/public-roster.css",
    "css/public-sets-library.css",
    "css/public-transmissions.css",
    "css/related-content.css",
    "css/style.css",
    "discadmin/admin-appearance.css",
    "discadmin/admin-color-field.css",
    "discadmin/admin-data-grid.css",
    "discadmin/admin-information-architecture.css",
    "discadmin/admin-modules.css",
    "discadmin/admin-record-lists.css",
    "discadmin/admin-shell.css",
    "discadmin/backups.css",
    "discadmin/blog.css",
    "discadmin/content-core.css",
    "discadmin/content-ordering.css",
    "discadmin/dashboard-v2.css",
    "discadmin/hero-slider.css",
    "discadmin/media-library.css",
    "discadmin/memories.css",
    "discadmin/releases.css",
    "discadmin/seo-workspace.css",
    "discadmin/settings-v2.css",
    "discadmin/system-status-v2.css",
    "discadmin/theme-studio-configuration.css",
    "discadmin/theme-studio-v2.css"
]
FONT_DECLARATION = re.compile(
    r"(?:^|[;{])\s*(font(?:-size)?)\s*:\s*([^;{}]*)",
    re.IGNORECASE | re.MULTILINE,
)
PX_VALUE = re.compile(r"(\d+(?:\.\d+)?)px\b", re.IGNORECASE)


class CssLegibilityTests(unittest.TestCase):
    def test_minimum_declared_font_size(self):
        failures = []
        for relative in CSS_PATHS:
            path = ROOT / relative
            self.assertTrue(path.is_file(), f"CSS reclamado ausente: {relative}")
            data = path.read_text(encoding="utf-8")
            for declaration in FONT_DECLARATION.finditer(data):
                for number in PX_VALUE.finditer(declaration.group(2)):
                    if float(number.group(1)) < 11:
                        failures.append(
                            f"{relative}: {declaration.group(1)}={number.group(1)}px"
                        )
        self.assertFalse(failures, "Tamaños explícitos menores de 11px: " + "; ".join(failures))


if __name__ == "__main__":
    unittest.main()
