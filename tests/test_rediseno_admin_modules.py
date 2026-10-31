#!/usr/bin/env python3
"""BRVTAL #1365: accessible admin theme and content modules (static contract)."""
from __future__ import annotations

import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CSS_PATHS = (
    "discadmin/theme-studio-v2.css",
    "discadmin/theme-studio-configuration.css",
    "discadmin/theme-studio-reliability.css",
    "discadmin/seo-workspace.css",
    "discadmin/releases.css",
    "discadmin/blog.css",
    "discadmin/memories.css",
    "discadmin/content-core.css",
    "discadmin/content-ordering.css",
    "discadmin/legacy-editor-drafts.css",
    "discadmin/admin-appearance.css",
    "discadmin/admin-frecuencia.css",
    "discadmin/password-recovery.css",
)
FONT = re.compile(r"(?<![\w-])font(?:-size)?\s*:\s*([^;{}]*)", re.I)
PIXELS = re.compile(r"(\d+(?:\.\d+)?)px\b", re.I)
TRANSITION_ALL = re.compile(r"(?<![\w-])transition\s*:\s*all(?=\s|[,;}]|$)", re.I)
TRANSITION_UNNAMED = re.compile(r"(?<![\w-])transition\s*:\s*(?:\d|\.\d)", re.I)
OUTLINE_NONE = re.compile(r"(?<![\w-])outline\s*:\s*(?:none|0)(?=\s*[;}])", re.I)
MOTION = re.compile(r"(?<![\w-])(?:animation|transition)\s*:", re.I)
REDUCED = re.compile(r"@media\s*\(\s*prefers-reduced-motion\s*:\s*reduce\s*\)", re.I)


def validate_css(relative: str, source: str) -> None:
    css = re.sub(r"/\*.*?\*/", "", source, flags=re.S)
    for match in FONT.finditer(css):
        for value in PIXELS.finditer(match.group(1)):
            if float(value.group(1)) < 12:
                raise ValueError(f"{relative}: small typography: {match.group(0)}")
    if TRANSITION_ALL.search(css) or TRANSITION_UNNAMED.search(css):
        raise ValueError(f"{relative}: unnamed or all-property transition")
    if OUTLINE_NONE.search(css):
        raise ValueError(f"{relative}: outline suppressed")
    if ":focus-visible" not in css:
        raise ValueError(f"{relative}: missing keyboard focus")
    if MOTION.search(css) and not REDUCED.search(css):
        raise ValueError(f"{relative}: motion must honor reduced-motion")


class RedisenoAdminModulesTests(unittest.TestCase):
    def test_criterio_principal(self) -> None:
        self.assertEqual(len(CSS_PATHS), 13)
        for relative in CSS_PATHS:
            with self.subTest(path=relative):
                path = ROOT / relative
                self.assertTrue(path.is_file(), f"Missing scoped file: {relative}")
                validate_css(relative, path.read_text(encoding="utf-8"))

    def test_regresiones_fail_closed(self) -> None:
        valid = ".widget:focus-visible{outline:2px solid #fff}.widget{font-size:12px}"
        validate_css("positive.css", valid)
        invalid = (
            ".widget{font-size:11px}",
            ".widget{font:700 9px/1.3 monospace}",
            ".widget{transition:all .2s}",
            ".widget{transition:.2s}",
            ".widget{outline:none}",
            ".widget{animation:blink 1s infinite}",
            ".widget{transition:opacity .2s}",
        )
        for example in invalid:
            with self.subTest(css=example), self.assertRaises(ValueError):
                validate_css("regression.css", valid + example)

    def test_touch_focus_and_typography_signals(self) -> None:
        content = "\n".join(
            (ROOT / path).read_text(encoding="utf-8") for path in CSS_PATHS
        )
        self.assertIn("min-height:44px", content)
        self.assertIn("transform:scale(.96)", content)
        self.assertIn("font-variant-numeric:tabular-nums", content)
        self.assertIn("text-wrap:balance", content)
        self.assertIn("text-wrap:pretty", content)


if __name__ == "__main__":
    unittest.main()
