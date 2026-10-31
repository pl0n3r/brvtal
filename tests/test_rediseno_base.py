#!/usr/bin/env python3
"""Deterministic, offline public design baseline checks for BRVTAL #1360.

Static assertions cover the four claimed CSS sources. Browser contrast and
owner screenshot approval are separately tracked under #1358.
"""
from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[1]
CSS_PATHS = (
    "css/public-concept05-tokens.css",
    "css/public-legibility.css",
    "css/public-visual-identity.css",
    "css/style.css",
)
COMMENTS = re.compile(r"/\*.*?\*/", flags=re.S)
DECL = re.compile(r"(?<![\w-])(?:font-size|font|--c5-type-mono)\s*:\s*([^;{}]*)", re.I)
PX = re.compile(r"(\d+(?:\.\d+)?)px\b", re.I)
TRANSITION_ALL = re.compile(r"(?<![\w-])transition\s*:\s*all\b", re.I)
OUTLINE_NONE = re.compile(r"(?<![\w-])outline\s*:\s*(?:none|0(?:px)?)(?=\s*[;}])", re.I)
MOTION = re.compile(r"(?<![\w-])(?:animation|transition)\s*:\s*(?!none\b)", re.I)
REDUCED = re.compile(r"@media\s*\(\s*prefers-reduced-motion\s*:\s*reduce\s*\)", re.I)


def violations(relative: str, source: str) -> list[str]:
    """Reject explicit small text, blanket transition, lost focus or unsafe motion."""
    css = COMMENTS.sub("", source)
    problems = []
    for declaration in DECL.finditer(css):
        for size in PX.finditer(declaration.group(1)):
            if float(size.group(1)) < 12:
                problems.append(f"{relative}: explicit text size < 12px: {size.group(0)}")
    if TRANSITION_ALL.search(css):
        problems.append(f"{relative}: transition: all")
    if OUTLINE_NONE.search(css):
        problems.append(f"{relative}: outline removed")
    if (MOTION.search(css) or "@keyframes" in css) and not REDUCED.search(css):
        problems.append(f"{relative}: motion without prefers-reduced-motion")
    return problems


class RedisenoBaseTests(unittest.TestCase):
    def test_criterio_principal(self) -> None:
        sources = {}
        problems = []
        for relative in CSS_PATHS:
            file = ROOT / relative
            self.assertTrue(file.is_file(), f"Missing declared file: {relative}")
            source = file.read_text(encoding="utf-8")
            self.assertTrue(source.strip(), relative)
            sources[relative] = source
            problems.extend(violations(relative, source))
        self.assertFalse(problems, "; ".join(problems))
        self.assertIn("--c5-type-mono:clamp(12px", sources[CSS_PATHS[0]])
        self.assertIn(":focus-visible", sources[CSS_PATHS[3]])
        self.assertIn("outline:2px solid", sources[CSS_PATHS[3]])
        self.assertIn("min-height:44px", sources[CSS_PATHS[3]])
        self.assertIn("transform:scale(.96)", sources[CSS_PATHS[3]])
        self.assertIn("text-wrap:balance", sources[CSS_PATHS[3]])
        self.assertIn("text-wrap:pretty", sources[CSS_PATHS[3]])
        self.assertIn("font-variant-numeric:tabular-nums", sources[CSS_PATHS[3]])
        self.verify_negative_cases()

    def verify_negative_cases(self) -> None:
        bad = {
            "font-size": ("p{font-size:11px}", "explicit text size"),
            "shorthand": ("p{font:700 10px/1.5 monospace}", "explicit text size"),
            "mono token": (":root{--c5-type-mono:clamp(11px,.9vw,13px)}", "explicit text size"),
            "transition": ("a{transition: all .2s}", "transition: all"),
            "outline": ("a:focus{outline:none}", "outline removed"),
            "outline zero": ("a:focus{outline:0}", "outline removed"),
            "motion": ("p{animation:pulse 1s infinite}", "without prefers-reduced-motion"),
        }
        for name, (source, expected) in bad.items():
            with self.subTest(name=name):
                errors = violations(name, source)
                self.assertTrue(any(expected in error for error in errors), errors)
        self.assertEqual([], violations("good", "p{font:700 12px/1.4 monospace;transition:opacity .3s}@media(prefers-reduced-motion:reduce){p{transition:none}}"))
        self.assertEqual([], violations("comment", "/*p{font-size:9px}*/p{font-size:12px}"))

    def test_rechaza_regresiones(self) -> None:
        self.verify_negative_cases()


if __name__ == "__main__":
    unittest.main()
