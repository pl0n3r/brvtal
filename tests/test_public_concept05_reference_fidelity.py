#!/usr/bin/env python3
from __future__ import annotations

import json
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
FIXTURE = ROOT / "tests/fixtures/public-concept05-reference-v1.json"
HERO = ROOT / "css/public-concept05-hero.css"
SHELL = ROOT / "css/public-concept05-shell.css"
RUNTIME = ROOT / "js/public-concept05-hero.js"
PUBLIC_HOME = ROOT / "config/public_home.php"


class Concept05ReferenceFidelityTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.reference = json.loads(FIXTURE.read_text(encoding="utf-8"))
        cls.hero = HERO.read_text(encoding="utf-8")
        cls.shell = SHELL.read_text(encoding="utf-8")
        cls.runtime = RUNTIME.read_text(encoding="utf-8")
        cls.public_home = PUBLIC_HOME.read_text(encoding="utf-8")

    def test_reference_contract_pins_canonical_desktop_and_mobile_composition(self) -> None:
        self.assertEqual(1, self.reference["version"])
        self.assertEqual(
            "01793a8dc4f33267f10e4221f0f00586ba6cc042ab1f2bfa500c4e3628dc8c3f",
            self.reference["source"]["sha256"],
        )
        self.assertEqual({"width": 1440}, self.reference["viewports"]["desktop"])
        self.assertEqual({"width": 390}, self.reference["viewports"]["mobile"])
        self.assertEqual("BR/VT/AL", self.reference["hero"]["desktop"]["title_stack"])
        self.assertEqual("BR/VT/AL", self.reference["hero"]["mobile"]["title_stack"])

    def test_desktop_shell_and_hero_match_reference_geometry(self) -> None:
        for marker in (
            "/* #922 — canonical Concept 05 header fidelity contract. */",
            "height:52px;",
            "/* #922 — canonical Concept 05 shell/Hero fidelity contract. */",
            "--c5-hero-title-stack:2.08ch;",
            "--c5-hero-documentary-left:18%;",
            "--c5-hero-documentary-width:55%;",
            "--c5-hero-statement-left:47%;",
            "word-break:break-all;",
            "min-height:44px;",
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, self.shell + self.hero)
        self.assertNotIn("min-height:max(760px,100svh);\n  background:", self.hero.split("/* #922", 1)[1])

    def test_mobile_hero_is_authored_poster_not_desktop_reflow(self) -> None:
        mobile = self.hero.split("@media(max-width:900px){", 1)[1]
        for marker in (
            "--c5-hero-title-stack:2.08ch;",
            "font-size:clamp(100px,31vw,124px);",
            "left:30%;",
            "width:70%;",
            "bottom:calc(var(--c5-bottom-nav-h) + 34px);",
            "display:block;",
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, mobile)
        self.assertIn("height:54px;", self.shell)

    def test_dynamic_hero_projection_hooks_are_preserved(self) -> None:
        for marker in (
            "data-c5-hero-description",
            "data-c5-hero-documentary",
            "data-c5-hero-documentary-image",
            "data-c5-hero-documentary-label",
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, self.public_home)
        self.assertIn("projectDescription(data);", self.runtime)
        self.assertIn("projectDocumentary(data);", self.runtime)
        self.assertIn("prefers-reduced-motion: reduce", self.runtime)
        self.assertNotIn("GENESIS", self.runtime)


if __name__ == "__main__":
    unittest.main()
