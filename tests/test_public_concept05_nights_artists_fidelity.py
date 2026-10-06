#!/usr/bin/env python3
from __future__ import annotations

import json
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
FIXTURE = ROOT / "tests/fixtures/public-concept05-reference-v1.json"
CSS = ROOT / "css/public-concept05-nights-artists.css"
RUNTIME = ROOT / "js/public-concept05-nights-artists.js"
E2E = ROOT / "tests/e2e/public-concept05-nights-artists.spec.mjs"


class Concept05NightsArtistsFidelityTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.reference = json.loads(FIXTURE.read_text(encoding="utf-8"))
        cls.css = CSS.read_text(encoding="utf-8")
        cls.runtime = RUNTIME.read_text(encoding="utf-8")
        cls.e2e = E2E.read_text(encoding="utf-8")

    @staticmethod
    def _rule(css: str, selector: str) -> str:
        start = css.index(f"{selector}{{") + len(selector) + 1
        end = css.index("}", start)
        return css[start:end]

    def test_reference_contract_pins_nights_and_artists_geometry(self) -> None:
        contract = self.reference["nights_artists"]
        self.assertEqual("clamp(270px,22.5vw,324px)", contract["nights"]["desktop"]["card_width"])
        self.assertEqual("3/4", contract["nights"]["desktop"]["artwork_aspect_ratio"])
        self.assertEqual(0, contract["nights"]["desktop"]["gap_px"])
        self.assertEqual("min(86vw,334px)", contract["nights"]["mobile"]["card_width"])
        self.assertTrue(contract["nights"]["mobile"]["native_swipe"])
        self.assertEqual(5, contract["artists"]["desktop"]["columns"])
        self.assertEqual("2/3", contract["artists"]["desktop"]["portrait_aspect_ratio"])
        self.assertEqual(2, contract["artists"]["mobile"]["columns"])
        self.assertEqual("3/4", contract["artists"]["mobile"]["portrait_aspect_ratio"])

    def test_nights_desktop_strip_and_mobile_swipe_match_reference(self) -> None:
        contract = self.reference["nights_artists"]["nights"]
        desktop = self.css.split("@media(max-width:900px){", 1)[0]
        track = self._rule(desktop, '[data-concept="05"] .events-track')
        card = self._rule(desktop, '[data-concept="05"] .event-card.c5-night-card')
        media = self._rule(desktop, '[data-concept="05"] .c5-night-card .event-img')
        info = self._rule(desktop, '[data-concept="05"] .c5-night-card .event-info')

        self.assertIn("/* #930 — canonical Concept 05 NIGHTS + ARTISTS fidelity contract. */", desktop)
        self.assertIn(f'--c5-night-card-w:{contract["desktop"]["card_width"]};', track)
        self.assertIn("gap:0;", track)
        self.assertIn("border-top:1px solid var(--c5-rule-color);", track)
        self.assertIn("border-bottom:1px solid var(--c5-rule-color);", track)
        self.assertIn("flex:0 0 var(--c5-night-card-w);", card)
        self.assertIn("border-left:1px solid var(--c5-rule-color);", card)
        self.assertIn(f'aspect-ratio:{contract["desktop"]["artwork_aspect_ratio"].replace("/", " / ")};', media)
        self.assertIn(f'border-top:{contract["desktop"]["info_red_rule_px"]}px solid var(--c5-signal-red);', info)

        mobile = self.css.split("@media(max-width:900px){", 1)[1].split(
            "@media(prefers-reduced-motion:reduce)", 1
        )[0]
        mobile_track = self._rule(mobile, '[data-concept="05"] .events-track')
        mobile_card = self._rule(mobile, '[data-concept="05"] .event-card.c5-night-card')
        self.assertIn("overflow-x:auto;", desktop)
        self.assertIn("touch-action:pan-x pan-y;", desktop)
        self.assertIn("gap:0;", mobile_track)
        self.assertIn(f'flex-basis:{contract["mobile"]["card_width"]};', mobile_card)
        self.assertIn(f'width:{contract["mobile"]["card_width"]}!important;', mobile_card)
        self.assertIn(
            f'padding-bottom:calc(var(--c5-bottom-nav-h) + {contract["mobile"]["bottom_nav_clearance_px"]}px);',
            mobile,
        )

    def test_artists_desktop_and_mobile_keep_authored_editorial_density(self) -> None:
        contract = self.reference["nights_artists"]["artists"]
        desktop = self.css.split("@media(max-width:900px){", 1)[0]
        group = self._rule(desktop, '[data-concept="05"] .roster-group')
        card = self._rule(desktop, '[data-concept="05"] .artist.c5-artist-card')
        media = self._rule(desktop, '[data-concept="05"] .c5-artist-media')
        focus = self._rule(desktop, '[data-concept="05"] .artist.c5-artist-card:focus-visible')

        self.assertIn(f'grid-template-columns:repeat({contract["desktop"]["columns"]},minmax(0,1fr));', group)
        self.assertIn("gap:0;", group)
        self.assertIn("border:0;", card)
        self.assertIn("border-left:1px solid var(--c5-rule-color);", card)
        self.assertIn(f'aspect-ratio:{contract["desktop"]["portrait_aspect_ratio"].replace("/", " / ")};', media)
        self.assertIn(f'box-shadow:inset {contract["desktop"]["hover_registration_px"]}px 0 var(--c5-signal-green);', focus)

        mobile = self.css.split("@media(max-width:900px){", 1)[1].split(
            "@media(prefers-reduced-motion:reduce)", 1
        )[0]
        mobile_group = self._rule(mobile, '[data-concept="05"] .roster-group')
        mobile_media = self._rule(mobile, '[data-concept="05"] .c5-artist-media')
        self.assertIn(f'grid-template-columns:repeat({contract["mobile"]["columns"]},minmax(0,1fr));', mobile_group)
        self.assertIn("gap:0;", mobile_group)
        self.assertIn(f'aspect-ratio:{contract["mobile"]["portrait_aspect_ratio"].replace("/", " / ")};', mobile_media)
        self.assertNotIn("@media(max-width:420px)", self.css)

    def test_dynamic_event_artist_routes_and_failure_semantics_are_preserved(self) -> None:
        for marker in (
            "No network requests",
            "safeMediaUrl",
            "failClosed",
            "BRVTAL_CONCEPT05_STRIPS_INIT",
            "brvtal:roster-rendered",
            "is-media-missing",
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, self.runtime)

        for marker in (
            "BRVTALPublicDataPromise",
            "__publicPayloadReads",
            "/events/active-night",
            "/artists/pl0n3r",
            "external.example/should-not-win",
            "event-ticket",
            "is-media-missing",
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, self.e2e)

        self.assertIn("expect(await page.evaluate(() => window.__publicPayloadReads)).toBe(1);", self.e2e)
        self.assertIn("touchAction:getComputedStyle(el).touchAction", self.e2e)
        self.assertIn("prefers-reduced-motion:reduce", self.css)


if __name__ == "__main__":
    unittest.main()
