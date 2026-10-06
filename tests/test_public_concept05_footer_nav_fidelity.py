#!/usr/bin/env python3
from __future__ import annotations

import json
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
FIXTURE = ROOT / "tests/fixtures/public-concept05-reference-v1.json"
CSS = ROOT / "css/public-concept05-shell.css"
RUNTIME = ROOT / "js/public-concept05-shell.js"
E2E = ROOT / "tests/e2e/public-concept05-shell.spec.mjs"
MARKER = "/* #938 — canonical Concept 05 footer + mobile nav fidelity contract. */"


class Concept05FooterNavFidelityTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.reference = json.loads(FIXTURE.read_text(encoding="utf-8"))
        cls.css = CSS.read_text(encoding="utf-8")
        cls.runtime = RUNTIME.read_text(encoding="utf-8")
        cls.e2e = E2E.read_text(encoding="utf-8")

    def test_reference_contract_pins_footer_and_mobile_nav_geometry(self) -> None:
        contract = self.reference["footer_nav"]
        desktop = contract["desktop"]
        mobile = contract["mobile"]
        self.assertEqual(118, desktop["footer_min_height_px"])
        self.assertEqual(72, desktop["footer_wordmark_max_px"])
        self.assertEqual(12, desktop["footer_grid_columns"])
        self.assertEqual("1/4", desktop["footer_brand_column"])
        self.assertEqual("4/7", desktop["footer_nav_column"])
        self.assertEqual("7/9", desktop["footer_contact_column"])
        self.assertEqual("9/11", desktop["footer_social_column"])
        self.assertEqual("11/-1", desktop["footer_legal_column"])
        self.assertEqual("single-horizontal-band", desktop["footer_layout"])
        self.assertEqual(5, mobile["nav_items"])
        self.assertEqual(48, mobile["nav_target_min_px"])
        self.assertEqual(16, mobile["nav_icon_width_px"])
        self.assertEqual(3, mobile["nav_active_rule_px"])
        self.assertTrue(mobile["safe_area"])
        self.assertEqual(96, mobile["footer_wordmark_max_px"])
        self.assertEqual(20, mobile["footer_grid_gap_px"])
        self.assertEqual(42, mobile["footer_bottom_clearance_px"])

    def test_footer_uses_only_canonical_identity_social_contact_and_pages(self) -> None:
        for marker in (
            MARKER,
            "min-height:118px;",
            "font-size:clamp(48px,5vw,72px);",
            "grid-column:1/4;",
            "grid-column:4/7;",
            "grid-column:7/9;",
            "grid-column:9/11;",
            "grid-column:11/-1;",
            "a[hidden]{display:none!important}",
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, self.css)

        for marker in (
            "window.BRVTALPublicDataPromise",
            "const preferred = ['privacy', 'privacy-policy', 'privacy-notice'];",
            "privacy.href = '/pages/' + encodeURIComponent(slug);",
            "privacy.hidden = true;",
            "root.dataset.c5ShellData = 'unavailable';",
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, self.runtime)

        self.assertNotIn("fetch(", self.runtime)
        for marker in (
            'href="/contact">CONTACT ↗',
            'href="/contact">COLLABORATE ↗',
            'data-social="instagram" hidden',
            "expect(await page.evaluate(() => window.__reads)).toBe(1);",
            "footer keeps privacy fail-closed",
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, self.e2e)

    def test_mobile_nav_is_authored_safe_area_aware_and_canonical(self) -> None:
        for marker in (
            "padding-bottom:var(--c5-mobile-safe-bottom);",
            "min-height:48px;",
            "height:3px;",
            "width:16px;height:14px;",
            'data-icon="nights"',
            'data-icon="artists"',
            'data-icon="sound"',
            'data-icon="records"',
            'data-icon="journal"',
        ):
            with self.subTest(marker=marker):
                source = self.css if marker.startswith(("padding", "min-height", "height:", "width:")) else self.e2e
                self.assertIn(marker, source)

        for marker in (
            "activeFromHash",
            "IntersectionObserver",
            "aria-current",
            "bottomNav.dataset.menuState = locked ? 'hidden' : 'visible';",
            "bottomNav.inert = locked;",
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, self.runtime)

        self.assertIn("await expect(page.locator('.c5-bottom-nav a')).toHaveCount(5);", self.e2e)
        self.assertIn("toBeGreaterThanOrEqual(44)", self.e2e)
        self.assertIn("document.documentElement.scrollWidth <= document.documentElement.clientWidth", self.e2e)

    def test_accessibility_failure_and_reduced_motion_semantics_are_preserved(self) -> None:
        mobile = self.css.split("@media(max-width:900px){", 1)[1].split(
            "@media(prefers-reduced-motion:reduce)", 1
        )[0]
        self.assertIn("padding:58px 14px calc(var(--c5-bottom-nav-h) + 42px)", mobile)
        self.assertIn("grid-template-columns:1fr;gap:20px", mobile)
        self.assertIn("font-size:clamp(72px,24vw,96px)", mobile)
        self.assertIn("min-height:44px", mobile)
        self.assertIn('@media(prefers-reduced-motion:reduce)', self.css)
        self.assertIn("transition:none!important", self.css)
        self.assertIn("html.c5-visual-test", self.css)
        self.assertIn("aria-hidden", self.runtime)
        self.assertIn("menu-scroll-locked", self.runtime)
        self.assertIn("reduced motion keeps the authored shell complete and static", self.e2e)


if __name__ == "__main__":
    unittest.main()
