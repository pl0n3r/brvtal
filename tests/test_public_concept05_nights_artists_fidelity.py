#!/usr/bin/env python3
from __future__ import annotations

import json
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
FIXTURE = ROOT / "tests/fixtures/public-concept05-reference-v1.json"
CSS = ROOT / "css/public-concept05-nights-artists.css"
ENHANCER = ROOT / "js/public-concept05-nights-artists.js"
APP = ROOT / "js/app.js"
ROSTER = ROOT / "js/public-roster.js"
MARKER = "/* #930 — canonical Concept 05 Nights + Artists fidelity contract. */"


class Concept05NightsArtistsFidelityTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.reference = json.loads(FIXTURE.read_text(encoding="utf-8"))
        cls.css = CSS.read_text(encoding="utf-8")
        cls.enhancer = ENHANCER.read_text(encoding="utf-8")
        cls.app = APP.read_text(encoding="utf-8")
        cls.roster = ROSTER.read_text(encoding="utf-8")

    @classmethod
    def _canonical_css(cls) -> str:
        self_marker = cls.MARKER if hasattr(cls, "MARKER") else MARKER
        return cls.css.rsplit(self_marker, 1)[1]

    def test_reference_contract_pins_nights_and_artists_geometry(self) -> None:
        contract = self.reference["nights_artists"]
        self.assertEqual(4, contract["desktop"]["nights_visible_cards_target"])
        self.assertEqual("clamp(270px,23.05vw,332px)", contract["desktop"]["nights_card_width"])
        self.assertEqual("16/9", contract["desktop"]["nights_media_aspect_ratio"])
        self.assertEqual(6, contract["desktop"]["artists_columns"])
        self.assertEqual("4/5", contract["desktop"]["artists_media_aspect_ratio"])
        self.assertEqual("min(88vw,342px)", contract["mobile"]["nights_card_width"])
        self.assertEqual("42% minmax(0,1fr)", contract["mobile"]["nights_grid"])
        self.assertEqual("1/1", contract["mobile"]["nights_media_aspect_ratio"])
        self.assertEqual(2, contract["mobile"]["artists_columns"])
        self.assertEqual("4/5", contract["mobile"]["artists_media_aspect_ratio"])
        self.assertEqual(42, contract["mobile"]["bottom_nav_clearance_px"])

    def test_nights_desktop_strip_and_mobile_swipe_match_reference(self) -> None:
        css = self._canonical_css()
        desktop = css.split("@media(min-width:901px){", 1)[1].split("@media(max-width:900px){", 1)[0]
        mobile = css.split("@media(max-width:900px){", 1)[1]

        for marker in (
            "flex:0 0 clamp(270px,23.05vw,332px);",
            "width:clamp(270px,23.05vw,332px)!important;",
            "aspect-ratio:16 / 9;",
            "min-height:132px;",
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, desktop)

        for marker in (
            "grid-template-columns:42% minmax(0,1fr);",
            "flex-basis:min(88vw,342px);",
            "width:min(88vw,342px)!important;",
            "aspect-ratio:1 / 1;",
            "calc(var(--c5-bottom-nav-h) + 42px)",
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, mobile)

        self.assertIn("overflow-x:auto;", self.css)
        self.assertIn("touch-action:pan-x pan-y;", self.css)
        self.assertEqual(1, self.css.count(MARKER))

    def test_artists_desktop_and_mobile_keep_authored_editorial_density(self) -> None:
        css = self._canonical_css()
        desktop = css.split("@media(min-width:901px){", 1)[1].split("@media(max-width:900px){", 1)[0]
        mobile = css.split("@media(max-width:900px){", 1)[1]

        self.assertIn("grid-template-columns:repeat(6,minmax(0,1fr));", desktop)
        self.assertIn("aspect-ratio:4 / 5;", desktop)
        self.assertIn("border:0;", desktop)
        self.assertIn("font-size:clamp(14px,1.3vw,19px);", desktop)
        self.assertIn("grid-template-columns:repeat(2,minmax(0,1fr));", mobile)
        self.assertIn("font-size:clamp(17px,5.8vw,23px);", mobile)

    def test_dynamic_event_artist_routes_and_failure_semantics_are_preserved(self) -> None:
        for marker in (
            "window.BRVTALPublicDataPromise",
            "canonicalEventList(activeItems, archiveItems)",
            "eventRecordUrl(event)",
            "eventTicketUrl(event, status)",
            'data-c5-lifecycle="',
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, self.app)

        for marker in (
            "window.BRVTALPublicDataPromise",
            "window.BRVTALPublicRoster",
            "/artists/",
            "data-roster-group",
            "brvtal:roster-rendered",
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, self.roster)

        self.assertIn("function failClosed(host, image)", self.enhancer)
        self.assertIn("function safeMediaUrl(value)", self.enhancer)
        self.assertNotIn("fetch(", self.enhancer)
        self.assertIn("@media(prefers-reduced-motion:reduce)", self.css)
        self.assertIn("min-height:44px;", self.css)


if __name__ == "__main__":
    unittest.main()
