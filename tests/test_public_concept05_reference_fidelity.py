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

    @staticmethod
    def _rule(css: str, selector: str) -> str:
        start = css.index(f"{selector}{{") + len(selector) + 1
        end = css.index("}", start)
        return css[start:end]

    def test_reference_contract_pins_canonical_desktop_and_mobile_composition(self) -> None:
        self.assertEqual(2, self.reference["version"])
        self.assertEqual(
            "f3d8434a605cee56d84d97307c0e3f5a6409b84d",
            self.reference["source"]["git_blob_sha"],
        )
        self.assertEqual("62041aa6", self.reference["source"]["sha256_prefix"])
        self.assertEqual({"width": 1440}, self.reference["viewports"]["desktop"])
        self.assertEqual({"width": 390}, self.reference["viewports"]["mobile"])
        self.assertEqual("BR/VT/AL", self.reference["hero"]["desktop"]["title_stack"])
        self.assertEqual("BR/VT/AL", self.reference["hero"]["mobile"]["title_stack"])

    def test_desktop_shell_and_hero_match_reference_geometry(self) -> None:
        desktop = self.reference["hero"]["desktop"]
        desktop_hero = self.hero.split("@media(max-width:900px){", 1)[0]
        root = self._rule(desktop_hero, '[data-concept="05"] .home-phase-a-hero')
        title = self._rule(desktop_hero, '[data-concept="05"] .home-phase-a-hero .hero-title')
        documentary = self._rule(desktop_hero, '[data-concept="05"] .c5-hero-documentary')
        statement = self._rule(desktop_hero, '[data-concept="05"] .home-phase-a-hero .hero-declaration')
        nav = self._rule(self.shell, '[data-concept="05"] .nav')

        self.assertIn("/* #922 — canonical Concept 05 shell/Hero fidelity contract. */", desktop_hero)
        self.assertIn("/* #922 — canonical Concept 05 header fidelity contract. */", self.shell)
        self.assertIn(f'--c5-hero-title-stack:{desktop["title_columns_ch"]}ch;', root)
        self.assertIn(f'--c5-hero-documentary-left:{desktop["documentary_left_pct"]}%;', root)
        self.assertIn(f'--c5-hero-documentary-width:{desktop["documentary_width_pct"]}%;', root)
        self.assertIn(f'--c5-hero-statement-left:{desktop["statement_left_pct"]}%;', root)
        self.assertIn(f'height:{desktop["header_height_px"]}px;', nav)
        self.assertIn("word-break:break-all;", title)
        self.assertIn("left:var(--c5-hero-documentary-left);", documentary)
        self.assertIn("width:var(--c5-hero-documentary-width);", documentary)
        self.assertIn("left:var(--c5-hero-statement-left);", statement)
        self.assertIn("min-height:44px;", self._rule(desktop_hero, '[data-concept="05"] .c5-hero-explore'))

    def test_mobile_hero_is_authored_poster_not_desktop_reflow(self) -> None:
        mobile_ref = self.reference["hero"]["mobile"]
        mobile = self.hero.split("@media(max-width:900px){", 1)[1].split(
            "@media(max-width:430px)", 1
        )[0]
        mobile_shell = self.shell.split("@media(max-width:900px){", 1)[1].split(
            "@media(prefers-reduced-motion:reduce)", 1
        )[0]
        title = self._rule(mobile, '[data-concept="05"] .home-phase-a-hero .hero-title')
        documentary = self._rule(mobile, '[data-concept="05"] .c5-hero-documentary')
        statement = self._rule(mobile, '[data-concept="05"] .home-phase-a-hero .hero-declaration')
        nav = self._rule(mobile_shell, '[data-concept="05"] .nav')

        self.assertEqual("below-poster", mobile_ref["statement_position"])
        self.assertIn("width:var(--c5-hero-title-stack);", title)
        self.assertIn("font-size:clamp(100px,31vw,124px);", title)
        self.assertIn(f'left:{mobile_ref["documentary_left_pct"]}%;', documentary)
        self.assertIn(f'width:{mobile_ref["documentary_width_pct"]}%;', documentary)
        self.assertIn("height:390px;", documentary)
        self.assertIn("bottom:calc(var(--c5-bottom-nav-h) + 34px);", statement)
        self.assertIn(f'height:{mobile_ref["header_height_px"]}px;', nav)

        short_mobile = self.hero.split("@media(max-width:430px) and (max-height:760px){", 1)[1].split(
            "@media(prefers-reduced-motion:reduce)", 1
        )[0]
        short_documentary = self._rule(short_mobile, '[data-concept="05"] .c5-hero-documentary')
        self.assertIn("height:350px;", short_documentary)
        self.assertNotIn("top:", short_documentary)

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
