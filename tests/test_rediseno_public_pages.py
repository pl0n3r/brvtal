#!/usr/bin/env python3
"""#1362: deterministic public CSS accessibility contract for Concept 05."""
from __future__ import annotations

import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CSS_PATHS = (
    "css/public-concept05-nights-artists.css",
    "css/public-concept05-journal-connected.css",
    "css/public-concept05-sound-memories.css",
    "css/public-concept05-experience.css",
    "css/public-entity.css",
    "css/public-event-record.css",
    "css/public-roster.css",
    "css/public-sets-library.css",
    "css/public-transmissions.css",
    "css/public-memories.css",
    "css/public-media.css",
    "css/contact-social.css",
    "css/archive.css",
    "css/related-content.css",
)
FONT = re.compile(r"(?<![\w-])font(?:-size)?\s*:\s*([^;{}]*)", re.I)
PX = re.compile(r"(?<![\w.-])(\d+(?:\.\d+)?)px\b", re.I)
TRANSITION_ALL = re.compile(r"(?<![\w-])transition(?:-property)?\s*:\s*all\b", re.I)
TRANSITION_UNNAMED = re.compile(r"(?<![\w-])transition\s*:\s*(?:\d|\.)", re.I)
OUTLINE_NONE = re.compile(r"(?<![\w-])outline\s*:\s*(?:none|0)(?=\s*[;}])", re.I)
MOTION = re.compile(r"(?<![\w-])(?:animation|transition)\s*:", re.I)
REDUCED = re.compile(r"@media\s*\(\s*prefers-reduced-motion\s*:\s*reduce\s*\)", re.I)


def check_css(relative: str, source: str) -> None:
    css = re.sub(r"/\*.*?\*/", "", source, flags=re.S)
    for declaration in FONT.finditer(css):
        for number in PX.finditer(declaration.group(1)):
            if float(number.group(1)) < 12:
                raise ValueError(f"{relative}: font below 12px: {declaration.group(0)}")
    if TRANSITION_ALL.search(css) or TRANSITION_UNNAMED.search(css):
        raise ValueError(f"{relative}: indiscriminate or unnamed transition")
    if OUTLINE_NONE.search(css):
        raise ValueError(f"{relative}: keyboard outline removed")
    if ":focus-visible" not in css:
        raise ValueError(f"{relative}: missing visible keyboard focus")
    if MOTION.search(css) and not REDUCED.search(css):
        raise ValueError(f"{relative}: motion without prefers-reduced-motion")
    if MOTION.search(css) and not re.search(
        r"@media\s*\(\s*prefers-reduced-motion\s*:\s*reduce\s*\)"
        r"\s*\{[^{}]*(?:\{[^{}]*\}[^{}]*)*\}",
        css, re.I | re.S,
    ):
        raise ValueError(f"{relative}: invalid reduced-motion block")


class RedisenoPublicPagesTests(unittest.TestCase):
    def test_criterio_principal(self) -> None:
        self.assertEqual(14, len(CSS_PATHS))
        for relative in CSS_PATHS:
            with self.subTest(path=relative):
                path = ROOT / relative
                self.assertTrue(path.is_file(), relative)
                check_css(relative, path.read_text(encoding="utf-8"))

    def test_regresiones_negativas(self) -> None:
        samples = (
            'a:focus-visible{outline:2px solid #fff}p{font-size:11px}',
            'a:focus-visible{outline:2px solid #fff}p{font:700 11px/1 monospace}',
            'a:focus-visible{outline:2px solid #fff}a{transition:all .2s}',
            'a:focus-visible{outline:2px solid #fff}a{transition-property:all}',
            'a:focus-visible{outline:2px solid #fff}a{transition:.2s}',
            'a:focus-visible{outline:2px solid #fff}a{outline:none}',
            'a{font-size:12px}',
            'a:focus-visible{outline:2px solid #fff}a{animation:pulse 1s}',
        )
        for sample in samples:
            with self.subTest(css=sample):
                with self.assertRaises(ValueError):
                    check_css("fixture.css", sample)

    def test_accessibility_tokens(self) -> None:
        content = "\n".join((ROOT / path).read_text(encoding="utf-8") for path in CSS_PATHS)
        self.assertIn("transform:scale(.96)", content)
        self.assertIn("font-variant-numeric:tabular-nums", content)
        self.assertIn("text-wrap:balance", content)
        self.assertIn("min-height:44px", content)


if __name__ == "__main__":
    unittest.main()
