#!/usr/bin/env python3
"""#1364: deterministic static contract for admin data, lists, forms and states.

This test intentionally checks only the nine coordinated CSS paths; it does
not pretend to replace browser contrast testing or the owner's visual signoff.
"""
from __future__ import annotations

import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CSS_PATHS = (
    "discadmin/admin-data-grid.css",
    "discadmin/admin-record-lists.css",
    "discadmin/admin-form-dialogs.css",
    "discadmin/admin-color-field.css",
    "discadmin/system-status-v2.css",
    "discadmin/backups.css",
    "discadmin/media-library.css",
    "discadmin/settings-v2.css",
    "discadmin/security.css",
)
FONT = re.compile(r"(?<![\w-])font(?:-size)?\s*:\s*([^;{}]*)", re.I)
PIXELS = re.compile(r"(?<![\w.-])(\d+(?:\.\d+)?)px\b", re.I)
TRANSITION_ALL = re.compile(r"(?<![\w-])transition\s*:\s*all(?:\s|;|$)", re.I)
TRANSITION_UNNAMED = re.compile(r"(?<![\w-])transition\s*:\s*(?:\d|\.\d)", re.I)
OUTLINE_NONE = re.compile(r"(?<![\w-])outline\s*:\s*(?:none|0)(?=\s*[;}])", re.I)
MOTION = re.compile(r"(?<![\w-])(?:animation|transition)\s*:", re.I)
REDUCED = re.compile(r"@media\s*\(\s*prefers-reduced-motion\s*:\s*reduce\s*\)", re.I)


def validate_css(relative: str, source: str) -> None:
    """Reject explicit sub-12px text, unsafe transitions, lost focus or motion."""
    css = re.sub(r"/\*.*?\*/", "", source, flags=re.S)
    for declaration in FONT.finditer(css):
        for number in PIXELS.finditer(declaration.group(1)):
            if float(number.group(1)) < 12:
                raise ValueError(f"{relative}: font below 12px: {declaration.group(0)}")
    if TRANSITION_ALL.search(css) or TRANSITION_UNNAMED.search(css):
        raise ValueError(f"{relative}: unnamed or all-property transition")
    if OUTLINE_NONE.search(css):
        raise ValueError(f"{relative}: removed keyboard outline")
    if ":focus-visible" not in css:
        raise ValueError(f"{relative}: missing visible keyboard focus")
    if MOTION.search(css) and not REDUCED.search(css):
        raise ValueError(f"{relative}: motion without prefers-reduced-motion")
    if MOTION.search(css) and not re.search(
        r"@media\s*\(\s*prefers-reduced-motion\s*:\s*reduce\s*\)"
        r"\s*\{[^{}]*(?:\{[^{}]*\}[^{}]*)*\}",
        css, flags=re.I | re.S
    ):
        raise ValueError(f"{relative}: missing reduced-motion rules")


class RedisenoAdminDataTests(unittest.TestCase):
    def test_criterio_principal(self) -> None:
        self.assertEqual(len(CSS_PATHS), 9)
        for relative in CSS_PATHS:
            path = ROOT / relative
            with self.subTest(css=relative):
                self.assertTrue(path.is_file(), f"CSS faltante: {relative}")
                validate_css(relative, path.read_text(encoding="utf-8"))

    def test_regresiones_fail_closed(self) -> None:
        samples = (
            "button:focus-visible{outline:2px solid white}p{font-size:11px}",
            "button:focus-visible{outline:2px solid white}p{font:800 9px/1.4 monospace}",
            "button:focus-visible{outline:2px solid white}a{transition:all .2s}",
            "button:focus-visible{outline:2px solid white}a{transition:.15s}",
            "button:focus-visible{outline:2px solid white}a{outline:none}",
            "button{font-size:12px}",
            "button:focus-visible{outline:2px solid white}a{animation:pulse 1s infinite}",
        )
        for source in samples:
            with self.subTest(source=source):
                with self.assertRaises(ValueError):
                    validate_css("fixture.css", source)

    def test_accessibility_tokens(self) -> None:
        content = "\n".join((ROOT / path).read_text(encoding="utf-8") for path in CSS_PATHS)
        self.assertIn("font-variant-numeric:tabular-nums", content)
        self.assertIn("text-wrap:balance", content)
        self.assertIn("min-height:44px", content)
        self.assertIn("transform:scale(.96)", content)


if __name__ == "__main__":
    unittest.main()
