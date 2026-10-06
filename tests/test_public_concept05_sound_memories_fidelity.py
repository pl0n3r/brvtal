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
        desktop = contract["desktop"]
        mobile = contract["mobile"]

        self.assertEqual("compact-strip", desktop["header_mode"])
        self.assertEqual("split-feature-index", desktop["sound_layout"])
        self.assertEqual("minmax(0,1.08fr) minmax(0,.92fr)", desktop["sound_list_columns"])
        self.assertEqual(182, desktop["sound_feature_min_height_px"])
        self.assertEqual(
            "36px 128px minmax(0,1fr) 84px",
            desktop["sound_feature_columns"],
        )
        self.assertEqual("1/1", desktop["sound_feature_cover_aspect_ratio"])
        self.assertEqual("36px minmax(0,1fr) 88px", desktop["sound_record_columns"])
        self.assertEqual(12, desktop["memories_columns"])
        self.assertEqual(4, desktop["memories_gap_px"])
        self.assertEqual([5, 3, 4, 4, 5, 3], desktop["memories_spans"])

        self.assertEqual(92, mobile["sound_feature_min_height_px"])
        self.assertEqual(
            "28px 72px minmax(0,1fr) 52px",
            mobile["sound_feature_columns"],
        )
        self.assertEqual("1/1", mobile["sound_feature_cover_aspect_ratio"])
        self.assertEqual(2, mobile["memories_columns"])
        self.assertEqual(4, mobile["memories_gap_px"])
        self.assertEqual(5, mobile["memories_full_span_cycle"])
        self.assertEqual(42, mobile["bottom_nav_clearance_px"])

    def test_sound_desktop_and_mobile_match_archive_system_reference(self) -> None:
        desktop = self.css.split("@media(max-width:900px){", 1)[0]
        mobile = self.css.split("@media(max-width:900px){", 1)[1].split(
            "@media(prefers-reduced-motion:reduce)", 1
        )[0]

        for marker in (
            MARKER,
            "grid-template-columns:auto auto minmax(0,1fr);",
            "font-size:clamp(12px,1.1vw,16px);",
            "display:none;",
            "grid-template-columns:minmax(0,1.08fr) minmax(0,.92fr);",
            "grid-column:1;",
            "grid-row:1 / span 4;",
            "grid-template-columns:36px 128px minmax(0,1fr) 84px;",
            "min-height:182px;",
            "aspect-ratio:1/1;",
            "grid-template-columns:36px minmax(0,1fr) 88px;",
            "height:22px;",
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, desktop)

        for marker in (
            "display:block;",
            "grid-template-columns:28px 72px minmax(0,1fr) 52px;",
            "min-height:92px;",
            "width:72px;",
            "max-width:72px;",
            "aspect-ratio:1/1;",
            "grid-template-columns:28px minmax(0,1fr) 52px;",
            "font-size:clamp(16px,5.1vw,20px);",
            "calc(var(--c5-bottom-nav-h) + 42px)",
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, mobile)

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
