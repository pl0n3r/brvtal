#!/usr/bin/env python3
from __future__ import annotations

import json
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
FIXTURE = ROOT / "tests/fixtures/public-concept05-reference-v1.json"
CSS = ROOT / "css/public-concept05-journal-connected.css"
JOURNAL = ROOT / "js/public-transmissions.js"
CONNECTED = ROOT / "js/public-concept05-connected.js"
E2E = ROOT / "tests/e2e/public-concept05-journal-connected.spec.mjs"
MARKER = "/* #937 — canonical Concept 05 JOURNAL + CONNECTED fidelity contract. */"


class Concept05JournalConnectedFidelityTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.reference = json.loads(FIXTURE.read_text(encoding="utf-8"))
        cls.css = CSS.read_text(encoding="utf-8")
        cls.journal = JOURNAL.read_text(encoding="utf-8")
        cls.connected = CONNECTED.read_text(encoding="utf-8")
        cls.e2e = E2E.read_text(encoding="utf-8")

    def test_reference_contract_pins_journal_and_connected_geometry(self) -> None:
        contract = self.reference["journal_connected"]
        desktop = contract["desktop"]
        mobile = contract["mobile"]
        self.assertEqual("compact-strip", desktop["header_mode"])
        self.assertEqual("minmax(0,1.18fr) minmax(320px,.82fr)", desktop["journal_list_columns"])
        self.assertEqual("3/2", desktop["journal_feature_cover_aspect_ratio"])
        self.assertEqual("34px minmax(0,1fr) auto", desktop["journal_index_columns"])
        self.assertEqual(360, desktop["connected_graph_min_height_px"])
        self.assertEqual(5, desktop["connected_columns"])
        self.assertEqual(104, desktop["connected_node_min_height_px"])
        self.assertEqual(92, desktop["connected_tagline_max_px"])
        self.assertEqual(430, mobile["connected_graph_min_height_px"])
        self.assertEqual(2, mobile["connected_columns"])
        self.assertEqual(72, mobile["connected_node_min_height_px"])
        self.assertEqual(42, mobile["bottom_nav_clearance_px"])

    def test_journal_preserves_canonical_blog_projection_and_editorial_states(self) -> None:
        for marker in (
            MARKER,
            "grid-template-columns:auto auto minmax(0,1fr);",
            "grid-template-columns:minmax(0,1.18fr) minmax(320px,.82fr);",
            "aspect-ratio:3/2;",
            "grid-template-columns:34px minmax(0,1fr) auto;",
            "font-size:clamp(36px,4.7vw,68px);",
            "min-height:220px;",
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, self.css)

        for marker in (
            "window.BRVTALPublicDataPromise",
            "const routeUrl = (type, slug) => {",
            "const safeMediaUrl = value => {",
            "data.blog",
            "transmission-feature",
            "transmission-indexed",
            "transmission-relations",
            "NO TRANSMISSIONS PUBLISHED YET.",
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, self.journal)

        for marker in (
            "/blog/night-does-not-end",
            "/events/genesis",
            "/artists/pl0n3r",
            "Open latest Journal entry",
            "is-media-missing",
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, self.e2e)

    def test_connected_uses_only_canonical_relation_counts_and_edges(self) -> None:
        for marker in (
            "min-height:360px;",
            "grid-template-columns:repeat(5,minmax(0,1fr));",
            "min-height:104px;",
            "font-size:clamp(28px,3.4vw,48px);",
            "font-size:clamp(42px,6.5vw,92px);",
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, self.css)

        for marker in (
            "var EDGE_SPECS = [",
            "relations && typeof relations.counts === 'object'",
            "if (value < 1) return;",
            "NO STRUCTURED LINKS YET.",
            "NO STRUCTURED RELATIONSHIPS PUBLISHED YET.",
            "window.BRVTALPublicDataPromise",
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, self.connected)

        self.assertIn("await expect(page.locator('[data-connected-lines] line')).toHaveCount(0);", self.e2e)
        self.assertIn("expect(await page.evaluate(() => window.__publicReads)).toBe(1);", self.e2e)

    def test_mobile_and_failure_states_are_authored_accessible_and_deterministic(self) -> None:
        mobile = self.css.split("@media(max-width:900px){", 1)[1].split(
            "@media(prefers-reduced-motion:reduce)", 1
        )[0]
        for marker in (
            "grid-template-columns:auto minmax(0,1fr);",
            "grid-template-columns:30px minmax(0,1fr);",
            "font-size:clamp(30px,9.8vw,42px);",
            "min-height:430px;",
            "grid-template-columns:repeat(2,minmax(0,1fr));",
            "min-height:72px;",
            "font-size:32px;",
            "font-size:clamp(42px,14vw,58px);",
            "calc(var(--c5-bottom-nav-h) + 42px)",
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, mobile)

        self.assertIn("min-height:44px;", self.css)
        self.assertIn("@media(prefers-reduced-motion:reduce)", self.css)
        self.assertIn("html.c5-visual-test", self.css)
        self.assertIn("document.documentElement.scrollWidth <= document.documentElement.clientWidth", self.e2e)
        self.assertIn("nodeTransition", self.e2e)


if __name__ == "__main__":
    unittest.main()
