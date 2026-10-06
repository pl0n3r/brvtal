#!/usr/bin/env python3
from __future__ import annotations

import json
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
FIXTURE = ROOT / "tests/fixtures/public-concept05-reference-v1.json"
CSS = ROOT / "css/public-concept05-sound-memories.css"
ENHANCER = ROOT / "js/public-concept05-sound-memories.js"
SETS = ROOT / "js/public-sets-library.js"
MEDIA = ROOT / "js/public-media.js"
E2E = ROOT / "tests/e2e/public-concept05-sound-memories.spec.mjs"
MARKER = "/* #933 — canonical Concept 05 SOUND + MEMORIES fidelity contract. */"


class Concept05SoundMemoriesFidelityTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.reference = json.loads(FIXTURE.read_text(encoding="utf-8"))
        cls.css = CSS.read_text(encoding="utf-8")
        cls.enhancer = ENHANCER.read_text(encoding="utf-8")
        cls.sets = SETS.read_text(encoding="utf-8")
        cls.media = MEDIA.read_text(encoding="utf-8")
        cls.e2e = E2E.read_text(encoding="utf-8")

    def test_reference_contract_pins_sound_and_memories_geometry(self) -> None:
        contract = self.reference["sound_memories"]
        self.assertEqual("compact-strip", contract["desktop"]["header_mode"])
        self.assertEqual(260, contract["desktop"]["sound_feature_min_height_px"])
        self.assertEqual(
            "52px minmax(180px,.52fr) minmax(0,1.48fr) 112px",
            contract["desktop"]["sound_feature_columns"],
        )
        self.assertEqual("4/3", contract["desktop"]["sound_feature_cover_aspect_ratio"])
        self.assertEqual("52px minmax(0,1fr) 112px", contract["desktop"]["sound_record_columns"])
        self.assertEqual(12, contract["desktop"]["memories_columns"])
        self.assertEqual(4, contract["desktop"]["memories_gap_px"])
        self.assertEqual([5, 3, 4, 4, 5, 3], contract["desktop"]["memories_spans"])
        self.assertEqual("16/10", contract["mobile"]["sound_feature_cover_aspect_ratio"])
        self.assertEqual(2, contract["mobile"]["memories_columns"])
        self.assertEqual(4, contract["mobile"]["memories_gap_px"])
        self.assertEqual(5, contract["mobile"]["memories_full_span_cycle"])
        self.assertEqual(42, contract["mobile"]["bottom_nav_clearance_px"])

    def test_sound_desktop_and_mobile_match_archive_system_reference(self) -> None:
        for marker in (
            MARKER,
            "grid-template-columns:auto auto minmax(0,1fr);",
            "font-size:clamp(12px,1.1vw,16px);",
            "grid-template-columns:52px minmax(0,1fr) 112px;",
            "grid-template-columns:52px minmax(180px,.52fr) minmax(0,1.48fr) 112px;",
            "min-height:260px;",
            "aspect-ratio:4/3;",
            "height:28px;",
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, self.css)

        mobile = self.css.split("@media(max-width:900px){", 1)[1].split(
            "@media(prefers-reduced-motion:reduce)", 1
        )[0]
        self.assertIn("grid-template-columns:auto minmax(0,1fr);", mobile)
        self.assertIn("aspect-ratio:16/10;", mobile)
        self.assertIn("font-size:clamp(24px,8.2vw,34px);", mobile)
        self.assertIn("calc(var(--c5-bottom-nav-h) + 42px)", mobile)
        self.assertIn("min-height:44px;", self.css)

    def test_memories_desktop_and_mobile_keep_documentary_contact_sheet(self) -> None:
        self.assertIn("grid-template-columns:repeat(12,minmax(0,1fr));", self.css)
        self.assertIn("gap:4px;", self.css)
        for span in (5, 3, 4, 4, 5, 3):
            self.assertIn(f"grid-column:span {span};", self.css)
        self.assertIn("border:0;", self.css)
        self.assertIn("border:1px solid var(--c5-rule-color);", self.css)
        self.assertIn("min-height:58px;", self.css)

        mobile = self.css.split("@media(max-width:900px){", 1)[1].split(
            "@media(prefers-reduced-motion:reduce)", 1
        )[0]
        self.assertIn("grid-template-columns:repeat(2,minmax(0,1fr));", mobile)
        self.assertIn("gap:4px;", mobile)
        self.assertIn(".c5-memory-cell:nth-child(5n+1)", mobile)
        self.assertIn("grid-column:1/-1;", mobile)
        self.assertIn("aspect-ratio:1/1;", mobile)
        self.assertIn("aspect-ratio:16/10;", mobile)

    def test_shared_payload_routes_relations_and_fail_closed_semantics_are_preserved(self) -> None:
        for marker in (
            "const safeHttpUrl = value => {",
            "return /^https?:$/i.test(url.protocol) ? url.href : '';",
            "const routeUrl = (type, slug) => {",
            "window.BRVTALPublicDataPromise",
            "window.BRVTALPublicSetsLibrary",
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, self.sets)

        for marker in (
            "function closeViewer()",
            "function openViewer(item,trigger)",
            "function markMemoryUnavailable(item, mediaNode, stage)",
            "function relationHref(relation)",
            "window.BRVTALScrollLock?.lock('media-viewer')",
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, self.media)

        self.assertIn("No network requests", self.enhancer)
        self.assertNotIn("fetch(", self.enhancer)
        self.assertIn("window.BRVTAL_CONCEPT05_SOUND_MEMORIES_INIT = hydrate;", self.enhancer)

        for marker in (
            "window.BRVTALPublicDataPromise",
            "window.__payloadReads",
            "RAW MEDIA MUST NOT RENDER",
            "javascript:alert(1)",
            "data:text/html",
            "/sets/genesis-closing-signal",
            "/artists/pl0n3r",
            "/events/genesis",
            "MEDIA UNAVAILABLE",
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, self.e2e)

        self.assertIn("@media(prefers-reduced-motion:reduce)", self.css)


if __name__ == "__main__":
    unittest.main()
