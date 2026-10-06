#!/usr/bin/env python3
from __future__ import annotations

import json
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
FIXTURE = ROOT / "tests/fixtures/public-concept05-reference-v1.json"
CSS = ROOT / "css/public-concept05-experience.css"
PUBLIC_HOME = ROOT / "config/public_home.php"
RUNTIME = ROOT / "js/public-concept05-experience.js"


class Concept05ExperienceFidelityTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.reference = json.loads(FIXTURE.read_text(encoding="utf-8"))
        cls.css = CSS.read_text(encoding="utf-8")
        cls.public_home = PUBLIC_HOME.read_text(encoding="utf-8")
        cls.runtime = RUNTIME.read_text(encoding="utf-8")

    @staticmethod
    def _rule(css: str, selector: str) -> str:
        start = css.rindex(f"{selector}{{") + len(selector) + 1
        end = css.index("}", start)
        return css[start:end]

    def test_reference_contract_pins_next_experience_hierarchy_and_geometry(self) -> None:
        experience = self.reference["experience"]
        self.assertEqual(
            ["identity-artwork", "date", "venue-city", "ticket-state-action", "lineup"],
            experience["hierarchy"],
        )
        self.assertEqual(1.42, experience["desktop"]["grid_art_fr"])
        self.assertEqual(0.58, experience["desktop"]["grid_copy_fr"])
        self.assertEqual("clamp(72px,6vw,96px)", experience["desktop"]["copy_overlap"])
        self.assertEqual("clamp(600px,60vw,790px)", experience["desktop"]["artwork_min"])
        self.assertEqual("4/5", experience["mobile"]["artwork_aspect_ratio"])
        self.assertEqual(62, experience["mobile"]["copy_overlap_px"])
        self.assertEqual(54, experience["mobile"]["bottom_nav_clearance_px"])

    def test_desktop_next_experience_matches_reference_geometry(self) -> None:
        desktop = self.reference["experience"]["desktop"]
        css = self.css.split("@media(max-width:900px){", 1)[0]
        root = self._rule(css, '[data-concept="05"] .c5-experience-authored')
        artwork = self._rule(css, '[data-concept="05"] .c5-experience-authored .c5-experience-artwork')
        copy = self._rule(css, '[data-concept="05"] .c5-experience-authored .genesis-copy')
        title = self._rule(css, '[data-concept="05"] .c5-experience-authored .genesis-copy h2')
        actions = self._rule(css, '[data-concept="05"] .c5-experience-authored .experience-actions')
        ticket = self._rule(css, '[data-concept="05"] .c5-experience-authored .experience-actions .ticket-cta')

        self.assertIn("/* #927 — canonical Concept 05 Next Experience fidelity contract. */", css)
        self.assertIn(
            f'grid-template-columns:minmax(0,{desktop["grid_art_fr"]}fr) minmax(340px,{desktop["grid_copy_fr"]}fr);',
            root,
        )
        self.assertIn(f'--c5-exp-copy-overlap:{desktop["copy_overlap"]};', root)
        self.assertIn(f'--c5-exp-artwork-min:{desktop["artwork_min"]};', root)
        self.assertIn("min-height:var(--c5-exp-artwork-min);", artwork)
        self.assertIn("calc(0px - var(--c5-exp-copy-overlap))", copy)
        self.assertIn("border-left:6px solid var(--c5-signal-red);", copy)
        self.assertIn(f'max-width:{desktop["title_max_ch"]}ch;', title)
        self.assertIn("grid-template-columns:minmax(0,.72fr) minmax(0,1.28fr);", actions)
        self.assertIn(f'min-height:{desktop["ticket_cta_min_height_px"]}px;', ticket)

    def test_mobile_next_experience_is_authored_and_bottom_nav_safe(self) -> None:
        mobile_ref = self.reference["experience"]["mobile"]
        mobile = self.css.split("@media(max-width:900px){", 1)[1].split(
            "@media(prefers-reduced-motion:reduce)", 1
        )[0]
        root = self._rule(mobile, '[data-concept="05"] .c5-experience-authored')
        artwork = self._rule(mobile, '[data-concept="05"] .c5-experience-authored .c5-experience-artwork')
        copy = self._rule(mobile, '[data-concept="05"] .c5-experience-authored .genesis-copy')
        title = self._rule(mobile, '[data-concept="05"] .c5-experience-authored .genesis-copy h2')
        actions = self._rule(mobile, '[data-concept="05"] .c5-experience-authored .experience-actions')
        ticket = self._rule(mobile, '[data-concept="05"] .c5-experience-authored .experience-actions .ticket-cta')

        self.assertIn(f'calc(var(--c5-bottom-nav-h) + {mobile_ref["bottom_nav_clearance_px"]}px)', root)
        self.assertIn(f'aspect-ratio:{mobile_ref["artwork_aspect_ratio"]};', artwork)
        self.assertIn(f'width:calc(100% - {mobile_ref["copy_left_px"]}px);', copy)
        self.assertIn(
            f'margin:-{mobile_ref["copy_overlap_px"]}px 0 0 {mobile_ref["copy_left_px"]}px;',
            copy,
        )
        self.assertIn("font-size:clamp(58px,19vw,86px);", title)
        self.assertIn("grid-template-columns:1fr;", actions)
        self.assertEqual("first", mobile_ref["ticket_action_order"])
        self.assertIn("order:-1;", ticket)
        self.assertIn(f'min-height:{mobile_ref["ticket_cta_min_height_px"]}px;', ticket)
        self.assertNotIn("@media(max-width:390px)", self.css)

    def test_dynamic_projection_and_failure_semantics_are_preserved(self) -> None:
        for marker in (
            "brvtal_public_select_next_experience",
            "brvtal_public_next_experience",
            "brvtal_public_render_next_experience",
            "brvtalPublicNextExperienceTicketSignalMarkup",
            "brvtal_public_next_experience_lineup_markup",
            'data-c5-fact="date"',
            'data-c5-fact="time"',
            'data-c5-fact="location"',
            "data-c5-experience-artwork",
            "public_ticket_url",
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, self.public_home)
        self.assertIn("failClosed", self.runtime)
        self.assertIn("is-media-missing", self.runtime)
        self.assertIn("@media(prefers-reduced-motion:reduce)", self.css)
        self.assertNotIn("GENESIS</h2>", self.public_home)


if __name__ == "__main__":
    unittest.main()
